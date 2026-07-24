<?php

require '../../../auth/config.php';

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