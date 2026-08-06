<?php
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for GarbageCollector::cleanChessRooms() with a real
 * database connection.
 *
 * Each test runs inside a database transaction that is rolled back
 * in tearDown(), ensuring no test pollutes the database.
 *
 * @requires extension mysqli
 * @group integration
 * @covers GarbageCollector::cleanChessRooms
 */
class GarbageCollectorChessRoomsIntegrationTest extends TestCase
{
    private DbTestHelper $dbHelper;
    private mysqli $conn;

    // Throttle file dipakai method yang di-test — backup & pulihkan agar
    // test tidak bergantung pada state throttle prod.
    private string $throttleFile;
    private ?string $throttleBackup = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbHelper = new DbTestHelper();
        $this->conn = $this->dbHelper->getConnection();

        $this->throttleFile = MEEL_ROOT . '/temp/gc_chess_last_run.txt';
        // Hapus throttle sementara supaya cleanup benar-benar berjalan.
        if (is_file($this->throttleFile)) {
            $this->throttleBackup = (string) @file_get_contents($this->throttleFile);
            @unlink($this->throttleFile);
        }
    }

    protected function tearDown(): void
    {
        // Pulihkan throttle file (jika memang ada sebelumnya).
        if ($this->throttleBackup !== null) {
            @file_put_contents($this->throttleFile, $this->throttleBackup);
        } else {
            @unlink($this->throttleFile);
        }

        $this->dbHelper->rollback();
        $this->dbHelper->close();
        parent::tearDown();
    }

    /** Buat room test dengan room_code unik (hindari bentrok data asli). */
    private function insertRoom(string $code, bool $blackJoined, string $createdAt): void
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO rooms (room_code, white_user_id, black_user_id, black_joined, created_at)
             VALUES (?, 1, ?, ?, ?)"
        );
        $blackUserId = $blackJoined ? 10 : null;
        $joined = $blackJoined ? 1 : 0;
        $stmt->bind_param("siis", $code, $blackUserId, $joined, $createdAt);
        $stmt->execute();
        $stmt->close();
    }

    /** Buat baris moves test (move_data harus JSON valid — ada CHECK constraint). */
    private function insertMove(string $code, string $createdAt): void
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO moves
                (room_code, from_r, from_c, to_r, to_c, piece, color, captured, promoted_piece_type, move_data, created_at)
             VALUES (?, 0, 0, 0, 0, 'x', 'w', NULL, NULL, ?, ?)"
        );
        $json = '{"test":true}';
        $stmt->bind_param("sss", $code, $json, $createdAt);
        $stmt->execute();
        $stmt->close();
    }

    /** Buat event terminal (resign / game_over dll) seperti insertGameEvent(). */
    private function insertEvent(string $code, string $type, string $color = 'w', string $createdAt = ''): void
    {
        if ($createdAt === '') $createdAt = date('Y-m-d H:i:s', time() - 8 * 86400);
        $stmt = $this->conn->prepare(
            "INSERT INTO moves
                (room_code, from_r, from_c, to_r, to_c, piece, color, captured, promoted_piece_type, move_data, created_at)
             VALUES (?, 0, 0, 0, 0, 'x', ?, NULL, NULL, ?, ?)"
        );
        $json = json_encode(['type' => $type, 'color' => $color]);
        $stmt->bind_param("ssss", $code, $color, $json, $createdAt);
        $stmt->execute();
        $stmt->close();
    }

    private function roomExists(string $code): bool
    {
        $stmt = $this->conn->prepare("SELECT 1 FROM rooms WHERE room_code = ?");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $exists;
    }

    private function moveCount(string $code): int
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) AS n FROM moves WHERE room_code = ?");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $count = (int) $stmt->get_result()->fetch_assoc()['n'];
        $stmt->close();
        return $count;
    }

    private function newCode(): string
    {
        return 'ZZ' . strtoupper(substr(uniqid('', true), -6));
    }

    // ══════════════════════════════════════════════════════════════
    // LOBBY BASI — lawan tak pernah join (black_joined = 0)
    // ══════════════════════════════════════════════════════════════

    public function testLobbyOlderThan24HoursIsDeleted(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code, false, date('Y-m-d H:i:s', time() - 2 * 86400));

        $cleaned = GarbageCollector::cleanChessRooms($this->conn);

        $this->assertGreaterThanOrEqual(1, $cleaned);
        $this->assertFalse($this->roomExists($code));
    }

    public function testFreshLobbyIsKept(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code, false, date('Y-m-d H:i:s', time() - 3600));

        GarbageCollector::cleanChessRooms($this->conn);

        $this->assertTrue($this->roomExists($code));
    }

    // ══════════════════════════════════════════════════════════════
    // GAME DITINGGALKAN — sudah dimulai tapi tanpa aktivitas
    // ══════════════════════════════════════════════════════════════

    public function testStartedGameWithStaleLastMoveIsDeletedWithMoves(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code, true, date('Y-m-d H:i:s', time() - 30 * 86400));
        // Langkah terakhir 8 hari lalu → sudah lewat batas 7 hari.
        $this->insertMove($code, date('Y-m-d H:i:s', time() - 8 * 86400));
        $this->assertSame(1, $this->moveCount($code));

        $cleaned = GarbageCollector::cleanChessRooms($this->conn);

        $this->assertGreaterThanOrEqual(1, $cleaned);
        $this->assertFalse($this->roomExists($code));
        $this->assertSame(0, $this->moveCount($code));
    }

    public function testActiveGameIsKept(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code, true, date('Y-m-d H:i:s', time() - 2 * 86400));
        $this->insertMove($code, date('Y-m-d H:i:s', time() - 3600));

        GarbageCollector::cleanChessRooms($this->conn);

        $this->assertTrue($this->roomExists($code));
        $this->assertSame(1, $this->moveCount($code));
    }

    public function testStartedGameWithoutMovesFallsBackToCreatedAt(): void
    {
        // Hitam sudah join tapi belum ada langkah sama sekali 8 hari.
        $code = $this->newCode();
        $this->insertRoom($code, true, date('Y-m-d H:i:s', time() - 8 * 86400));

        $cleaned = GarbageCollector::cleanChessRooms($this->conn);

        $this->assertGreaterThanOrEqual(1, $cleaned);
        $this->assertFalse($this->roomExists($code));
    }

    // ══════════════════════════════════════════════════════════════
    // GAME SELESAI — harus DI-PERTAHANKAN walau tidak ada aktivitas
    // ══════════════════════════════════════════════════════════════

    public function testFinishedGameWithResignEventIsKept(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code, true, date('Y-m-d H:i:s', time() - 30 * 86400));
        $this->insertMove($code, date('Y-m-d H:i:s', time() - 8 * 86400));
        $this->insertEvent($code, 'resign', 'b');

        $cleaned = GarbageCollector::cleanChessRooms($this->conn);

        $this->assertSame(0, $cleaned);
        $this->assertTrue($this->roomExists($code));
        $this->assertSame(2, $this->moveCount($code));
    }

    public function testFinishedGameWithGameOverEventIsKept(): void
    {
        // Checkmate/stalemate dicatat via event game_over → game SELESAI.
        $code = $this->newCode();
        $this->insertRoom($code, true, date('Y-m-d H:i:s', time() - 30 * 86400));
        $this->insertMove($code, date('Y-m-d H:i:s', time() - 8 * 86400));
        $this->insertEvent($code, 'game_over', 'b');

        $cleaned = GarbageCollector::cleanChessRooms($this->conn);

        $this->assertSame(0, $cleaned);
        $this->assertTrue($this->roomExists($code));
    }

    public function testFinishedGameWithDrawAcceptEventIsKept(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code, true, date('Y-m-d H:i:s', time() - 30 * 86400));
        $this->insertEvent($code, 'draw_accept', 'b');

        $cleaned = GarbageCollector::cleanChessRooms($this->conn);

        $this->assertSame(0, $cleaned);
        $this->assertTrue($this->roomExists($code));
    }

    public function testStaleLobbyWithMovesCleansBoth(): void
    {
        // save_move.php tidak memeriksa black_joined — secara teori Putih bisa
        // mengirim langkah sebelum lawan join. Lobby basi dengan moves harus
        // tetap dibersihkan BESERTA moves-nya (defensive deletion).
        $code = $this->newCode();
        $this->insertRoom($code, false, date('Y-m-d H:i:s', time() - 2 * 86400));
        $this->insertMove($code, date('Y-m-d H:i:s', time() - 2 * 86400));
        $this->assertSame(1, $this->moveCount($code));

        $cleaned = GarbageCollector::cleanChessRooms($this->conn);

        $this->assertGreaterThanOrEqual(1, $cleaned);
        $this->assertFalse($this->roomExists($code));
        $this->assertSame(0, $this->moveCount($code));
    }

    public function testFinishedGameWithResignIsKept(): void
    {
        // Game macet 30 hari + event resign → SUDAH SELESAI → tidak dihapus.
        $code = $this->newCode();
        $this->insertRoom($code, true, date('Y-m-d H:i:s', time() - 30 * 86400));
        $this->insertMove($code, date('Y-m-d H:i:s', time() - 8 * 86400));
        $this->insertEvent($code, 'resign', 'w', date('Y-m-d H:i:s', time() - 8 * 86400));

        GarbageCollector::cleanChessRooms($this->conn);

        $this->assertTrue($this->roomExists($code));
        $this->assertSame(2, $this->moveCount($code));
    }

    public function testFinishedGameWithGameOverIsKept(): void
    {
        // Checkmate: client mencatat event game_over → game selesai → dipertahankan.
        $code = $this->newCode();
        $this->insertRoom($code, true, date('Y-m-d H:i:s', time() - 30 * 86400));
        $this->insertMove($code, date('Y-m-d H:i:s', time() - 8 * 86400));
        $this->insertEvent($code, 'game_over', 'b', date('Y-m-d H:i:s', time() - 8 * 86400));

        GarbageCollector::cleanChessRooms($this->conn);

        $this->assertTrue($this->roomExists($code));
        $this->assertSame(2, $this->moveCount($code));
    }

    public function testMixedRunKeepsFinishedAndCleansStuckInSameCall(): void
    {
        // Satu run yang sama: room selesai (ada game_over) DI-PERTAHANKAN,
        // room macet di tengah (tanpa event terminal) DIHAPUS.
        $finished = $this->newCode();
        $this->insertRoom($finished, true, date('Y-m-d H:i:s', time() - 30 * 86400));
        $this->insertMove($finished, date('Y-m-d H:i:s', time() - 8 * 86400));
        $this->insertEvent($finished, 'game_over', 'b', date('Y-m-d H:i:s', time() - 8 * 86400));

        $stuck = $this->newCode();
        $this->insertRoom($stuck, true, date('Y-m-d H:i:s', time() - 30 * 86400));
        $this->insertMove($stuck, date('Y-m-d H:i:s', time() - 8 * 86400));

        $cleaned = GarbageCollector::cleanChessRooms($this->conn);

        $this->assertGreaterThanOrEqual(1, $cleaned);
        $this->assertTrue($this->roomExists($finished));
        $this->assertSame(2, $this->moveCount($finished));
        $this->assertFalse($this->roomExists($stuck));
        $this->assertSame(0, $this->moveCount($stuck));
    }

    public function testMixedRunOnlyRemovesStaleRooms(): void
    {
        // Satu run membersihkan hanya room basi — room segar (lobby & game
        // aktif) harus tetap utuh dalam run yang sama.
        $stale = $this->newCode();
        $this->insertRoom($stale, false, date('Y-m-d H:i:s', time() - 2 * 86400));

        $freshLobby = $this->newCode();
        $this->insertRoom($freshLobby, false, date('Y-m-d H:i:s', time() - 3600));

        $activeGame = $this->newCode();
        $this->insertRoom($activeGame, true, date('Y-m-d H:i:s', time() - 2 * 86400));
        $this->insertMove($activeGame, date('Y-m-d H:i:s', time() - 3600));

        $cleaned = GarbageCollector::cleanChessRooms($this->conn);

        $this->assertGreaterThanOrEqual(1, $cleaned);
        $this->assertFalse($this->roomExists($stale));
        $this->assertTrue($this->roomExists($freshLobby));
        $this->assertTrue($this->roomExists($activeGame));
        $this->assertSame(1, $this->moveCount($activeGame));
    }

    // ══════════════════════════════════════════════════════════════
    // THROTTLE
    // ══════════════════════════════════════════════════════════════

    public function testThrottleSkipsCleanupWhenRecentlyRun(): void
    {
        // Tulis throttle "baru saja jalan" → method harus langsung return 0.
        @file_put_contents($this->throttleFile, time());
        $code = $this->newCode();
        $this->insertRoom($code, false, date('Y-m-d H:i:s', time() - 2 * 86400));

        $cleaned = GarbageCollector::cleanChessRooms($this->conn);

        $this->assertSame(0, $cleaned);
        $this->assertTrue($this->roomExists($code));
    }

    public function testThrottleFileNotWritableIsReclaimedWithoutWarning(): void
    {
        // Simulasi file throttle milik user lain (mis. dibuat cron/CLI): file
        // ada tapi tidak writable oleh proses ini. writeThrottleFile() harus
        // menghapus & membuat ulang — TANPA memicu warning PHP.
        $file = $this->throttleFile;
        @file_put_contents($file, '123');
        chmod($file, 0444);
        $this->assertFalse(is_writable($file));

        $warnings = [];
        set_error_handler(static function (int $no, string $str) use (&$warnings): bool {
            $warnings[] = $str;
            return true;
        });

        try {
            GarbageCollector::cleanChessRooms($this->conn);
        } finally {
            restore_error_handler();
            @chmod($file, 0644); // pulihkan agar tearDown() bisa restore backup
        }

        $this->assertSame([], $warnings, 'Throttle file yang tidak writable tidak boleh memicu warning PHP.');
        $this->assertTrue(is_file($file), 'Throttle file harus dibuat ulang.');
        $this->assertGreaterThan(time() - 5, (int) @file_get_contents($file), 'Throttle file harus berisi timestamp terbaru.');
    }
}
