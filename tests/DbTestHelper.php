<?php
/**
 * DbTestHelper — Database connection helper for integration tests.
 *
 * Provides a real mysqli connection to the MEeL database with
 * transaction-based isolation. Each test automatically rolls back
 * changes to prevent test pollution.
 *
 * Usage:
 *   $helper = new DbTestHelper();
 *   $conn = $helper->getConnection();
 *   // ... test code ...
 *   $helper->rollback(); // Restore initial state
 */

class DbTestHelper
{
    private ?mysqli $conn = null;
    private bool $inTransaction = false;

    /**
     * Known test data IDs (read-only — these exist in the actual DB).
     */
    const ADMIN_USER_ID = 1;     // BTMEeL2026 (admin)
    const ADMIN2_USER_ID = 9;    // Mifada (admin)
    const MEMBER_USER_ID = 10;   // Daffa (member)
    const REGULAR_USER_ID = 39;  // aruniaru (user)

    const MUSIC_ID_1 = 49;       // Chunithm Colaboration
    const MUSIC_ID_2 = 50;       // メロメロイド
    const MUSIC_ID_3 = 51;       // あいしていたのに

    const VIDEO_ID_1 = 4;        // Poppin Candy Fever!
    const VIDEO_ID_2 = 5;        // Positive*Dance Time
    const VIDEO_ID_3 = 6;        // God-ish

    /**
     * Get a real DB connection and start a transaction.
     */
    public function getConnection(): mysqli
    {
        if ($this->conn === null) {
            $this->conn = new mysqli('localhost', 'root', '', 'MEeL');
            if ($this->conn->connect_error) {
                throw new RuntimeException(
                    'DB Connection failed: ' . $this->conn->connect_error
                );
            }
            // Start transaction for isolation
            $this->conn->begin_transaction();
            $this->inTransaction = true;
        }
        return $this->conn;
    }

    /**
     * Rollback the transaction — restores DB to pre-test state.
     * Call this in tearDown().
     */
    public function rollback(): void
    {
        if ($this->conn !== null && $this->inTransaction) {
            $this->conn->rollback();
            $this->inTransaction = false;
        }
    }

    /**
     * Commit the transaction (use only if intentionally persisting test data).
     */
    public function commit(): void
    {
        if ($this->conn !== null && $this->inTransaction) {
            $this->conn->commit();
            $this->inTransaction = false;
        }
    }

    /**
     * Close the connection.
     */
    public function close(): void
    {
        if ($this->conn !== null) {
            $this->rollback(); // Rollback any uncommitted changes
            $this->conn->close();
            $this->conn = null;
        }
    }

    /**
     * Get the original likes/dislikes counts for a music item.
     */
    public function getMusicLikesCount(int $musicId): array
    {
        $stmt = $this->conn->prepare("SELECT likes, dislikes FROM music WHERE id = ?");
        $stmt->bind_param("i", $musicId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return [
            'likes' => (int)($result['likes'] ?? 0),
            'dislikes' => (int)($result['dislikes'] ?? 0)
        ];
    }

    /**
     * Get the original likes/dislikes counts for a video item.
     */
    public function getVideoLikesCount(int $videoId): array
    {
        $stmt = $this->conn->prepare("SELECT likes, dislikes FROM video WHERE id = ?");
        $stmt->bind_param("i", $videoId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return [
            'likes' => (int)($result['likes'] ?? 0),
            'dislikes' => (int)($result['dislikes'] ?? 0)
        ];
    }

    /**
     * Check if an interaction exists for a user on a specific media item.
     */
    public function interactionExists(int $userId, string $col, int $mediaId): ?string
    {
        $stmt = $this->conn->prepare(
            "SELECT `TYPE` FROM interactions WHERE user_id = ? AND $col = ?"
        );
        $stmt->bind_param("ii", $userId, $mediaId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result['TYPE'] ?? null;
    }

    /**
     * Create a test comment in the database.
     * Returns the new comment ID.
     */
    public function createTestComment(int $userId, ?int $musicId, ?int $videoId, string $text): int
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO comments (user_id, music_id, video_id, comment) VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param("iiss", $userId, $musicId, $videoId, $text);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * Verify a comment exists by ID and belongs to a specific user.
     */
    public function getCommentOwner(int $commentId): ?int
    {
        $stmt = $this->conn->prepare("SELECT user_id FROM comments WHERE id = ?");
        $stmt->bind_param("i", $commentId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result ? (int)$result['user_id'] : null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
