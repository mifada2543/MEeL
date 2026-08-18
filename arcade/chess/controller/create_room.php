<?php
require '../../../auth/config.php';
header('Content-Type: application/json');

// Auth guard: wajib login (JSON 401, tanpa redirect)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode([
        "success" => false,
        "login_required" => true,
        "message" => "Anda harus login untuk membuat room multiplayer."
    ]));
}

// CSRF guard: semua POST wajib token valid
if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    http_response_code(403);
    die(json_encode([
        "success" => false,
        "message" => "CSRF token tidak valid."
    ]));
}

// Room code acak 6 karakter
$room = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$user_id = (int)$_SESSION['user_id'];
$sql = "INSERT INTO rooms (room_code, white_user_id) VALUES (?, ?)";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    die(json_encode([
        "success" => false,
        "error" => $conn->error
    ]));
}
$stmt->bind_param("si", $room, $user_id);
$stmt->execute();

// GarbageCollector sudah ter-autoload lewat auth/config.php.
GarbageCollector::cleanChessRooms($conn);

echo json_encode([
    "success" => true,
    "room" => $room,
    "color" => "white"
]);
