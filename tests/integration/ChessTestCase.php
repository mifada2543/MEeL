<?php
use PHPUnit\Framework\TestCase;

/**
 * Base class test integrasi catur: koneksi DB dengan rollback per-test
 * + helper pembuatan room & langkah (dipakai oleh beberapa test class).
 */
abstract class ChessTestCase extends TestCase
{
    protected DbTestHelper $dbHelper;
    protected mysqli $conn;

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

    /* Buat room test dengan black sudah join (black_joined=1). */
    protected function insertRoom(string $code): void
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO rooms (room_code, white_user_id, black_user_id, black_joined)
             VALUES (?, 1, 10, 1)"
        );
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $stmt->close();
    }

    /* Buat langkah catur asli (bukan event) — move_data JSON tanpa 'type'. */
    protected function insertMove(string $code, string $color): void
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
}
