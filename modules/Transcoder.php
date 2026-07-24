<?php
// File: auth/Transcoder.php
// Optimized for: Intel Core i3-1220P (10 core / 12 thread), Dual-Channel RAM, USB HDD storage
// VA-API: Intel iHD 24.1.0 — H264/HEVC/VP9 encode+decode tersedia, tapi tidak dipakai di HLS
//         karena pipeline ini sudah pakai -codec copy (stream copy, tanpa re-encode)

// Pastikan konstanta path terdefinisi (dari auth/config.php)
if (!defined('MEEL_HDD_BASE')) {
    define('MEEL_HDD_BASE', '/path/to/your/media');
    define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
    define('MEEL_HDD_VIDEO_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'video/');
    define('MEEL_HDD_THUMB_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'thumbnail/');
    define('MEEL_HDD_MUSIC_UPLOAD', MEEL_HDD_BASE . '/music/upload/');
    define('MEEL_HDD_BOOKS_UPLOAD', MEEL_HDD_BASE . '/books/upload/');
    define('MEEL_HDD_DRIVE',        MEEL_HDD_BASE . '/drive/');
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/japanese.php';
require_once __DIR__ . '/GarbageCollector.php';
require_once __DIR__ . '/transcoder/FfmpegUtils.php';
require_once __DIR__ . '/exceptions/ProcessException.php';
require_once __DIR__ . '/exceptions/DownloadException.php';
require_once __DIR__ . '/exceptions/TranscodeException.php';

class Transcoder
{
    use FfmpegUtils;
    private \mysqli $conn;
    private int $user_id;
    private string $user_role;
    private string $base_path;
    private string $cookies_path;
    private string $user_agent;
    private string $base_cmd;
    private string $ffmpeg_bin;
    private string $ffprobe_bin;

    // ─── KONSTANTA HARDWARE ───────────────────────────────────────────────────
    private const FFMPEG_THREADS        = 8;

    // HLS: durasi tiap segment (detik)
    private const HLS_SEGMENT_DURATION  = 10;

    // Download timeout (detik)
    private const DOWNLOAD_TIMEOUT      = 900;

    // ─── ENV PREFIX ───────────────────────────────────────────────────────────
    // Didefinisikan di class (bukan trait) karena PHP 8.0 tidak support trait constants
    private const ENV_PREFIX = "export LD_LIBRARY_PATH=''; export PATH=/usr/local/bin:/usr/bin:/bin; export LC_ALL=en_US.UTF-8; ";

    public function __construct(\mysqli $db_connection, int $session_user_id)
    {
        $this->conn         = $db_connection;
        $this->user_id      = (int)$session_user_id;
        $this->base_path    = dirname(__DIR__);
        $this->cookies_path = $this->base_path . "/cookies.txt";
        $this->user_agent   = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36";
        $this->ffmpeg_bin   = $this->resolveBinary(['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg']);
        $this->ffprobe_bin  = $this->resolveBinary(['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', 'ffprobe']);

        $this->user_role = get_user_role($this->conn, $this->user_id);

        $this->base_cmd = "export PATH=/usr/local/bin:/usr/bin:/bin; export LC_ALL=en_US.UTF-8;"
            . " /usr/local/bin/yt-dlp --js-runtime node:/usr/bin/node"
            . " --remote-components ejs:github"
            . " --no-warnings --restrict-filenames"
            . " --user-agent "      . escapeshellarg($this->user_agent)
            . " --referer "         . escapeshellarg("https://www.youtube.com/")
            . " --cookies "         . escapeshellarg($this->cookies_path) . " ";

    }

    public function getUserRole(): string
    {
        return $this->user_role;
    }

    /**
     * Resolve path RAM disk (/dev/shm) dengan kriteria:
     * 1. Direktori /dev/shm ada
     * 2. Bisa ditulisi (writable)
     * 3. Ruang kosong minimal 500MB
     *
     * Struktur direktori di RAM:
     *   /dev/shm/meel/{subdir}
     *
     * Jika tidak memenuhi, fallback ke project temp/.
     *
     * @param string $subdir Subdirektori (misal: 'temp', 'upload', 'transcode')
     */
    private function resolveShmPath(string $subdir): string
    {
        // Bersihkan file sampah stale sebelum pakai temp directory
        GarbageCollector::run();

        static $resolved = [];
        if (!isset($resolved[$subdir])) {
            $shm_path = '/dev/shm';
            $use_shm  = false;

            if (is_dir($shm_path) && is_writable($shm_path)) {
                $free = @disk_free_space($shm_path);
                if ($free !== false && $free >= 512 * 1024 * 1024) {
                    $use_shm = true;
                }
            }

            $resolved[$subdir] = $use_shm
                ? "$shm_path/meel/$subdir"
                : dirname(__DIR__) . '/temp';

            if (!is_dir($resolved[$subdir])) {
                @mkdir($resolved[$subdir], 0755, true);
            }
        }
        return $resolved[$subdir];
    }

    /**
     * Dapatkan path untuk temp/staging upload/download, prioritas RAM disk.
     * Gunakan /dev/shm/meel/temp jika layak, fallback ke project temp/.
     */
    private function getShmTempPath(): string
    {
        return $this->resolveShmPath('temp');
    }

    /**
     * Dapatkan path untuk temp/staging transcode (ekstrak audio dari video), prioritas RAM disk.
     * Gunakan /dev/shm/meel/transcode jika layak, fallback ke project temp/.
     */
    private function getShmTranscodePath(): string
    {
        return $this->resolveShmPath('transcode');
    }

    /**
     * Dapatkan full path file transcode untuk didownload. Public agar bisa diakses
     * dari download proxy controller.
     */
    public function getTranscodeFilePath(string $filename): ?string
    {
        $path = $this->getShmTranscodePath() . '/' . $filename;
        return file_exists($path) ? $path : null;
    }

    // ─── BINARY RESOLVER ──────────────────────────────────────────────────────

    private function resolveBinary(array $candidates): string
    {
        return resolve_binary($candidates);
    }

    // ─── QUEUE MANAGEMENT ─────────────────────────────────────────────────────

    /**
     * @deprecated Gunakan System->getActiveQueues() atau System->isServerBusy()
     */
    public function checkServerBusy(): ?array
    {
        $res = $this->conn->query(
            "SELECT q.*, u.username FROM upload_queue q
             JOIN users u ON q.user_id = u.id
             WHERE q.status = 'processing' LIMIT 1"
        );
        return $res ? $res->fetch_assoc() : null;
    }

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

    // ─── METADATA ─────────────────────────────────────────────────────────────

    private function fetchMetadata(string $url): ?array
    {
        $cmd    = $this->base_cmd . "--skip-download --print-json " . escapeshellarg($url) . " 2>&1";
        exec($cmd, $output_array, $return_var);
        $output = implode("\n", $output_array);

        if ($return_var !== 0) {
            throw new ProcessException(
                "yt-dlp gagal mengambil metadata (exit code $return_var)",
                $cmd,
                $return_var,
                $output
            );
        }

        $start = strpos($output, '{');
        $end   = strrpos($output, '}');

        if ($start !== false && $end !== false) {
            $json_string = substr($output, $start, ($end - $start) + 1);
            $data        = json_decode($json_string, true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($data)) {
                return $data;
            }
        }

        // Catat error ke log server
        error_log("[MEeL-Transcoder] Gagal parsing metadata untuk URL: " . $url);
        throw new DownloadException(
            "Gagal parsing metadata dari yt-dlp",
            $url,
            'metadata'
        );
    }

    /**
     * Tampilkan informasi debug saat fetchMetadata gagal.
     * Pisahkan dari fetchMetadata agar mudah di-toggle atau di-log ke file.
     */
    private function renderMetadataDebug(string $url, string $cmd, int $return_var, string $output): void
    {
        // 🔒 SECURITY: Hanya admin yang boleh melihat info debug ini
        // Info ini mengekspos path absolut ffmpeg/ffprobe dan path sistem lainnya
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            error_log('[MEeL] Debug info attempted by non-admin');
            return;
        }

        $check_node  = trim((string)shell_exec("which node 2>/dev/null"));
        $check_ytdlp = trim((string)shell_exec("which yt-dlp 2>/dev/null"));

        echo "<div style='background:#1a1a1a;color:#ff4444;padding:20px;border:2px solid red;font-family:monospace;margin:20px;border-radius:10px;'>";
        echo "<h2 style='color:white;border-bottom:1px solid #333;'>⚠️ DEBUG: GAGAL PARSING METADATA</h2>";
        echo "<strong>URL:</strong> "        . htmlspecialchars($url)    . "<br><br>";
        echo "<strong>Perintah Shell:</strong><br>";
        echo "<code style='background:#000;color:#00ff00;padding:5px;display:block;margin:5px 0;'>" . htmlspecialchars($cmd) . "</code><br>";
        echo "<strong>Return Code:</strong> " . $return_var . " (0 = sukses)<br><br>";
        echo "<strong>Output yt-dlp:</strong>";
        echo "<pre style='background:#000;color:#ccc;padding:15px;overflow:auto;max-height:400px;border:1px solid #444;'>" . htmlspecialchars($output) . "</pre>";
        echo "<strong>Cek Path System:</strong><br>";
        echo "- Node:    " . ($check_node  ?: "<span style='color:yellow;'>Tidak ditemukan</span>") . "<br>";
        echo "- yt-dlp:  " . ($check_ytdlp ?: "<span style='color:yellow;'>Tidak ditemukan</span>") . "<br>";
        echo "- ffmpeg:  " . htmlspecialchars($this->ffmpeg_bin)  . "<br>";
        echo "- ffprobe: " . htmlspecialchars($this->ffprobe_bin) . "<br>";
        echo "</div>";

        die("--- PROSES DIHENTIKAN UNTUK DEBUG ---");
    }

    // ─── FORMAT RESOLVER ──────────────────────────────────────────────────────

    private function resolveVideoFormat(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        if (strpos($host, 'youtube.com') !== false || strpos($host, 'youtu.be') !== false) {
            // YouTube: preferensikan H.264 + AAC/M4A agar bisa stream-copy ke HLS tanpa re-encode
            return "bestvideo[height<=1080][vcodec^=avc1]+bestaudio[ext=m4a]/best[height<=1080][vcodec^=avc1]";
        }
        if (strpos($host, 'nicovideo.jp') !== false || strpos($host, 'nico.ms') !== false) {
            return "bestvideo[height<=1080]+bestaudio/best";
        }
        if (strpos($host, 'tiktok.com') !== false) {
            // [FIX] 'bestvideo1' bukan format string yang valid — diganti ke format standar
            return "bestvideo+bestaudio/best";
        }
        return "bestvideo[height<=1080]+bestaudio/best";
    }

    // ─── OVERLAY SYSTEM ───────────────────────────────────────────────────────

    private function showMEeLOverlay(string $initial_phase = 'download'): void
    {
        while (ob_get_level()) ob_end_clean();

        header('X-Accel-Buffering: no');
        header('Content-Encoding: none');

        $ui_file = $this->base_path . "/partials/ui.php";
        if (file_exists($ui_file)) include $ui_file;

        // Padding agar browser langsung flush dan render
        echo str_repeat(' ', 65536);
        echo "<script>meelPhase('" . htmlspecialchars($initial_phase) . "');</script>";
        flush();
    }

    // =========================================================
    // BAGIAN 1: DOWNLOAD & FINALISASI (processDownload)
    // =========================================================

    public function processDownload(string $url, string $type): string
    {
        // Validasi input
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $url)) {
            throw new DownloadException("URL tidak valid atau protokol tidak didukung.", $url, 'validation');
        }
        if (!in_array($type, ['video', 'music'], true)) {
            throw new DownloadException("Tipe media tidak valid.", $url, 'validation');
        }
        if (strlen($url) > 500) {
            throw new DownloadException("URL terlalu panjang.", $url, 'validation');
        }

        // 🟢 PRE-FLIGHT: Cek ruang disk sebelum download (queue belum di-lock)
        require_disk_space(512 * 1024 * 1024, $this->getShmTempPath(), 'RAM disk staging');
        $hdd_path = defined('MEEL_HDD_BASE') ? MEEL_HDD_BASE : dirname(__DIR__);
        require_disk_space(2 * 1024 * 1024 * 1024, $hdd_path, 'HDD storage');

        $queue_id = $this->lockQueue($url, $type);

        try {
            $meta = $this->fetchMetadata($url);
            if (!$meta) {
                throw new DownloadException("Gagal ambil metadata dari yt-dlp.", $url, 'metadata');
            }
        } catch (Throwable $e) {
            $this->releaseQueue($queue_id, 'failed');
            throw $e;
        }

        $title       = $meta['title']                                     ?? "Upload_" . time();
        $artist      = $meta['artist']      ?? ($meta['uploader']         ?? 'Unknown Artist');
        $album       = $meta['album']                                     ?? 'Single';
        $duration    = (int)($meta['duration']                            ?? 0);
        $clean       = getRomajiName($title);
        $description = !empty($meta['description']) ? $meta['description'] : 'Upload by MEeL Engine';

        // Siapkan perintah download sesuai tipe
        $shm_temp    = null;
        $temp_id     = null;
        $staging_dir = null;
        $basename    = null;

        if ($type === 'music') {
            $shm_temp  = $this->getShmTempPath();
            $temp_id   = "raw_" . time();
            $temp_path = "$shm_temp/$temp_id.%(ext)s";
            $cmd_dl    = $this->base_cmd
                . "-f bestaudio -o " . escapeshellarg($temp_path)
                . " --write-thumbnail --embed-thumbnail"
                . " --newline " . escapeshellarg($url) . " 2>&1";
        } else {
            // Download staging di RAM disk (/dev/shm) — lebih cepat untuk I/O yt-dlp
            $staging_dir = $this->getShmTempPath() . '/';
            $basename    = $clean;

            // Hindari konflik nama file di staging
            if (file_exists($staging_dir . $basename . ".mp4")) {
                $basename .= "-" . substr(md5(uniqid('', true)), -4);
            }

            $output_tpl = $staging_dir . $basename . ".%(ext)s";
            $format     = $this->resolveVideoFormat($url);

            $cmd_dl = $this->base_cmd
                . "-f " . escapeshellarg($format)
                . " --merge-output-format mp4 -o " . escapeshellarg($output_tpl)
                . " --write-thumbnail --newline "
                . escapeshellarg($url) . " 2>&1";
        }

        // Tampilkan overlay sebelum eksekusi
        $this->showMEeLOverlay('download');

        // Kirim URL ke overlay
        echo "<script>meelDlInfo(" . json_encode($url) . ");</script>";
        flush();

        $error_log = "";
        $start     = time();
        // Tambahkan -N 4 (4 koneksi paralel untuk mempercepat download, aman untuk server single-user)
        // 🔒 FIX SECURITY: Set env via putenv() agar popen() tidak perlu shell metacharacters
        putenv('PATH=/usr/local/bin:/usr/bin:/bin');
        putenv('LC_ALL=en_US.UTF-8');
        // $cmd_dl sudah berisi args yang di-escape dengan escapeshellarg() + filter_var() untuk URL
        $full_cmd  = "timeout " . self::DOWNLOAD_TIMEOUT . " $cmd_dl";
        $handle    = @popen($full_cmd, 'r');

        if (!$handle) {
            $this->releaseQueue($queue_id, 'failed');
            $this->jsError("Gagal menjalankan yt-dlp. Cek permission atau install yt-dlp.");
            return "";
        }

        echo str_repeat(' ', 1024);

        // Contoh output yt-dlp:
        // [download]  63.2% of   45.23MiB at    4.20MiB/s ETA 00:42 (frag 3/5)
        $frag_total = 0;
        while (!feof($handle)) {
            if (time() - $start > self::DOWNLOAD_TIMEOUT) {
                $error_log .= "\n[ERROR] Timeout exceeded";
                break;
            }
            $line = fgets($handle);
            if ($line === false) break;

            $error_log .= $line;

            if (preg_match('/\[download\]\s+(\d+(?:\.\d+)?)%\s+of\s+([\d.]+\s*\S+)\s+at\s+([\d.]+\s*\S+\/s)(?:\s+ETA\s+([\d:]+))?(?:\s+\(frag\s+(\d+)\/(\d+)\))?/', $line, $m)) {
                $pct   = (int)$m[1];
                $size  = $m[2]  ?? '';
                $speed = $m[3]  ?? '';
                $eta   = isset($m[4]) ? 'ETA ' . $m[4] : '';
                $frag  = (isset($m[5], $m[6]) && $m[6]) ? $m[5] . ' / ' . $m[6] : '';
                $args  = json_encode($pct) . ',' . json_encode($eta) . ',' . json_encode($speed) . ',' . json_encode($size) . ',' . json_encode($frag);
                echo "<script>meelDlPct($args);</script>";
                flush();
            } elseif (preg_match('/\[download\]\s+(\d+(?:\.\d+)?)%/', $line, $m)) {
                // Fallback: hanya persentase
                $pct  = (int)$m[1];
                echo "<script>meelDlPct($pct);</script>";
                flush();
            }
        }

        $exit_code = pclose($handle);

        // Validasi hasil download
        $is_success = false;
        // 🔴 BUG SEBELUMNYA: glob() pakai `{$this->base_path}/temp/` tapi yt-dlp simpan file di `$shm_temp` (/dev/shm/meel/temp/)
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

            $error_msg  = "Download gagal. Detail disimpan di server.";
            $last_lines = array_slice(explode("\n", $error_log), -3);
            $detail     = trim(implode(" | ", $last_lines));
            if ($detail) $error_msg = substr($detail, 0, 200);

            $this->jsError($error_msg);
            return "";
        }

        $this->releaseQueue($queue_id, 'completed');

        if ($type === 'music') {
            return $this->finalizeMusic($temp_id, $title, $artist, $album, $duration, $description);
        }
        return $this->finalizeVideo($basename, $basename . ".webp", $title, $artist, $duration, $description);
    }

    // ─── FINALIZE MUSIC ───────────────────────────────────────────────────────

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
            // Skip file gambar (thumbnail), cari file audio
            if (!in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $raw_file = basename($f);
                break;
            }
        }

        if ($raw_file) {
            $params = http_build_query([
                'temp_file'   => $raw_file,
                'title'       => $title,
                'artist'      => $artist,
                'album'       => $album,
                'duration'    => $duration,
                'description' => $description,
            ]);
            echo "<script>window.location.href = 'controllers/api/post_encode.php?$params';</script>";
            exit;
        }

        return $this->msgError("File audio tidak ditemukan setelah download.");
    }

    // ─── FINALIZE VIDEO (HLS) ─────────────────────────────────────────────────

    private function finalizeVideo(
        string $basename,
        string $db_thumb,
        string $title,
        string $artist,
        int    $duration,
        string $description = 'Upload by MEeL Engine'
    ): string {
        $shm_temp     = $this->getShmTempPath();
        $staging_mp4  = "$shm_temp/{$basename}.mp4";

        // Cari file thumbnail dari yt-dlp (format asli, biasanya .webp)
        $dl_thumb_src = null;
        foreach (glob("$shm_temp/{$basename}.*") as $f) {
            $ext = pathinfo($f, PATHINFO_EXTENSION);
            if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $dl_thumb_src = $f;
                break;
            }
        }

        if (!file_exists($staging_mp4)) {
            $this->jsError("File MP4 staging tidak ditemukan: $staging_mp4");
            return "";
        }

        echo "<script>meelPhase('transcode');</script>";
        flush();

        // ── Tentukan nama folder unik di HDD ──────────────────────────────────
        // 🔒 FIX SECURITY: Lock untuk serialisasi folder — cegah TOCTOU race condition
        $flock_path = sys_get_temp_dir() . '/meel_transcode_folder.lock';
        $lock_fp    = @fopen($flock_path, 'c');
        $locked     = $lock_fp && flock($lock_fp, LOCK_EX);

        $folder_name = $basename;
        $counter     = 1;
        while (is_dir(MEEL_HDD_VIDEO_DIR . $folder_name . "/")) {
            $folder_name = $basename . "-" . $counter;
            $counter++;
        }

        $db_filename = "video/{$folder_name}/{$folder_name}.m3u8";
        // HLS work folder di RAM disk — segmen .ts ditulis ke RAM, baru dipindah ke HDD
        $shm_temp    = $this->getShmTempPath();
        $work_folder = "$shm_temp/{$folder_name}/";
        if (!is_dir($work_folder)) mkdir($work_folder, 0755, true);

        if ($locked) {
            flock($lock_fp, LOCK_UN);
            fclose($lock_fp);
        }

        // ── Kompres thumbnail ke WebP ────────────────────────────────────────
        $work_thumb = $work_folder . $db_thumb;
        if ($dl_thumb_src && file_exists($dl_thumb_src)) {
            // Konversi ke WebP — scale ke max 1280px, quality 78 (balance ukuran vs kualitas)
            // WebP rata-rata 30-50% lebih kecil dari JPG setara
            $cmd_compress = self::ENV_PREFIX . escapeshellarg($this->ffmpeg_bin)
                . " -y -threads 1"
                . " -i " . escapeshellarg($dl_thumb_src)
                . " -vf " . escapeshellarg("scale='min(1280,iw)':-1")
                . " -c:v libwebp -q:v 78 " . escapeshellarg($work_thumb) . " 2>&1";
            shell_exec($cmd_compress);

            if (!file_exists($work_thumb) || filesize($work_thumb) === 0) {
                copy($dl_thumb_src, $work_thumb); // fallback: simpan asli sebagai .webp
            }
            @unlink($dl_thumb_src);
        }

        $thumb_generated = file_exists($work_thumb) && filesize($work_thumb) > 0;
        if (!$thumb_generated) $db_thumb = "default_thumb.webp";

        // ── Dapatkan durasi video ─────────────────────────────────────────────
        $file_dur = $this->probeDuration($staging_mp4);

        // ── Transcode ke HLS ──────────────────────────────────────────────────
        // -codec copy: stream copy (tidak re-encode), sehingga QSV tidak relevan.
        // -threads hanya berlaku untuk muxer/demuxer I/O; tetap set untuk konsistensi.
        $work_m3u8  = $work_folder . $folder_name . ".m3u8";
        // 🔒 FIX SECURITY: proc_open dengan array arguments + env vars — bypasses shell entirely
        $hls_env = ['LD_LIBRARY_PATH' => '', 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'en_US.UTF-8'];
        $hls_cmd = [
            $this->ffmpeg_bin,
            '-threads', (string)self::FFMPEG_THREADS,
            '-i', $staging_mp4,
            '-codec', 'copy',
            '-start_number', '0',
            '-hls_time', (string)self::HLS_SEGMENT_DURATION,
            '-hls_list_size', '0',
            '-hls_segment_filename', $work_folder . $folder_name . '_%03d.ts',
            '-f', 'hls',
            $work_m3u8,
        ];

        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $hls_proc = @proc_open($hls_cmd, $desc, $hls_pipes, null, $hls_env);
        if (!$hls_proc || !is_resource($hls_proc)) {
            $this->cleanupDir($work_folder);
            $this->jsError("Gagal menjalankan ffmpeg untuk transcode HLS. Cek instalasi ffmpeg.");
            return "";
        }
        @fclose($hls_pipes[0]); // stdin
        @fclose($hls_pipes[1]); // stdout — tidak dipakai (FFmpeg output progress ke stderr)
        $hls_out = $hls_pipes[2]; // stderr — FFmpeg tulis time=... di sini

        while (!feof($hls_out)) {
            $line = fgets($hls_out);
            if (preg_match('/time=((\d+):(\d+):(\d+)\.(\d+))/', $line, $m) && $file_dur > 0) {
                $cur = ($m[2] * 3600) + ($m[3] * 60) + $m[4];
                $pct = min(99, round(($cur / $file_dur) * 100));
                echo "<script>meelTcPct($pct);</script>";
                flush();
            }
        }
        @fclose($hls_pipes[2]);
        proc_close($hls_proc);

        if (!file_exists($work_m3u8) || filesize($work_m3u8) === 0) {
            $this->cleanupDir($work_folder);
            @unlink($staging_mp4);
            $this->jsError("Transcode HLS gagal. File .m3u8 tidak terbentuk.");
            return "";
        }

        // ── Sprite & VTT (DIOPTIMALKAN KE RAM DISK) ──────────────────────────
        echo "<script>meelPhase('sprite');meelSpPct(0,'Membuat thumbnail.vtt...');</script>";
        flush();

        // Buat folder kerja sementara di RAM Disk Linux (/dev/shm)
        // Fallback ke /tmp jika /dev/shm tidak tersedia atau tidak writable
        $shm_base   = (is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir());
        $ram_folder = $shm_base . '/meel_sprite_' . uniqid() . '/';
        if (!is_dir($ram_folder)) mkdir($ram_folder, 0777, true);

        // Jalankan pembuatan sprite di dalam RAM
        $this->generateSpriteAndVTT($staging_mp4, $ram_folder);

        // Pindahkan hasil dari RAM ke work_folder — catat ke error_log jika gagal
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

        // Bersihkan RAM setelah semua file berhasil dipindahkan
        $this->cleanupDir($ram_folder);

        echo "<script>meelSpPct(100,'Sprite & VTT selesai.');</script>";
        flush();

        @unlink($staging_mp4);

        // ── Pindahkan ke USB HDD ──────────────────────────────────────────────
        // Wajib pakai moveFile() karena USB HDD = filesystem berbeda dari /tmp/work
        // rename() cross-device akan selalu gagal diam-diam di Linux
        $hdd_target_folder = MEEL_HDD_VIDEO_DIR . $folder_name . "/";
        if (!is_dir($hdd_target_folder)) mkdir($hdd_target_folder, 0755, true);

        $move_failed = false;
        foreach (glob($work_folder . "*") as $work_file) {
            $filename = basename($work_file);

            if ($thumb_generated && $filename === $db_thumb) {
                $dest = MEEL_HDD_THUMB_DIR . $filename;
            } else {
                $dest = $hdd_target_folder . $filename;
            }

            if (!$this->moveFile($work_file, $dest)) {
                $move_failed = true;
                break;
            }
        }

        @rmdir($work_folder);

        if ($move_failed) {
            // Rollback: hapus file yang sudah terlanjur dipindahkan
            $this->cleanupDir($hdd_target_folder);
            @unlink(MEEL_HDD_THUMB_DIR . $db_thumb);
            $this->jsError("Gagal memindahkan file ke storage. Cek permission USB HDD.");
            return "";
        }

        // ── Simpan ke database ────────────────────────────────────────────────
        $hdd_m3u8_full  = MEEL_HDD_VIDEO_UPLOAD . $db_filename;
        $hdd_thumb_full = MEEL_HDD_THUMB_DIR . $db_thumb;

        if (!file_exists($hdd_m3u8_full) || filesize($hdd_m3u8_full) === 0) {
            $this->jsError("File M3U8 tidak ditemukan di HDD setelah dipindahkan: $hdd_m3u8_full");
            return "";
        }
        if ($thumb_generated && (!file_exists($hdd_thumb_full) || filesize($hdd_thumb_full) === 0)) {
            $this->jsError("Thumbnail tidak ditemukan di HDD setelah dipindahkan: $hdd_thumb_full");
            return "";
        }

        $analysis = analyzeJapaneseText($title); // 1x MeCab, hasilkan romaji + english sekaligus
        $metadata = mb_strtolower(trim("$title $artist {$analysis['romaji']} {$analysis['english']}"), 'UTF-8');
        $views    = 0;

        $stmt = $this->conn->prepare(
            "INSERT INTO video (title, description, filename, thumbnail, duration, views, user_id, search_metadata, upload_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        if (!$stmt) {
            $this->jsError("Database prepare error: " . $this->conn->error);
            return "";
        }
        $stmt->bind_param("ssssiiss", $title, $description, $db_filename, $db_thumb, $duration, $views, $this->user_id, $metadata);
        if (!$stmt->execute()) {
            $this->jsError("Database insert error: " . $stmt->error);
            return "";
        }
        $stmt->close();

        echo "<script>meelDone(" . json_encode($title) . ", 'index.php');</script>";
        flush();
        return "";
    }

    // =========================================================
    // BAGIAN 2: POST ENCODE (post_encode.php)
    // =========================================================

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

        // 🟢 PRE-FLIGHT: Cek HDD untuk output encode — butuh minimal 500MB
        $music_dir = "{$this->base_path}/music/upload/file";
        if (!is_dir($music_dir)) @mkdir($music_dir, 0755, true);
        $hdd_free = @disk_free_space($music_dir);
        if ($hdd_free !== false && $hdd_free < 500 * 1024 * 1024) {
            return ['status' => 'error', 'msg' => 'Storage HDD untuk musik tidak mencukupi (hanya ' . sprintf('%.1f', $hdd_free / (1024 ** 3)) . ' GB free).'];
        }

        $shm_temp  = $this->getShmTempPath();
        $input_path = "$shm_temp/$temp_file";
        $clean      = getRomajiName($title);

        // Cek konflik nama file
        $final_fname = $clean . ".ogg";
        $counter     = 1;
        while (file_exists("{$this->base_path}/music/upload/file/$final_fname")) {
            $final_fname = $clean . "-" . $counter . ".ogg";
            $counter++;
        }

        $final_path = "{$this->base_path}/music/upload/file/$final_fname";
        $thumb_name = str_replace('.ogg', '.webp', $final_fname);

        // Encode ke Opus/OGG
        // -compression_level 10: kualitas encoding terbaik (libopus default = 10, eksplisit untuk kejelasan)
        // -vbr on: Variable Bitrate, lebih efisien dari CBR
        // -threads: libopus adalah single-threaded per stream, tapi ffmpeg bisa paralel demuxer
        $cmd = escapeshellarg($this->ffmpeg_bin)
            . " -y -threads " . self::FFMPEG_THREADS
            . " -i "                 . escapeshellarg($input_path)
            . " -c:a libopus -vbr on -compression_level 10"
            . " -metadata title="    . escapeshellarg($title)
            . " -metadata artist="   . escapeshellarg($artist)
            . " " . escapeshellarg($final_path) . " 2>&1";
        $log = shell_exec($cmd);

        if (!file_exists($final_path) || filesize($final_path) === 0) {
            return ['status' => 'error', 'msg' => $log];
        }

        // Ekstrak thumbnail (3 strategi: yt-dlp file → audio metadata → default)
        $temp_base    = pathinfo($temp_file, PATHINFO_FILENAME);
        $temp_dir     = $this->getShmTempPath();
        $thumb_result = $this->extractMusicThumbnail($input_path, $temp_dir, $temp_base, $thumb_name);

        @unlink($input_path);

        // Bersihkan sisa file temporary dari yt-dlp
        foreach (glob("$temp_dir/$temp_base.*") as $leftover) {
            @unlink($leftover);
        }

        $title_analysis  = analyzeJapaneseText($title);  // 1x MeCab (romaji + english title)
        $artist_analysis = analyzeJapaneseText($artist); // 1x MeCab (romaji + english artist)
        $metadata        = mb_strtolower(trim(
            "$title $artist $album {$title_analysis['romaji']} {$artist_analysis['romaji']} "
                . "{$title_analysis['english']} {$artist_analysis['english']}"
        ), 'UTF-8');

        $stmt = $this->conn->prepare(
            "INSERT INTO music (title, artist, album, description, search_metadata, filename, thumbnail, duration, user_id, upload_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param("sssssssii", $title, $artist, $album, $description, $metadata, $final_fname, $thumb_result, $duration, $this->user_id);

        if ($stmt->execute()) {
            $stmt->close();
            return ['status' => 'success', 'filename' => $final_fname];
        }
        $err = $this->conn->error;
        $stmt->close();
        return ['status' => 'error', 'msg' => 'Database error: ' . $err];
    }

    // ─── THUMBNAIL HELPERS ────────────────────────────────────────────────────

    private function extractMusicThumbnail(
        string $audio_file,
        string $temp_dir,
        string $temp_base,
        string $target_name
    ): string {
        $thumb_dir = "{$this->base_path}/music/upload/thumbnail";
        if (!is_dir($thumb_dir)) mkdir($thumb_dir, 0755, true);

        // Strategi 1: Cari thumbnail dari yt-dlp
        foreach (['.jpg', '.webp', '.png', '.jpeg'] as $ext) {
            $pattern = "$temp_dir/$temp_base$ext";
            if (file_exists($pattern) && filesize($pattern) > 0) {
                return $this->convertAndSaveThumbnail($pattern, $thumb_dir, $target_name);
            }
        }

        // Strategi 2: Ekstrak dari ID3/VORBIS metadata audio
        $extracted = $this->extractThumbnailFromAudio($audio_file, $thumb_dir, $target_name);
        if ($extracted !== 'music_default.png') return $extracted;

        // Strategi 3: Gunakan default
        return 'music_default.png';
    }

    private function convertAndSaveThumbnail(
        string $source_image,
        string $target_dir,
        string $target_name
    ): string {
        $target_path = "$target_dir/$target_name";
        $src_ext     = strtolower(pathinfo($source_image, PATHINFO_EXTENSION));

        // Kalau sudah WebP, langsung copy (tidak perlu re-encode)
        if ($src_ext === 'webp') {
            if (copy($source_image, $target_path)) {
                @unlink($source_image);
                return $target_name;
            }
        }

        // Konversi ke WebP via ffmpeg — scale ke max 500px, -threads 1 cukup untuk gambar kecil
        // WebP quality 78 memberikan kualitas visual setara JPG ~85 dengan ukuran 30-50% lebih kecil
        $cmd = self::ENV_PREFIX . escapeshellarg($this->ffmpeg_bin)
            . " -y -threads 1"
            . " -i "  . escapeshellarg($source_image)
            . " -vf " . escapeshellarg("scale='min(500,iw)':-1")
            . " -c:v libwebp -q:v 78 " . escapeshellarg($target_path) . " 2>&1";
        @shell_exec($cmd);

        if (file_exists($target_path) && filesize($target_path) > 0) {
            @unlink($source_image);
            return $target_name;
        }

        // Fallback: copy original
        if (copy($source_image, $target_path)) {
            @unlink($source_image);
            return $target_name;
        }

        @unlink($source_image);
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

        // Ekstrak cover art dari metadata audio, simpan sebagai WebP
        $cmd = self::ENV_PREFIX . escapeshellarg($this->ffmpeg_bin)
            . " -y -threads 1"
            . " -i " . escapeshellarg($audio_file)
            . " -an -vframes 1"
            . " -vf " . escapeshellarg("scale='min(500,iw)':-1")
            . " -c:v libwebp -q:v 78 " . escapeshellarg($temp_extracted) . " 2>&1";
        @shell_exec($cmd);

        if (file_exists($temp_extracted) && filesize($temp_extracted) > 1000) {
            $final_path = "$target_dir/$target_name";
            if (rename($temp_extracted, $final_path)) {
                return $target_name;
            }
            @unlink($temp_extracted);
        }

        @unlink($temp_extracted);
        return 'music_default.png';
    }

    // =========================================================
    // BAGIAN 3: TRANSCODE VIDEO → AUDIO (transcode.php)
    // =========================================================

    public function checkTranscodeQueue(): bool
    {
        $res = $this->conn->query(
            "SELECT COUNT(*) as total FROM transcode_queue WHERE status = 'processing'"
        );
        return $res->fetch_assoc()['total'] >= 2;
    }

    public function transcodeVideo(int $video_id, string $format = 'mp3'): array
    {
        // Output hanya di RAM disk (primer) — fallback ke project temp/
        $output_dir = $this->getShmTranscodePath() . '/';
        if (!is_dir($output_dir)) mkdir($output_dir, 0755, true);

        // 🟢 PRE-FLIGHT: Cek RAM disk untuk output transcode — butuh minimal 256MB
        $shm_free = @disk_free_space($output_dir);
        if ($shm_free !== false && $shm_free < 256 * 1024 * 1024) {
            return ['status' => 'error', 'msg' => 'RAM disk tidak mencukupi untuk transcode. Hanya tersedia ' . sprintf('%.1f', $shm_free / (1024 ** 3)) . ' GB.'];
        }

        // Bersihkan file lama (> 2 jam)
        foreach (glob($output_dir . "*") as $file) {
            if (time() - filemtime($file) >= 7200) @unlink($file);
        }

        // Bersihkan antrean macet (> 15 menit) — gunakan prepared statement
        $stmt_clean = $this->conn->prepare(
            "DELETE FROM transcode_queue WHERE created_at < NOW() - INTERVAL 15 MINUTE"
        );
        $stmt_clean->execute();
        $stmt_clean->close();

        // Ambil data video
        $stmt = $this->conn->prepare(
            "SELECT title, filename, thumbnail FROM video WHERE id = ? LIMIT 1"
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

        // Validasi durasi & ukuran
        $file_dur   = $this->probeDuration($m3u8_path);
        $total_size = array_sum(array_map('filesize', $ts_files));

        if ($this->user_role !== 'admin') {
            if ($total_size > 200 * 1024 * 1024 || $file_dur > 600) {
                $reasons = [];
                if ($total_size > 200 * 1024 * 1024) {
                    $reasons[] = 'ukuran ' . round($total_size / (1024*1024), 1) . 'MB (maks 200MB)';
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

        // Cache / reuse output
        // Pakai judul asli dari database, disanitasi untuk filesystem
        $output_filename = $this->sanitizeFilename($v_data['title']) . '.' . $format;
        $output_path     = $output_dir . $output_filename;

        // 🔒 FIX SECURITY: Marker file — atomic check-then-create untuk cegah duplikat transcode
        // Pendekatan ini lebih baik dari flock() yang di-hold lama karena FFmpeg bisa memakan waktu menit.
        $marker_file = $output_path . '.processing';

        $mtx_path  = sys_get_temp_dir() . '/meel_transcode_marker.lock';
        $mtx_fp    = @fopen($mtx_path, 'c');
        $mtx_locked = $mtx_fp && flock($mtx_fp, LOCK_EX);

        if (file_exists($output_path) && filesize($output_path) > 0) {
            if ($mtx_locked) { flock($mtx_fp, LOCK_UN); fclose($mtx_fp); }
            $download_link = "controllers/api/download_transcode.php?file=" . rawurlencode($output_filename);
            echo "<script>meelDoneTranscode(" . json_encode($v_data['title']) . ", " . json_encode($download_link) . ");</script>";
            flush();
            return ['status' => 'success', 'download_link' => $download_link];
        }

        // Cek marker — jika ada, proses lain sedang mengerjakan output yang sama
        if (file_exists($marker_file)) {
            $marker_age = time() - filemtime($marker_file);
            if ($marker_age < 600) { // < 10 menit — masih wajar
                if ($mtx_locked) { flock($mtx_fp, LOCK_UN); fclose($mtx_fp); }
                return ['status' => 'error', 'msg' => 'Output sedang diproses oleh antrean lain. Tunggu beberapa saat.'];
            }
            // Marker > 10 menit (stale) — lanjutkan
        }

        // Buat marker file
        @touch($marker_file);

        if ($mtx_locked) {
            flock($mtx_fp, LOCK_UN);
            fclose($mtx_fp);
        }

        // Cek server busy
        require_once __DIR__ . '/System.php';
        $sys = new System($this->conn);
        if ($sys->isServerBusy()) {
            return ['status' => 'error', 'msg' => 'Silahkan Menunggu. Server sedang sibuk memproses antrean lain.'];
        }

        // Catat ke queue dengan prepared statement
        $stmt_q = $this->conn->prepare(
            "INSERT INTO transcode_queue (user_id, status, created_at) VALUES (?, 'processing', NOW())"
        );
        $stmt_q->bind_param("i", $this->user_id);
        $stmt_q->execute();
        $queue_id = (int)$this->conn->insert_id;
        $stmt_q->close();

        // Buat concat list
        $concat_list_path = $output_dir . "concat_{$video_id}_" . time() . ".txt";
        $concat_content   = "";
        foreach ($ts_files as $ts) {
            // Gunakan single quote yang di-escape untuk path ffmpeg concat
            $safe_ts        = str_replace("'", "'\\''", $ts);
            $concat_content .= "file '$safe_ts'\n";
        }
        file_put_contents($concat_list_path, $concat_content);

        $thumb_path = $hls_base . "thumbnail/" . $v_data['thumbnail'];
        $use_thumb  = file_exists($thumb_path) && !empty($v_data['thumbnail']);

        // Konfigurasi codec per format
        switch ($format) {
            case 'ogg':
                // libopus: single-threaded per stream, -threads tidak berdampak pada codec
                // tapi tetap set untuk ffmpeg I/O
                $codec     = "libopus";
                $bitrate   = "-b:a 128k -vbr on";
                $use_thumb = false; // OGG/Opus tidak support embedded picture
                break;
            case 'm4a':
                $codec   = "copy"; // Stream copy audio dari HLS AAC
                $bitrate = "";
                break;
            default: // mp3
                // libmp3lame mendukung multi-thread via -threads
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
        $cmd .= " -c:a $codec $bitrate"
            . " -metadata title="  . escapeshellarg($v_data['title'])
            . " -metadata artist='MEeL Transcoder'"
            . " " . escapeshellarg($output_path) . " 2>&1";

        // 🔒 FIX SECURITY: proc_open dengan array arguments + env vars — bypasses shell entirely
        // Daripada popen() yang melewati shell (rentan shell injection meski args sudah di-escape)
        $tc_env  = ['LD_LIBRARY_PATH' => '', 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'en_US.UTF-8'];
        $tc_cmd  = [$this->ffmpeg_bin,
            '-y', '-threads', (string)self::FFMPEG_THREADS,
            '-f', 'concat', '-safe', '0', '-i', $concat_list_path,
        ];
        if ($use_thumb) {
            $tc_cmd[] = '-i'; $tc_cmd[] = $thumb_path;
        }
        $tc_cmd[] = '-map'; $tc_cmd[] = '0:a';
        if ($use_thumb) {
            $tc_cmd[] = '-map'; $tc_cmd[] = '1:v';
            $tc_cmd[] = '-c:v'; $tc_cmd[] = 'copy';
            $tc_cmd[] = '-disposition:v:0'; $tc_cmd[] = 'attached_pic';
            if ($format === 'mp3') { $tc_cmd[] = '-id3v2_version'; $tc_cmd[] = '3'; }
        }
        $tc_cmd[] = '-c:a'; $tc_cmd[] = $codec;
        if (!empty($bitrate)) {
            $parts = explode(' ', $bitrate);
            foreach ($parts as $p) $tc_cmd[] = $p;
        }
        $tc_cmd[] = '-metadata'; $tc_cmd[] = "title=" . $v_data['title'];
        $tc_cmd[] = '-metadata'; $tc_cmd[] = "artist=MEeL Transcoder";
        $tc_cmd[] = $output_path;

        $this->showMEeLOverlay('transcode');

        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $tc_proc = @proc_open($tc_cmd, $desc, $tc_pipes, null, $tc_env);
        if ($tc_proc && is_resource($tc_proc)) {
            @fclose($tc_pipes[0]); // stdin
            @fclose($tc_pipes[1]); // stdout — tidak dipakai (FFmpeg output progress ke stderr)
            $tc_out = $tc_pipes[2]; // stderr — FFmpeg tulis time=... di sini
            while (!feof($tc_out)) {
                $line = fgets($tc_out);
                if (preg_match('/time=((\d+):(\d+):(\d+)\.(\d+))/', $line, $m) && $file_dur > 0) {
                    $cur   = ($m[2] * 3600) + ($m[3] * 60) + $m[4];
                    $pct   = min(100, round(($cur / $file_dur) * 100));
                    $fmt   = strtoupper($format);
                    $label = "$pct% — CONVERTING TO $fmt";
                    echo "<script>meelTcPct($pct, " . json_encode($label) . ");</script>";
                    flush();
                }
            }
            @fclose($tc_pipes[2]);
            proc_close($tc_proc);
        }

        @unlink($concat_list_path);

        // Update queue status via prepared statement (bukan raw query)
        $stmt_upd = $this->conn->prepare(
            "UPDATE transcode_queue SET status = 'completed' WHERE id = ?"
        );
        $stmt_upd->bind_param("i", $queue_id);
        $stmt_upd->execute();
        $stmt_upd->close();

        @unlink($marker_file); // Hapus marker setelah selesai

        if (!file_exists($output_path) || filesize($output_path) === 0) {
            $this->jsError("FFmpeg gagal menghasilkan file output.");
            return ['status' => 'error', 'msg' => 'FFmpeg gagal menghasilkan file.'];
        }

        $download_link = "controllers/api/download_transcode.php?file=" . rawurlencode($output_filename);
        echo "<script>meelDoneTranscode(" . json_encode($v_data['title']) . ", " . json_encode($download_link) . ");</script>";
        flush();

        return [
            'status'          => 'success',
            'download_link'   => $download_link,
            'output_filename' => $output_filename,
        ];
    }



    /**
     * Emit JavaScript error ke browser.
     */
    private function jsError(string $msg): void
    {
        echo "<script>meelError(" . json_encode($msg) . ");</script>";
        flush();
    }

    /**
     * Render pesan error HTML (untuk return value, bukan echo langsung).
     */
    private function msgError(string $msg): string
    {
        return "<div class='bg-red-500/10 text-red-500 p-4 rounded-xl border border-red-500/20 mb-6 font-bold text-sm'>✕ "
            . htmlspecialchars($msg) . "</div>";
    }
}
