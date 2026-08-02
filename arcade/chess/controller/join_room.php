<?php
require '../../../auth/config.php';
header('Content-Type: application/json');
// Verifikasi token untuk AJAX POST
if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    die(json_encode(['success' => false, 'message' => 'CSRF token tidak valid.']));
}
$room = $_POST['room'] ?? '';
if (!$room) {
    die(json_encode([
        'success' => false,
        'message' => 'Room kosong'
    ]));
}
$stmt = $conn->prepare("UPDATE rooms SET black_joined = 1 WHERE room_code = ?");
if (!$stmt) {
    die(json_encode([
        'success' => false,
        'message' => $conn->error
    ]));
}
$stmt->bind_param("s", $room);
$stmt->execute();
if ($stmt->affected_rows > 0) {
    echo json_encode([
        'success' => true,
        'room' => $room
    ]);
} else {
    $check = $conn->prepare("SELECT black_joined FROM rooms WHERE room_code = ?");
    $check->bind_param("s", $room);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Room tidak wujud.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Room sudah penuh.']);
    }
}
