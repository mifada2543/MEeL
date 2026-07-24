<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/japanese.php';
require_once __DIR__ . '/GarbageCollector.php';
require_once __DIR__ . '/../transcoder/FfmpegUtils.php';

class Uploader
{
    use FfmpegUtils;
    private \mysqli $conn;
    private int $user_id;
    private string $username;
    private string $user_role;
    private string $base_dir;
    private string $ffmpeg_bin;
    private string $ffprobe_bin;

    public function __construct(\mysqli $db_connection, int $session_user_id, string $session_username)
    {
        $this->conn      = $db_connection;
        $this->user_id   = (int)$session_user_id;
        $this->username  = $session_username;
        $this->base_dir  = defined('MEEL_HDD_VIDEO_UPLOAD') ? MEEL_HDD_VIDEO_UPLOAD : "/path/to/your/media/video/upload/";
        $this->ffmpeg_bin  = $this->resolveBinary(['/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg', 'ffmpeg']);
        $this->ffprobe_bin = $this->resolveBinary(['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', 'ffprobe']);

        $this->user_role = get_user_role($this->conn, $this->user_id);
    }

    // ─── PRIVATE HELPERS ──────────────────────────────────────────────────────

    private function resolveBinary(array $candidates): string
    {
        return resolve_binary($candidates);
    }

    private function checkRateLimit(string $table): array
    {
        require_once __DIR__ . '/System.php';
        $sys = new System($this->conn);
        return $sys->checkRateLimit($this->user_id, $table, $this->user_role);
    }

    private function generateMetadata(string $title, string $artist = "", string $album = ""): string
    {
        $original = trim("$title $artist $album");
        $analysis = analyzeJapaneseText($original); // 1x MeCab, hasilkan romaji + english sekaligus

        $combined = trim($original . " " . $analysis['romaji'] . " " . $analysis['english']);
        return mb_strtolower($combined, 'UTF-8');
    }

    /**
     * 🔒 FIX SECURITY: Validasi magic bytes file video.
     * Cek header file untuk memastikan benar-benar video (MP4/WebM/MKV).
     */
    private function validateVideoMagicBytes(string $filePath): bool
    {
        if (!is_file($filePath) || filesize($filePath) < 12) {
            return false;
        }

        $handle = @fopen($filePath, 'rb');
        if (!$handle) {
            return false;
        }
        $header = fread($handle, 16);
        fclose($handle);

        if ($header === false || strlen($header) < 4) {
            return false;
        }

        // MP4/MOV: dimulai dengan 'ftyp' di offset 4
        if (strlen($header) >= 8 && substr($header, 4, 4) === 'ftyp') {
            return true;
        }

        // WebM/MKV: dimulai dengan \x1A\x45\xDF\xA3 (EBML header)
        if (str_starts_with($header, "\x1A\x45\xDF\xA3")) {
            return true;
        }

        return false;
    }

    /**
     * 🔒 FIX SECURITY: Cek jumlah upload aktif untuk mencegah overload server.
     * Batasi maksimal 3 proses upload simultan.
     * Dilengkapi TTL auto-reset (5 menit) untuk mencegah counter stale akibat PHP crash.
     */
    private function checkActiveUploadLimit(): bool
    {
        $lock_file    = sys_get_temp_dir() . '/meel_upload_counter.lock';
        $counter_file = sys_get_temp_dir() . '/meel_upload_count.dat';

        $fp = @fopen($lock_file, 'c');
        if (!$fp) return true; // fallback: allow jika gagal lock
        flock($fp, LOCK_EX);

        // 🔄 TTL auto-reset: jika counter file lebih dari 5 menit, reset ke 0
        if (file_exists($counter_file) && (time() - filemtime($counter_file)) > 300) {
            @unlink($counter_file);
        }

        $current = (int)@file_get_contents($counter_file);
        if ($current >= 3) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }

        file_put_contents($counter_file, $current + 1);
        flock($fp, LOCK_UN);
        fclose($fp);

        // Register shutdown function untuk decrement
        register_shutdown_function(function () use ($lock_file, $counter_file) {
            $fp2 = @fopen($lock_file, 'c');
            if ($fp2) {
                flock($fp2, LOCK_EX);
                $count = max(0, (int)@file_get_contents($counter_file) - 1);
                file_put_contents($counter_file, $count);
                flock($fp2, LOCK_UN);
                fclose($fp2);
            }
        });

        return true;
    }

    private function getUniqueFilename(string $clean_name, string $ext, string $target_dir): string
    {
        $file_name = $clean_name . "." . $ext;
        $counter = 1;

        while (file_exists($target_dir . $file_name)) {
            $file_name = $clean_name . "-" . $counter . "." . $ext;
            $counter++;
        }

        return $file_name;
    }

    // ─── MUSIC ────────────────────────────────────────────────────────────────

    public function processMusic(array $post, array $files, string $base_dir): array
    {
        GarbageCollector::run();
        $limit = $this->checkRateLimit('music');
        if (!$limit['allowed']) {
            return ['status' => 'error', 'msg' => "Batas upload tercapai! Tunggu {$limit['minutes']} menit lagi.", 'alert' => true];
        }

        // 🔒 FIX SECURITY: Batasi proses upload simultan
        if (!$this->checkActiveUploadLimit()) {
            return ['status' => 'error', 'msg' => "Terlalu banyak proses upload bersamaan. Coba lagi nanti.", 'alert' => true];
        }

        // 🟢 PRE-FLIGHT: Cek ruang disk HDD untuk music storage
        try {
            require_disk_space(500 * 1024 * 1024, $base_dir . 'upload/file/', 'storage musik HDD');
        } catch (\RuntimeException $e) {
            return ['status' => 'error', 'msg' => $e->getMessage(), 'alert' => true];
        }

        $title       = trim($post['title'] ?? '');
        $artist      = trim($post['artist'] ?? 'Unknown Artist');
        $album       = trim($post['album']  ?? 'Single');
        $description = trim($post['description'] ?? '');

        if (empty($files['media']['name'])) return ['status' => 'no_file'];

        $raw_filename = pathinfo($files['media']['name'], PATHINFO_FILENAME);
        $ext          = strtolower(pathinfo($files['media']['name'], PATHINFO_EXTENSION));
        $clean_name   = getRomajiName($raw_filename);

        $allowed_ext = ['mp3', 'opus', 'ogg', 'm4a', 'wav', 'flac'];
        if (!in_array($ext, $allowed_ext, true) || preg_match('/\.(php|phtml|sh)/i', $files['media']['name'])) {
            return ['status' => 'error', 'msg' => "Security Error / Format ditolak!"];
        }

        // 🔒 FIX SECURITY: Lock untuk serialisasi penamaan file — cegah TOCTOU race condition
        $lock_file = sys_get_temp_dir() . '/meel_music_upload.lock';
        $lock_fp   = @fopen($lock_file, 'c');
        $locked    = $lock_fp && flock($lock_fp, LOCK_EX);

        $file_name   = $this->getUniqueFilename($clean_name, $ext, $base_dir . "upload/file/");
        $target_file = $base_dir . "upload/file/" . $file_name;

        if ($locked) {
            flock($lock_fp, LOCK_UN);
            fclose($lock_fp);
        }

        if (!move_uploaded_file($files['media']['tmp_name'], $target_file)) {
            return ['status' => 'upload_failed'];
        }

        $max_size = ($this->user_role === 'admin') ? 200 * 1024 * 1024 : 50 * 1024 * 1024;
        if (filesize($target_file) > $max_size) {
            unlink($target_file);
            return ['status' => 'error', 'msg' => "File terlalu besar!", 'alert' => true];
        }

        $duration = $this->probeDuration($target_file);

        // 🔒 FIX SECURITY: Jika ffprobe gagal (duration 0 atau negatif), reject file
        if ($duration <= 0) {
            unlink($target_file);
            return ['status' => 'error', 'msg' => "Gagal memverifikasi durasi file. File mungkin korup atau tidak valid.", 'alert' => true];
        }

        $max_dur  = ($this->user_role === 'admin') ? 3600 : 300;
        if ($duration > $max_dur) {
            unlink($target_file);
            return ['status' => 'error', 'msg' => "Durasi maksimal 5 menit!", 'alert' => true];
        }

        $thumb_name    = "music_default.png";
        $thumb_base    = getRomajiName(pathinfo($file_name, PATHINFO_FILENAME));
        $thumb_dir     = $base_dir . "upload/thumbnail/";

        if (!empty($files['thumbnail']['name']) && !empty($files['thumbnail']['tmp_name'])) {
            // ── PRIORITAS 1: Cover art manual dari form upload ────────────────
            $t_clean         = getRomajiName(pathinfo($files['thumbnail']['name'], PATHINFO_FILENAME));
            $thumb_candidate = $this->getUniqueFilename($t_clean, "thumb.webp", $thumb_dir);
            $abs_out         = $thumb_dir . $thumb_candidate;

            shell_exec("export LD_LIBRARY_PATH=''; " . escapeshellarg($this->ffmpeg_bin) . " -y -i " . escapeshellarg($files['thumbnail']['tmp_name']) . " -vf \"scale='min(256,iw)':-1\" -c:v libwebp -q:v 78 " . escapeshellarg($abs_out) . " 2>&1");

            if (file_exists($abs_out) && filesize($abs_out) > 0) {
                $thumb_name = $thumb_candidate;
            }
        }

        if ($thumb_name === "music_default.png") {
            // ── PRIORITAS 2: Embedded thumbnail di dalam file audio (ID3/FLAC) ─
            // FFmpeg: -an abaikan audio, -vframes 1 ambil 1 frame video (= cover art)
            $thumb_candidate = $this->getUniqueFilename($thumb_base, "thumb.webp", $thumb_dir);
            $abs_out         = $thumb_dir . $thumb_candidate;

            shell_exec("export LD_LIBRARY_PATH=''; " . escapeshellarg($this->ffmpeg_bin) . " -y -i " . escapeshellarg($target_file) . " -an -vframes 1 -vf \"scale='min(256,iw)':-1\" -c:v libwebp -q:v 78 " . escapeshellarg($abs_out) . " 2>&1");

            if (file_exists($abs_out) && filesize($abs_out) > 0) {
                $thumb_name = $thumb_candidate;
            }
        }

        $skip_transcode = (isset($post['skip_transcode']) && $this->user_role === 'admin');
        if (!$skip_transcode) {
            // 🔒 FIX SECURITY: Gunakan flock agar dua proses tidak menulis ke file .ogg yang sama
            $opus_file = pathinfo($file_name, PATHINFO_FILENAME) . ".ogg";
            $opus_path = $base_dir . "upload/file/" . $opus_file;

            $lock_tc = @fopen(sys_get_temp_dir() . '/meel_music_transcode.lock', 'c');
            $tc_locked = $lock_tc && flock($lock_tc, LOCK_EX);

            exec("export LD_LIBRARY_PATH=''; " . escapeshellarg($this->ffmpeg_bin) . " -y -i " . escapeshellarg($target_file) . " -c:a libopus -vbr on -compression_level 10 " . escapeshellarg($opus_path), $out, $ret);

            if ($tc_locked) {
                flock($lock_tc, LOCK_UN);
                fclose($lock_tc);
            }

            if ($ret === 0 && file_exists($opus_path)) {
                unlink($target_file);
                $file_name = $opus_file;
            }
        }

        $meta = $this->generateMetadata($title, $artist, $album);

        // 🔒 TRANSACTION: Atomic DB insert — rollback jika gagal
        $this->conn->begin_transaction();
        try {
            $sql  = "INSERT INTO music (title, artist, description, search_metadata, album, filename, thumbnail, user_id, upload_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new \RuntimeException('Prepare gagal: ' . $this->conn->error);
            }
            $stmt->bind_param("sssssssi", $title, $artist, $description, $meta, $album, $file_name, $thumb_name, $this->user_id);
            if (!$stmt->execute()) {
                throw new \RuntimeException('Execute gagal: ' . $stmt->error);
            }
            $this->conn->commit();
            $stmt->close();
            return ['status' => 'success'];
        } catch (\Throwable $e) {
            $this->conn->rollback();
            // Bersihkan file yang sudah terlanjur dipindahkan
            $target_file = $base_dir . "upload/file/" . $file_name;
            if (file_exists($target_file)) @unlink($target_file);
            // Hapus thumbnail juga jika bukan default
            if ($thumb_name !== 'music_default.png') {
                $thumb_path = $base_dir . "upload/thumbnail/" . $thumb_name;
                if (file_exists($thumb_path)) @unlink($thumb_path);
            }
            return ['status' => 'error', 'msg' => "Database error! [" . $e->getMessage() . "]"];
        }
    }

    // ─── VIDEO ────────────────────────────────────────────────────────────────
    public function processVideo(array $post, array $files, string $upload_dir = ""): array
    {
        GarbageCollector::run();
        $limit = $this->checkRateLimit('video');
        if (!$limit['allowed']) {
            return ['status' => 'error', 'msg' => "Batas upload tercapai! Tunggu {$limit['minutes']} menit lagi.", 'alert' => true];
        }

        // 🔒 FIX SECURITY: Batasi proses upload simultan
        if (!$this->checkActiveUploadLimit()) {
            return ['status' => 'error', 'msg' => "Terlalu banyak proses upload bersamaan. Coba lagi nanti.", 'alert' => true];
        }

        // 🟢 PRE-FLIGHT: Cek ruang disk untuk video storage + RAM disk untuk staging
        try {
            // Minimal 1GB free di HDD video storage
            require_disk_space(1024 * 1024 * 1024, $this->base_dir . 'video/', 'storage video HDD');
            // Minimal 512MB free di RAM disk untuk staging HLS
            $shm_path = '/dev/shm';
            if (is_dir($shm_path) && is_writable($shm_path)) {
                require_disk_space(512 * 1024 * 1024, $shm_path, 'RAM disk (/dev/shm)');
            }
        } catch (\RuntimeException $e) {
            return ['status' => 'error', 'msg' => $e->getMessage(), 'alert' => true];
        }

        if (empty($files['video']['tmp_name']) || !is_uploaded_file($files['video']['tmp_name'])) {
            return ['status' => 'error', 'msg' => 'Tidak ada file video yang diterima.', 'alert' => true];
        }

        $title           = trim($post['title'] ?? 'Untitled Video');
        $temp_video      = $files['video']['tmp_name'];
        $video_name_orig = $files['video']['name'];

        // Validasi ekstensi
        $ext = strtolower(pathinfo($video_name_orig, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'webm', 'mkv'], true)) {
            return ['status' => 'error', 'msg' => "Format video tidak didukung! Gunakan MP4, WebM, atau MKV.", 'alert' => true];
        }

        // 🔒 FIX SECURITY: Validasi magic bytes — cegah file non-video lolos
        if (!$this->validateVideoMagicBytes($temp_video)) {
            return ['status' => 'error', 'msg' => "File tidak valid sebagai video (magic bytes mismatch).", 'alert' => true];
        }

        $raw_clean_name = pathinfo($video_name_orig, PATHINFO_FILENAME);
        $clean_name     = getRomajiName($raw_clean_name); // transliterasi ke romaji (dash-separated), konsisten dgn processMusic()
        $clean_name     = substr($clean_name, 0, 60);     // batasi panjang biar aman utk kolom DB
        $clean_name     = trim($clean_name, '-');         // jaga2 kalau substr motong di tengah, sisa dash di ujung dibuang
        if ($clean_name === '') $clean_name = 'video-' . time(); // fallback kalau nama jadi kosong

        // ── TENTUKAN NAMA FOLDER (cek konflik di HDD tujuan) ─────────────────
        // 🔒 FIX SECURITY: Gunakan flock() untuk serialisasi akses — cegah TOCTOU race condition
        $lock_file = sys_get_temp_dir() . '/meel_upload_video.lock';
        $lock_fp   = fopen($lock_file, 'c');
        if (!$lock_fp) {
            return ['status' => 'error', 'msg' => 'Gagal menginisialisasi lock file.', 'alert' => true];
        }
        flock($lock_fp, LOCK_EX);

        try {
            $folder_name   = $clean_name;
            $hdd_video_dir = $this->base_dir . "video/";
            $counter       = 1;

            while (is_dir($hdd_video_dir . $folder_name . "/")) {
                $folder_name = $clean_name . "-" . $counter;
                $counter++;
            }

            // ── DIREKTORI KERJA — PRIORITAS RAM DISK (/dev/shm) ────────────────
            $shm_path  = '/dev/shm';
            $use_shm   = false;
            if (is_dir($shm_path) && is_writable($shm_path)) {
                $free = @disk_free_space($shm_path);
                if ($free !== false && $free >= 512 * 1024 * 1024) {
                    $use_shm = true;
                }
            }

            $meel_base   = $use_shm ? ($shm_path . '/meel/upload') : (dirname(__DIR__) . '/temp');
            if (!is_dir($meel_base)) @mkdir($meel_base, 0755, true);
            $work_folder = $meel_base . '/' . $folder_name . '/';
            @mkdir($work_folder, 0755, true);
        } finally {
            flock($lock_fp, LOCK_UN);
            fclose($lock_fp);
        }

        // Stage file upload ke work_folder agar ekstensi tersedia untuk FFmpeg
        $staged_video = $work_folder . $clean_name . "_staged." . $ext;
        if (!copy($temp_video, $staged_video)) {
            @rmdir($work_folder);
            return ['status' => 'error', 'msg' => 'Gagal menyalin file upload ke staging area.', 'alert' => true];
        }

        // ── THUMBNAIL ─────────────────────────────────────────────────────────
        // PRIORITAS 1: Thumbnail yang diupload user
        // PRIORITAS 2: Auto-generate dari frame video (fallback)
        $thumb_name    = "default_thumb.webp";
        $thumb_dir     = $this->base_dir . "thumbnail/";
        $thumb_from_user = false;

        if (
            !empty($files['thumbnail']['tmp_name']) && is_uploaded_file($files['thumbnail']['tmp_name'])
            && $files['thumbnail']['error'] === UPLOAD_ERR_OK
        ) {
            // ── PRIORITAS 1: User upload thumbnail ───────────────────────────
            $t_name = $clean_name . "_thumb.webp";
            $t_dst  = $thumb_dir . $t_name;

            // Konversi ke WebP via FFmpeg — lebih kecil dari JPG, kualitas tetap terjaga
            $cmd_user_thumb = "export LD_LIBRARY_PATH=; " . escapeshellarg($this->ffmpeg_bin)
                . " -y -i " . escapeshellarg($files['thumbnail']['tmp_name'])
                . " -vf \"scale='min(1280,iw)':-1\" -c:v libwebp -q:v 78 "
                . escapeshellarg($t_dst) . " 2>&1";
            exec($cmd_user_thumb);

            if (file_exists($t_dst) && filesize($t_dst) > 0) {
                $thumb_name      = $t_name;
                $thumb_from_user = true;
            } elseif (move_uploaded_file($files['thumbnail']['tmp_name'], $t_dst)) {
                // Fallback: simpan apa adanya jika FFmpeg gagal convert
                $thumb_name      = $t_name;
                $thumb_from_user = true;
            }
        }

        if (!$thumb_from_user) {
            // ── PRIORITAS 2: Auto-generate dari frame video ───────────────────
            $thumb_name  = $clean_name . "_thumb.webp";
            $work_thumb  = $work_folder . $thumb_name;

            $cmd_thumb = "export LD_LIBRARY_PATH=; " . escapeshellarg($this->ffmpeg_bin) . " -y -i "
                . escapeshellarg($staged_video)
                . " -ss 00:00:05 -vframes 1 -vf \"scale='min(1280,iw)':-1\" -c:v libwebp -q:v 78 "
                . escapeshellarg($work_thumb) . " 2>&1";
            exec($cmd_thumb);

            // Fallback: ambil frame ke-1 kalau video < 5 detik
            if (!file_exists($work_thumb) || filesize($work_thumb) === 0) {
                $cmd_thumb_fallback = "export LD_LIBRARY_PATH=; " . escapeshellarg($this->ffmpeg_bin) . " -y -i "
                    . escapeshellarg($staged_video)
                    . " -ss 00:00:01 -vframes 1 -vf \"scale='min(1280,iw)':-1\" -c:v libwebp -q:v 78 "
                    . escapeshellarg($work_thumb) . " 2>&1";
                exec($cmd_thumb_fallback);
            }

            $thumb_generated = file_exists($work_thumb) && filesize($work_thumb) > 0;
            if (!$thumb_generated) {
                $thumb_name = "default_thumb.webp";
            }
        }

        // ── TRANSCODE KE HLS (output ke work_folder) ─────────────────────────
        $work_m3u8 = $work_folder . $folder_name . ".m3u8";
        $db_filename = "video/" . $folder_name . "/" . $folder_name . ".m3u8";

        $cmd = "export LD_LIBRARY_PATH=; " . escapeshellarg($this->ffmpeg_bin) . " -i " . escapeshellarg($staged_video)
            . " -codec copy"
            . " -start_number 0 -hls_time 20 -hls_list_size 0"
            . " -hls_segment_filename " . escapeshellarg($work_folder . $folder_name . "_%03d.ts")
            . " -f hls " . escapeshellarg($work_m3u8) . " 2>&1";

        exec($cmd, $output, $result);

        // Generate sprite & VTT ke work_folder
        if ($result === 0) {
            $this->generateSpriteAndVTT($staged_video, $work_folder);
        }

        // Hapus staged video setelah FFmpeg selesai
        @unlink($staged_video);

        if ($result !== 0) {
            // Bersihkan work_folder jika FFmpeg gagal
            foreach (glob($work_folder . "*") as $f) @unlink($f);
            @rmdir($work_folder);
            return ['status' => 'error', 'msg' => 'FFmpeg Error: ' . implode("\n", $output)];
        }

        // ── PINDAHKAN KE HDD ──────────────────────────────────────────────────
        // Semua file di work_folder sudah terbuat (.m3u8, .ts, sprite.jpg, .vtt)
        // Sekarang baru pindahkan ke direktori final di HDD.

        $hdd_target_folder = $hdd_video_dir . $folder_name . "/";
        $hdd_thumb_dir     = $this->base_dir . "thumbnail/";

        // 🔒 FIX SECURITY: Lock saat memindahkan file ke HDD — cegah race condition
        $lock_move = fopen(sys_get_temp_dir() . '/meel_move_hdd.lock', 'c');
        $move_locked = $lock_move && flock($lock_move, LOCK_EX);

        if (!is_dir($hdd_target_folder)) {
            mkdir($hdd_target_folder, 0755, true);
        }

        $move_failed = false;

        foreach (glob($work_folder . "*") as $work_file) {
            $filename = basename($work_file);

            // Thumbnail auto-generated dipindahkan ke folder thumbnail/ terpisah
            // Thumbnail dari user sudah langsung disimpan ke $thumb_dir, skip
            if (!$thumb_from_user && $filename === $thumb_name) {
                if (!rename($work_file, $hdd_thumb_dir . $filename)) {
                    $move_failed = true;
                    break;
                }
                continue;
            }

            // Semua file HLS (.m3u8, .ts, sprite, .vtt) ke folder video/
            if (!rename($work_file, $hdd_target_folder . $filename)) {
                $move_failed = true;
                break;
            }
        }

        // Bersihkan work_folder (seharusnya sudah kosong)
        if ($move_locked) {
            flock($lock_move, LOCK_UN);
            fclose($lock_move);
        }

        @rmdir($work_folder);

        if ($move_failed) {
            // Rollback: hapus file yang sudah terlanjur dipindahkan
            foreach (glob($hdd_target_folder . "*") as $f) @unlink($f);
            @rmdir($hdd_target_folder);
            // Hapus thumbnail (baik dari user maupun auto-generated)
            @unlink($hdd_thumb_dir . $thumb_name);
            return ['status' => 'error', 'msg' => 'Gagal memindahkan file ke storage. Cek permission HDD.', 'alert' => true];
        }

        // ── INSERT DATABASE (WITH TRANSACTION) ─────────────────────────────────
        $title       = trim($post['title'] ?? 'Untitled Video');
        // 1. Ambil data deskripsi dari form POST
        $description = trim($post['description'] ?? '');
        $meta        = $this->generateMetadata($title);

        // 🔒 TRANSACTION: Atomic DB insert — rollback + cleanup jika gagal
        $this->conn->begin_transaction();
        try {
            $stmt  = $this->conn->prepare(
                "INSERT INTO video (title, description, filename, thumbnail, search_metadata, user_id, upload_date)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );

            if (!$stmt) {
                throw new \RuntimeException('Prepare gagal: ' . $this->conn->error);
            }

            $stmt->bind_param("sssssi", $title, $description, $db_filename, $thumb_name, $meta, $this->user_id);

            if (!$stmt->execute()) {
                throw new \RuntimeException('Execute gagal: ' . $stmt->error);
            }

            $this->conn->commit();
            return ['status' => 'success'];
        } catch (\Throwable $e) {
            $this->conn->rollback();

            // Bersihkan semua file HLS yang sudah terlanjur dipindahkan ke HDD
            $hdd_target_folder = $hdd_video_dir . $folder_name . "/";
            if (is_dir($hdd_target_folder)) {
                foreach (glob($hdd_target_folder . "*") as $f) @unlink($f);
                @rmdir($hdd_target_folder);
            }
            // Hapus thumbnail (auto-generated atau dari user)
            $hdd_thumb_dir = $this->base_dir . "thumbnail/";
            @unlink($hdd_thumb_dir . $thumb_name);

            return ['status' => 'error', 'msg' => 'Database error! [' . $e->getMessage() . '] | title_len=' . strlen($title) . ' meta_len=' . strlen($meta) . ' filename=' . $db_filename];
        }
    }
    // ─── SPRITE & VTT disediakan oleh FfmpegUtils trait ───────────────────────
}
