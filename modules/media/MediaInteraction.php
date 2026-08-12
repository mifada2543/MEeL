<?php
class MediaInteraction {
    private \mysqli $conn;
    private int $user_id;
    private string $error = '';

    public function __construct(\mysqli $db_connection, int $session_user_id) {
        $this->conn = $db_connection;
        $this->user_id = (int)$session_user_id;
    }

    // LIKE / DISLIKE FUNCTIONALITY
    /**
     * @param int $media_id ID dari music atau video
     * @param string $media_type 'music' atau 'video'
     * @param string $like_type 'like' atau 'dislike'
     * @return array Status dan data terbaru
     */
    public function toggleLike(int $media_id, string $media_type, string $like_type): array {
        if (!$this->validateUser()) {
            return $this->getResponse(false, 'User tidak terautentikasi', 403);
        }

        if (!$this->validateLikeInput($media_id, $media_type, $like_type)) {
            return $this->getResponse(false, 'Input tidak valid', 400);
        }

        try {
            $col = ($media_type === 'music') ? 'music_id' : 'video_id';
            $table = ($media_type === 'music') ? 'music' : 'video';

            // 1. Cek interaksi sebelumnya
            $existing = $this->getExistingInteraction($col, $media_id);

            // 2. INSERT / UPDATE / DELETE
            $this->performInteractionOperation($existing, $col, $media_id, $like_type);

            // 3. Sinkronisasi likes/dislikes
            $this->syncLikesCount($table, $col, $media_id);

            // 4. Ambil data terbaru
            $data = $this->getLikesData($table, $media_id, $col);
            return $this->getResponse(true, 'Berhasil', 200, $data);

        } catch (RuntimeException $e) {
            return $this->getResponse(false, $e->getMessage(), 500);
        }
    }

    /* @param int $media_id; @param string $media_type; @return array|null */
    public function getUserInteractionStatus(int $media_id, string $media_type): ?string {
        $col = ($media_type === 'music') ? 'music_id' : 'video_id';
        $existing = $this->getExistingInteraction($col, $media_id);
        return $existing ? $existing['TYPE'] : null;
    }

    /* @param string $table; @param int $media_id; @return array */
    public function getLikesCount(string $table, int $media_id): array {
        $stmt = $this->conn->prepare("SELECT likes, dislikes FROM $table WHERE id = ?");
        $stmt->bind_param("i", $media_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();

        return [
            'likes' => (int)($data['likes'] ?? 0),
            'dislikes' => (int)($data['dislikes'] ?? 0)
        ];
    }

    // COMMENT FUNCTIONALITY
    /* @param int $comment_id; @return array Status response */
    public function deleteComment(int $comment_id): array {
        if (!$this->validateUser()) {
            return $this->getResponse(false, 'User tidak terautentikasi', 403);
        }

        if ($comment_id <= 0) {
            return $this->getResponse(false, 'Comment ID tidak valid', 400);
        }

        try {
            // Ambil kepemilikan komentar + media tempat komentar berada
            $stmt = $this->conn->prepare("SELECT user_id, video_id, music_id FROM comments WHERE id = ?");
            if (!$stmt) {
                throw new RuntimeException($this->conn->error);
            }

            $stmt->bind_param("i", $comment_id);
            $stmt->execute();
            $comment = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$comment) {
                return $this->getResponse(false, 'Komentar tidak ditemukan', 404);
            }

            // Otorisasi: pemilik komentar ATAU uploader media ATAU admin
            $is_owner    = ((int)$comment['user_id'] === $this->user_id);
            $is_uploader = false;
            $is_admin    = false;

            if (!$is_owner) {
                $is_uploader = $this->isMediaUploader(
                    (int)($comment['video_id'] ?? 0),
                    (int)($comment['music_id'] ?? 0)
                );
            }

            if (!$is_owner && !$is_uploader) {
                $is_admin = $this->isAdmin();
            }

            if (!$is_owner && !$is_uploader && !$is_admin) {
                return $this->getResponse(false, 'Komentar tidak ditemukan atau Anda tidak berwenang', 404);
            }

            // Hapus komentar (reply ikut terhapus via ON DELETE CASCADE)
            $stmt = $this->conn->prepare("DELETE FROM comments WHERE id = ?");
            if (!$stmt) {
                throw new RuntimeException($this->conn->error);
            }

            $stmt->bind_param("i", $comment_id);

            if (!$stmt->execute()) {
                throw new RuntimeException($this->conn->error);
            }

            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected === 0) {
                return $this->getResponse(false, 'Komentar tidak ditemukan atau Anda tidak berwenang', 404);
            }

            return $this->getResponse(true, 'Komentar berhasil dihapus', 200);

        } catch (RuntimeException $e) {
            return $this->getResponse(false, $e->getMessage(), 500);
        }
    }

    // PRIVATE HELPER FUNCTIONS
    /**
     * @param int|null $video_id ID video tempat komentar (0/null = tidak ada)
     * @param int|null $music_id ID music tempat komentar (0/null = tidak ada)
     * @return bool True jika user ini adalah uploader media tsb
     */
    private function isMediaUploader(?int $video_id, ?int $music_id): bool
    {
        if ($video_id) {
            $stmt = $this->conn->prepare("SELECT user_id FROM video WHERE id = ?");
            if (!$stmt) {
                throw new RuntimeException($this->conn->error);
            }
            $stmt->bind_param("i", $video_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row && (int)$row['user_id'] === $this->user_id;
        }

        if ($music_id) {
            $stmt = $this->conn->prepare("SELECT user_id FROM music WHERE id = ?");
            if (!$stmt) {
                throw new RuntimeException($this->conn->error);
            }
            $stmt->bind_param("i", $music_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row && (int)$row['user_id'] === $this->user_id;
        }

        return false;
    }

    /* @return bool True jika user ini admin */
    private function isAdmin(): bool
    {
        return get_user_role($this->conn, $this->user_id) === 'admin';
    }

    private function validateUser(): bool {
        return $this->user_id > 0;
    }

    private function validateLikeInput(int $media_id, string $media_type, string $like_type): bool {
        if ($media_id <= 0) {
            $this->error = 'Media ID tidak valid';
            return false;
        }
        if (!in_array($media_type, ['music', 'video'], true)) {
            $this->error = 'Tipe media tidak valid';
            return false;
        }
        if (!in_array($like_type, ['like', 'dislike'], true)) {
            $this->error = 'Tipe like/dislike tidak valid';
            return false;
        }
        return true;
    }

    private function getExistingInteraction(string $col, int $media_id): ?array {
        $stmt = $this->conn->prepare("SELECT `TYPE` FROM interactions WHERE user_id = ? AND $col = ?");
        if (!$stmt) {
            throw new RuntimeException($this->conn->error);
        }

        $stmt->bind_param("ii", $this->user_id, $media_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $existing = $result->fetch_assoc();
        $stmt->close();

        return $existing;
    }

    private function performInteractionOperation(?array $existing, string $col, int $media_id, string $like_type): void {
        if ($existing) {
            if ($existing['TYPE'] === $like_type) {
                // Delete: toggle OFF (same type)
                $op = $this->conn->prepare("DELETE FROM interactions WHERE user_id = ? AND $col = ?");
                $op->bind_param("ii", $this->user_id, $media_id);
            } else {
                // Update: change type
                $op = $this->conn->prepare("UPDATE interactions SET `TYPE` = ? WHERE user_id = ? AND $col = ?");
                $op->bind_param("sii", $like_type, $this->user_id, $media_id);
            }
        } else {
            // Insert: new interaction
            $op = $this->conn->prepare("INSERT INTO interactions (user_id, $col, `TYPE`) VALUES (?, ?, ?)");
            $op->bind_param("iis", $this->user_id, $media_id, $like_type);
        }

        if (!$op->execute()) {
            throw new RuntimeException($this->conn->error);
        }
        $op->close();
    }

    private function syncLikesCount(string $table, string $col, int $media_id): void {
        $sync = $this->conn->prepare(
            "UPDATE $table t SET
                likes    = (SELECT COUNT(*) FROM interactions WHERE $col = t.id AND `TYPE` = 'like'),
                dislikes = (SELECT COUNT(*) FROM interactions WHERE $col = t.id AND `TYPE` = 'dislike')
             WHERE t.id = ?"
        );
        if (!$sync) {
            throw new RuntimeException($this->conn->error);
        }

        $sync->bind_param("i", $media_id);
        if (!$sync->execute()) {
            throw new RuntimeException($this->conn->error);
        }
        $sync->close();
    }

    private function getLikesData(string $table, int $media_id, string $col): array {
        $counts  = $this->getLikesCount($table, $media_id);
        $existing = $this->getExistingInteraction($col, $media_id);

        return [
            'likes'            => $counts['likes'],
            'dislikes'         => $counts['dislikes'],
            'user_interaction' => $existing['TYPE'] ?? null,
        ];
    }

    private function getResponse(bool $success, string $message, int $http_code, mixed $data = null): array {
        return [
            'success' => $success,
            'message' => $message,
            'http_code' => $http_code,
            'data' => $data
        ];
    }

    // GETTERS
    public function getError(): string {
        return $this->error;
    }

    public function getUserId(): int {
        return $this->user_id;
    }
}
