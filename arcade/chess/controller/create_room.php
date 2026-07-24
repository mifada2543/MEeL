<?php
require '../../../auth/config.php';

// Gunakan random_bytes alih-alih md5(time()) untuk mencegah prediksi/tebakan kode room
// random_bytes(4) = 8 hex chars → potong 6 karakter pertama
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