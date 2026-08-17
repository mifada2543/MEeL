<?php

/**
 * PlaylistRepository — akses data playlist untuk halaman music.
 *
 * Mengkonsolidasi query playlist yang sebelumnya tersebar di beberapa file
 * halaman (view_playlist.php, watch.php) ke satu tempat, sehingga tidak ada
 * duplikasi logika akses data.
 */
class PlaylistRepository
{
    private \mysqli $conn;

    public function __construct(\mysqli $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Ambil playlist milik user (validasi kepemilikan).
     *
     * @return array|null Baris playlist, atau null jika tidak ada / bukan milik user.
     */
    public function getOwnedPlaylist(int $playlist_id, int $user_id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM playlists WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $playlist_id, $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Ambil semua lagu dalam sebuah playlist (dengan pivot id), terbaru dulu.
     *
     * @return \mysqli_result
     */
    public function getTracks(int $playlist_id)
    {
        $stmt = $this->conn->prepare(
            "SELECT m.*, pt.id as pivot_id
             FROM music m
             JOIN playlist_tracks pt ON m.id = pt.music_id
             WHERE pt.playlist_id = ?
             ORDER BY pt.added_at DESC, pt.id DESC"
        );
        $stmt->bind_param("i", $playlist_id);
        $stmt->execute();
        return $stmt->get_result();
    }

}
