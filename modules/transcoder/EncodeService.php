<?php

require_once __DIR__ . '/../core/TranscoderBase.php';

class EncodeService extends TranscoderBase
{
    public function encodeMusic(
        string $temp_file,
        string $title,
        string $artist,
        string $album,
        int    $duration,
        string $description = 'Upload by MEeL Engine'
    ): array {
        putenv("LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu:/usr/local/lib");
        putenv("PATH=/usr/local/bin:/usr/bin:/bin");

        $music_dir = meel_media_base_path('music') . '/file';
        if (!is_dir($music_dir)) {
            $this->ensureDir($music_dir);
        }
        $hdd_free = disk_free_space($music_dir);
        if ($hdd_free !== false && $hdd_free < 500 * 1024 * 1024) {
            return ['status' => 'error', 'msg' => 'Storage HDD untuk musik tidak mencukupi (hanya ' . sprintf('%.1f', $hdd_free / (1024 ** 3)) . ' GB free).'];
        }

        // Token validity: tolak path traversal/absolut — tapi file boleh sudah
        // tidak ada (request lain sudah memprosesnya → dedup di bawah).
        if ($temp_file === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $temp_file) || str_contains($temp_file, '..')) {
            $msg = "Token file sumber audio tidak valid.";
            error_log("[MEeL] encodeMusic: token input tidak valid ($temp_file)");
            return ['status' => 'error', 'msg' => $msg];
        }

        $shm_temp   = $this->getShmTempPath();
        $input_path = $shm_temp . '/' . $temp_file;

        $lock_path = sys_get_temp_dir() . '/meel_encode_' . md5($temp_file) . '.lock';
        $lock_fp   = fopen($lock_path, 'c');
        $lock_held = $lock_fp !== false && flock($lock_fp, LOCK_EX);

        try {
            if ($lock_held && !file_exists($input_path)) {
                $stmt_recent = $this->conn->prepare(
                    "SELECT filename FROM music
                     WHERE user_id = ? AND upload_date >= NOW() - INTERVAL 60 SECOND
                     ORDER BY id DESC LIMIT 1"
                );
                if ($stmt_recent) {
                    $stmt_recent->bind_param("i", $this->user_id);
                    $stmt_recent->execute();
                    $row = $stmt_recent->get_result()->fetch_assoc();
                    $stmt_recent->close();
                    if (!empty($row['filename'])) {
                        error_log("[MEeL] encodeMusic: input $temp_file sudah diproses request lain → dianggap sukses (file: {$row['filename']})");
                        return ['status' => 'success', 'filename' => $row['filename']];
                    }
                }
            }

            $clean = meel_sanitize_clean_name(getRomajiName($title), 120);
            if ($clean === '') {
                $clean = 'track';
            }

            // Alokasi nama final .ogg secara ATOMIK via helper bersama
            // (fopen 'x') — dua request dengan judul sama tidak saling menimpa.
            $final_fname = meel_reserve_unique_filename($music_dir, $clean, 'ogg');
            if ($final_fname === null) {
                // Folder penuh / tidak writable: fallback nama dengan timestamp.
                $final_fname = $clean . "-" . time() . ".ogg";
            }
            $final_path = $music_dir . "/$final_fname";

            $thumb_name = str_replace('.ogg', '.webp', $final_fname);

            // Encoding Opus via helper bersama (Uploader & EncodeService satu
            // jalur). Env sudah di-set via putenv() di atas → env_prefix '';
            // opsi -threads & metadata diteruskan seperti semula.
            $opus_result = meel_ffmpeg_encode_opus(
                $this->ffmpeg_bin,
                $input_path,
                $final_path,
                '',
                self::FFMPEG_THREADS,
                ['title' => $title, 'artist' => $artist]
            );
            $log = $opus_result[1];

            if (!file_exists($final_path) || filesize($final_path) === 0) {
                
                
                $friendly = "Gagal mengonversi audio. Silakan coba lagi nanti.";
                if (!file_exists($input_path)) {
                    $friendly = "File sumber audio tidak ditemukan. Media mungkin sudah diproses — periksa library Anda, atau coba upload ulang.";
                } elseif ($log) {
                    $lines = array_values(array_filter(array_map('trim', explode("\n", $log))));
                    foreach (array_reverse($lines) as $line) {
                        if (preg_match('/error|failed|invalid/i', $line)) {
                            $friendly = "Gagal mengonversi audio: " . substr($line, 0, 160);
                            break;
                        }
                    }
                }
                $this->removeFile($final_path); // hapus placeholder hasil fopen('x')
                error_log("[MEeL] encodeMusic GAGAL ($temp_file): " . substr(trim($log), 0, 800));
                return ['status' => 'error', 'msg' => $friendly];
            }

            $temp_base    = pathinfo($temp_file, PATHINFO_FILENAME);
            $temp_dir     = $this->getShmTempPath();
            $thumb_result = $this->extractMusicThumbnail($input_path, $temp_dir, $temp_base, $thumb_name);

            $this->removeFile($input_path);

            foreach (glob("$temp_dir/$temp_base.*") as $leftover) {
                $this->removeFile($leftover);
            }

            $metadata = generate_search_metadata($title, $artist, $album);

            // INSERT via helper bersama (Uploader & EncodeService satu jalur).
            $ins = meel_insert_music_row($this->conn, $this->user_id, $title, $artist, $album, $description, $metadata, $final_fname, $thumb_result, $duration);

            if ($ins[0]) {
                return ['status' => 'success', 'filename' => $final_fname];
            }
            return ['status' => 'error', 'msg' => 'Database error: ' . $ins[1]];
        } finally {
            if ($lock_held) {
                flock($lock_fp, LOCK_UN);
                fclose($lock_fp);
            }
        }
    }

    private function extractMusicThumbnail(
        string $audio_file,
        string $temp_dir,
        string $temp_base,
        string $target_name
    ): string {
        $thumb_dir = meel_media_base_path('music') . '/thumbnail';
        if (!is_dir($thumb_dir)) {
            $this->ensureDir($thumb_dir);
        }

        foreach (['.jpg', '.webp', '.png', '.jpeg'] as $ext) {
            $pattern = "$temp_dir/$temp_base$ext";
            if (file_exists($pattern) && filesize($pattern) > 0) {
                return $this->convertAndSaveThumbnail($pattern, $thumb_dir, $target_name);
            }
        }

        $extracted = $this->extractThumbnailFromAudio($audio_file, $thumb_dir, $target_name);
        if ($extracted !== 'music_default.png') return $extracted;

        return 'music_default.png';
    }

    private function convertAndSaveThumbnail(
        string $source_image,
        string $target_dir,
        string $target_name
    ): string {
        $target_path = "$target_dir/$target_name";
        $src_ext     = strtolower(pathinfo($source_image, PATHINFO_EXTENSION));

        if ($src_ext === 'webp') {
            if (copy($source_image, $target_path)) {
                $this->removeFile($source_image);
                return $target_name;
            }
        }

        // Konversi via helper bersama (ffmpeg → webp) — duplikasi inline dihapus.
        $ok = meel_ffmpeg_thumbnail_webp($this->ffmpeg_bin, $source_image, $target_path, 500, '', self::ENV_PREFIX, 1);

        if ($ok) {
            $this->removeFile($source_image);
            return $target_name;
        }

        if (copy($source_image, $target_path)) {
            $this->removeFile($source_image);
            return $target_name;
        }

        $this->removeFile($source_image);
        return 'music_default.png';
    }

    private function extractThumbnailFromAudio(
        string $audio_file,
        string $target_dir,
        string $target_name
    ): string {
        if (!file_exists($audio_file) || filesize($audio_file) === 0) {
            return 'music_default.png';
        }

        $temp_extracted = "$target_dir/.temp_thumb_" . time() . "_" . random_int(1000, 9999) . ".webp";

        // Ekstrak frame dari audio via helper bersama (ffmpeg → webp).
        $ok = meel_ffmpeg_thumbnail_webp($this->ffmpeg_bin, $audio_file, $temp_extracted, 500, '-an -vframes 1', self::ENV_PREFIX, 1);

        if ($ok && filesize($temp_extracted) > 1000) {
            $final_path = "$target_dir/$target_name";
            if (rename($temp_extracted, $final_path)) {
                return $target_name;
            }
            $this->removeFile($temp_extracted);
        }

        $this->removeFile($temp_extracted);
        return 'music_default.png';
    }
}
