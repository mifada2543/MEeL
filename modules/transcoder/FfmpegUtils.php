<?php
/**
 * FfmpegUtils — Trait berisi utility function yang shared antara Transcoder dan Uploader.
 *
 * Trait ini mengekstrak fungsi-fungsi yang identik atau hampir identik
 * dari kedua class untuk menghilangkan duplikasi kode.
 *
 * CATATAN: PHP 8.0 tidak mendukung constants di trait (min. PHP 8.2).
 * Gunakan method getEnvPrefix() untuk mengakses prefix environment variable.
 *
 * @package MEeL\Transcoder
 */

require_once __DIR__ . '/../core/helpers.php';

trait FfmpegUtils
{
    /**
     * Dapatkan ENV prefix untuk shell command.
     * Dipisahkan sebagai method karena PHP 8.0 tidak support trait constants.
     */
    protected function getEnvPrefix(): string
    {
        return "export LD_LIBRARY_PATH=''; export PATH=/usr/local/bin:/usr/bin:/bin; export LC_ALL=en_US.UTF-8; ";
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DURATION PROBE
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Dapatkan durasi media (detik) via ffprobe.
     *
     * @param string $file_path Path ke file media
     * @return float Durasi dalam detik
     */
    protected function probeDuration(string $file_path): float
    {
        $cmd = $this->getEnvPrefix() . escapeshellarg($this->ffprobe_bin)
            . " -v error -show_entries format=duration"
            . " -of default=noprint_wrappers=1:nokey=1 "
            . escapeshellarg($file_path);
        return (float)trim((string)shell_exec($cmd));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FILE SYSTEM HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Pindahkan file lintas filesystem (untuk USB HDD).
     * PHP rename() tidak bisa lintas device — wajib copy() + unlink().
     *
     * @param string $src Path sumber
     * @param string $dst Path tujuan
     * @return bool True jika sukses
     */
    protected function moveFile(string $src, string $dst): bool
    {
        // Coba rename dulu (cepat, jika sama filesystem)
        error_clear_last();
        if (@rename($src, $dst)) return true;

        // Tangkap alasan nyata kegagalan rename (EXDEV, ENOENT, EACCES, dll.)
        $rename_err = error_get_last();
        $rename_msg = is_array($rename_err) ? ($rename_err['message'] ?? 'unknown') : 'unknown';

        // Fallback: copy + unlink (untuk USB/cross-device)
        error_clear_last();
        if (@copy($src, $dst)) {
            @unlink($src);
            return true;
        }

        // Tangkap alasan nyata kegagalan copy — lebih berguna daripada pesan generik
        $copy_err = error_get_last();
        $copy_msg = is_array($copy_err) ? ($copy_err['message'] ?? 'unknown') : 'unknown';

        error_log(sprintf(
            '[MEeL] moveFile GAGAL: src=%s dst=%s | rename: %s | copy: %s',
            $src,
            $dst,
            $rename_msg,
            $copy_msg
        ));
        return false;
    }

    /**
     * Hapus semua isi direktori tanpa rekursif (flat directory).
     *
     * @param string $dir Path direktori
     */
    protected function cleanupDir(string $dir): void
    {
        foreach (glob(rtrim($dir, '/') . "/*") as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    /**
     * Sanitasi judul untuk dijadikan nama file.
     * Hapus karakter berbahaya, path separator, dan batasi panjang.
     *
     * @param string $title Judul yang akan disanitasi
     * @return string Nama file yang aman
     */
    protected function sanitizeFilename(string $title): string
    {
        $name = trim($title);
        if (empty($name)) {
            $name = 'untitled-media';
        }

        // Hapus karakter yang tidak aman untuk filesystem
        $name = preg_replace('/[\\\\/:*?"<>|\s]+/u', '-', $name);
        // Path traversal
        $name = str_replace(['..', './'], '', $name);
        // Batasi panjang
        $name = mb_substr($name, 0, 120);
        // Hindari nama file yang hanya terdiri dari delimiter
        $name = trim($name, "- \t\n\r\0\x0B");

        return $name ?: 'untitled-media';
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SPRITE & VTT GENERATOR
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Generate sprite thumbnail + VTT file dari video.
     *
     * @param string $video_path   Path ke file video sumber
     * @param string $target_folder Folder tujuan untuk sprite .webp dan .vtt
     */
    protected function generateSpriteAndVTT(string $video_path, string $target_folder): void
    {
        $w    = 160;  // Lebar per thumbnail
        $h    = 90;   // Tinggi per thumbnail (16:9)
        $cols = 5;    // Jumlah kolom dalam sprite

        $duration = $this->probeDuration($video_path);
        if ($duration <= 0) return;

        // Tentukan interval dinamis berdasarkan durasi
        if ($duration > 3600) {
            $interval = 300;   // > 1 jam   → tiap 5 menit
        } elseif ($duration > 1800) {
            $interval = 180;   // > 30 menit → tiap 3 menit
        } elseif ($duration > 300) {
            $interval = 60;    // > 5 menit  → tiap 1 menit
        } elseif ($duration > 0) {
            $interval = 10;    // ≤ 5 menit  → tiap 10 detik
        } else {
            $interval = 10;    // fallback jika durasi 0
        }

        $total_frames = (int)ceil($duration / $interval);
        $rows         = max(1, (int)ceil($total_frames / $cols));

        $sprite_file = $target_folder . 'thumb_sprite.webp';
        $vtt_file    = $target_folder . 'thumbnails.vtt';

        // Buat sprite — fps filter + scale + tile (CPU/software decode)
        $filter     = "fps=1/$interval,scale=$w:$h,tile={$cols}x{$rows}";
        $cmd_sprite = $this->getEnvPrefix() . escapeshellarg($this->ffmpeg_bin)
            . " -y -threads 8"
            . " -i " . escapeshellarg($video_path)
            . " -vf " . escapeshellarg($filter)
            . " -c:v libwebp -q:v 78 " . escapeshellarg($sprite_file) . " 2>&1";

        $ffmpeg_out = [];
        exec($cmd_sprite, $ffmpeg_out);

        if (!file_exists($sprite_file) || filesize($sprite_file) === 0) {
            error_log("[MEeL] ERROR: Sprite gagal. Output: " . implode(" | ", array_slice($ffmpeg_out, -10)));
            return;
        }

        // Tulis VTT
        $vtt_content = "WEBVTT\n\n";
        for ($i = 0; $i < $total_frames; $i++) {
            $start = $i * $interval;
            $end   = min(($i + 1) * $interval, $duration);

            $start_time = gmdate("H:i:s", (int)$start) . ".000";
            $end_time   = gmdate("H:i:s", (int)$end)   . ".000";

            $x = ($i % $cols) * $w;
            $y = (int)floor($i / $cols) * $h;

            $vtt_content .= "$start_time --> $end_time\n";
            $vtt_content .= "thumb_sprite.webp#xywh=$x,$y,$w,$h\n\n";
        }
        file_put_contents($vtt_file, $vtt_content);
    }
}
