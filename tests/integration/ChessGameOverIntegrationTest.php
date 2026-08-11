<?php
require_once MEEL_ROOT . '/arcade/chess/controller/chess_helpers.php';
require_once __DIR__ . '/ChessTestCase.php';

use PHPUnit\Framework\TestCase;

/**
 * @requires extension mysqli
 * @group integration
 * @covers chess_record_game_over
 * @covers chess_has_terminal_event
 * @covers chess_last_move_color
 */
class ChessGameOverIntegrationTest extends ChessTestCase
{
    /* Buat event (resign / game_over dll) seperti insertGameEvent(). */
    private function insertEvent(string $code, string $type, string $color = 'w', string $reason = null): void
    {
        $extra = $reason !== null ? ['reason' => $reason] : [];
        $json = json_encode(array_merge(['type' => $type, 'color' => $color], $extra));
        $stmt = $this->conn->prepare(
            "INSERT INTO moves
                (room_code, from_r, from_c, to_r, to_c, piece, color, captured, promoted_piece_type, move_data)
             VALUES (?, 0, 0, 0, 0, 'x', ?, NULL, NULL, ?)"
        );
        $stmt->bind_param("sss", $code, $color, $json);
        $stmt->execute();
        $stmt->close();
    }

    private function newCode(): string
    {
        return 'GO' . strtoupper(substr(uniqid('', true), -6));
    }

    /* Jumlah event dengan type tertentu di room. */
    private function countEvents(string $code, string $type): int
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS n FROM moves
             WHERE room_code = ?
               AND JSON_UNQUOTE(JSON_EXTRACT(move_data, '$.type')) = ?"
        );
        $stmt->bind_param("ss", $code, $type);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) $row['n'];
    }

    /* Isi move_data event game_over terakhir. */
    private function lastGameOverData(string $code): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT move_data FROM moves
             WHERE room_code = ?
               AND JSON_UNQUOTE(JSON_EXTRACT(move_data, '$.type')) = 'game_over'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? json_decode($row['move_data'], true) : null;
    }

    // VALIDASI PECUNDANG
    public function testValidCheckmateAfterWhiteMoveRecordsBlackLoser(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);
        $this->insertMove($code, 'w'); // langkah terakhir Putih → pecundang Hitam

        $result = chess_record_game_over($this->conn, $code, 'b', 'checkmate');

        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['id']);
        $this->assertSame(1, $this->countEvents($code, 'game_over'));
        $data = $this->lastGameOverData($code);
        $this->assertSame('game_over', $data['type']);
        $this->assertSame('b', $data['color']);
        $this->assertSame('checkmate', $data['reason']);
    }

    public function testValidStalemateAfterBlackMoveRecordsWhiteLoser(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);
        $this->insertMove($code, 'b'); // langkah terakhir Hitam → pecundang Putih

        $result = chess_record_game_over($this->conn, $code, 'w', 'stalemate');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $this->countEvents($code, 'game_over'));
        $data = $this->lastGameOverData($code);
        $this->assertSame('stalemate', $data['reason']);
    }

    public function testWrongLoserColorIsRejected(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);
        $this->insertMove($code, 'w'); // pecundang seharusnya Hitam (b)

        $result = chess_record_game_over($this->conn, $code, 'w', 'checkmate');

        $this->assertFalse($result['success']);
        $this->assertSame('Warna pecundang tidak sesuai langkah terakhir.', $result['message']);
        $this->assertSame(0, $this->countEvents($code, 'game_over'));
    }

    public function testGameOverWithoutAnyMoveIsRejected(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code); // room ada tapi belum ada langkah

        $result = chess_record_game_over($this->conn, $code, 'b', 'checkmate');

        $this->assertFalse($result['success']);
        $this->assertSame('Belum ada langkah — game over tidak valid.', $result['message']);
        $this->assertSame(0, $this->countEvents($code, 'game_over'));
    }

    public function testInvalidReasonIsRejected(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);
        $this->insertMove($code, 'w');

        $result = chess_record_game_over($this->conn, $code, 'b', 'blunder');

        $this->assertFalse($result['success']);
        $this->assertSame('Alasan game over tidak dikenal.', $result['message']);
        $this->assertSame(0, $this->countEvents($code, 'game_over'));
    }

    public function testInvalidLoserColorIsRejected(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);
        $this->insertMove($code, 'w');

        $result = chess_record_game_over($this->conn, $code, 'x', 'checkmate');

        $this->assertFalse($result['success']);
        $this->assertSame('Warna tidak valid.', $result['message']);
        $this->assertSame(0, $this->countEvents($code, 'game_over'));
    }

    // DEDUP — game sudah berakhir harus menolak event baru
    public function testDuplicateGameOverIsRejected(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);
        $this->insertMove($code, 'w');

        $first = chess_record_game_over($this->conn, $code, 'b', 'checkmate');
        $second = chess_record_game_over($this->conn, $code, 'b', 'checkmate');

        $this->assertTrue($first['success']);
        $this->assertFalse($second['success']);
        $this->assertSame('Permainan sudah berakhir.', $second['message']);
        $this->assertSame(1, $this->countEvents($code, 'game_over'));
    }

    public function testGameOverAfterResignIsRejected(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);
        $this->insertMove($code, 'w');
        $this->insertEvent($code, 'resign', 'w');

        $result = chess_record_game_over($this->conn, $code, 'b', 'checkmate');

        $this->assertFalse($result['success']);
        $this->assertSame('Permainan sudah berakhir.', $result['message']);
        $this->assertSame(0, $this->countEvents($code, 'game_over'));
    }

    public function testGameOverAfterDrawAcceptIsRejected(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);
        $this->insertMove($code, 'w');
        $this->insertEvent($code, 'draw_accept', 'b');

        $result = chess_record_game_over($this->conn, $code, 'b', 'checkmate');

        $this->assertFalse($result['success']);
        $this->assertSame('Permainan sudah berakhir.', $result['message']);
        $this->assertSame(0, $this->countEvents($code, 'game_over'));
    }

    public function testGameOverAfterDisconnectIsRejected(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);
        $this->insertMove($code, 'w');
        $this->insertEvent($code, 'disconnect', 'w');

        $result = chess_record_game_over($this->conn, $code, 'b', 'checkmate');

        $this->assertFalse($result['success']);
        $this->assertSame('Permainan sudah berakhir.', $result['message']);
        $this->assertSame(0, $this->countEvents($code, 'game_over'));
    }

    // HELPER PENDUKUNG
    public function testHasTerminalEventReflectsGameState(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);
        $this->insertMove($code, 'w');

        $this->assertFalse(chess_has_terminal_event($this->conn, $code));

        $this->insertEvent($code, 'game_over', 'b', 'checkmate');
        $this->assertTrue(chess_has_terminal_event($this->conn, $code));
    }

    public function testLastMoveColorIgnoresEvents(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);
        $this->assertNull(chess_last_move_color($this->conn, $code));

        $this->insertMove($code, 'w');
        $this->assertSame('w', chess_last_move_color($this->conn, $code));

        // Event setelahnya TIDAK mengubah warna langkah terakhir.
        $this->insertEvent($code, 'draw_offer', 'w');
        $this->assertSame('w', chess_last_move_color($this->conn, $code));
    }

    public function testInsertGameEventStoresValidJson(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);

        insertGameEvent($this->conn, $code, 'b', 'game_over', ['reason' => 'stalemate']);

        $this->assertSame(1, $this->countEvents($code, 'game_over'));
        $data = $this->lastGameOverData($code);
        $this->assertSame('b', $data['color']);
        $this->assertSame('stalemate', $data['reason']);
    }
}
