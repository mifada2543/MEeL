<?php
require '../../../auth/config.php';
header('Content-Type: application/json');
$room = $_GET['room'] ?? '';
if (!$room) {
    die(json_encode(["success" => false, "message" => "Room code diperlukan"]));
}
$stmt = $conn->prepare("SELECT black_joined FROM rooms WHERE room_code = ?");
$stmt->bind_param("s", $room);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
if ($result) {
    echo json_encode([
        "success" => true,
        "joined" => (int)$result['black_joined'] === 1
    ]);
} else {
    echo json_encode(["success" => false, "message" => "Room tidak ditemukan"]);
}