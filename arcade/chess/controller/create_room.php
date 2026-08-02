<?php
require '../../../auth/config.php';
// Random_bytes md5(time())
$room = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$sql = "INSERT INTO rooms (room_code) VALUES (?)";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    die(json_encode([
        "success" => false,
        "error" => $conn->error
    ]));
}
$stmt->bind_param("s", $room);
$stmt->execute();
header('Content-Type: application/json');
echo json_encode([
    "success" => true,
    "room" => $room,
    "color" => "white"
]);