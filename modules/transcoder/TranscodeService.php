<?php

require_once __DIR__ . '/../core/TranscoderBase.php';

class TranscodeService extends TranscoderBase
{
    public function transcodeVideo(int $video_id, string $format = 'mp3'): array
    {

        $output_dir = $this->getShmTranscodePath() . '/';
        if (!is_dir($output_dir)) {
            $this->ensureDir($output_dir);
        }

        $shm_free = disk_free_space($output_dir);
        if ($shm_free !== false && $shm_free < 256 * 1024 * 1024) {
            return ['status' => 'error', 'msg' => 'RAM disk tidak mencukupi untuk transcode. Hanya tersedia ' . sprintf('%.1f', $shm_free / (1024 ** 3)) . ' GB.'];
        }

        foreach (glob($output_dir . "*") as $file) {
            if (is_file($file) && time() - filemtime($file) >= 7200) {
                $this->removeFile($file);
            }
        }

        $stmt_clean = $this->conn->prepare(
            "DELETE FROM transcode_queue WHERE created_at < NOW() - INTERVAL 15 MINUTE"
        );
        $stmt_clean->execute();
        $stmt_clean->close();

        $stmt = $this->conn->prepare(
            "SELECT v.title, v.filename, v.thumbnail, v.description, v.upload_date, u.username
             FROM video v
             LEFT JOIN users u ON v.user_id = u.id
             WHERE v.id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $video_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();

        if (!$res || $res->num_rows === 0) {
            return ['status' => 'error', 'msg' => 'ID Video tidak ditemukan!'];
        }

        $v_data  = $res->fetch_assoc();
        $db_file = $v_data['filename'];

        $hls_base   = MEEL_HDD_VIDEO_UPLOAD;
        $m3u8_path  = $hls_base . $db_file;
        $hls_folder = dirname($m3u8_path) . "/";

        if (!file_exists($m3u8_path)) {
            return ['status' => 'error', 'msg' => "File HLS tidak ditemukan di: $m3u8_path"];
        }

        $ts_files = glob($hls_folder . "*.ts");
        if (empty($ts_files)) {
            return ['status' => 'error', 'msg' => 'File segmen HLS (.ts) tidak ditemukan!'];
        }
        natsort($ts_files);
        $ts_files = array_values($ts_files);

        $file_dur   = $this->probeDuration($m3u8_path);
        $total_size = array_sum(array_map('filesize', $ts_files));

        if ($this->user_role !== 'admin') {
            if ($total_size > 200 * 1024 * 1024 || $file_dur > 600) {
                $reasons = [];
                if ($total_size > 200 * 1024 * 1024) {
                    $reasons[] = 'ukuran ' . round($total_size / (1024 * 1024), 1) . 'MB (maks 200MB)';
                }
                if ($file_dur > 600) {
                    $reasons[] = 'durasi ' . round($file_dur / 60, 1) . ' menit (maks 10 menit)';
                }
                return [
                    'status' => 'error',
                    'msg' => 'File tidak memenuhi syarat: ' . implode(' dan ', $reasons) . '.'
                ];
            }
        }

        $output_filename = $this->sanitizeFilename($v_data['title']) . '.' . $format;
        $output_path     = $output_dir . $output_filename;

        $marker_file = $output_path . '.processing';

        $mtx_path  = sys_get_temp_dir() . '/meel_transcode_marker.lock';
        $mtx_fp    = fopen($mtx_path, 'c');
        $mtx_locked = $mtx_fp !== false && flock($mtx_fp, LOCK_EX);

        $cache_valid = false;
        if (file_exists($output_path)) {
            $cache_size = filesize($output_path);
            if ($cache_size > 10240) {
                
                $cache_dur = $this->probeDuration($output_path);
                if ($cache_dur > 0 && $file_dur > 0 && $cache_dur >= $file_dur * 0.5) {
                    $cache_valid = true;
                } elseif ($cache_dur <= 0 && $cache_size > 50000) {
                    
                    $cache_valid = true;
                }
            }
        }
        if ($cache_valid) {
            if ($mtx_locked) {
                flock($mtx_fp, LOCK_UN);
                fclose($mtx_fp);
            }
            $this->grantTranscodeOwnership($output_filename);
            $download_link = "api/download-transcode?file=" . rawurlencode($output_filename) . "&title=" . rawurlencode($v_data['title']);
            $this->emit('transcode_start');
            $this->emit('done_transcode', ['title' => $v_data['title'], 'download_link' => $download_link]);
            return ['status' => 'success', 'download_link' => $download_link, 'output_filename' => $output_filename, 'title' => $v_data['title']];
        }

        if (file_exists($output_path)) {
            $reason = filesize($output_path) <= 10240 ? 'too small (' . filesize($output_path) . ' bytes)' : 'duration mismatch';
            error_log("[MEeL] transcodeVideo: hapus cache invalid ($output_path, $reason)");
            $this->removeFile($output_path);
        }

        if (file_exists($marker_file)) {
            $marker_age = time() - filemtime($marker_file);
            if ($marker_age < 600) { 
                if ($mtx_locked) {
                    flock($mtx_fp, LOCK_UN);
                    fclose($mtx_fp);
                }
                return ['status' => 'error', 'msg' => 'Output sedang diproses oleh antrean lain. Tunggu beberapa saat.'];
            }
            }

        if (!touch($marker_file)) {
            error_log("[MEeL] Gagal membuat marker file: {$marker_file}");
        }

        if ($mtx_locked) {
            flock($mtx_fp, LOCK_UN);
            fclose($mtx_fp);
        }

        require_once __DIR__ . '/System.php';
        $sys = new System($this->conn);
        if ($sys->isServerBusy()) {
            return ['status' => 'error', 'msg' => 'Silahkan Menunggu. Server sedang sibuk memproses antrean lain.'];
        }

        $stmt_q = $this->conn->prepare(
            "INSERT INTO transcode_queue (user_id, status, created_at) VALUES (?, 'processing', NOW())"
        );
        $stmt_q->bind_param("i", $this->user_id);
        $stmt_q->execute();
        $queue_id = (int)$this->conn->insert_id;
        $stmt_q->close();

        $concat_list_path = $output_dir . "concat_{$video_id}_" . time() . ".txt";
        $concat_content   = "";
        foreach ($ts_files as $ts) {
            
            $safe_ts        = str_replace("'", "'\\''", $ts);
            $concat_content .= "file '$safe_ts'\n";
        }
        file_put_contents($concat_list_path, $concat_content);

        $thumb_path = $hls_base . "thumbnail/" . $v_data['thumbnail'];
        $use_thumb  = file_exists($thumb_path) && !empty($v_data['thumbnail']);

        switch ($format) {
            case 'ogg':
                $codec     = "libopus";
                $bitrate   = "-b:a 128k -vbr on";
                $use_thumb = false; 
                break;
            case 'm4a':
                $codec   = "copy"; 
                $bitrate = "";
                break;
            default: 
                $codec   = "libmp3lame";
                $bitrate = "-q:a 2";
                break;
        }

        $cmd = self::ENV_PREFIX . escapeshellarg($this->ffmpeg_bin)
            . " -y -threads " . self::FFMPEG_THREADS
            . " -f concat -safe 0 -i " . escapeshellarg($concat_list_path);
        if ($use_thumb) $cmd .= " -i " . escapeshellarg($thumb_path);
        $cmd .= " -map 0:a";
        if ($use_thumb) {
            $cmd .= " -map 1:v -c:v copy -disposition:v:0 attached_pic";
            if ($format === 'mp3') $cmd .= " -id3v2_version 3";
        }
        $meta_title  = $v_data['title'] ?? 'Untitled';
        $meta_artist = $v_data['username'] ?? 'MEeL Transcoder';
        $meta_album  = 'MEeL';
        $meta_date   = !empty($v_data['upload_date']) ? date('Y-m-d', strtotime($v_data['upload_date'])) : date('Y-m-d');
        $meta_comment = !empty($v_data['description']) ? mb_substr($v_data['description'], 0, 256) : 'Transcoded by MEeL';

        $cmd .= " -c:a $codec $bitrate"
            . " -metadata title="  . escapeshellarg($meta_title)
            . " -metadata artist=" . escapeshellarg($meta_artist)
            . " -metadata album="  . escapeshellarg($meta_album)
            . " -metadata date="   . escapeshellarg($meta_date)
            . " -metadata comment=" . escapeshellarg($meta_comment)
            . " -metadata album_artist=" . escapeshellarg('MEeL')
            . " " . escapeshellarg($output_path) . " 2>&1";

        $tc_env  = ['LD_LIBRARY_PATH' => self::FFMPEG_LIB_PATH, 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'en_US.UTF-8'];
        $tc_cmd  = [
            $this->ffmpeg_bin,
            '-y',
            '-threads',
            (string)self::FFMPEG_THREADS,
            '-f',
            'concat',
            '-safe',
            '0',
            '-i',
            $concat_list_path,
        ];
        if ($use_thumb) {
            $tc_cmd[] = '-i';
            $tc_cmd[] = $thumb_path;
        }
        $tc_cmd[] = '-map';
        $tc_cmd[] = '0:a';
        if ($use_thumb) {
            $tc_cmd[] = '-map';
            $tc_cmd[] = '1:v';
            
            
            
            
            
            
            
            $tc_cmd[] = '-c:v';
            $tc_cmd[] = 'mjpeg';
            $tc_cmd[] = '-disposition:v:0';
            $tc_cmd[] = 'attached_pic';
            if ($format === 'mp3') {
                $tc_cmd[] = '-id3v2_version';
                $tc_cmd[] = '3';
            }
        }
        $tc_cmd[] = '-c:a';
        $tc_cmd[] = $codec;
        if (!empty($bitrate)) {
            $parts = explode(' ', $bitrate);
            foreach ($parts as $p) $tc_cmd[] = $p;
        }
        $tc_cmd[] = '-metadata';
        $tc_cmd[] = "title=" . $meta_title;
        $tc_cmd[] = '-metadata';
        $tc_cmd[] = "artist=" . $meta_artist;
        $tc_cmd[] = '-metadata';
        $tc_cmd[] = "album=" . $meta_album;
        $tc_cmd[] = '-metadata';
        $tc_cmd[] = "date=" . $meta_date;
        $tc_cmd[] = '-metadata';
        $tc_cmd[] = "comment=" . $meta_comment;
        $tc_cmd[] = '-metadata';
        $tc_cmd[] = "album_artist=MEeL";
        $tc_cmd[] = $output_path;

        $this->emit('transcode_start');

        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $tc_proc = proc_open($tc_cmd, $desc, $tc_pipes, null, $tc_env);
        if (is_resource($tc_proc)) {
            fclose($tc_pipes[0]); 
            fclose($tc_pipes[1]); 
            $tc_out = $tc_pipes[2]; 

            $tc_status = proc_get_status($tc_proc);
            $tc_pid    = (int)($tc_status['pid'] ?? 0);
            $this->trackChildProcess($tc_pid, false, 'ffmpeg transcode audio (' . $output_filename . ')');
            $this->writePidFile('transcode', $queue_id, $tc_pid);

            stream_set_timeout($tc_out, 30); 
            $ffmpeg_stderr = [];
            $tc_start = time();
            while (!feof($tc_out)) {
                if (time() - $tc_start > self::TRANSCODE_AUDIO_TIMEOUT) {
                    error_log("[MEeL] transcodeVideo: timeout setelah " . self::TRANSCODE_AUDIO_TIMEOUT . "s");
                    $this->terminateChildProcess($tc_pid, 'ffmpeg transcode audio timeout', false);
                    break;
                }
                $line = fgets($tc_out);
                if ($line === false) break;
                $ffmpeg_stderr[] = $line;
                $fmt = strtoupper($format);
                if (preg_match('/time=((\d+):(\d+):(\d+)\.(\d+))/', $line, $m)) {
                    if ($file_dur > 0) {
                        $cur   = ($m[2] * 3600) + ($m[3] * 60) + $m[4];
                        $pct   = min(100, round(($cur / $file_dur) * 100));
                        $label = "$pct% — CONVERTING TO $fmt";
                        $this->emit('transcode_progress', ['pct' => $pct, 'label' => $label]);
                    } else {
                        
                        
                        
                        
                        
                        $elapsed = time() - $tc_start;
                        $pct     = min(95, (int)round(($elapsed / self::TRANSCODE_AUDIO_TIMEOUT) * 100));
                        $label   = "$pct% — CONVERTING TO $fmt (estimasi)";
                        $this->emit('transcode_progress', ['pct' => $pct, 'label' => $label]);
                    }
                }
            }
            fclose($tc_pipes[2]);

            
            $tc_exit = proc_close($tc_proc);
            $this->untrackChildProcess($tc_pid);
            $this->removePidFile('transcode', $queue_id);

            if ($tc_exit !== 0) {
                $tail = implode('', array_slice($ffmpeg_stderr, -15));
                error_log("[MEeL] transcodeVideo: ffmpeg exit=$tc_exit | $output_filename | stderr: $tail");
                $friendly_msg = 'FFmpeg gagal memproses audio (exit code: ' . $tc_exit . ').';
                $this->emit('error', ['message' => $friendly_msg]);
                $this->removeFile($output_path);
                $this->removeFile($concat_list_path);
                $this->removeFile($marker_file);
                $stmt_upd = $this->conn->prepare("UPDATE transcode_queue SET status = 'failed' WHERE id = ?");
                $stmt_upd->bind_param("i", $queue_id);
                $stmt_upd->execute();
                $stmt_upd->close();
                return ['status' => 'error', 'msg' => 'FFmpeg gagal memproses audio (exit code: ' . $tc_exit . ').'];
            }
        }

        $this->removeFile($concat_list_path);

        $stmt_upd = $this->conn->prepare(
            "UPDATE transcode_queue SET status = 'completed' WHERE id = ?"
        );
        $stmt_upd->bind_param("i", $queue_id);
        $stmt_upd->execute();
        $stmt_upd->close();

        $this->removeFile($marker_file);

        if (!file_exists($output_path) || filesize($output_path) === 0) {
            $this->emit('error', ['message' => 'FFmpeg gagal menghasilkan file output.']);
            return ['status' => 'error', 'msg' => 'FFmpeg gagal menghasilkan file.'];
        }

        $this->grantTranscodeOwnership($output_filename);
        $download_link = "api/download-transcode?file=" . rawurlencode($output_filename) . "&title=" . rawurlencode($v_data['title']);
        $this->emit('done_transcode', ['title' => $v_data['title'], 'download_link' => $download_link]);

        return [
            'status'          => 'success',
            'download_link'   => $download_link,
            'output_filename' => $output_filename,
            'title'           => $v_data['title'],
        ];
    }

    /**
     * Catat kepemilikan output transcode di sesi user (untuk download yang
     * aman — user lain tidak boleh menebak nama file transcode milik user ini).
     */
    private function grantTranscodeOwnership(string $outputFilename): void
    {
        if ($outputFilename === '' || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (!is_array($_SESSION['meel_transcode_files'] ?? null)) {
            $_SESSION['meel_transcode_files'] = [];
        }
        // Prune entri basi (> 4 jam) — file transcode juga dibersihkan ~2 jam.
        foreach ($_SESSION['meel_transcode_files'] as $f => $ts) {
            if ((int)$ts < time() - 4 * 3600) {
                unset($_SESSION['meel_transcode_files'][$f]);
            }
        }
        $_SESSION['meel_transcode_files'][$outputFilename] = time();
    }

    /**
     * Cek kepemilikan output transcode di sesi aktif user.
     */
    public static function ownsTranscodeFile(string $outputFilename): bool
    {
        if ($outputFilename === '' || session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $files = $_SESSION['meel_transcode_files'] ?? [];
        if (!is_array($files) || !isset($files[$outputFilename])) {
            return false;
        }
        // File transcode dibersihkan server setelah ~2 jam.
        if ((int)$files[$outputFilename] < time() - 4 * 3600) {
            unset($_SESSION['meel_transcode_files'][$outputFilename]);
            return false;
        }
        return true;
    }
}
