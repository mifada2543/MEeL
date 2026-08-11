<?php
if (!defined('CHESS_OPPONENT_OFFLINE_SECONDS')) {
    define('CHESS_OPPONENT_OFFLINE_SECONDS', 90);
}

/**
 * @param \mysqli $conn Koneksi database aktif
 * @param int $opponentId user_id lawan (0 = lawan belum ada)
 * @return bool true jika last_activity < CHESS_OPPONENT_OFFLINE_SECONDS
 */
function chess_opponent_online(\mysqli $conn, int $opponentId): bool
{
    if ($opponentId <= 0) {
        return false;
    }
    $stmt = $conn->prepare("SELECT last_activity FROM users WHERE id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $opponentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['last_activity'])) {
        return false;
    }
    return (time() - strtotime($row['last_activity'])) < CHESS_OPPONENT_OFFLINE_SECONDS;
}

/* @param \mysqli $conn Koneksi database aktif; @param string $room Room code; @return bool true jika sudah ada event terminal */
function chess_has_terminal_event(\mysqli $conn, string $room): bool
{
    $stmt = $conn->prepare(
        "SELECT id FROM moves
         WHERE room_code = ?
           AND JSON_UNQUOTE(JSON_EXTRACT(move_data, '$.type')) IN ('resign','draw_accept','disconnect','game_over')
         ORDER BY id DESC LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("s", $room);
    $stmt->execute();
    $found = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $found;
}

/* @param \mysqli $conn Koneksi database aktif; @param string $room Room code; @return string|null 'w'/'b', atau null bila belum ada langkah sama sekali */
function chess_last_move_color(\mysqli $conn, string $room): ?string
{
    $stmt = $conn->prepare(
        "SELECT color FROM moves
         WHERE room_code = ?
           AND JSON_UNQUOTE(JSON_EXTRACT(move_data, '$.type')) IS NULL
         ORDER BY id DESC LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("s", $room);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['color'] : null;
}

/**
 * @param \mysqli $conn Koneksi database aktif
 * @param string $room Room code
 * @param string $loserColor Warna PECUNDANG ('w'/'b') — dikirim client
 * @param string $reason 'checkmate' | 'stalemate'
 * @return array{success:bool, message?:string, id?:int}
 */
function chess_record_game_over(\mysqli $conn, string $room, string $loserColor, string $reason): array
{
    if (!in_array($reason, ['checkmate', 'stalemate'], true)) {
        return ["success" => false, "message" => "Alasan game over tidak dikenal."];
    }
    if (!in_array($loserColor, ['w', 'b'], true)) {
        return ["success" => false, "message" => "Warna tidak valid."];
    }
    // Dedup: game sudah berakhir (termasuk game_over sebelumnya).
    if (chess_has_terminal_event($conn, $room)) {
        return ["success" => false, "message" => "Permainan sudah berakhir."];
    }
    // Validasi pecundang vs langkah terakhir.
    $lastMoveColor = chess_last_move_color($conn, $room);
    if ($lastMoveColor === null) {
        return ["success" => false, "message" => "Belum ada langkah — game over tidak valid."];
    }
    $expectedLoser = $lastMoveColor === 'w' ? 'b' : 'w';
    if ($loserColor !== $expectedLoser) {
        return ["success" => false, "message" => "Warna pecundang tidak sesuai langkah terakhir."];
    }
    insertGameEvent($conn, $room, $loserColor, 'game_over', ['reason' => $reason]);
    return ["success" => true, "id" => $conn->insert_id];
}

function insertGameEvent(\mysqli $conn, string $room, string $color, string $type, array $extra = []): void
{
    $json = json_encode(array_merge(['type' => $type, 'color' => $color], $extra));
    $stmt = $conn->prepare(
        "INSERT INTO moves
            (room_code, from_r, from_c, to_r, to_c, piece, color, captured, promoted_piece_type, move_data)
         VALUES (?, 0, 0, 0, 0, 'x', ?, NULL, NULL, ?)"
    );
    if (!$stmt) {
        die(json_encode(["success" => false, "message" => $conn->error]));
    }
    $stmt->bind_param("sss", $room, $color, $json);
    if (!$stmt->execute()) {
        die(json_encode(["success" => false, "message" => $stmt->error]));
    }
}

/**
 * @param \mysqli $conn Koneksi database aktif
 * @param string $room Room code
 * @return array{type: string|null, color: string|null}|null null bila belum ada event
 */
function chess_last_event(\mysqli $conn, string $room): ?array
{
    $stmt = $conn->prepare(
        "SELECT move_data, color FROM moves
         WHERE room_code = ?
           AND JSON_UNQUOTE(JSON_EXTRACT(move_data, '$.type')) IS NOT NULL
         ORDER BY id DESC LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("s", $room);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    $data = json_decode($row['move_data'], true);
    return [
        'type'  => is_array($data) ? ($data['type'] ?? null) : null,
        'color' => $row['color'],
    ];
}

/* @param \mysqli $conn Koneksi database aktif; @param string $room Room code */
function chess_reset_room_game(\mysqli $conn, string $room): void
{
    $stmt = $conn->prepare("DELETE FROM moves WHERE room_code = ?");
    if (!$stmt) {
        die(json_encode(["success" => false, "message" => $conn->error]));
    }
    $stmt->bind_param("s", $room);
    if (!$stmt->execute()) {
        die(json_encode(["success" => false, "message" => $stmt->error]));
    }
    $stmt->close();
}

/**
 * @param \mysqli $conn Koneksi database aktif
 * @param string $room Room code
 * @param string $color Warna pemain yang mengirim aksi ('w'/'b')
 * @param string $action rematch_offer | rematch_accept | rematch_decline
 * @param int|null $opponentId user_id lawan untuk validasi kehadiran; null =
 * @return array{success:bool, message?:string, id?:int, opponent_gone?:bool}
 */
function chess_rematch(\mysqli $conn, string $room, string $color, string $action, ?int $opponentId = null): array
{

    if (!chess_has_terminal_event($conn, $room)) {
        return ["success" => false, "message" => "Permainan belum selesai."];
    }

    if (($action === 'rematch_offer' || $action === 'rematch_accept')
        && $opponentId !== null
        && !chess_opponent_online($conn, $opponentId)) {
        return [
            "success"       => false,
            "opponent_gone" => true,
            "message"       => "Lawan sudah keluar dari permainan.",
        ];
    }

    // Tawaran pending = event terakhir bertipe rematch_offer.
    $lastEvent = chess_last_event($conn, $room);
    $pending   = $lastEvent !== null && $lastEvent['type'] === 'rematch_offer';
    $pendingBy = $pending ? $lastEvent['color'] : null;

    if ($action === 'rematch_offer') {
        if ($pending) {
            return ["success" => false, "message" => "Masih ada tawaran tanding ulang yang menunggu jawaban."];
        }
        insertGameEvent($conn, $room, $color, 'rematch_offer');
        return ["success" => true, "id" => $conn->insert_id];
    }

    if ($action === 'rematch_accept') {
        if (!$pending) {
            return ["success" => false, "message" => "Tidak ada tawaran tanding ulang yang menunggu."];
        }
        if ($pendingBy === $color) {
            return ["success" => false, "message" => "Anda tidak dapat menjawab tawaran anda sendiri."];
        }
        // client penawar ikut me-reset papannya (sinkron via polling).
        // setelah game selesai (UI memblokir).
        chess_reset_room_game($conn, $room);
        insertGameEvent($conn, $room, $color, 'rematch_accept');
        return ["success" => true, "id" => $conn->insert_id];
    }

    if ($action === 'rematch_decline') {
        if (!$pending) {
            return ["success" => false, "message" => "Tidak ada tawaran tanding ulang yang menunggu."];
        }
        // Boleh dari lawan (menolak) ATAU dari pengirim (membatalkan).
        insertGameEvent($conn, $room, $color, 'rematch_decline');
        return ["success" => true, "id" => $conn->insert_id];
    }

    return ["success" => false, "message" => "Aksi tidak dikenal."];
}
