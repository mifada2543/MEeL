<?php
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
require_once __DIR__ . '/../transcoder/FfmpegUtils.php';
require_once __DIR__ . '/../exceptions/ProcessException.php';
require_once __DIR__ . '/../exceptions/DownloadException.php';
require_once __DIR__ . '/../exceptions/TranscodeException.php';
require_once __DIR__ . '/ProgressObserver.php';
require_once __DIR__ . '/../auth/SsrfGuard.php';
require_once __DIR__ . '/../auth/ValidatingProxy.php';

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

    private ?ProgressObserver $progressObserver = null;

    /** Validating forward proxy (SSRF per redirect hop). Null sampai download pertama. */
    private ?ValidatingProxy $validatingProxy = null;
    /** Argumen yt-dlp untuk proxy (cache hasil spawn). */
    private string $proxyArgs = '';

    /* @var array<int, array{pid:int, group:bool, label:string, started:int}> */
    private array $childProcesses = [];

    private const FFMPEG_THREADS        = 8;

    // HLS: durasi tiap segment (detik)
    private const HLS_SEGMENT_DURATION  = 10;

    // Download timeout (detik)
    private const DOWNLOAD_TIMEOUT      = 900;

    // Ambang deteksi timeout fragment yt-dlp: berapa kali pesan
    // 1 = hentikan segera begitu pola retry fragment terdeteksi.
    private const FRAGMENT_RETRY_LIMIT  = 1;

    // PID file directory untuk cross-process kill (admin panel)
    private const PID_DIR = '/tmp/meel_pids';
    // Transcode audio timeout (detik) — mencegah loop tak berujung
    private const TRANSCODE_AUDIO_TIMEOUT = 600;

    // Shared library path untuk ffmpeg/ffprobe (diperlukan oleh proc_open)
    private const FFMPEG_LIB_PATH = '/usr/lib/x86_64-linux-gnu:/usr/local/lib';

    private const ENV_PREFIX = "export LD_LIBRARY_PATH='/usr/lib/x86_64-linux-gnu:/usr/local/lib'; export PATH=/usr/local/bin:/usr/bin:/bin; export LC_ALL=en_US.UTF-8; ";

    public function __construct(
        \mysqli $db_connection,
        int $session_user_id,
        callable|ProgressObserver|null $progressListener = null
    ) {
        $this->conn         = $db_connection;
        $this->user_id      = (int)$session_user_id;
        $this->base_path    = dirname(__DIR__, 2);
        $this->cookies_path = $this->base_path . "/cookies.txt";
        $this->user_agent   = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36";
        $this->ffmpeg_bin   = resolve_binary(['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg']);
        $this->ffprobe_bin  = resolve_binary(['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', 'ffprobe']);

        $this->user_role = get_user_role($this->conn, $this->user_id);

        $ytdlp_bin = defined('MEEL_YTDLP_PATH') && MEEL_YTDLP_PATH !== ''
            ? MEEL_YTDLP_PATH
            : resolve_binary(['/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp', 'yt-dlp']);
        $node_bin  = defined('MEEL_NODE_PATH') && MEEL_NODE_PATH !== ''
            ? MEEL_NODE_PATH
            : '/usr/bin/node';

        $this->base_cmd = "export PATH=/usr/local/bin:/usr/bin:/bin; export LC_ALL=en_US.UTF-8;"
            . " " . escapeshellarg($ytdlp_bin) . " --js-runtime " . escapeshellarg($node_bin)
            . " --remote-components ejs:github"
            . " --no-warnings --restrict-filenames"
            . " --user-agent "      . escapeshellarg($this->user_agent)
            . " --referer "         . escapeshellarg("https://www.youtube.com/")
            . " --cookies "         . escapeshellarg($this->cookies_path) . " ";

        $this->setProgressListener($progressListener);
    }

    /* @param callable(string $stage, array $data): void|ProgressObserver|null $listener */
    public function __destruct()
    {
        // Pastikan proxy (dan seluruh child process) ikut dimatikan saat
        // object Transcoder dibuang — tidak ada proses yatim yang tersisa.
        $this->validatingProxy?->stop();
        $this->terminateAllProcesses();
    }

    public function setProgressListener(callable|ProgressObserver|null $listener): void
    {
        if ($listener instanceof ProgressObserver) {
            $this->progressObserver = $listener;
        } elseif (is_callable($listener)) {
            $this->progressObserver = new CallableProgressObserver($listener);
        } else {
            $this->progressObserver = null;
        }
    }

    public function getUserRole(): string
    {
        return $this->user_role;
    }

    /* @param string $stage Nama stage (lihat ProgressObserver docblock); @param array<string, mixed> $data Payload event */
    private function emit(string $stage, array $data = []): void
    {
        try {
            $this->progressObserver?->onProgress($stage, $data);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[MEeL] ProgressObserver error pada stage "%s": %s',
                $stage,
                $e->getMessage()
            ));
        }
    }

    // PROCESS CONTROL (PID-BASED TERMINATION)
    /**
     * @param int $pid PID (atau PGID bila $processGroup true)
     * @param bool $processGroup True bila proses adalah session/group leader
     * @param string $label Label deskriptif untuk logging
     */
    private function trackChildProcess(int $pid, bool $processGroup, string $label): void
    {
        if ($pid > 0) {
            $this->childProcesses[] = [
                'pid'     => $pid,
                'group'   => $processGroup,
                'label'   => $label,
                'started' => time(),
            ];
        }
    }

    /* @param int $pid PID yang dicatat oleh trackChildProcess() */
    private function untrackChildProcess(int $pid): void
    {
        foreach ($this->childProcesses as $i => $proc) {
            if ($proc['pid'] === $pid) {
                unset($this->childProcesses[$i]);
            }
        }
        $this->childProcesses = array_values($this->childProcesses);
    }

    /* @param int $pid PID target; @param string $label Label untuk logging; @param bool $processGroup True = kill seluruh process group (-$pid) */
    private function terminateChildProcess(int $pid, string $label, bool $processGroup = false): void
    {
        if ($pid <= 0) {
            return;
        }

        $target   = $processGroup ? -$pid : $pid;
        $prefix   = $processGroup ? '-' : '';
        $termSent = false;

        if (function_exists('posix_kill')) {
            $termSent = posix_kill($target, SIGTERM);
        }
        if (!$termSent) {
            // bukan pkill -f dengan pencocokan string/regex.
            shell_exec('kill -TERM -- ' . $prefix . $pid . ' 2>/dev/null');
        }

        usleep(300000); // grace period 300ms

        if (function_exists('posix_kill')) {
            posix_kill($target, SIGKILL);
        } else {
            shell_exec('kill -KILL -- ' . $prefix . $pid . ' 2>/dev/null');
        }

        error_log(sprintf(
            '[MEeL] Terminated %s%s (%s)',
            $label,
            $processGroup ? ' process group' : '',
            $pid
        ));
    }

    public function terminateAllProcesses(): void
    {
        foreach ($this->childProcesses as $proc) {
            $this->terminateChildProcess($proc['pid'], $proc['label'], $proc['group']);
        }
        $this->childProcesses = [];
    }

    // PID FILE MANAGEMENT — cross-process kill (admin panel)

    /** Tulis PID ke file agar bisa di-kill dari proses lain (admin panel). */
    private function writePidFile(string $taskType, int $queueId, int $pid): void
    {
        $dir = self::PID_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = "$dir/{$taskType}_{$queueId}.pid";
        @file_put_contents($path, (string)$pid);
    }

    /** Hapus PID file setelah proses selesai. */
    private function removePidFile(string $taskType, int $queueId): void
    {
        $path = self::PID_DIR . "/{$taskType}_{$queueId}.pid";
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Kill proses berdasarkan PID file (dipanggil dari admin panel / System.php).
     * @return bool true jika proses ditemukan dan di-kill
     */
    public static function killByPidFile(string $taskType, int $queueId): bool
    {
        $path = self::PID_DIR . "/{$taskType}_{$queueId}.pid";
        if (!file_exists($path)) {
            return false;
        }
        $pid = (int)@file_get_contents($path);
        @unlink($path);

        if ($pid <= 0) {
            return false;
        }

        $killed = false;
        if (function_exists('posix_kill')) {
            $killed = posix_kill($pid, SIGTERM);
        }
        if (!$killed) {
            @shell_exec('kill -TERM ' . $pid . ' 2>/dev/null');
        }

        usleep(500000); // 500ms grace period

        if (function_exists('posix_kill')) {
            @posix_kill($pid, SIGKILL);
        } else {
            @shell_exec('kill -KILL ' . $pid . ' 2>/dev/null');
        }

        error_log("[MEeL] killByPidFile: killed {$taskType}#{$queueId} (PID {$pid})");
        return true;
    }

    /**
     * Bersihkan semua PID file stale (> 30 menit).
     * Dipanggil dari GarbageCollector atau periodic cleanup.
     */
    public static function cleanupStalePidFiles(): int
    {
        $dir = self::PID_DIR;
        if (!is_dir($dir)) {
            return 0;
        }
        $cleaned = 0;
        foreach (glob("$dir/*.pid") ?: [] as $file) {
            if (time() - @filemtime($file) > 1800) {
                @unlink($file);
                $cleaned++;
            }
        }
        return $cleaned;
    }

    /* @param string $subdir Subdirektori (misal: 'temp', 'upload', 'transcode') */
    private function resolveShmPath(string $subdir): string
    {
        GarbageCollector::run();

        static $resolved = [];
        if (!isset($resolved[$subdir])) {
            $shm_path = '/dev/shm';
            $use_shm  = false;

            if (is_dir($shm_path) && is_writable($shm_path)) {
                $free = disk_free_space($shm_path);
                if ($free !== false && $free >= 512 * 1024 * 1024) {
                    $use_shm = true;
                }
            }

            $resolved[$subdir] = $use_shm
                ? "$shm_path/meel/$subdir"
                : dirname(__DIR__, 2) . '/temp/' . $subdir;

            if (!is_dir($resolved[$subdir])) {
                $this->ensureDir($resolved[$subdir]);
            }
        }
        return $resolved[$subdir];
    }

    private function getShmTempPath(): string
    {
        return $this->resolveShmPath('temp');
    }

    private function getShmTranscodePath(): string
    {
        return $this->resolveShmPath('transcode');
    }

    public function getTranscodeFilePath(string $filename): ?string
    {
        $path = $this->getShmTranscodePath() . '/' . $filename;
        return file_exists($path) ? $path : null;
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

    /**
     * Pastikan validating forward proxy aktif dan kembalikan argumen yt-dlp
     * untuk memakainya (--proxy). Fail closed: bila proxy tidak bisa
     * dijalankan, lempar RuntimeException — download tidak pernah diizinkan
     * melewati jalur yang tidak tervalidasi.
     */
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
        // Defense-in-depth: never let an unvalidated URL reach yt-dlp, even if
        // a future caller skips processDownload(). SsrfGuard performs protocol
        // allowlisting + public-IP validation of every resolved address.
        try {
            (new SsrfGuard())->validate($url);
        } catch (\RuntimeException $e) {
            throw new DownloadException($e->getMessage(), $url, 'validation');
        }

        // Defense-in-depth: routing lewat validating proxy juga dipaksa di sini,
        // sehingga hop redirect apa pun (termasuk yang diikuti yt-dlp sendiri)
        // tetap melewati SsrfGuard di sisi proxy. Hindari duplikasi bila caller
        // (processDownload) sudah menempelkan --proxy pada $extraArgs.
        $proxyArgs = str_contains($extraArgs, '--proxy') ? '' : $this->ensureDownloadProxy();
        $cmd    = $this->base_cmd . $proxyArgs . $extraArgs . "--skip-download --print-json " . escapeshellarg($url) . " 2>&1";
        exec($cmd, $output_array, $return_var);
        $output = implode("\n", $output_array);

        // yt-dlp kadang return exit 1 padahal JSON-nya valid (WARNING di-promote ke error).
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

        // SSRF GUARD
        // Validasi sentral: hanya http/https, dan semua alamat hasil resolusi
        // DNS harus publik (bukan localhost/10.x/172.16-31.x/192.168.x/
        // 169.254.x/IPv6 private, dll). Gagal-fail = tolak URL.
        try {
            $ssrf = new SsrfGuard();
            $ssrf->validate($url);

            // Pin koneksi HTTP ke IP publik yang sudah divalidasi + paksa Host
            // header asli. Ini menutup celah DNS-rebinding antara validasi dan
            // request nyata.
            [$dl_url, $dl_extra] = $ssrf->pinHttpUrl($url);
        } catch (\RuntimeException $e) {
            throw new DownloadException("URL tidak diizinkan: " . $e->getMessage(), $url, 'validation');
        }

        // Arahkan SEMUA trafik yt-dlp (metadata + download + setiap redirect)
        // lewat validating forward proxy. Proxy menerapkan SsrfGuard pada
        // SETIAP hop — menutup celah open-redirect → IP private yang tidak bisa
        // ditutup hanya dengan validasi URL awal. Gagal start = tolak download
        // (fail closed, tidak ada fallback tanpa proteksi). Kegagalan ini adalah
        // masalah infrastruktur, BUKAN penolakan URL — jadi pesan dibedakan.
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
            // temp_id unik per download — cegah bentrok file staging antar
            // proses yang mulai di detik yang sama.
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
        fclose($dl_pipes[0]); // stdin — tidak dipakai
        fclose($dl_pipes[2]); // stderr — sudah diarahkan ke stdout via 2>&1

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

            // TIMEOUT FRAGMENT: yt-dlp retry fragment berulang ("1/10, 2/10, dst")
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

        // TIMEOUT FRAGMENT / TIMEOUT SISI PHP: hentikan tree proses yt-dlp
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
                // Tampilkan SEMUA baris error dari yt-dlp (ERROR, WARNING, trace)
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

    // FINALIZE VIDEO (HLS)

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

        $folder_name = $basename;
        $counter     = 1;
        while (is_dir(MEEL_HDD_VIDEO_DIR . $folder_name . "/")) {
            $folder_name = $basename . "-" . $counter;
            $counter++;
        }

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
            $this->removeFile($dl_thumb_src);
        }

        $thumb_generated = file_exists($work_thumb) && filesize($work_thumb) > 0;
        if (!$thumb_generated) $db_thumb = "default_thumb.webp";

        $file_dur = $this->probeDuration($staging_mp4);

        // Transcode ke HLS
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
        fclose($hls_pipes[0]); // stdin
        fclose($hls_pipes[1]); // stdout — tidak dipakai (FFmpeg output progress ke stderr)
        $hls_out = $hls_pipes[2]; // stderr — FFmpeg tulis time=... di sini

        $hls_status = proc_get_status($hls_proc);
        $hls_pid    = (int)($hls_status['pid'] ?? 0);
        $this->trackChildProcess($hls_pid, false, 'ffmpeg HLS (' . $folder_name . ')');
        $this->writePidFile('transcode', 0, $hls_pid);

        stream_set_timeout($hls_out, 30);
        $hls_start = time();
        $hls_timeout = max(120, (int)($file_dur * 2)); // min 2min atau 2x durasi (codec copy = cepat)
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

    /**
     * @param string $hdd_target_folder Folder video target di HDD
     * @param string $db_thumb Nama file thumbnail di database
     * @param bool $thumb_generated Apakah thumbnail benar-benar dibuat (bukan default)
     */
    private function rollbackFinalizeVideo(
        string $hdd_target_folder,
        string $db_thumb,
        bool   $thumb_generated
    ): void {
        if (is_dir($hdd_target_folder)) {
            $this->removeDir($hdd_target_folder);
        }
        // Jangan hapus default_thumb.webp — dipakai bersama.
        if ($thumb_generated && $db_thumb !== 'default_thumb.webp') {
            $this->removeFile(MEEL_HDD_THUMB_DIR . $db_thumb);
        }
    }

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

        $shm_temp   = $this->getShmTempPath();
        $input_path = "$shm_temp/$temp_file";

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

            $clean      = getRomajiName($title);

            $final_fname = $clean . ".ogg";
            $counter     = 1;
            while (file_exists($music_dir . "/$final_fname")) {
                $final_fname = $clean . "-" . $counter . ".ogg";
                $counter++;
            }

            $final_path = $music_dir . "/$final_fname";
            $thumb_name = str_replace('.ogg', '.webp', $final_fname);

            $cmd = escapeshellarg($this->ffmpeg_bin)
                . " -y -threads " . self::FFMPEG_THREADS
                . " -i "                 . escapeshellarg($input_path)
                . " -c:a libopus -vbr on -compression_level 10"
                . " -metadata title="    . escapeshellarg($title)
                . " -metadata artist="   . escapeshellarg($artist)
                . " " . escapeshellarg($final_path) . " 2>&1";
            $log = (string) shell_exec($cmd);

            if (!file_exists($final_path) || filesize($final_path) === 0) {
                // Pesan ramah — jangan tampilkan banner ffmpeg mentah ke user.
                // Detail lengkap tetap dicatat ke error_log.
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

        $cmd = self::ENV_PREFIX . escapeshellarg($this->ffmpeg_bin)
            . " -y -threads 1"
            . " -i "  . escapeshellarg($source_image)
            . " -vf " . escapeshellarg("scale='min(500,iw)':-1")
            . " -c:v libwebp -q:v 78 " . escapeshellarg($target_path) . " 2>&1";
        shell_exec($cmd);

        if (file_exists($target_path) && filesize($target_path) > 0) {
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

        $cmd = self::ENV_PREFIX . escapeshellarg($this->ffmpeg_bin)
            . " -y -threads 1"
            . " -i " . escapeshellarg($audio_file)
            . " -an -vframes 1"
            . " -vf " . escapeshellarg("scale='min(500,iw)':-1")
            . " -c:v libwebp -q:v 78 " . escapeshellarg($temp_extracted) . " 2>&1";
        shell_exec($cmd);

        if (file_exists($temp_extracted) && filesize($temp_extracted) > 1000) {
            $final_path = "$target_dir/$target_name";
            if (rename($temp_extracted, $final_path)) {
                return $target_name;
            }
            $this->removeFile($temp_extracted);
        }

        $this->removeFile($temp_extracted);
        return 'music_default.png';
    }

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
                // Validasi durasi output mendekati durasi sumber (toleransi 50%)
                $cache_dur = $this->probeDuration($output_path);
                if ($cache_dur > 0 && $file_dur > 0 && $cache_dur >= $file_dur * 0.5) {
                    $cache_valid = true;
                } elseif ($cache_dur <= 0 && $cache_size > 50000) {
                    // Jika ffprobe gagal tapi file cukup besar, anggap valid
                    $cache_valid = true;
                }
            }
        }
        if ($cache_valid) {
            if ($mtx_locked) {
                flock($mtx_fp, LOCK_UN);
                fclose($mtx_fp);
            }
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
            if ($marker_age < 600) { // < 10 menit — masih wajar
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
            // Gunakan single quote yang di-escape untuk path ffmpeg concat
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
                $use_thumb = false; // OGG/Opus tidak support embedded picture
                break;
            case 'm4a':
                $codec   = "copy"; // Stream copy audio dari HLS AAC
                $bitrate = "";
                break;
            default: // mp3
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
            // PENTING: JANGAN stream-copy ('copy') gambar cover di sini.
            // Thumbnail disimpan sebagai WebP (lihat finalizeVideo()), dan
            // muxer MP3/M4A tidak tahu mimetype untuk codec WebP saat
            // dipakai sebagai attached_pic ("No mimetype is known for
            // stream 1, cannot write an attached picture." -> ffmpeg exit
            // 234, Conversion failed). Re-encode ke MJPEG (image/jpeg)
            // yang dikenali semua muxer ID3/MP4.
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
            fclose($tc_pipes[0]); // stdin
            fclose($tc_pipes[1]); // stdout — tidak dipakai (FFmpeg output progress ke stderr)
            $tc_out = $tc_pipes[2]; // stderr — FFmpeg tulis time=... di sini

            $tc_status = proc_get_status($tc_proc);
            $tc_pid    = (int)($tc_status['pid'] ?? 0);
            $this->trackChildProcess($tc_pid, false, 'ffmpeg transcode audio (' . $output_filename . ')');
            $this->writePidFile('transcode', $queue_id, $tc_pid);

            stream_set_timeout($tc_out, 30); // 30s timeout per fgets
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
                        // Fallback: ffprobe gagal membaca durasi ($file_dur = 0).
                        // Tanpa ini progress tidak pernah terkirim dan UI terlihat
                        // freeze di 0% sampai TRANSCODE_AUDIO_TIMEOUT (600s), padahal
                        // ffmpeg berjalan normal. Pakai rasio elapsed/timeout sebagai
                        // estimasi kasar agar user tetap melihat pergerakan.
                        $elapsed = time() - $tc_start;
                        $pct     = min(95, (int)round(($elapsed / self::TRANSCODE_AUDIO_TIMEOUT) * 100));
                        $label   = "$pct% — CONVERTING TO $fmt (estimasi)";
                        $this->emit('transcode_progress', ['pct' => $pct, 'label' => $label]);
                    }
                }
            }
            fclose($tc_pipes[2]);

            // Cek exit code FFmpeg
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

        $download_link = "api/download-transcode?file=" . rawurlencode($output_filename) . "&title=" . rawurlencode($v_data['title']);
        $this->emit('done_transcode', ['title' => $v_data['title'], 'download_link' => $download_link]);

        return [
            'status'          => 'success',
            'download_link'   => $download_link,
            'output_filename' => $output_filename,
            'title'           => $v_data['title'],
        ];
    }
}
