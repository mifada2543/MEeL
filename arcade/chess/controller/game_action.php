<?php
require '../../../auth/config.php';
require_once __DIR__ . '/chess_helpers.php';
header('Content-Type: application/json');

// ─── Auth guard: wajib login (JSON 401, tanpa redirect) ───
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode([
        "success" => false,
        "login_required" => true,
        "message" => "Anda harus login untuk melakukan aksi ini."
    ]));
}

// ─── CSRF guard: semua POST wajib token valid ───
if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    http_response_code(403);
    die(json_encode([
        "success" => false,
        "message" => "CSRF token tidak valid."
    ]));
}

$room   = $_POST['room'] ?? '';
$action = $_POST['action'] ?? '';
$user_id = (int)$_SESSION['user_id'];

$allowed = ['resign', 'draw_offer', 'draw_accept', 'draw_decline', 'disconnect_win', 'game_over', 'rematch_offer', 'rematch_accept', 'rematch_decline'];
if (!in_array($action, $allowed, true)) {
    die(json_encode(["success" => false, "message" => "Aksi tidak dikenal."]));
}

// ─── Otorisasi pemain: warna ditentukan server-side, bukan dari client ───
$roomStmt = $conn->prepare("SELECT white_user_id, black_user_id, black_joined FROM rooms WHERE room_code = ?");
$roomStmt->bind_param("s", $room);
$roomStmt->execute();
$roomRow = $roomStmt->get_result()->fetch_assoc();
if (!$roomRow) {
    http_response_code(404);
    die(json_encode(["success" => false, "message" => "Room tidak ditemukan."]));
}
if ((int)$roomRow['white_user_id'] === $user_id) {
    $server_color = 'w';
} elseif ((int)$roomRow['black_user_id'] === $user_id) {
    $server_color = 'b';
} else {
    http_response_code(403);
    die(json_encode(["success" => false, "message" => "Anda bukan pemain di room ini."]));
}

// ─── Game harus sudah dimulai: lawan (hitam) wajib sudah bergabung ───
if ((int)$roomRow['black_joined'] !== 1) {
    die(json_encode([
        "success" => false,
        "message" => "Lawan belum bergabung, permainan belum dimulai."
    ]));
}

// ─── Cek permainan sudah berakhir (event terminal apa pun) ───
$gameEnded = chess_has_terminal_event($conn, $room);

// ─── Rematch (tanding ulang) — hanya boleh SETELAH game selesai ───
if (in_array($action, ['rematch_offer', 'rematch_accept', 'rematch_decline'], true)) {
    $opponentId = ($server_color === 'w')
        ? (int)$roomRow['black_user_id']
        : (int)$roomRow['white_user_id'];
    $result = chess_rematch($conn, $room, $server_color, $action, $opponentId);
    if (!$result['success']) {
        $resp = ["success" => false, "message" => $result['message']];

        if (!empty($result['opponent_gone'])) {
            $resp['opponent_gone'] = true;
        }
        die(json_encode($resp));
    }
    echo json_encode(["success" => true, "id" => $result['id']]);
    exit;
}

if ($gameEnded) {
    die(json_encode(["success" => false, "message" => "Permainan sudah berakhir."]));
}

// ─── Resign: sepihak, kapan saja (FIDE 5.1.2) ───
if ($action === 'resign') {
    insertGameEvent($conn, $room, $server_color, 'resign');
    echo json_encode(["success" => true, "id" => $conn->insert_id]);
    exit;
}

// ─── Disconnect win: klaim kemenangan karena lawan terputus ───
if ($action === 'disconnect_win') {
    $opponentId = ($server_color === 'w')
        ? (int)$roomRow['black_user_id']
        : (int)$roomRow['white_user_id'];
    if (chess_opponent_online($conn, $opponentId)) {
        die(json_encode([
            "success" => false,
            "message" => "Lawan masih terhubung — belum dapat mengklaim kemenangan."
        ]));
    }
    $opponentColor = ($server_color === 'w') ? 'b' : 'w';
    insertGameEvent($conn, $room, $opponentColor, 'disconnect');
    echo json_encode(["success" => true, "id" => $conn->insert_id]);
    exit;
}

// ─── Game over (checkmate/stalemate): informasi dari client ───
if ($action === 'game_over') {
    $result = chess_record_game_over(
        $conn,
        $room,
        $_POST['color'] ?? '',
        $_POST['reason'] ?? ''
    );
    if (!$result['success']) {
        die(json_encode(["success" => false, "message" => $result['message']]));
    }
    echo json_encode(["success" => true, "id" => $result['id']]);
    exit;
}

// ─── Tentukan giliran sekarang: dari langkah terakhir yang BUKAN event ───
$lastMoveColor = chess_last_move_color($conn, $room);
$turn = $lastMoveColor ? ($lastMoveColor === 'w' ? 'b' : 'w') : 'w';

// ─── Event terakhir (untuk melacak tawaran seri yang sedang pending) ───
$lastEv = chess_last_event($conn, $room);
$pendingOffer = false;
$pendingBy    = null;
if ($lastEv && $lastEv['type'] === 'draw_offer') {
    $pendingOffer = true;
    $pendingBy    = $lastEv['color'];
}

// ─── Tawarkan seri: hanya pemain yang gilirannya (FIDE 9.1.2), satu pending ───
if ($action === 'draw_offer') {
    if ($turn !== $server_color) {
        die(json_encode([
            "success" => false,
            "message" => "Bukan giliran anda untuk menawarkan seri."
        ]));
    }
    if ($pendingOffer) {
        die(json_encode([
            "success" => false,
            "message" => "Masih ada tawaran seri yang menunggu jawaban."
        ]));
    }
    insertGameEvent($conn, $room, $server_color, 'draw_offer');
    echo json_encode(["success" => true, "id" => $conn->insert_id]);
    exit;
}

// ─── Terima / tolak tawaran seri: hanya oleh lawan yang menerima tawaran ───
if ($action === 'draw_accept' || $action === 'draw_decline') {
    if (!$pendingOffer) {
        die(json_encode([
            "success" => false,
            "message" => "Tidak ada tawaran seri yang menunggu."
        ]));
    }
    if ($pendingBy === $server_color) {
        die(json_encode([
            "success" => false,
            "message" => "Anda tidak dapat menjawab tawaran anda sendiri."
        ]));
    }
    insertGameEvent($conn, $room, $server_color, $action);
    echo json_encode(["success" => true, "id" => $conn->insert_id]);
    exit;
}
