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
    private string $user_role;
    private string $base_dir;
    private string $ffmpeg_bin;
    private string $ffprobe_bin;

    public function __construct(\mysqli $db_connection, int $session_user_id, string $session_username)
    {
        $this->conn      = $db_connection;
        $this->user_id   = (int)$session_user_id;
        $this->base_dir  = defined('MEEL_HDD_VIDEO_UPLOAD') ? MEEL_HDD_VIDEO_UPLOAD : "/path/to/your/media/video/upload/";
        $this->ffmpeg_bin  = resolve_binary(['/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg', 'ffmpeg']);
        $this->ffprobe_bin = resolve_binary(['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', 'ffprobe']);

        $this->user_role = get_user_role($this->conn, $this->user_id);
    }

    private function checkRateLimit(string $table): array
    {
        require_once __DIR__ . '/System.php';
        $sys = new System($this->conn);
        return $sys->checkRateLimit($this->user_id, $table, $this->user_role);
    }

    private function validateVideoMagicBytes(string $filePath): bool
    {
        // Satu sumber kebenaran magic-byte: meel_magic_extension_ok('video')
        // (MP4 ftyp / WebM-Matroska) — duplikasi inline dihapus.
        return meel_magic_extension_ok($filePath, 'mp4', 'video') === '';
    }

    private function checkActiveUploadLimit(): bool
    {
        $lock_file    = sys_get_temp_dir() . '/meel_upload_counter.lock';
        $counter_file = sys_get_temp_dir() . '/meel_upload_count.dat';

        $fp = fopen($lock_file, 'c');
        if (!$fp) return true;
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

    /**
     * Alokasi nama file secara ATOMIK (fopen 'x' via meel_reserve_unique_filename).
     * Dua request bersamaan tidak bisa mendapat nama yang sama.
     */
    private function getUniqueFilename(string $clean_name, string $ext, string $target_dir): string
    {
        $clean = meel_sanitize_clean_name($clean_name, 120);
        if ($clean === '') {
            $clean = 'file';
        }

        $reserved = meel_reserve_unique_filename($target_dir, $clean, $ext);
        if ($reserved === null) {
            throw new \RuntimeException('Gagal membuat nama file unik. Cek izin folder penyimpanan.');
        }
        return $reserved;
    }

    public function processMusic(array $post, array $files, string $base_dir): array
    {
        GarbageCollector::run();
        $limit = $this->checkRateLimit('music');
        if (!$limit['allowed']) {
            return ['status' => 'error', 'msg' => "Batas upload tercapai! Tunggu {$limit['minutes']} menit lagi.", 'alert' => true];
        }

        if (!$this->checkActiveUploadLimit()) {
            return ['status' => 'error', 'msg' => "Terlalu banyak proses upload bersamaan. Coba lagi nanti.", 'alert' => true];
        }

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
        if (!in_array($ext, $allowed_ext, true) || preg_match('/\.(php|phtml|sh)/i', $files['media']['name']) || str_contains($files['media']['name'], "\0")) {
            return ['status' => 'error', 'msg' => "Security Error / Format ditolak!"];
        }

        // Cek ukuran deklarasi sebelum menyentuh disk (TOCTOU guard).
        $max_size = ($this->user_role === 'admin') ? 200 * 1024 * 1024 : 50 * 1024 * 1024;
        if ((int)($files['media']['size'] ?? 0) > $max_size) {
            return ['status' => 'error', 'msg' => "File terlalu besar!", 'alert' => true];
        }

        // Magic bytes harus cocok dengan extension audio (server-side, bukan
        // $_FILES['type']).
        $magic_err = meel_magic_extension_ok($files['media']['tmp_name'], $ext, 'audio');
        if ($magic_err !== '') {
            return ['status' => 'error', 'msg' => "File tidak valid sebagai audio.", 'alert' => true];
        }

        // Alokasi nama atomik — tanpa lock eksternal & tanpa while(file_exists).
        $file_name   = $this->getUniqueFilename($clean_name, $ext, $base_dir . "upload/file/");
        $target_file = $base_dir . "upload/file/" . $file_name;

        if (!move_uploaded_file($files['media']['tmp_name'], $target_file)) {
            @unlink($target_file); // hapus placeholder reserve jika gagal
            return ['status' => 'upload_failed'];
        }

        if (filesize($target_file) > $max_size) {
            unlink($target_file);
            return ['status' => 'error', 'msg' => "File terlalu besar!", 'alert' => true];
        }

        $duration = $this->probeDuration($target_file);

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

        if (!empty($files['thumbnail']['name']) && !empty($files['thumbnail']['tmp_name']) && is_uploaded_file($files['thumbnail']['tmp_name']) && ($files['thumbnail']['error'] ?? -1) === UPLOAD_ERR_OK) {
            if (meel_magic_extension_ok($files['thumbnail']['tmp_name'], 'img', 'image') !== '') {
                error_log("[MEeL] Music upload: thumbnail user ditolak (bukan gambar): " . ($files['thumbnail']['name'] ?? 'unknown'));
            } else {
                $t_clean         = getRomajiName(pathinfo($files['thumbnail']['name'], PATHINFO_FILENAME));
                $thumb_candidate = $this->getUniqueFilename($t_clean, "thumb.webp", $thumb_dir);
                $abs_out         = $thumb_dir . $thumb_candidate;

                if (meel_ffmpeg_thumbnail_webp($this->ffmpeg_bin, $files['thumbnail']['tmp_name'], $abs_out, 256, '', $this->getEnvPrefix())) {
                    $thumb_name = $thumb_candidate;
                } else {
                    $this->removeFile($abs_out);
                }
            }
        }

        if ($thumb_name === "music_default.png") {
            $thumb_candidate = $this->getUniqueFilename($thumb_base, "thumb.webp", $thumb_dir);
            $abs_out         = $thumb_dir . $thumb_candidate;

            if (meel_ffmpeg_thumbnail_webp($this->ffmpeg_bin, $target_file, $abs_out, 256, '-an -vframes 1', $this->getEnvPrefix())) {
                $thumb_name = $thumb_candidate;
            } else {
                $this->removeFile($abs_out);
            }
        }

        $skip_transcode = (isset($post['skip_transcode']) && $this->user_role === 'admin');
        if (!$skip_transcode && strtolower(pathinfo($file_name, PATHINFO_EXTENSION)) !== 'ogg') {

            // Nama output .ogg juga dialokasikan secara atomik agar dua request
            // dengan judul sama tidak saling menimpa.
            $opus_base = pathinfo($file_name, PATHINFO_FILENAME);
            $opus_file = $this->getUniqueFilename($opus_base, 'ogg', $base_dir . "upload/file/");
            $opus_path = $base_dir . "upload/file/" . $opus_file;

            // Encoding Opus via helper bersama (Uploader & EncodeService satu jalur).
            $opus_result = meel_ffmpeg_encode_opus($this->ffmpeg_bin, $target_file, $opus_path, $this->getEnvPrefix());
            $ret = $opus_result[0];

            if ($ret === 0 && file_exists($opus_path) && filesize($opus_path) > 0) {
                unlink($target_file);
                $file_name = $opus_file;
            } else {
                $this->removeFile($opus_path);
            }
        }

        $meta = generate_search_metadata($title, $artist, $album);

        $this->conn->begin_transaction();
        try {
            // INSERT via helper bersama (Uploader & EncodeService satu jalur).
            // Uploader tidak menyimpan duration — perilaku lama dijaga (null).
            $ins = meel_insert_music_row($this->conn, $this->user_id, $title, $artist, $album, $description, $meta, $file_name, $thumb_name);
            if (!$ins[0]) {
                throw new \RuntimeException($ins[1]);
            }
            $this->conn->commit();
            return ['status' => 'success'];
        } catch (\Throwable $e) {
            $this->conn->rollback();
            $target_file = $base_dir . "upload/file/" . $file_name;
            $this->removeFile($target_file);
            if ($thumb_name !== 'music_default.png') {
                $thumb_path = $base_dir . "upload/thumbnail/" . $thumb_name;
                $this->removeFile($thumb_path);
            }
            return ['status' => 'error', 'msg' => "Database error! [" . $e->getMessage() . "]"];
        }
    }

    public function processVideo(array $post, array $files, string $upload_dir = ""): array
    {
        GarbageCollector::run();
        $limit = $this->checkRateLimit('video');
        if (!$limit['allowed']) {
            return ['status' => 'error', 'msg' => "Batas upload tercapai! Tunggu {$limit['minutes']} menit lagi.", 'alert' => true];
        }

        if (!$this->checkActiveUploadLimit()) {
            return ['status' => 'error', 'msg' => "Terlalu banyak proses upload bersamaan. Coba lagi nanti.", 'alert' => true];
        }

        try {
            require_disk_space(1024 * 1024 * 1024, $this->base_dir . 'video/', 'storage video HDD');
            
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

        if (!$this->validateVideoMagicBytes($temp_video)) {
            return ['status' => 'error', 'msg' => "File tidak valid sebagai video (magic bytes mismatch).", 'alert' => true];
        }

        $raw_clean_name = pathinfo($video_name_orig, PATHINFO_FILENAME);
        $clean_name     = getRomajiName($raw_clean_name);
        $clean_name     = substr($clean_name, 0, 60);
        $clean_name     = trim($clean_name, '-');
        if ($clean_name === '') $clean_name = 'video-' . time();

        $lock_file = sys_get_temp_dir() . '/meel_upload_video.lock';
        $lock_fp   = fopen($lock_file, 'c');
        if (!$lock_fp) {
            return ['status' => 'error', 'msg' => 'Gagal menginisialisasi lock file.', 'alert' => true];
        }
        flock($lock_fp, LOCK_EX);

        try {
            $hdd_video_dir = $this->base_dir . "video/";
            // Alokasi nama folder unik via helper bersama (dipanggil dalam lock).
            $folder_name = meel_allocate_unique_dir($hdd_video_dir, $clean_name);

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

        $thumb_name    = "default_thumb.webp";
        $thumb_dir     = $this->base_dir . "thumbnail/";
        $thumb_from_user = false;

        if (
            !empty($files['thumbnail']['tmp_name']) && is_uploaded_file($files['thumbnail']['tmp_name'])
            && $files['thumbnail']['error'] === UPLOAD_ERR_OK
        ) {
            // Hanya terima file thumbnail yang benar-benar gambar.
            if (meel_magic_extension_ok($files['thumbnail']['tmp_name'], 'img', 'image') !== '') {
                error_log("[MEeL] Video upload: thumbnail ditolak (bukan gambar): " . ($files['thumbnail']['name'] ?? 'unknown'));
            } else {
                $t_name = $clean_name . "_thumb.webp";
                $t_dst  = $thumb_dir . $t_name;

                if (meel_ffmpeg_thumbnail_webp($this->ffmpeg_bin, $files['thumbnail']['tmp_name'], $t_dst, 1280, '', $this->getEnvPrefix())) {
                    $thumb_name      = $t_name;
                    $thumb_from_user = true;
                } elseif (move_uploaded_file($files['thumbnail']['tmp_name'], $t_dst)) {
                    
                    $thumb_name      = $t_name;
                    $thumb_from_user = true;
                }
            }
        }

        if (!$thumb_from_user) {
            $thumb_name  = $clean_name . "_thumb.webp";
            $work_thumb  = $work_folder . $thumb_name;

            $thumb_generated = meel_ffmpeg_thumbnail_webp($this->ffmpeg_bin, $staged_video, $work_thumb, 1280, '-ss 00:00:05 -vframes 1', $this->getEnvPrefix());
            if (!$thumb_generated) {
                // Fallback frame di detik 1.
                $thumb_generated = meel_ffmpeg_thumbnail_webp($this->ffmpeg_bin, $staged_video, $work_thumb, 1280, '-ss 00:00:01 -vframes 1', $this->getEnvPrefix());
            }
            if (!$thumb_generated) {
                $thumb_name = "default_thumb.webp";
            }
        }

        
        $work_m3u8 = $work_folder . $folder_name . ".m3u8";
        $db_filename = "video/" . $folder_name . "/" . $folder_name . ".m3u8";

        $cmd = $this->getEnvPrefix() . escapeshellarg($this->ffmpeg_bin) . " -i " . escapeshellarg($staged_video)
            . " -codec copy"
            . " -start_number 0 -hls_time 20 -hls_list_size 0"
            . " -hls_segment_filename " . escapeshellarg($work_folder . $folder_name . "_%03d.ts")
            . " -f hls " . escapeshellarg($work_m3u8) . " 2>&1";

        exec($cmd, $output, $result);

        if ($result === 0) {
            $this->generateSpriteAndVTT($staged_video, $work_folder);
        }

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
                    $sub_content = strip_utf8_bom($sub_content);
                    $sub_target  = $work_folder . $folder_name . '.' . $sub_lang . '.vtt';
                    if (file_put_contents($sub_target, $sub_content, LOCK_EX) === false) {
                        error_log("[MEeL] Gagal menulis subtitle ke work_folder: " . $sub_target);
                    }
                }
            } else {
                error_log("[MEeL] Subtitle ditolak (format/validasi): " . ($files['subtitle']['name'] ?? 'unknown'));
            }
        }

        $this->removeFile($staged_video);

        if ($result !== 0) {
            
            $this->removeDir($work_folder);
            return ['status' => 'error', 'msg' => 'FFmpeg Error: ' . implode("\n", $output)];
        }

        $hdd_target_folder = $hdd_video_dir . $folder_name . "/";
        $hdd_thumb_dir     = $this->base_dir . "thumbnail/";

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

            
            if (!rename($work_file, $hdd_target_folder . $filename)) {
                $move_failed = true;
                break;
            }
        }

        if ($move_locked) {
            flock($lock_move, LOCK_UN);
            fclose($lock_move);
        }

        $this->removeDir($work_folder);

        if ($move_failed) {
            $this->removeDir($hdd_target_folder);
            $this->removeFile($hdd_thumb_dir . $thumb_name);
            return ['status' => 'error', 'msg' => 'Gagal memindahkan file ke storage. Cek permission HDD.', 'alert' => true];
        }

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
            $hdd_thumb_dir = $this->base_dir . "thumbnail/";
            $this->removeFile($hdd_thumb_dir . $thumb_name);

            return ['status' => 'error', 'msg' => 'Database error! [' . $e->getMessage() . '] | title_len=' . strlen($title) . ' meta_len=' . strlen($meta) . ' filename=' . $db_filename];
        }
    }
    
}
