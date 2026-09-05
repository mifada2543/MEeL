<?php



class ProfileRepository
{
    private \mysqli $conn;

    public function __construct(\mysqli $conn)
    {
        $this->conn = $conn;
    }

    
    public function isMfaEnabled(int $user_id): int
    {
        $stmt = $this->conn->prepare('SELECT mfa_enabled FROM users WHERE id = ?');
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($res['mfa_enabled'] ?? 0);
    }

    
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->conn->prepare('SELECT id, username, bio, role, profile_picture, last_activity FROM users WHERE username = ?');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res  = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $res ?: null;
    }

    public function countVideo(int $user_id): int
    {
        return $this->countMedia('video', $user_id);
    }

    
    public function countMusic(int $user_id): int
    {
        return $this->countMedia('music', $user_id);
    }

    
    public function getVideosPaginated(int $user_id, int $limit, int $offset): array
    {
        return $this->getMediaList('video', $user_id, $limit, $offset);
    }

    
    public function getMusicPaginated(int $user_id, int $limit, int $offset): array
    {
        return $this->getMediaList('music', $user_id, $limit, $offset);
    }

    private function countMedia(string $table, int $user_id): int
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $count = (int) $stmt->get_result()->fetch_row()[0];
        $stmt->close();
        return $count;
    }

    private function getMediaList(string $table, int $user_id, int $limit, int $offset): array
    {
        $cols = $table === 'music'
            ? 'id, title, artist, thumbnail, views, likes, dislikes, upload_date'
            : 'id, title, thumbnail, views, likes, dislikes, upload_date';

        $stmt = $this->conn->prepare(
            "SELECT {$cols} FROM {$table} WHERE user_id = ? ORDER BY upload_date DESC LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('iii', $user_id, $limit, $offset);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}
