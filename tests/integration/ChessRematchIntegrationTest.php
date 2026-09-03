<?php
require_once MEEL_ROOT . '/arcade/chess/controller/chess_helpers.php';
require_once __DIR__ . '/ChessTestCase.php';

use PHPUnit\Framework\TestCase;

/**
 * @requires extension mysqli
 * @group integration
 * @covers chess_rematch
 * @covers chess_last_event
 * @covers chess_reset_room_game
 */
class ChessRematchIntegrationTest extends ChessTestCase
{
    
    private function insertEvent(string $code, string $type, string $color = 'w', string $reason = null): void
    {
        $extra = $reason !== null ? ['reason' => $reason] : [];
        insertGameEvent($this->conn, $code, $color, $type, $extra);
    }

    
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

    
    private function insertUser(int $lastActivityTs): int
    {
        $username = 'rm_test_' . substr(uniqid('', true), -8);
        $lastActivity = date('Y-m-d H:i:s', $lastActivityTs);
        
        $password = bin2hex(random_bytes(16));
        $stmt = $this->conn->prepare(
            "INSERT INTO users (username, password, last_activity) VALUES (?, ?, ?)"
        );
        $stmt->bind_param("sss", $username, $password, $lastActivity);
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

    
    public function testOfferRejectedWhenGameNotFinished(): void
    {
        $code = $this->newCode();
        $this->insertRoom($code);
        $this->insertMove($code, 'w'); 

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

    
    public function testSecondOfferWhilePendingIsRejected(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        $this->assertTrue(chess_rematch($this->conn, $code, 'w', 'rematch_offer')['success']);

        $result = chess_rematch($this->conn, $code, 'b', 'rematch_offer');

        $this->assertFalse($result['success']);
        $this->assertSame('Masih ada tawaran tanding ulang yang menunggu jawaban.', $result['message']);
    }

    
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
        chess_rematch($this->conn, $code, 'w', 'rematch_offer'); 

        $result = chess_rematch($this->conn, $code, 'w', 'rematch_accept'); 

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
        
        $this->assertSame(1, $this->countMoves($code));
        $this->assertSame('rematch_accept', chess_last_event($this->conn, $code)['type']);

        $this->assertFalse(chess_has_terminal_event($this->conn, $code));
        $this->assertNull(chess_last_move_color($this->conn, $code));
    }

    public function testNewGameCanEndAgainAfterRematch(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        chess_rematch($this->conn, $code, 'w', 'rematch_offer');
        chess_rematch($this->conn, $code, 'b', 'rematch_accept');

        
        $this->insertMove($code, 'w');
        $this->assertTrue(chess_record_game_over($this->conn, $code, 'b', 'checkmate')['success']);
        $this->assertTrue(chess_rematch($this->conn, $code, 'b', 'rematch_offer')['success']);
    }

    
    public function testDeclineByOpponentIsAllowed(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        chess_rematch($this->conn, $code, 'w', 'rematch_offer'); 

        $result = chess_rematch($this->conn, $code, 'b', 'rematch_decline'); 

        $this->assertTrue($result['success']);
        $this->assertSame('rematch_decline', chess_last_event($this->conn, $code)['type']);
        
        $this->assertTrue(chess_has_terminal_event($this->conn, $code));
        $this->assertGreaterThan(1, $this->countMoves($code));
    }

    public function testOffererCanCancelOwnOffer(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        chess_rematch($this->conn, $code, 'w', 'rematch_offer');

        $result = chess_rematch($this->conn, $code, 'w', 'rematch_decline'); 

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

        $result = chess_rematch($this->conn, $code, 'w', 'rematch_offer'); 

        $this->assertTrue($result['success']);
    }

    public function testAcceptAfterDeclineIsRejected(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        chess_rematch($this->conn, $code, 'w', 'rematch_offer');
        chess_rematch($this->conn, $code, 'b', 'rematch_decline');

        
        $result = chess_rematch($this->conn, $code, 'b', 'rematch_accept');

        $this->assertFalse($result['success']);
        $this->assertSame('Tidak ada tawaran tanding ulang yang menunggu.', $result['message']);
    }

    
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

    public function testOfferRejectedWhenOpponentOffline(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        $oppId = $this->insertUser(time() - 7200); 

        $result = chess_rematch($this->conn, $code, 'w', 'rematch_offer', $oppId);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['opponent_gone']);
        $this->assertSame('Lawan sudah keluar dari permainan.', $result['message']);

        $this->assertSame('game_over', chess_last_event($this->conn, $code)['type']);
    }

    public function testOfferSucceedsWhenOpponentOnline(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        $oppId = $this->insertUser(time()); 

        $result = chess_rematch($this->conn, $code, 'w', 'rematch_offer', $oppId);

        $this->assertTrue($result['success']);
        $this->assertSame('rematch_offer', chess_last_event($this->conn, $code)['type']);
    }

    public function testAcceptRejectedWhenOffererOffline(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);
        chess_rematch($this->conn, $code, 'w', 'rematch_offer'); 
        $offererId = $this->insertUser(time() - 7200); 

        $result = chess_rematch($this->conn, $code, 'b', 'rematch_accept', $offererId);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['opponent_gone']);

        $this->assertTrue(chess_has_terminal_event($this->conn, $code));
    }

    public function testPresenceCheckSkippedWhenOpponentIdNull(): void
    {
        $code = $this->newCode();
        $this->insertFinishedGame($code);

        $result = chess_rematch($this->conn, $code, 'w', 'rematch_offer');

        $this->assertTrue($result['success']);
    }
}
