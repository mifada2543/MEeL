<?php

/**
 * ProfileRepository — akses data konten user untuk halaman profil
 * (profile/index.php & profile/manage.php).
 *
 * Query COUNT & daftar media user sebelumnya ditulis berulang di dua file
 * halaman; dikonsolidasi di sini agar tidak ada duplikasi logika akses data.
 */
class ProfileRepository
{
    private \mysqli $conn;

    public function __construct(\mysqli $conn)
    {
        $this->conn = $conn;
    }

    /** Jumlah video milik user. */
    public function countVideo(int $user_id): int
    {
        return $this->countMedia('video', $user_id);
    }

    /** Jumlah music milik user. */
    public function countMusic(int $user_id): int
    {
        return $this->countMedia('music', $user_id);
    }

    /** Daftar video user dengan pagination. */
    public function getVideosPaginated(int $user_id, int $limit, int $offset): array
    {
        return $this->getMediaList('video', $user_id, $limit, $offset);
    }

    /** Daftar music user dengan pagination. */
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
