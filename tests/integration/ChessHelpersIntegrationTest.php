<?php
require_once MEEL_ROOT . '/arcade/chess/controller/chess_helpers.php';
require_once __DIR__ . '/ChessTestCase.php';

use PHPUnit\Framework\TestCase;

/**
 * @requires extension mysqli
 * @group integration
 * @covers chess_opponent_online
 */
class ChessHelpersIntegrationTest extends ChessTestCase
{

    public function testRecentlyActiveUserIsOnline(): void
    {

        $this->conn->query(
            "UPDATE users SET last_activity = NOW() WHERE id = " . DbTestHelper::REGULAR_USER_ID
        );

        $this->assertTrue(chess_opponent_online($this->conn, DbTestHelper::REGULAR_USER_ID));
    }

    public function testStaleUserIsOffline(): void
    {
        
        $this->conn->query(
            "UPDATE users SET last_activity = DATE_SUB(NOW(), INTERVAL 10 MINUTE)
             WHERE id = " . DbTestHelper::REGULAR_USER_ID
        );

        $this->assertFalse(chess_opponent_online($this->conn, DbTestHelper::REGULAR_USER_ID));
    }

    public function testBoundaryJustUnderThresholdIsOnline(): void
    {
        
        $this->conn->query(
            "UPDATE users SET last_activity = DATE_SUB(NOW(), INTERVAL 60 SECOND)
             WHERE id = " . DbTestHelper::REGULAR_USER_ID
        );

        $this->assertTrue(chess_opponent_online($this->conn, DbTestHelper::REGULAR_USER_ID));
    }

    public function testUnknownUserIsOffline(): void
    {
        $this->assertFalse(chess_opponent_online($this->conn, 999999999));
    }

    public function testZeroIdIsOffline(): void
    {
        
        $this->assertFalse(chess_opponent_online($this->conn, 0));
    }

    public function testConstantIsDefined(): void
    {
        $this->assertTrue(defined('CHESS_OPPONENT_OFFLINE_SECONDS'));
        $this->assertGreaterThan(0, CHESS_OPPONENT_OFFLINE_SECONDS);
    }
}
