<?php
class MediaViewer
{
    private $conn;
    private $user_id;
    private $user_data; // Menyimpan data user agar tidak perlu query berulang
    private $media_type;
    private $media_id;
    private $table;

    public function __construct($db_connection, $session_user_id, $media_type, $media_id)
    {
        $this->conn = $db_connection;
        $this->user_id = $session_user_id;
        $this->media_type = $media_type;
        $this->media_id = (int)$media_id;
        $this->table = ($media_type === 'video') ? 'video' : 'music';

        // Tarik data user SATU KALI SAJA saat class dipanggil
        if ($this->user_id) {
            $stmt_user = $this->conn->prepare("SELECT is_active, role FROM users WHERE id = ? LIMIT 1");
            $stmt_user->bind_param("i", $this->user_id);
            $stmt_user->execute();
            $this->user_data = $stmt_user->get_result()->fetch_assoc();
        }
    }

    // ─── 1. LOGIKA VIEWS ───
    public function recordView() {
        if (!$this->user_id || !$this->media_id) return false;

        $user = $this->user_data;

        if ($user && $user['is_active'] == 1 && $user['role'] !== 'guest') {
            $log_column = ($this->media_type === 'video') ? 'video_id' : 'music_id';
            $stmt_log = $this->conn->prepare("INSERT IGNORE INTO view_logs (user_id, $log_column) VALUES (?, ?)");
            $stmt_log->bind_param("ii", $this->user_id, $this->media_id);
            $stmt_log->execute();

            if ($stmt_log->affected_rows > 0) {
                // Menggunakan prepared statement untuk update views
                $stmt_upd = $this->conn->prepare("UPDATE {$this->table} SET views = views + 1 WHERE id = ?");
                $stmt_upd->bind_param("i", $this->media_id);
                $stmt_upd->execute();
            }
            return true;
        }
        return false;
    }

    // ─── 2. AMBIL DATA MEDIA UTAMA ───
    public function getMediaData()
    {
        // DIUBAH: Menggunakan prepared statement untuk m.id
        $sql = "SELECT m.*, u.username as uploader, u.profile_picture as uploader_pfp 
                FROM {$this->table} m 
                JOIN users u ON m.user_id = u.id 
                WHERE m.id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->media_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return ($result && $result->num_rows > 0) ? $result->fetch_assoc() : null;
    }

    // ─── 3. LOGIKA INTERAKSI (LIKE/DISLIKE) ───
    public function getUserInteraction()
    {
        if (!$this->user_id) return null;
        $col = ($this->media_type === 'video') ? 'video_id' : 'music_id';
        $stmt = $this->conn->prepare("SELECT type FROM interactions WHERE user_id = ? AND $col = ?");
        $stmt->bind_param("ii", $this->user_id, $this->media_id);
        $stmt->execute();
        $res = $stmt->get_result();
        return ($row = $res->fetch_assoc()) ? $row['type'] : null;
    }

    // ─── 4. MANAJEMEN KOMENTAR ───
    public function addComment($post_data)
    {
        if (!$this->user_id || empty(trim($post_data['comments']))) return false;

        // Gunakan data yang sudah ditarik di construct (Lebih ringan!)
        if (!$this->user_data || $this->user_data['is_active'] != 1 || $this->user_data['role'] === 'guest') {
            return false;
        }

        $raw = trim($post_data['comments']);
        $parent_id = !empty($post_data['parent_id']) ? (int)$post_data['parent_id'] : null;
        $col = ($this->media_type === 'video') ? 'video_id' : 'music_id';

        $stmt = $this->conn->prepare("INSERT INTO comments ($col, user_id, parent_id, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("iiis", $this->media_id, $this->user_id, $parent_id, $raw);
        return $stmt->execute();
    }

    public function getComments(int $limit = 200)
    {
        $col = ($this->media_type === 'video') ? 'video_id' : 'music_id';

        $stmt = $this->conn->prepare("SELECT c.*, u.username, u.role FROM comments c LEFT JOIN users u ON c.user_id = u.id WHERE c.$col = ? ORDER BY c.created_at ASC LIMIT ?");
        $stmt->bind_param("ii", $this->media_id, $limit);
        $stmt->execute();
        $raw_comments = $stmt->get_result();

        $grouped = [];
        $user_map = [];
        while ($row = $raw_comments->fetch_assoc()) {
            $p_id = $row['parent_id'] ?? 0;
            $grouped[$p_id][] = $row;
            $user_map[$row['id']] = $row['username'] ?? 'Guest';
        }
        return ['grouped' => $grouped, 'user_map' => $user_map];
    }

    // ─── 5. REKOMENDASI ───
    public function getRecommendations($limit = 10)
    {

        $limit = (int)$limit;
        $table = $this->table; // sudah tervalidasi di constructor: 'video' | 'music'

        // Ambil MAX(id) — murah, pakai indeks PRIMARY
        $max_res = $this->conn->query("SELECT MAX(id) AS max_id FROM {$table}");
        $max_id  = (int)($max_res ? $max_res->fetch_assoc()['max_id'] : 0);

        if ($max_id <= 1) {

            return $this->conn->query(
                "SELECT m.*, u.username AS uploader FROM {$table} m
                 JOIN users u ON m.user_id = u.id WHERE 1 = 0"
            );
        }

        $random_offset = rand(0, max(0, $max_id - $limit));

        $stmt = $this->conn->prepare(
            "SELECT m.*, u.username AS uploader
             FROM {$table} m
             JOIN users u ON m.user_id = u.id
             WHERE m.id != ? AND m.id > ?
             ORDER BY m.id ASC LIMIT ?"
        );
        $stmt->bind_param("iii", $this->media_id, $random_offset, $limit);
        $stmt->execute();
        return $stmt->get_result();
    }

    // ─── 6. KHUSUS MUSIC: PLAYLIST QUEUE ───
    public function getPlaylistQueue($playlist_id)
    {
        if ($this->media_type !== 'music' || !$playlist_id) return null;
        $playlist_id = (int)$playlist_id;

        // DIUBAH: Semua query di bawah menggunakan Prepared Statements
        // 1. Ambil list antrean
        // NOTE: added_at bertipe TIMESTAMP presisi detik, jadi track yang ditambahkan
        // dalam detik yang sama punya nilai identik. Tie-breaker pt.id (AUTO_INCREMENT,
        // urut insert) dipakai agar urutan SELALU deterministik & identik dengan
        // music/view_playlist.php (ORDER BY yang sama persis).
        $stmt_q = $this->conn->prepare("SELECT m.*, pt.added_at FROM music m JOIN playlist_tracks pt ON m.id = pt.music_id WHERE pt.playlist_id = ? ORDER BY pt.added_at DESC, pt.id DESC");
        $stmt_q->bind_param("i", $playlist_id);
        $stmt_q->execute();
        $queue = $stmt_q->get_result();

        // 2. Ambil posisi (added_at + id) track saat ini — id diambil sebagai
        //    tie-breaker untuk row-value comparison di query "next"
        $stmt_curr = $this->conn->prepare("SELECT added_at, id FROM playlist_tracks WHERE playlist_id = ? AND music_id = ? ORDER BY id DESC LIMIT 1");
        $stmt_curr->bind_param("ii", $playlist_id, $this->media_id);
        $stmt_curr->execute();
        $current = $stmt_curr->get_result()->fetch_assoc();

        $next_url = "";
        if ($current) {
            // 3. Cari lagu berikutnya: baris terbesar yang masih "di bawah" track saat
            //    ini dalam urutan (added_at DESC, id DESC). Row-value comparison
            //    (added_at, id) < (?, ?) dipakai agar track yang added_at-nya SAMA
            //    persis (detik yang sama) tidak terlewat.
            $stmt_next = $this->conn->prepare("SELECT music_id FROM playlist_tracks WHERE playlist_id = ? AND (added_at, id) < (?, ?) ORDER BY added_at DESC, id DESC LIMIT 1");
            $stmt_next->bind_param("isi", $playlist_id, $current['added_at'], $current['id']);
            $stmt_next->execute();
            $next_q = $stmt_next->get_result();

            if ($next_d = $next_q->fetch_assoc()) {
                $next_url = "watch.php?id=" . $next_d['music_id'] . "&playlist_id=" . $playlist_id;
            }
        }
        return ['queue' => $queue, 'next_url' => $next_url];
    }
}
