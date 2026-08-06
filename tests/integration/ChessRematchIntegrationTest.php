<?php
require_once MEEL_ROOT . '/arcade/chess/controller/chess_helpers.php';

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for fitur tanding ulang (rematch) multiplayer catur:
 * chess_rematch() beserta helper-nya chess_last_event() dan
 * chess_reset_room_game().
 *
 * Memakai DB asli — setiap test berjalan di dalam transaksi yang di-rollback
 * di tearDown(), jadi tidak mencemari data produksi.
 *
 * @requires extension mysqli
 * @group integration
 * @covers chess_rematch
 * @covers chess_last_event
 * @covers chess_reset_room_game
 */
class ChessRematchIntegrationTest extends TestCase
{
    private DbTestHelper $dbHelper;
    private mysqli $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbHelper = new DbTestHelper();
        $this->conn = $this->dbHelper->getConnection();
    }

    protected function tearDown(): void
    {
        $this->dbHelper->rollback();
        $this->dbHelper->close();
        parent::tearDown();
    }

    /** Buat room test dengan black sudah join (black_joined=1). */
    private function insertRoom(string $code): void
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO rooms (room_code, white_user_id, black_user_id, black_joined)
             VALUES (?, 1, 10, 1)"
        );
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $stmt->close();
    }

    /** Buat langkah catur asli (bukan event) — move_data JSON tanpa 'type'. */
    private function insertMove(string $code, string $color): void
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO moves
                (room_code, from_r, from_c, to_r, to_c, piece, color, captured, promoted_piece_type, move_data)
             VALUES (?, 2, 4, 2, 5, 'p', ?, NULL, NULL, ?)"
        );
        $json = json_encode(['fromR' => 2, 'fromC' => 4, 'toR' => 2, 'toC' => 5, 'color' => $color]);
        $stmt->bind_param("sss", $code, $color, $json);
        $stmt->execute();
        $stmt->close();
    }

    /** Buat event seperti insertGameEvent(). */
    private function insertEvent(string $code, string $type, string $color = 'w', string $reason = null): void
    {
        $extra = $reason !== null ? ['reason' => $reason] : [];
        insertGameEvent($this->conn, $code, $color, $type, $extra);
    }

    /** Skenario umum: room dengan game sudah selesai (checkmate via game_over). */
    private function insertFinishedGame(string $code): void
    {
        $this->insertRoom($code);
        $this->insertMove($code, 'w');
        $this->insertEvent($code, 'game_over', 'b', 'checkmate');
    }

    private function newCode(): string
    {
        return 'RM' . strtoupper(substr(uniqid('', true), -6));
    }

    /** Buat user test dengan last_activity yang dikendalikan (rollback otomatis). */
    private function insertUser(int $lastActivityTs): int
    {
        $username = 'rm_test_' . substr(uniqid('', true), -8);
        $lastActivity = date('Y-m-d H:i:s', $lastActivityTs);
        $stmt = $this->conn->prepare(
            "INSERT INTO users (username, last_activity) VALUES (?, ?)"
        );
        $stmt->bind_param("ss", $username, $lastActivity);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    private function countMoves(string $code): int
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) AS n FROM moves WHERE room_code = ?");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) $row['n'];
    }

    // ══════════════════════════════════════════════════════════════
    // REMATCH HANYA SETELAH GAME SELESAI
    // ══════════════════════════════════════════════════════════════

    public function testOfferRejectedWhenGameNotFinished(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);
        $this->insertMove($code, 'w'); // belum ada event terminal

        $result = chess_rematch($this->conn, $code, 'w', 'rematch_offer');

        $this->assertFalse($result['success']);
        $this->assertSame('Permainan belum selesai.', $result['message']);
    }

    public function testOfferSucceedsAfterCheckmate(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);

        $result = chess_rematch($this->conn, $code, 'w', 'rematch_offer');

        $this->assertTrue($result['success']);
        $this->assertSame('rematch_offer', chess_last_event($this->conn, $code)['type']);
    }

    public function testOfferSucceedsAfterResign(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);
        $this->insertMove($code, 'w');
        $this->insertEvent($code, 'resign', 'w');

        $result = chess_rematch($this->conn, $code, 'b', 'rematch_offer');

        $this->assertTrue($result['success']);
    }

    public function testOfferSucceedsAfterDrawAgreement(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);
        $this->insertMove($code, 'w');
        $this->insertMove($code, 'b');
        $this->insertEvent($code, 'draw_accept', 'w');

        $result = chess_rematch($this->conn, $code, 'w', 'rematch_offer');

        $this->assertTrue($result['success']);
    }

    // ══════════════════════════════════════════════════════════════
    // SATU TAWARAN PENDING
    // ══════════════════════════════════════════════════════════════

    public function testSecondOfferWhilePendingIsRejected(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        $this->assertTrue(chess_rematch($this->conn, $code, 'w', 'rematch_offer')['success']);

        $result = chess_rematch($this->conn, $code, 'b', 'rematch_offer');

        $this->assertFalse($result['success']);
        $this->assertSame('Masih ada tawaran tanding ulang yang menunggu jawaban.', $result['message']);
    }

    // ══════════════════════════════════════════════════════════════
    // TERIMA (ACCEPT) → RESET GAME
    // ══════════════════════════════════════════════════════════════

    public function testAcceptWithoutOfferIsRejected(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);

        $result = chess_rematch($this->conn, $code, 'b', 'rematch_accept');

        $this->assertFalse($result['success']);
        $this->assertSame('Tidak ada tawaran tanding ulang yang menunggu.', $result['message']);
    }

    public function testAcceptOwnOfferIsRejected(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        chess_rematch($this->conn, $code, 'w', 'rematch_offer'); // putih menawar

        $result = chess_rematch($this->conn, $code, 'w', 'rematch_accept'); // putih menjawab sendiri

        $this->assertFalse($result['success']);
        $this->assertSame('Anda tidak dapat menjawab tawaran anda sendiri.', $result['message']);
    }

    public function testAcceptResetsGameAndRecordsAccept(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        $this->assertTrue(chess_rematch($this->conn, $code, 'w', 'rematch_offer')['success']);

        $result = chess_rematch($this->conn, $code, 'b', 'rematch_accept');

        $this->assertTrue($result['success']);
        // Riwayat lama dihapus; hanya tersisa event rematch_accept.
        $this->assertSame(1, $this->countMoves($code));
        $this->assertSame('rematch_accept', chess_last_event($this->conn, $code)['type']);
        // Game baru: belum ada event terminal & belum ada langkah → giliran putih.
        $this->assertFalse(chess_has_terminal_event($this->conn, $code));
        $this->assertNull(chess_last_move_color($this->conn, $code));
    }

    public function testNewGameCanEndAgainAfterRematch(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        chess_rematch($this->conn, $code, 'w', 'rematch_offer');
        chess_rematch($this->conn, $code, 'b', 'rematch_accept');

        // Game baru: langkah + game_over bisa dicatat lagi, dan rematch kedua
        // juga bisa ditawarkan setelahnya.
        $this->insertMove($code, 'w');
        $this->assertTrue(chess_record_game_over($this->conn, $code, 'b', 'checkmate')['success']);
        $this->assertTrue(chess_rematch($this->conn, $code, 'b', 'rematch_offer')['success']);
    }

    // ══════════════════════════════════════════════════════════════
    // TOLAK (DECLINE) / BATAL
    // ══════════════════════════════════════════════════════════════

    public function testDeclineByOpponentIsAllowed(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        chess_rematch($this->conn, $code, 'w', 'rematch_offer'); // putih menawar

        $result = chess_rematch($this->conn, $code, 'b', 'rematch_decline'); // hitam menolak

        $this->assertTrue($result['success']);
        $this->assertSame('rematch_decline', chess_last_event($this->conn, $code)['type']);
        // Menolak TIDAK menghapus riwayat game lama.
        $this->assertTrue(chess_has_terminal_event($this->conn, $code));
        $this->assertGreaterThan(1, $this->countMoves($code));
    }

    public function testOffererCanCancelOwnOffer(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        chess_rematch($this->conn, $code, 'w', 'rematch_offer');

        $result = chess_rematch($this->conn, $code, 'w', 'rematch_decline'); // batalkan tawaran sendiri

        $this->assertTrue($result['success']);
        $this->assertSame('rematch_decline', chess_last_event($this->conn, $code)['type']);
    }

    public function testDeclineWithoutOfferIsRejected(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);

        $result = chess_rematch($this->conn, $code, 'b', 'rematch_decline');

        $this->assertFalse($result['success']);
        $this->assertSame('Tidak ada tawaran tanding ulang yang menunggu.', $result['message']);
    }

    public function testOfferAgainAfterDeclineIsAllowed(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        chess_rematch($this->conn, $code, 'w', 'rematch_offer');
        chess_rematch($this->conn, $code, 'b', 'rematch_decline');

        $result = chess_rematch($this->conn, $code, 'w', 'rematch_offer'); // tawar lagi

        $this->assertTrue($result['success']);
    }

    public function testAcceptAfterDeclineIsRejected(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        chess_rematch($this->conn, $code, 'w', 'rematch_offer');
        chess_rematch($this->conn, $code, 'b', 'rematch_decline');

        // Tawaran sudah tidak pending → accept tidak valid lagi.
        $result = chess_rematch($this->conn, $code, 'b', 'rematch_accept');

        $this->assertFalse($result['success']);
        $this->assertSame('Tidak ada tawaran tanding ulang yang menunggu.', $result['message']);
    }

    // ══════════════════════════════════════════════════════════════
    // HELPER PENDUKUNG
    // ══════════════════════════════════════════════════════════════

    public function testLastEventIgnoresRealMoves(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);
        $this->insertMove($code, 'w');

        $this->assertNull(chess_last_event($this->conn, $code));

        $this->insertEvent($code, 'rematch_offer', 'w');
        $ev = chess_last_event($this->conn, $code);
        $this->assertSame('rematch_offer', $ev['type']);
        $this->assertSame('w', $ev['color']);
    }

    public function testResetRoomGameClearsAllMoves(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);
        $this->insertMove($code, 'w');
        $this->insertMove($code, 'b');
        $this->assertSame(2, $this->countMoves($code));

        chess_reset_room_game($this->conn, $code);

        $this->assertSame(0, $this->countMoves($code));
    }

    public function testUnknownRematchActionIsRejected(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);

        $result = chess_rematch($this->conn, $code, 'w', 'rematch_whatever');

        $this->assertFalse($result['success']);
        $this->assertSame('Aksi tidak dikenal.', $result['message']);
    }

    // ══════════════════════════════════════════════════════════════
    // VALIDASI KEHADIRAN LAWAN (race condition: lawan sudah keluar)
    // ══════════════════════════════════════════════════════════════

    public function testOfferRejectedWhenOpponentOffline(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        $oppId = $this->insertUser(time() - 7200); // last_activity 2 jam lalu

        $result = chess_rematch($this->conn, $code, 'w', 'rematch_offer', $oppId);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['opponent_gone']);
        $this->assertSame('Lawan sudah keluar dari permainan.', $result['message']);
        // Tidak ada tawaran yang dicatat — room tetap bersih dari event rematch.
        $this->assertSame('game_over', chess_last_event($this->conn, $code)['type']);
    }

    public function testOfferSucceedsWhenOpponentOnline(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        $oppId = $this->insertUser(time()); // baru saja aktif

        $result = chess_rematch($this->conn, $code, 'w', 'rematch_offer', $oppId);

        $this->assertTrue($result['success']);
        $this->assertSame('rematch_offer', chess_last_event($this->conn, $code)['type']);
    }

    public function testAcceptRejectedWhenOffererOffline(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        chess_rematch($this->conn, $code, 'w', 'rematch_offer'); // tawaran sukses
        $offererId = $this->insertUser(time() - 7200); // penawar sudah pergi

        $result = chess_rematch($this->conn, $code, 'b', 'rematch_accept', $offererId);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['opponent_gone']);
        // Riwayat game lama TIDAK dihapus — tidak ada reset yang terjadi.
        $this->assertTrue(chess_has_terminal_event($this->conn, $code));
    }

    public function testPresenceCheckSkippedWhenOpponentIdNull(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);

        // opponentId null = validasi dilewati (helper dipanggil langsung).
        $result = chess_rematch($this->conn, $code, 'w', 'rematch_offer');

        $this->assertTrue($result['success']);
    }
}
