<?php
require '../../../auth/config.php';
header('Content-Type: application/json');

// ── Auth guard: wajib login (JSON 401, tanpa redirect) ──
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode([
        "success" => false,
        "login_required" => true,
        "message" => "Anda harus login untuk mengakses room."
    ]));
}

$room = $_GET['room'] ?? '';
$last = intval($_GET['after'] ?? $_GET['last'] ?? 0);
$user_id = (int)$_SESSION['user_id'];

// ── Otorisasi: hanya white/black di room ini yang boleh membaca riwayat
// langkah — mencegah user luar meng-intip/polling game orang lain lewat
// room_code yang bocor/ditebak. ──
$roomStmt = $conn->prepare("SELECT white_user_id, black_user_id FROM rooms WHERE room_code = ?");
$roomStmt->bind_param("s", $room);
$roomStmt->execute();
$roomRow = $roomStmt->get_result()->fetch_assoc();
if (!$roomRow || ((int)$roomRow['white_user_id'] !== $user_id && (int)$roomRow['black_user_id'] !== $user_id)) {
    http_response_code(403);
    die(json_encode(["success" => false, "message" => "Anda bukan pemain di room ini."]));
}

$stmt = $conn->prepare("
    SELECT *
    FROM moves
    WHERE room_code = ?
    AND id > ?
    ORDER BY id ASC
");
if (!$stmt) {
    die(json_encode([
        "success" => false,
        "message" => $conn->error
    ]));
}
$stmt->bind_param("si", $room, $last);
$stmt->execute();
$result = $stmt->get_result();
$moves = [];
while ($row = $result->fetch_assoc()) {
    $moves[] = $row;
}
echo json_encode($moves);
