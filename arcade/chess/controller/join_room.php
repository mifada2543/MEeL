<?php
require '../../../auth/config.php';
header('Content-Type: application/json');

// Auth guard: wajib login (JSON 401, tanpa redirect)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode([
        'success' => false,
        'login_required' => true,
        'message' => 'Anda harus login untuk bergabung ke room.'
    ]));
}

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
$user_id = (int)$_SESSION['user_id'];

$ownCheck = $conn->prepare("SELECT white_user_id FROM rooms WHERE room_code = ?");
$ownCheck->bind_param("s", $room);
$ownCheck->execute();
$ownRow = $ownCheck->get_result()->fetch_assoc();
if (!$ownRow) {
    die(json_encode(['success' => false, 'message' => 'Room tidak wujud.']));
}
if ((int)$ownRow['white_user_id'] === $user_id) {
    die(json_encode(['success' => false, 'message' => 'Tidak bisa bergabung ke room sendiri.']));
}

$stmt = $conn->prepare(
    "UPDATE rooms SET black_joined = 1, black_user_id = ? WHERE room_code = ? AND black_joined = 0"
);
if (!$stmt) {
    die(json_encode([
        'success' => false,
        'message' => $conn->error
    ]));
}
$stmt->bind_param("is", $user_id, $room);
$stmt->execute();
if ($stmt->affected_rows > 0) {
    echo json_encode([
        'success' => true,
        'room' => $room,
        'color' => 'black'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Room sudah penuh.']);
}
