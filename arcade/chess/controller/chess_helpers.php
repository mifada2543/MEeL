<?php
/**
 * Helper bersama untuk controller catur multiplayer (arcade/chess/controller/).
 *
 * Deteksi disconnect lawan memakai users.last_activity — kolom ini
 * diperbarui ke NOW() di SETIAP request lewat modules/core/activity_logger.php
 * (di-include oleh auth/config.php), termasuk polling AJAX get_move.php.
 * Jadi pemain yang masih polling selalu "online"; yang keluar / menutup tab
 * membeku dan dianggap offline setelah lewat ambang.
 */

// Ambang offline lawan (detik) — dibuat di atas throttle timer tab background
// browser (~60 dtk) supaya pemain yang hanya pindah tab tidak dianggap putus.
if (!defined('CHESS_OPPONENT_OFFLINE_SECONDS')) {
    define('CHESS_OPPONENT_OFFLINE_SECONDS', 90);
}

/**
 * Cek apakah lawan masih online berdasarkan users.last_activity.
 *
 * @param \mysqli $conn       Koneksi database aktif
 * @param int     $opponentId user_id lawan (0 = lawan belum ada)
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

/**
 * Cek apakah room sudah punya event terminal (game selesai).
 *
 * Event terminal = resign / draw_accept / disconnect / game_over. Dipakai
 * sebagai dedup: setelah game berakhir, aksi apa pun (termasuk game_over
 * kedua) harus ditolak.
 *
 * @param \mysqli $conn Koneksi database aktif
 * @param string  $room Room code
 * @return bool true jika sudah ada event terminal
 */
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

/**
 * Warna pembuat langkah TERAKHIR yang bukan event (gerakan catur asli).
 *
 * Event (resign/seri/dll) tidak dihitung sebagai langkah. Dipakai untuk
 * menentukan giliran sekarang dan memvalidasi pecundang game_over.
 *
 * @param \mysqli $conn Koneksi database aktif
 * @param string  $room Room code
 * @return string|null 'w'/'b', atau null bila belum ada langkah sama sekali
 */
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
 * Catat event game_over (checkmate/stalemate) setelah validasi server-side.
 *
 * Validasi integritas: pecundang harus lawan dari warna pembuat langkah
 * terakhir (berlaku untuk checkmate maupun stalemate — sisi yang harus
 * melangkah setelah langkah terminal = pecundang). Ini mencegah klaim
 * game_over palsu yang tidak sesuai riwayat langkah sebenarnya.
 *
 * Dedup: jika room sudah punya event terminal (termasuk game_over sebelumnya),
 * aksi ditolak — client tidak bisa mencatat game_over dua kali.
 *
 * @param \mysqli $conn     Koneksi database aktif
 * @param string  $room     Room code
 * @param string  $loserColor Warna PECUNDANG ('w'/'b') — dikirim client
 * @param string  $reason   'checkmate' | 'stalemate'
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

/**
 * Simpan event non-langkah (resign / draw / disconnect / game_over) sebagai
 * baris khusus di tabel moves. from_r..to_c diisi 0, piece 'x' sebagai penanda
 * sentinel; tipe asli ada di move_data JSON sehingga alur polling get_move.php
 * yang sudah ada tetap bekerja.
 */
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
 * Ambil event non-langkah TERAKHIR di room (resign / draw / rematch / dll).
 *
 * Event = baris moves dengan move_data JSON berisi 'type'. Langkah catur asli
 * (tanpa 'type') diabaikan. Dipakai untuk melacak tawaran seri / tanding ulang
 * yang sedang pending.
 *
 * @param \mysqli $conn Koneksi database aktif
 * @param string  $room Room code
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

/**
 * Hapus seluruh riwayat langkah room — dipakai untuk memulai game baru
 * (tanding ulang) di room yang sama. Warna pemain (white/black) tidak berubah.
 *
 * @param \mysqli $conn Koneksi database aktif
 * @param string  $room Room code
 */
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
 * Proses aksi tanding ulang (rematch) untuk room yang SUDAH SELESAI.
 *
 * Alur dua pemain:
 *   1. rematch_offer  — salah satu pemain menawarkan tanding ulang. Hanya satu
 *                       tawaran pending; pemain lain harus menjawab.
 *   2. rematch_accept — lawan MENERIMA → riwayat langkah di-reset (game baru di
 *                       room yang sama, warna tetap) + event rematch_accept
 *                       dicatat supaya client penawar ikut me-reset papannya.
 *   3. rematch_decline— lawan MENOLAK, atau pengirim MEMBATALKAN tawarannya.
 *                       Lawan yang menerima event ini tahu "permainan telah
 *                       keluar" dan kembali ke mode lokal.
 *
 * Anti-stuck (race condition): pada rematch_offer & rematch_accept, kehadiran
 * lawan diverifikasi lewat users.last_activity. Kalau lawan sudah keluar /
 * menutup tab (last_activity membeku > CHESS_OPPONENT_OFFLINE_SECONDS), aksi
 * ditolak dengan flag opponent_gone sehingga penawar tidak terjebak menunggu
 * jawaban selamanya. Catatan: lawan yang BARU keluar masih terlihat "online"
 * selama ambang offline (90 dtk) — celah itu ditutup timeout 30 dtk di client.
 *
 * @param \mysqli   $conn       Koneksi database aktif
 * @param string    $room       Room code
 * @param string    $color      Warna pemain yang mengirim aksi ('w'/'b')
 * @param string    $action     rematch_offer | rematch_accept | rematch_decline
 * @param int|null  $opponentId user_id lawan untuk validasi kehadiran; null =
 *                              validasi dilewati (dipakai unit/integration test
 *                              yang memanggil helper langsung).
 * @return array{success:bool, message?:string, id?:int, opponent_gone?:bool}
 */
function chess_rematch(\mysqli $conn, string $room, string $color, string $action, ?int $opponentId = null): array
{
    // Rematch hanya masuk akal SETELAH game selesai (ada event terminal).
    if (!chess_has_terminal_event($conn, $room)) {
        return ["success" => false, "message" => "Permainan belum selesai."];
    }

    // Validasi kehadiran lawan — cegah penawar terjebak menunggu lawan yang
    // sudah pergi (race condition). Berlaku untuk offer (lawan harus ada untuk
    // menjawab) dan accept (penawar harus ada untuk menerima reset).
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
        // Mulai game baru: hapus riwayat lama, lalu catat event accept agar
        // client penawar ikut me-reset papannya (sinkron via polling).
        // Catatan: TIDAK memakai transaksi — begin_transaction di tengah
        // transaksi aktif (mis. dari integration test) akan COMMIT transaksi
        // tersebut secara implisit. Window race (draw_offer curang di antara
        // reset & insert) dapat diabaikan: client tidak mengirim aksi balasan
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
