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

    // ─── PRIVATE HELPERS ───

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

    private function validateVideoMagicBytes(string $filePath): bool
    {
        if (!is_file($filePath) || filesize($filePath) < 12) {
            return false;
        }

        $handle = fopen($filePath, 'rb');
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

    private function checkActiveUploadLimit(): bool
    {
        $lock_file    = sys_get_temp_dir() . '/meel_upload_counter.lock';
        $counter_file = sys_get_temp_dir() . '/meel_upload_count.dat';

        $fp = fopen($lock_file, 'c');
        if (!$fp) return true; // fallback: allow jika gagal lock
        flock($fp, LOCK_EX);

        if (file_exists($counter_file) && (time() - filemtime($counter_file)) > 300) {
            $this->removeFile($counter_file);
        }

        $current = (int)(is_file($counter_file) ? file_get_contents($counter_file) : 0);
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
            $fp2 = fopen($lock_file, 'c');
            if ($fp2) {
                flock($fp2, LOCK_EX);
                $count = max(0, (int)(is_file($counter_file) ? file_get_contents($counter_file) : 0) - 1);
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

    // ─── MUSIC ───

    public function processMusic(array $post, array $files, string $base_dir): array
    {
        GarbageCollector::run();
        $limit = $this->checkRateLimit('music');
        if (!$limit['allowed']) {
            return ['status' => 'error', 'msg' => "Batas upload tercapai! Tunggu {$limit['minutes']} menit lagi.", 'alert' => true];
        }

        // Batasi proses upload simultan
        if (!$this->checkActiveUploadLimit()) {
            return ['status' => 'error', 'msg' => "Terlalu banyak proses upload bersamaan. Coba lagi nanti.", 'alert' => true];
        }

        // PRE-FLIGHT: Cek ruang disk HDD untuk music storage
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

        $lock_file = sys_get_temp_dir() . '/meel_music_upload.lock';
        $lock_fp   = fopen($lock_file, 'c');
        $locked    = $lock_fp !== false && flock($lock_fp, LOCK_EX);

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

        // Jika ffprobe gagal (duration 0 atau negatif), reject file
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
            // ─── PRIORITAS 1: Cover art manual dari form upload ───
            $t_clean         = getRomajiName(pathinfo($files['thumbnail']['name'], PATHINFO_FILENAME));
            $thumb_candidate = $this->getUniqueFilename($t_clean, "thumb.webp", $thumb_dir);
            $abs_out         = $thumb_dir . $thumb_candidate;

            shell_exec("export LD_LIBRARY_PATH=''; " . escapeshellarg($this->ffmpeg_bin) . " -y -i " . escapeshellarg($files['thumbnail']['tmp_name']) . " -vf \"scale='min(256,iw)':-1\" -c:v libwebp -q:v 78 " . escapeshellarg($abs_out) . " 2>&1");

            if (file_exists($abs_out) && filesize($abs_out) > 0) {
                $thumb_name = $thumb_candidate;
            }
        }

        if ($thumb_name === "music_default.png") {
            // ─── PRIORITAS 2: Embedded thumbnail di dalam file audio (ID3/FLAC) ───
            $thumb_candidate = $this->getUniqueFilename($thumb_base, "thumb.webp", $thumb_dir);
            $abs_out         = $thumb_dir . $thumb_candidate;

            shell_exec("export LD_LIBRARY_PATH=''; " . escapeshellarg($this->ffmpeg_bin) . " -y -i " . escapeshellarg($target_file) . " -an -vframes 1 -vf \"scale='min(256,iw)':-1\" -c:v libwebp -q:v 78 " . escapeshellarg($abs_out) . " 2>&1");

            if (file_exists($abs_out) && filesize($abs_out) > 0) {
                $thumb_name = $thumb_candidate;
            }
        }

        $skip_transcode = (isset($post['skip_transcode']) && $this->user_role === 'admin');
        if (!$skip_transcode) {

            $opus_file = pathinfo($file_name, PATHINFO_FILENAME) . ".ogg";
            $opus_path = $base_dir . "upload/file/" . $opus_file;

            $lock_tc = fopen(sys_get_temp_dir() . '/meel_music_transcode.lock', 'c');
            $tc_locked = $lock_tc !== false && flock($lock_tc, LOCK_EX);

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

        $meta = generate_search_metadata($title, $artist, $album);

        // TRANSACTION: Atomic DB insert — rollback jika gagal
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
            $this->removeFile($target_file);
            // Hapus thumbnail juga jika bukan default
            if ($thumb_name !== 'music_default.png') {
                $thumb_path = $base_dir . "upload/thumbnail/" . $thumb_name;
                $this->removeFile($thumb_path);
            }
            return ['status' => 'error', 'msg' => "Database error! [" . $e->getMessage() . "]"];
        }
    }

    // ─── VIDEO ───
    public function processVideo(array $post, array $files, string $upload_dir = ""): array
    {
        GarbageCollector::run();
        $limit = $this->checkRateLimit('video');
        if (!$limit['allowed']) {
            return ['status' => 'error', 'msg' => "Batas upload tercapai! Tunggu {$limit['minutes']} menit lagi.", 'alert' => true];
        }

        // Batasi proses upload simultan
        if (!$this->checkActiveUploadLimit()) {
            return ['status' => 'error', 'msg' => "Terlalu banyak proses upload bersamaan. Coba lagi nanti.", 'alert' => true];
        }

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

        $ext = strtolower(pathinfo($video_name_orig, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'webm', 'mkv'], true)) {
            return ['status' => 'error', 'msg' => "Format video tidak didukung! Gunakan MP4, WebM, atau MKV.", 'alert' => true];
        }

        // Validasi magic bytes — cegah file non-video lolos
        if (!$this->validateVideoMagicBytes($temp_video)) {
            return ['status' => 'error', 'msg' => "File tidak valid sebagai video (magic bytes mismatch).", 'alert' => true];
        }

        $raw_clean_name = pathinfo($video_name_orig, PATHINFO_FILENAME);
        $clean_name     = getRomajiName($raw_clean_name);
        $clean_name     = substr($clean_name, 0, 60);     // batasi panjang biar aman utk kolom DB
        $clean_name     = trim($clean_name, '-');
        if ($clean_name === '') $clean_name = 'video-' . time(); // fallback kalau nama jadi kosong

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

            // ─── DIREKTORI KERJA — PRIORITAS RAM DISK (/dev/shm) ───
            $shm_path  = '/dev/shm';
            $use_shm   = false;
            if (is_dir($shm_path) && is_writable($shm_path)) {
                $free = disk_free_space($shm_path);
                if ($free !== false && $free >= 512 * 1024 * 1024) {
                    $use_shm = true;
                }
            }

            $meel_base   = $use_shm ? ($shm_path . '/meel/upload') : (dirname(__DIR__, 2) . '/temp');
            if (!is_dir($meel_base)) $this->ensureDir($meel_base);
            $work_folder = $meel_base . '/' . $folder_name . '/';
            $this->ensureDir($work_folder);
        } finally {
            flock($lock_fp, LOCK_UN);
            fclose($lock_fp);
        }

        $staged_video = $work_folder . $clean_name . "_staged." . $ext;
        if (!copy($temp_video, $staged_video)) {
            $this->removeDir($work_folder);
            return ['status' => 'error', 'msg' => 'Gagal menyalin file upload ke staging area.', 'alert' => true];
        }

        // ─── THUMBNAIL ───
        $thumb_name    = "default_thumb.webp";
        $thumb_dir     = $this->base_dir . "thumbnail/";
        $thumb_from_user = false;

        if (
            !empty($files['thumbnail']['tmp_name']) && is_uploaded_file($files['thumbnail']['tmp_name'])
            && $files['thumbnail']['error'] === UPLOAD_ERR_OK
        ) {
            // ─── PRIORITAS 1: User upload thumbnail ───
            $t_name = $clean_name . "_thumb.webp";
            $t_dst  = $thumb_dir . $t_name;

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
            // ─── PRIORITAS 2: Auto-generate dari frame video ───
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

        // ─── TRANSCODE KE HLS (output ke work_folder) ───
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

        // ─── SUBTITLE (OPSIONAL) ───
        if (
            $result === 0
            && !empty($files['subtitle']['tmp_name'])
            && is_uploaded_file($files['subtitle']['tmp_name'])
            && $files['subtitle']['error'] === UPLOAD_ERR_OK
        ) {
            $sub_ext    = strtolower(pathinfo($files['subtitle']['name'] ?? '', PATHINFO_EXTENSION));
            $sub_lang   = sanitize_subtitle_lang($post['subtitle_lang'] ?? 'id');
            $sub_allowed = ['vtt', 'srt'];

            if (in_array($sub_ext, $sub_allowed, true) && validate_subtitle_file($files['subtitle']['tmp_name'])) {
                $sub_content = (string)file_get_contents($files['subtitle']['tmp_name']);
                if ($sub_content !== '') {
                    if ($sub_ext === 'srt') {
                        $sub_content = convert_srt_to_vtt($sub_content);
                    }
                    $sub_content = strip_utf8_bom($sub_content); // WEBVTT harus jadi byte pertama
                    $sub_target  = $work_folder . $folder_name . '.' . $sub_lang . '.vtt';
                    if (file_put_contents($sub_target, $sub_content, LOCK_EX) === false) {
                        error_log("[MEeL] Gagal menulis subtitle ke work_folder: " . $sub_target);
                    }
                }
            } else {
                // Subtitle tidak valid — jangan gagalkan upload, hanya catat
                error_log("[MEeL] Subtitle ditolak (format/validasi): " . ($files['subtitle']['name'] ?? 'unknown'));
            }
        }

        // Hapus staged video setelah FFmpeg selesai
        $this->removeFile($staged_video);

        if ($result !== 0) {
            // Bersihkan work_folder jika FFmpeg gagal
            $this->removeDir($work_folder);
            return ['status' => 'error', 'msg' => 'FFmpeg Error: ' . implode("\n", $output)];
        }

        // ─── PINDAHKAN KE HDD ───
        $hdd_target_folder = $hdd_video_dir . $folder_name . "/";
        $hdd_thumb_dir     = $this->base_dir . "thumbnail/";

        // Lock saat memindahkan file ke HDD — cegah race condition
        $lock_move = fopen(sys_get_temp_dir() . '/meel_move_hdd.lock', 'c');
        $move_locked = $lock_move && flock($lock_move, LOCK_EX);

        if (!is_dir($hdd_target_folder)) {
            mkdir($hdd_target_folder, 0755, true);
        }

        $move_failed = false;

        foreach (glob($work_folder . "*") as $work_file) {
            $filename = basename($work_file);

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

        $this->removeDir($work_folder);

        if ($move_failed) {
            // Rollback: hapus file yang sudah terlanjur dipindahkan
            $this->removeDir($hdd_target_folder);
            // Hapus thumbnail (baik dari user maupun auto-generated)
            $this->removeFile($hdd_thumb_dir . $thumb_name);
            return ['status' => 'error', 'msg' => 'Gagal memindahkan file ke storage. Cek permission HDD.', 'alert' => true];
        }

        // ─── INSERT DATABASE (WITH TRANSACTION) ───
        $title       = trim($post['title'] ?? 'Untitled Video');
        $description = trim($post['description'] ?? '');
        $meta        = generate_search_metadata($title);

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

            $hdd_target_folder = $hdd_video_dir . $folder_name . "/";
            $this->removeDir($hdd_target_folder);
            // Hapus thumbnail (auto-generated atau dari user)
            $hdd_thumb_dir = $this->base_dir . "thumbnail/";
            $this->removeFile($hdd_thumb_dir . $thumb_name);

            return ['status' => 'error', 'msg' => 'Database error! [' . $e->getMessage() . '] | title_len=' . strlen($title) . ' meta_len=' . strlen($meta) . ' filename=' . $db_filename];
        }
    }
    // ─── SPRITE & VTT disediakan oleh FfmpegUtils trait ───
}
