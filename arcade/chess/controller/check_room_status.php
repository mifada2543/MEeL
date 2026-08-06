<?php
require '../../../auth/config.php';
header('Content-Type: application/json');

// ── Auth guard: wajib login (JSON 401, tanpa redirect) ──
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode([
        "success" => false,
        "login_required" => true,
        "message" => "Anda harus login untuk mengecek status room."
    ]));
}

$room = $_GET['room'] ?? '';
if (!$room) {
    die(json_encode(["success" => false, "message" => "Room code diperlukan"]));
}
$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT white_user_id, black_user_id, black_joined FROM rooms WHERE room_code = ?");
$stmt->bind_param("s", $room);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
if (!$result) {
    die(json_encode(["success" => false, "message" => "Room tidak ditemukan"]));
}
// ── Otorisasi: hanya pembuat room (putih) atau yang sudah join (hitam)
// boleh cek status room ini — mencegah user luar polling status game
// orang lain lewat room_code yang bocor/ditebak. ──
if ((int)$result['white_user_id'] !== $user_id && (int)$result['black_user_id'] !== $user_id) {
    http_response_code(403);
    die(json_encode(["success" => false, "message" => "Anda bukan pemain di room ini."]));
}
echo json_encode([
    "success" => true,
    "joined" => (int)$result['black_joined'] === 1
]);
