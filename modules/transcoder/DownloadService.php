<?php

require_once __DIR__ . '/../core/TranscoderBase.php';

class DownloadService extends TranscoderBase
{
    private function lockQueue(string $url, string $type): int
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO upload_queue (url, media_type, user_id, status) VALUES (?, ?, ?, 'processing')"
        );
        $stmt->bind_param("ssi", $url, $type, $this->user_id);
        $stmt->execute();
        $id = (int)$this->conn->insert_id;
        $stmt->close();
        return $id;
    }

    private function releaseQueue(int $queue_id, string $status = 'completed'): void
    {
        $allowed = ['completed', 'failed'];
        $status  = in_array($status, $allowed, true) ? $status : 'failed';
        $stmt = $this->conn->prepare("UPDATE upload_queue SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $queue_id);
        $stmt->execute();
        $stmt->close();
    }

    

    private function ensureDownloadProxy(): string
    {
        if ($this->proxyArgs !== '') {
            return $this->proxyArgs;
        }
        $this->validatingProxy = new ValidatingProxy();
        $this->proxyArgs = '--proxy ' . escapeshellarg($this->validatingProxy->url()) . ' ';
        return $this->proxyArgs;
    }

    private function fetchMetadata(string $url, string $extraArgs = ''): ?array
    {
        
        
        
        try {
            (new SsrfGuard())->validate($url);
        } catch (\RuntimeException $e) {
            throw new DownloadException($e->getMessage(), $url, 'validation');
        }

        
        
        
        
        $proxyArgs = str_contains($extraArgs, '--proxy') ? '' : $this->ensureDownloadProxy();
        $cmd    = $this->base_cmd . $proxyArgs . $extraArgs . "--skip-download --print-json " . escapeshellarg($url) . " 2>&1";
        exec($cmd, $output_array, $return_var);
        $output = implode("\n", $output_array);

        
        $start = strpos($output, '{');
        $end   = strrpos($output, '}');

        if ($start !== false && $end !== false) {
            $json_string = substr($output, $start, ($end - $start) + 1);
            $data        = json_decode($json_string, true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($data)) {
                return $data;
            }
        }

        $detail = trim($output);
        $detail = preg_replace('/\s+/', ' ', $detail);
        $detail = mb_substr($detail, 0, 500);
        throw new ProcessException(
            "yt-dlp gagal mengambil metadata (exit $return_var): " . ($detail ?: '(no output)'),
            $cmd,
            $return_var,
            $output
        );
    }

    private function resolveVideoFormat(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        if (strpos($host, 'youtube.com') !== false || strpos($host, 'youtu.be') !== false) {

            return "bestvideo[height<=1080][vcodec^=avc1]+bestaudio[ext=m4a]/best[height<=1080][vcodec^=avc1]";
        }
        if (strpos($host, 'nicovideo.jp') !== false || strpos($host, 'nico.ms') !== false) {
            return "bestvideo[height<=1080]+bestaudio/best";
        }
        if (strpos($host, 'tiktok.com') !== false) {

            return "bestvideo+bestaudio/best";
        }
        return "bestvideo[height<=1080]+bestaudio/best";
    }

    public function processDownload(string $url, string $type): string
    {
        $url = trim($url);

        if (!in_array($type, ['video', 'music'], true)) {
            throw new DownloadException("Tipe media tidak valid.", $url, 'validation');
        }
        if (strlen($url) > 500) {
            throw new DownloadException("URL terlalu panjang.", $url, 'validation');
        }

        
        
        
        
        try {
            $ssrf = new SsrfGuard();
            $ssrf->validate($url);

            
            
            
            [$dl_url, $dl_extra] = $ssrf->pinHttpUrl($url);
        } catch (\RuntimeException $e) {
            throw new DownloadException("URL tidak diizinkan: " . $e->getMessage(), $url, 'validation');
        }

        
        
        
        
        
        
        try {
            $dl_extra = $this->ensureDownloadProxy() . $dl_extra;
        } catch (\RuntimeException $e) {
            throw new DownloadException(
                "Layanan keamanan download tidak tersedia. Coba lagi nanti.",
                $url,
                'proxy'
            );
        }

        require_disk_space(512 * 1024 * 1024, $this->getShmTempPath(), 'RAM disk staging');
        $hdd_path = defined('MEEL_HDD_BASE') ? MEEL_HDD_BASE : dirname(__DIR__, 2);
        require_disk_space(2 * 1024 * 1024 * 1024, $hdd_path, 'HDD storage');

        $queue_id = $this->lockQueue($url, $type);

        try {
            $meta = $this->fetchMetadata($dl_url, $dl_extra);
            if (!$meta) {
                throw new DownloadException("Gagal ambil metadata dari yt-dlp.", $url, 'metadata');
            }
        } catch (Throwable $e) {
            $this->releaseQueue($queue_id, 'failed');
            throw $e;
        }

        $title_candidates = array_values(array_filter(
            [$meta['title'] ?? '', $meta['fulltitle'] ?? '', $meta['alt_title'] ?? '', $meta['track'] ?? ''],
            fn($t) => $t !== '' && mb_substr(trim($t), -3) !== '...'
        ));
        usort($title_candidates, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $title       = $title_candidates[0] ?? $meta['title'] ?? "Upload_" . time();
        $artist      = $meta['artist']      ?? ($meta['uploader']         ?? 'Unknown Artist');
        $album       = $meta['album']                                     ?? 'Single';
        $duration    = (int)($meta['duration']                            ?? 0);
        $clean       = getRomajiName($title);
        $description = !empty($meta['description']) ? $meta['description'] : 'Upload by MEeL Engine';

        $shm_temp    = null;
        $temp_id     = null;
        $staging_dir = null;
        $basename    = null;

        if ($type === 'music') {
            $shm_temp  = $this->getShmTempPath();
            
            
            $temp_id   = "raw_" . time() . "_" . substr(md5(uniqid('', true)), 0, 4);
            $temp_path = "$shm_temp/$temp_id.%(ext)s";
            $cmd_dl    = $this->base_cmd . $dl_extra
                . "-f bestaudio -o " . escapeshellarg($temp_path)
                . " --write-thumbnail --embed-thumbnail"
                . " --newline " . escapeshellarg($dl_url) . " 2>&1";
        } else {

            $staging_dir = $this->getShmTempPath() . '/';
            $basename    = $clean;

            if (file_exists($staging_dir . $basename . ".mp4")) {
                $basename .= "-" . substr(md5(uniqid('', true)), -4);
            }

            $output_tpl = $staging_dir . $basename . ".%(ext)s";
            $format     = $this->resolveVideoFormat($url);

            $cmd_dl = $this->base_cmd . $dl_extra
                . "-f " . escapeshellarg($format)
                . " --merge-output-format mp4 -o " . escapeshellarg($output_tpl)
                . " --write-thumbnail --newline "
                . escapeshellarg($dl_url) . " 2>&1";
        }

        $this->emit('download_start', ['url' => $url]);

        $error_log = "";
        $start     = time();

        putenv('PATH=/usr/local/bin:/usr/bin:/bin');
        putenv('LC_ALL=en_US.UTF-8');
        $full_cmd = "exec setsid timeout " . self::DOWNLOAD_TIMEOUT
            . " sh -c " . escapeshellarg($cmd_dl);
        $dl_desc  = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $dl_proc  = proc_open($full_cmd, $dl_desc, $dl_pipes, null, null);

        if (!is_resource($dl_proc)) {
            $this->releaseQueue($queue_id, 'failed');
            $this->emit('error', ['message' => 'Gagal menjalankan yt-dlp. Cek permission atau install yt-dlp.']);
            return "";
        }
        fclose($dl_pipes[0]); 
        fclose($dl_pipes[2]); 

        $dl_status = proc_get_status($dl_proc);
        $dl_pgid   = (int)($dl_status['pid'] ?? 0);
        $dl_label  = ($type === 'music') ? ($temp_id ?? 'music') : ($basename ?? 'video');
        $this->trackChildProcess($dl_pgid, true, 'yt-dlp download (' . $dl_label . ')');
        $this->writePidFile('download', $queue_id, $dl_pgid);

        $frag_retry_abort  = false;
        $php_timeout_abort = false;
        $frag_total        = 0;
        $dl_out            = $dl_pipes[1];
        while (!feof($dl_out)) {
            if (time() - $start > self::DOWNLOAD_TIMEOUT) {
                $error_log .= "\n[ERROR] Timeout exceeded";
                $php_timeout_abort = true;
                break;
            }
            $line = fgets($dl_out);
            if ($line === false) break;

            $error_log .= $line;

            
            if (preg_match('/Retrying\s+fragment[s]?\b/i', $line)) {
                $frag_total++;
                if ($frag_total >= self::FRAGMENT_RETRY_LIMIT) {
                    $error_log .= "\n[ERROR] Fragment retry timeout (yt-dlp retrying repeatedly)";
                    $frag_retry_abort = true;
                    break;
                }
            }

            if (preg_match('/\[download\]\s+(\d+(?:\.\d+)?)%\s+of\s+([\d.]+\s*\S+)\s+at\s+([\d.]+\s*\S+\/s)(?:\s+ETA\s+([\d:]+))?(?:\s+\(frag\s+(\d+)\/(\d+)\))?/', $line, $m)) {
                $pct   = (int)$m[1];
                $size  = $m[2]  ?? '';
                $speed = $m[3]  ?? '';
                $eta   = isset($m[4]) ? 'ETA ' . $m[4] : '';
                $frag  = (isset($m[5], $m[6]) && $m[6]) ? $m[5] . ' / ' . $m[6] : '';
                $this->emit('download_progress', [
                    'pct'   => $pct,
                    'eta'   => $eta,
                    'speed' => $speed,
                    'size'  => $size,
                    'frag'  => $frag,
                ]);
            } elseif (preg_match('/\[download\]\s+(\d+(?:\.\d+)?)%/', $line, $m)) {
                $this->emit('download_progress', ['pct' => (int)$m[1]]);
            }
        }

        
        if ($frag_retry_abort || $php_timeout_abort) {
            $this->terminateChildProcess(
                $dl_pgid,
                'yt-dlp ' . ($frag_retry_abort ? 'fragment retry abort' : 'PHP timeout abort'),
                true
            );

            if ($type === 'music' && !empty($temp_id)) {
                foreach (glob($shm_temp . "/$temp_id.*") ?: [] as $f) {
                    $this->removeFile($f);
                }
            } elseif ($type === 'video' && !empty($basename)) {
                foreach (glob($staging_dir . $basename . ".*") ?: [] as $f) {
                    $this->removeFile($f);
                }
            }
        }

        fclose($dl_out);
        proc_close($dl_proc);
        $this->untrackChildProcess($dl_pgid);
        $this->removePidFile('download', $queue_id);

        $is_success = false;

        if ($type === 'music') {
            $files      = glob("$shm_temp/$temp_id.*");
            $is_success = !empty($files);
        } else {
            $expected   = $staging_dir . $basename . ".mp4";
            $is_success = file_exists($expected) && filesize($expected) > 0;
        }

        if (!$is_success) {
            $this->releaseQueue($queue_id, 'failed');
            file_put_contents('/tmp/ytdlp_error.log', $error_log);

            if ($frag_retry_abort) {

                $error_msg = "Timeout: yt-dlp gagal mengunduh fragment berulang kali (retry 1/10, 2/10, dst). "
                    . "Proses dihentikan otomatis. Coba lagi nanti atau gunakan URL lain.";
            } else {
                
                $lines = array_filter(explode("\n", $error_log), fn($l) => $l !== '');
                $lines = array_slice($lines, -10);
                $detail = trim(implode("\n", $lines));
                $error_msg = $detail !== ''
                    ? "Download gagal:\n" . $detail
                    : 'Download gagal tanpa detail error.';
            }

            $this->emit('error', ['message' => $error_msg]);
            return "";
        }

        $this->releaseQueue($queue_id, 'completed');

        if ($type === 'music') {
            return $this->finalizeMusic($temp_id, $title, $artist, $album, $duration, $description);
        }
        return $this->finalizeVideo($basename, $basename . ".webp", $title, $duration, $description);
    }

    private function finalizeMusic(
        string $temp_id,
        string $title,
        string $artist,
        string $album,
        int $duration,
        string $description = 'Upload by MEeL Engine'
    ): string {
        $found    = glob($this->getShmTempPath() . "/$temp_id.*");
        $raw_file = "";
        foreach ($found as $f) {
            $ext = pathinfo($f, PATHINFO_EXTENSION);
            if (!in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $raw_file = basename($f);
                break;
            }
        }

        if ($raw_file) {
            $meta_key = pathinfo($raw_file, PATHINFO_FILENAME);
            if (!isset($_SESSION['meel_pending_music']) || !is_array($_SESSION['meel_pending_music'])) {
                $_SESSION['meel_pending_music'] = [];
            }
            foreach ($_SESSION['meel_pending_music'] as $k => $v) {
                if (($v['ts'] ?? 0) < time() - 3600) unset($_SESSION['meel_pending_music'][$k]);
            }
            $_SESSION['meel_pending_music'][$meta_key] = [
                'ts'          => time(),
                'title'       => $title,
                'artist'      => $artist,
                'album'       => $album,
                'duration'    => $duration,
                'description' => $description,
            ];
            return 'ENCODE_MUSIC:' . $raw_file;
        }

        return "File audio tidak ditemukan setelah download.";
    }

    

    private function finalizeVideo(
        string $basename,
        string $db_thumb,
        string $title,
        int    $duration,
        string $description = 'Upload by MEeL Engine'
    ): string {
        $shm_temp    = $this->getShmTempPath();
        $staging_mp4 = "$shm_temp/{$basename}.mp4";

        $dl_thumb_src = null;
        foreach (glob("$shm_temp/{$basename}.*") as $f) {
            $ext = pathinfo($f, PATHINFO_EXTENSION);
            if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $dl_thumb_src = $f;
                break;
            }
        }

        if (!file_exists($staging_mp4)) {
            $this->emit('error', ['message' => "File MP4 staging tidak ditemukan: $staging_mp4"]);
            return "";
        }

        $this->emit('phase', ['phase' => 'transcode']);

        $flock_path = sys_get_temp_dir() . '/meel_transcode_folder.lock';
        $lock_fp    = fopen($flock_path, 'c');
        $locked     = $lock_fp !== false && flock($lock_fp, LOCK_EX);

        // Alokasi nama folder unik via helper bersama (dipanggil dalam lock).
        $folder_name = meel_allocate_unique_dir(MEEL_HDD_VIDEO_DIR, $basename);

        $db_filename = "video/{$folder_name}/{$folder_name}.m3u8";

        $shm_temp    = $this->getShmTempPath();
        $work_folder = "$shm_temp/{$folder_name}/";
        if (!is_dir($work_folder)) {
            $this->ensureDir($work_folder);
        }

        if ($locked) {
            flock($lock_fp, LOCK_UN);
            fclose($lock_fp);
        }

        $work_thumb = $work_folder . $db_thumb;
        if ($dl_thumb_src && file_exists($dl_thumb_src)) {
            // Kompres thumbnail hasil download via helper bersama (ffmpeg → webp).
            $thumb_ok = meel_ffmpeg_thumbnail_webp($this->ffmpeg_bin, $dl_thumb_src, $work_thumb, 1280, '', self::ENV_PREFIX, 1);
            if (!$thumb_ok) {
                copy($dl_thumb_src, $work_thumb); // fallback: salin mentah
            }
            $this->removeFile($dl_thumb_src);
        }

        $thumb_generated = file_exists($work_thumb) && filesize($work_thumb) > 0;
        if (!$thumb_generated) $db_thumb = "default_thumb.webp";

        $file_dur = $this->probeDuration($staging_mp4);

        
        $work_m3u8  = $work_folder . $folder_name . ".m3u8";

        $lib_path = '/usr/lib/x86_64-linux-gnu:/usr/local/lib';
        $hls_env = ['LD_LIBRARY_PATH' => $lib_path, 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'en_US.UTF-8'];
        $hls_cmd = [
            $this->ffmpeg_bin,
            '-threads',
            (string)self::FFMPEG_THREADS,
            '-i',
            $staging_mp4,
            '-codec',
            'copy',
            '-start_number',
            '0',
            '-hls_time',
            (string)self::HLS_SEGMENT_DURATION,
            '-hls_list_size',
            '0',
            '-hls_segment_filename',
            $work_folder . $folder_name . '_%03d.ts',
            '-f',
            'hls',
            $work_m3u8,
        ];

        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $hls_proc = proc_open($hls_cmd, $desc, $hls_pipes, null, $hls_env);
        if (!is_resource($hls_proc)) {
            $this->removeDir($work_folder);
            $this->emit('error', ['message' => 'Gagal menjalankan ffmpeg untuk transcode HLS. Cek instalasi ffmpeg.']);
            return "";
        }
        fclose($hls_pipes[0]); 
        fclose($hls_pipes[1]); 
        $hls_out = $hls_pipes[2]; 

        $hls_status = proc_get_status($hls_proc);
        $hls_pid    = (int)($hls_status['pid'] ?? 0);
        $this->trackChildProcess($hls_pid, false, 'ffmpeg HLS (' . $folder_name . ')');
        $this->writePidFile('transcode', 0, $hls_pid);

        stream_set_timeout($hls_out, 30);
        $hls_start = time();
        $hls_timeout = max(120, (int)($file_dur * 2)); 
        while (!feof($hls_out)) {
            if (time() - $hls_start > $hls_timeout) {
                error_log("[MEeL] finalizeVideo: HLS timeout setelah {$hls_timeout}s");
                $this->terminateChildProcess($hls_pid, 'ffmpeg HLS timeout', false);
                break;
            }
            $line = fgets($hls_out);
            if ($line === false) break;
            if (preg_match('/time=((\d+):(\d+):(\d+)\.(\d+))/', $line, $m) && $file_dur > 0) {
                $cur = ($m[2] * 3600) + ($m[3] * 60) + $m[4];
                $pct = min(99, round(($cur / $file_dur) * 100));
                $this->emit('transcode_progress', ['pct' => $pct]);
            }
        }
        fclose($hls_pipes[2]);
        proc_close($hls_proc);
        $this->untrackChildProcess($hls_pid);
        $this->removePidFile('transcode', 0);

        if (!file_exists($work_m3u8) || filesize($work_m3u8) === 0) {
            $this->removeDir($work_folder);
            $this->removeFile($staging_mp4);
            $this->emit('error', ['message' => 'Transcode HLS gagal. File .m3u8 tidak terbentuk.']);
            return "";
        }

        $this->emit('phase', ['phase' => 'sprite']);
        $this->emit('sprite_progress', ['pct' => 0, 'label' => 'Membuat thumbnail.vtt...']);

        $shm_base   = (is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir());
        $ram_folder = $shm_base . '/meel_sprite_' . uniqid() . '/';
        if (!is_dir($ram_folder)) {
            $this->ensureDir($ram_folder, 0777);
        }

        $this->generateSpriteAndVTT($staging_mp4, $ram_folder);

        $sprite_src = $ram_folder . 'thumb_sprite.webp';
        $vtt_src    = $ram_folder . 'thumbnails.vtt';

        if (file_exists($sprite_src)) {
            if (!$this->moveFile($sprite_src, $work_folder . 'thumb_sprite.webp')) {
                error_log("[MEeL] WARN: Gagal move thumb_sprite.webp dari RAM ke: $work_folder");
            }
        } else {
            error_log("[MEeL] WARN: thumb_sprite.webp tidak terbentuk di RAM: $ram_folder");
        }

        if (file_exists($vtt_src)) {
            if (!$this->moveFile($vtt_src, $work_folder . 'thumbnails.vtt')) {
                error_log("[MEeL] WARN: Gagal move thumbnails.vtt dari RAM ke: $work_folder");
            }
        } else {
            error_log("[MEeL] WARN: thumbnails.vtt tidak terbentuk di RAM: $ram_folder");
        }

        $this->removeDir($ram_folder);

        $this->emit('sprite_progress', ['pct' => 100, 'label' => 'Sprite & VTT selesai.']);

        $this->removeFile($staging_mp4);

        $hdd_target_folder = MEEL_HDD_VIDEO_DIR . $folder_name . "/";

        $this->conn->begin_transaction();
        try {
            if (!is_dir($hdd_target_folder)) {
                $this->ensureDir($hdd_target_folder);
            }
            if (!is_dir(MEEL_HDD_THUMB_DIR)) {
                $this->ensureDir(MEEL_HDD_THUMB_DIR);
            }

            foreach (glob($work_folder . "*") as $work_file) {
                $filename = basename($work_file);

                if ($thumb_generated && $filename === $db_thumb) {
                    $dest = MEEL_HDD_THUMB_DIR . $filename;
                } else {
                    $dest = $hdd_target_folder . $filename;
                }

                if (!$this->moveFile($work_file, $dest)) {
                    throw new \RuntimeException(
                        "Gagal memindahkan file ke storage: {$work_file} → {$dest}"
                    );
                }
            }

            $this->removeDir($work_folder);

            $hdd_m3u8_full  = MEEL_HDD_VIDEO_UPLOAD . $db_filename;
            $hdd_thumb_full = MEEL_HDD_THUMB_DIR . $db_thumb;

            if (!file_exists($hdd_m3u8_full) || filesize($hdd_m3u8_full) === 0) {
                throw new \RuntimeException("File M3U8 tidak ditemukan di HDD setelah dipindahkan: $hdd_m3u8_full");
            }
            if ($thumb_generated && (!file_exists($hdd_thumb_full) || filesize($hdd_thumb_full) === 0)) {
                throw new \RuntimeException("Thumbnail tidak ditemukan di HDD setelah dipindahkan: $hdd_thumb_full");
            }

            $metadata = generate_search_metadata($title);
            $views    = 0;

            $stmt = $this->conn->prepare(
                "INSERT INTO video (title, description, filename, thumbnail, duration, views, user_id, search_metadata, upload_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            if (!$stmt) {
                throw new \RuntimeException("Database prepare error: " . $this->conn->error);
            }
            $stmt->bind_param("ssssiiss", $title, $description, $db_filename, $db_thumb, $duration, $views, $this->user_id, $metadata);
            if (!$stmt->execute()) {
                throw new \RuntimeException("Database insert error: " . $stmt->error);
            }
            $stmt->close();

            $this->conn->commit();
        } catch (\Throwable $e) {

            $this->conn->rollback();

            $this->rollbackFinalizeVideo($hdd_target_folder, $db_thumb, $thumb_generated);
            $this->removeDir($work_folder);

            error_log("[MEeL] finalizeVideo GAGAL: " . $e->getMessage());
            $this->emit('error', ['message' => $e->getMessage()]);
            return "";
        }

        $this->emit('done', ['title' => $title, 'url' => 'index.php']);
        return "";
    }

    

    private function rollbackFinalizeVideo(
        string $hdd_target_folder,
        string $db_thumb,
        bool   $thumb_generated
    ): void {
        if (is_dir($hdd_target_folder)) {
            $this->removeDir($hdd_target_folder);
        }
        
        if ($thumb_generated && $db_thumb !== 'default_thumb.webp') {
            $this->removeFile(MEEL_HDD_THUMB_DIR . $db_thumb);
        }
    }

}
