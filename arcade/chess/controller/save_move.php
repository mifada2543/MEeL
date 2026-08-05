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
    $data['color'],
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
