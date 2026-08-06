<?php
require '../../../auth/config.php';
header('Content-Type: application/json');

// ── Auth guard: wajib login (JSON 401, tanpa redirect) ──
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode([
        "success" => false,
        "login_required" => true,
        "message" => "Anda harus login untuk mengirim langkah."
    ]));
}

$data = json_decode(
    file_get_contents("php://input"),
    true
);
if (!$data) {
    die(json_encode([
        "success" => false,
        "message" => "Data JSON tidak diterima"
    ]));
}

// ── CSRF guard: token dikirim dalam body JSON ──
if (empty($data['csrf_token']) || !verify_csrf_token($data['csrf_token'])) {
    http_response_code(403);
    die(json_encode([
        "success" => false,
        "message" => "CSRF token tidak valid."
    ]));
}

// ── Otorisasi pemain: verifikasi user login ini benar-benar white/black
// di room ini, dan JANGAN percaya $data['color'] dari client — warna
// ditentukan dari identitas server-side, bukan input client. Ini mencegah
// user mana pun yang tahu room_code mengirim langkah sebagai warna
// siapa pun, atau menyuntik langkah ke game orang lain. ──
$user_id = (int)$_SESSION['user_id'];
$room_code = $data['room'] ?? '';
$roomStmt = $conn->prepare("SELECT white_user_id, black_user_id FROM rooms WHERE room_code = ?");
$roomStmt->bind_param("s", $room_code);
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
    die(json_encode([
        "success" => false,
        "message" => "Anda bukan pemain di room ini."
    ]));
}

$stmt = $conn->prepare(
    "INSERT INTO moves
(room_code, from_r, from_c, to_r, to_c, piece, color, captured, promoted_piece_type, move_data)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
if (!$stmt) {
    die(json_encode([
        "success" => false,
        "message" => $conn->error
    ]));
}
// Jangan simpan token CSRF ke DB (move_data) — token session-bound
// tidak boleh ikut tersimpan & terkirim ke pemain lain via get_move.php.
unset($data['csrf_token']);
// Timpa color di move_data juga dengan versi yang tervalidasi server,
// supaya move_data (JSON mentah yang dikirim balik ke lawan) konsisten
// dengan kolom color di DB — bukan nilai mentah dari client.
$data['color'] = $server_color;
$json = json_encode($data);
$captured = $data['captured'] ?? null;
$promoted = $data['promotedPieceType'] ?? null;
$stmt->bind_param(
    "siiiisssss",
    $data['room'],
    $data['fromR'],
    $data['fromC'],
    $data['toR'],
    $data['toC'],
    $data['piece'],
    $server_color,
    $captured,
    $promoted,
    $json
);
if (!$stmt->execute()) {
    die(json_encode([
        "success" => false,
        "message" => $stmt->error
    ]));
}
echo json_encode([
    "success" => true,
    "id" => $conn->insert_id
]);
