<?php
// File: modules/core/Transcoder.php
// Optimized for: Intel Core i3-1220P (10 core / 12 thread), Dual-Channel RAM, USB HDD storage
// VA-API: Intel iHD 24.1.0 — H264/HEVC/VP9 encode+decode tersedia, tapi tidak dipakai di HLS
//         karena pipeline ini sudah pakai -codec copy (stream copy, tanpa re-encode)
//
// Arsitektur (refactor media-processing engine):
//   - Lapisan BISNIS murni: tidak ada echo/flush HTML/JS di class ini.
//     Progress dilaporkan lewat ProgressObserver (lihat emit()).
//   - Lapisan PRESENTASI: modules/core/BrowserProgressObserver.php
//     mengubah event progress menjadi overlay browser (meel*).
//   - Terminasi proses memakai PID/process-group presisi (posix_kill),
//     bukan pkill -f berbasis marker string.
//   - finalizeVideo() memindahkan file + INSERT database dalam satu
//     transaksi MySQL — gagal salah satu → rollback + cleanup otomatis.

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
require_once __DIR__ . '/../transcoder/FfmpegUtils.php';
require_once __DIR__ . '/../exceptions/ProcessException.php';
require_once __DIR__ . '/../exceptions/DownloadException.php';
require_once __DIR__ . '/../exceptions/TranscodeException.php';
require_once __DIR__ . '/ProgressObserver.php';

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

    /**
     * Observer progress (presentation layer). Null = tidak ada output
     * (aman untuk CLI script / cron / API).
     */
    private ?ProgressObserver $progressObserver = null;

    /**
     * Daftar proses anak yang sedang berjalan, untuk terminasi presisi.
     *
     * @var array<int, array{pid:int, group:bool, label:string, started:int}>
     */
    private array $childProcesses = [];

    // ─── KONSTANTA HARDWARE ───────────────────────────────────────────────────
    private const FFMPEG_THREADS        = 8;

    // HLS: durasi tiap segment (detik)
    private const HLS_SEGMENT_DURATION  = 10;

    // Download timeout (detik)
    private const DOWNLOAD_TIMEOUT      = 900;

    // Ambang deteksi timeout fragment yt-dlp: berapa kali pesan
    // "Retrying fragment N (1/10)" muncul sebelum proses dihentikan.
    // 1 = hentikan segera begitu pola retry fragment terdeteksi.
    private const FRAGMENT_RETRY_LIMIT  = 1;

    // ─── ENV PREFIX ───────────────────────────────────────────────────────────
    // Didefinisikan di class (bukan trait) karena PHP 8.0 tidak support trait constants
    private const ENV_PREFIX = "export LD_LIBRARY_PATH=''; export PATH=/usr/local/bin:/usr/bin:/bin; export LC_ALL=en_US.UTF-8; ";

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
        $this->ffmpeg_bin   = $this->resolveBinary(['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg']);
        $this->ffprobe_bin  = $this->resolveBinary(['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', 'ffprobe']);

        $this->user_role = get_user_role($this->conn, $this->user_id);

        $ytdlp_bin = defined('MEEL_YTDLP_PATH') && MEEL_YTDLP_PATH !== ''
            ? MEEL_YTDLP_PATH
            : $this->resolveBinary(['/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp', 'yt-dlp']);
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

    /**
     * Pasang (atau lepas) listener progress.
     *
     * Menerima instance ProgressObserver ATAU callable polos
     * (dibungkus CallableProgressObserver). Null = tanpa output.
     *
     * @param callable(string $stage, array $data): void|ProgressObserver|null $listener
     */
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

    /**
     * Laporkan event progress ke observer (jika ada).
     *
     * Exception dari observer DITELAN dan dicatat — observer (lapisan
     * presentasi) tidak boleh menggagalkan pipeline media di tengah jalan
     * (mis. menyisakan proses anak hidup atau file setengah terpindah).
     *
     * @param string $stage Nama stage (lihat ProgressObserver docblock)
     * @param array<string, mixed> $data Payload event
     */
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

    // ─── PROCESS CONTROL (PID-BASED TERMINATION) ─────────────────────────────
    // Pengganti `pkill -f <marker>` yang berisiko mematikan proses PHP/ffmpeg
    // milik request lain pada sistem multi-tenant. Setiap proses anak dicatat
    // PID-nya; terminasi memakai posix_kill() (atau fallback `kill -- -PGID`)
    // terhadap PID/process-group yang presisi, dengan urutan:
    //   SIGTERM → grace period → SIGKILL.

    /**
     * Catat proses anak yang sedang berjalan.
     *
     * @param int    $pid          PID (atau PGID bila $processGroup true)
     * @param bool   $processGroup True bila proses adalah session/group leader
     *                             (seluruh tree bisa dihentikan via kill -PGID)
     * @param string $label        Label deskriptif untuk logging
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

    /**
     * Lepas catatan proses anak setelah selesai normal.
     *
     * @param int $pid PID yang dicatat oleh trackChildProcess()
     */
    private function untrackChildProcess(int $pid): void
    {
        foreach ($this->childProcesses as $i => $proc) {
            if ($proc['pid'] === $pid) {
                unset($this->childProcesses[$i]);
            }
        }
        $this->childProcesses = array_values($this->childProcesses);
    }

    /**
     * Hentikan satu proses anak (atau seluruh process-group) secara presisi.
     *
     * Urutan: SIGTERM → grace period 300ms → SIGKILL (fallback graceful
     * shutdown). Saat $processGroup = true, target = -$pid (semua proses
     * dalam group — dipakai untuk tree yt-dlp + node runtime).
     *
     * @param int    $pid          PID target
     * @param string $label        Label untuk logging
     * @param bool   $processGroup True = kill seluruh process group (-$pid)
     */
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
            // Fallback (posix tidak tersedia): kill util presisi per PID/PGID,
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

    /**
     * Fallback graceful shutdown: hentikan SEMUA proses anak yang masih
     * tercatat. Dipanggil via register_shutdown_function() oleh caller
     * (upload_advanced.php, transcode.php) — hanya membunuh proses yang
     * belum selesai; proses yang sudah untrack tidak terpengaruh.
     */
    public function terminateAllProcesses(): void
    {
        foreach ($this->childProcesses as $proc) {
            $this->terminateChildProcess($proc['pid'], $proc['label'], $proc['group']);
        }
        $this->childProcesses = [];
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
                $free = disk_free_space($shm_path);
                if ($free !== false && $free >= 512 * 1024 * 1024) {
                    $use_shm = true;
                }
            }

            $resolved[$subdir] = $use_shm
                ? "$shm_path/meel/$subdir"
                : dirname(__DIR__, 2) . '/temp';

            if (!is_dir($resolved[$subdir])) {
                $this->ensureDir($resolved[$subdir]);
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
            // Catatan: 'bestvideo1' bukan format string yang valid — diganti ke format standar
            return "bestvideo+bestaudio/best";
        }
        return "bestvideo[height<=1080]+bestaudio/best";
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

        // PRE-FLIGHT: Cek ruang disk sebelum download (queue belum di-lock)
        require_disk_space(512 * 1024 * 1024, $this->getShmTempPath(), 'RAM disk staging');
        $hdd_path = defined('MEEL_HDD_BASE') ? MEEL_HDD_BASE : dirname(__DIR__, 2);
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

        // YouTube (via yt-dlp) terkadang memotong judul panjang dengan '...'
        // pada field `title`/`fulltitle` (sering terjadi pada video musik).
        // Field `track`/`alt_title` biasanya memuat judul LENGKAP — ambil
        // kandidat terpanjang yang tidak berakhiran '...' agar judul utuh
        // tersimpan ke database. Pemotongan visual '...' saat ditampilkan
        // ditangani CSS (text-overflow: ellipsis) di halaman library.
        // Catatan: bila title terpotong DAN track/alt_title tidak ada, judul
        // terpotong tetap tersimpan — tidak ada sumber lengkap untuk diperbaiki.
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

        // Siapkan perintah download sesuai tipe
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

        // Kirim event mulai unduh (observer memutuskan cara menampilkannya)
        $this->emit('download_start', ['url' => $url]);

        $error_log = "";
        $start     = time();
        // Tambahkan -N 4 (4 koneksi paralel untuk mempercepat download, aman untuk server single-user)
        // Set env via putenv() agar proc_open() tidak perlu shell metacharacters
        putenv('PATH=/usr/local/bin:/usr/bin:/bin');
        putenv('LC_ALL=en_US.UTF-8');
        // $cmd_dl sudah berisi args yang di-escape dengan escapeshellarg() + filter_var() untuk URL
        //
        // `exec setsid timeout N sh -c '<cmd>'` — menjadikan child sebagai
        // session/process-group leader (PGID == PID yang dilaporkan
        // proc_get_status()). Seluruh tree proses (yt-dlp + node runtime)
        // bisa dihentikan presisi via kill terhadap process group,
        // menggantikan pkill -f berbasis marker string.
        //
        // PENTING: pembungkus `sh -c` WAJIB. $cmd_dl diawali prefix env
        // (`export PATH=...; export LC_ALL=...;`) dan `exec` menggantikan
        // shell saat itu juga. Tanpa sh -c, yang jalan hanya
        // `setsid timeout N export ...` (export bukan binary, langsung
        // "command not found"), shell sudah terganti oleh exec, dan
        // perintah yt-dlp TIDAK PERNAH dieksekusi — download selalu gagal
        // diam-diam dengan error log kosong.
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

        // Contoh output yt-dlp:
        // [download]  63.2% of   45.23MiB at    4.20MiB/s ETA 00:42 (frag 3/5)
        // [download] Got error: HTTP Error 429: Too Many Requests. Retrying fragment 3 (1/10)...
        // [download] Got error: HTTP Error 403: Forbidden. Retrying fragment 3 (2/10)...
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

            // ── TIMEOUT FRAGMENT: yt-dlp retry fragment berulang ("1/10, 2/10, dst") ──
            // Begitu pola "Retrying fragment N (1/10)" terdeteksi (ambang FRAGMENT_RETRY_LIMIT),
            // proses dihentikan otomatis — jangan biarkan yt-dlp retry terus menerus.
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
                // Fallback: hanya persentase
                $this->emit('download_progress', ['pct' => (int)$m[1]]);
            }
        }

        // ── TIMEOUT FRAGMENT / TIMEOUT SISI PHP: hentikan tree proses yt-dlp
        // + node secara presisi (kill process group) dan bersihkan partial.
        // `timeout` tetap ada sebagai backstop bila signal diabaikan.
        if ($frag_retry_abort || $php_timeout_abort) {
            $this->terminateChildProcess(
                $dl_pgid,
                'yt-dlp ' . ($frag_retry_abort ? 'fragment retry abort' : 'PHP timeout abort'),
                true
            );
            // Hapus file partial download dari staging agar tidak jadi sampah
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

        // Validasi hasil download
        $is_success = false;
        // BUG SEBELUMNYA: glob() pakai `{$this->base_path}/temp/` tapi yt-dlp simpan file di `$shm_temp` (/dev/shm/meel/temp/)
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
                // Pesan spesifik untuk timeout fragment — lebih jelas daripada pesan generik
                $error_msg = "Timeout: yt-dlp gagal mengunduh fragment berulang kali (retry 1/10, 2/10, dst). "
                    . "Proses dihentikan otomatis. Coba lagi nanti atau gunakan URL lain.";
            } else {
                $error_msg  = "Download gagal. Detail disimpan di server.";
                $last_lines = array_slice(explode("\n", $error_log), -3);
                $detail     = trim(implode(" | ", $last_lines));
                if ($detail) $error_msg = substr($detail, 0, 200);
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
            // Metadata dikirim lewat SESSION (bukan query string URL) agar judul
            // dan deskripsi panjang tidak terpotong atau memicu error 414 oleh
            // batas URL server (Apache LimitRequestLine ~8190 byte).
            $meta_key = pathinfo($raw_file, PATHINFO_FILENAME);
            if (!isset($_SESSION['meel_pending_music']) || !is_array($_SESSION['meel_pending_music'])) {
                $_SESSION['meel_pending_music'] = [];
            }
            // Buang entri basi (> 1 jam) agar session tidak menumpuk
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
            // Tulis session sekarang juga agar data pasti tersimpan sebelum
            // browser mengeksekusi redirect (mencegah race condition).
            session_write_close();
            // Navigasi ke post_encode.php — event 'redirect' dipetakan observer
            // menjadi window.location.href. Return sentinel 'REDIRECT:' + URL
            // (bukan exit di lapisan bisnis) — caller/lapisan presentasi yang
            // memutuskan kelanjutan; CLI/API aman menerima nilai ini sebagai
            // string biasa.
            $redirect_url = 'controllers/api/post_encode.php?temp_file=' . rawurlencode($raw_file);
            $this->emit('redirect', ['url' => $redirect_url]);
            return 'REDIRECT:' . $redirect_url;
        }

        return "File audio tidak ditemukan setelah download.";
    }

    // ─── FINALIZE VIDEO (HLS) ─────────────────────────────────────────────────

    private function finalizeVideo(
        string $basename,
        string $db_thumb,
        string $title,
        int    $duration,
        string $description = 'Upload by MEeL Engine'
    ): string {
        $shm_temp    = $this->getShmTempPath();
        $staging_mp4 = "$shm_temp/{$basename}.mp4";

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
            $this->emit('error', ['message' => "File MP4 staging tidak ditemukan: $staging_mp4"]);
            return "";
        }

        $this->emit('phase', ['phase' => 'transcode']);

        // ── Tentukan nama folder unik di HDD ──────────────────────────────────
        // Lock untuk serialisasi folder — cegah TOCTOU race condition
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
        // HLS work folder di RAM disk — segmen .ts ditulis ke RAM, baru dipindah ke HDD
        $shm_temp    = $this->getShmTempPath();
        $work_folder = "$shm_temp/{$folder_name}/";
        if (!is_dir($work_folder)) {
            $this->ensureDir($work_folder);
        }

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
            $this->removeFile($dl_thumb_src);
        }

        $thumb_generated = file_exists($work_thumb) && filesize($work_thumb) > 0;
        if (!$thumb_generated) $db_thumb = "default_thumb.webp";

        // ── Dapatkan durasi video ─────────────────────────────────────────────
        $file_dur = $this->probeDuration($staging_mp4);

        // ── Transcode ke HLS ──────────────────────────────────────────────────
        // -codec copy: stream copy (tidak re-encode), sehingga QSV tidak relevan.
        // -threads hanya berlaku untuk muxer/demuxer I/O; tetap set untuk konsistensi.
        $work_m3u8  = $work_folder . $folder_name . ".m3u8";
        // proc_open dengan array arguments + env vars — bypasses shell entirely
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

        while (!feof($hls_out)) {
            $line = fgets($hls_out);
            if (preg_match('/time=((\d+):(\d+):(\d+)\.(\d+))/', $line, $m) && $file_dur > 0) {
                $cur = ($m[2] * 3600) + ($m[3] * 60) + $m[4];
                $pct = min(99, round(($cur / $file_dur) * 100));
                $this->emit('transcode_progress', ['pct' => $pct]);
            }
        }
        fclose($hls_pipes[2]);
        proc_close($hls_proc);
        $this->untrackChildProcess($hls_pid);

        if (!file_exists($work_m3u8) || filesize($work_m3u8) === 0) {
            $this->removeDir($work_folder);
            $this->removeFile($staging_mp4);
            $this->emit('error', ['message' => 'Transcode HLS gagal. File .m3u8 tidak terbentuk.']);
            return "";
        }

        // ── Sprite & VTT (DIOPTIMALKAN KE RAM DISK) ──────────────────────────
        $this->emit('phase', ['phase' => 'sprite']);
        $this->emit('sprite_progress', ['pct' => 0, 'label' => 'Membuat thumbnail.vtt...']);

        // Buat folder kerja sementara di RAM Disk Linux (/dev/shm)
        // Fallback ke /tmp jika /dev/shm tidak tersedia atau tidak writable
        $shm_base   = (is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir());
        $ram_folder = $shm_base . '/meel_sprite_' . uniqid() . '/';
        if (!is_dir($ram_folder)) {
            $this->ensureDir($ram_folder, 0777);
        }

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
        $this->removeDir($ram_folder);

        $this->emit('sprite_progress', ['pct' => 100, 'label' => 'Sprite & VTT selesai.']);

        $this->removeFile($staging_mp4);

        // ── TRANSACTION: Pindahkan ke HDD + INSERT DB (atomik) ───────────────
        // Wajib pakai moveFile() karena USB HDD = filesystem berbeda dari /tmp/work
        // (rename() cross-device akan selalu gagal diam-diam di Linux).
        //
        // File HLS dipindahkan DI DALAM scope transaksi: bila salah satu file
        // move atau INSERT database gagal, transaksi di-rollback dan routine
        // cleanup otomatis menghapus folder HLS/thumbnail yang sudah terlanjur
        // dipindah ke HDD — tidak ada orphaned file di storage.
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

            // ── Simpan ke database ────────────────────────────────────────────
            $hdd_m3u8_full  = MEEL_HDD_VIDEO_UPLOAD . $db_filename;
            $hdd_thumb_full = MEEL_HDD_THUMB_DIR . $db_thumb;

            if (!file_exists($hdd_m3u8_full) || filesize($hdd_m3u8_full) === 0) {
                throw new \RuntimeException("File M3U8 tidak ditemukan di HDD setelah dipindahkan: $hdd_m3u8_full");
            }
            if ($thumb_generated && (!file_exists($hdd_thumb_full) || filesize($hdd_thumb_full) === 0)) {
                throw new \RuntimeException("Thumbnail tidak ditemukan di HDD setelah dipindahkan: $hdd_thumb_full");
            }

            // Generate search_metadata — helper terpusat (romaji + english + alias),
            // konsisten dengan Uploader, backfill & admin/edit-*.php
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
            // mysqli::rollback() di luar transaksi aktif adalah no-op yang aman
            // (MySQL ROLLBACK tanpa transaksi tidak error), jadi tidak perlu
            // memeriksa status transaksi — metode inTransaction() bahkan tidak
            // ada di ekstensi mysqli (itu milik PDO). Pola sama dengan Uploader.
            $this->conn->rollback();

            // Cleanup otomatis: hapus HLS folder + thumbnail yang sudah terpindah ke HDD
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
     * Cleanup rutin setelah finalizeVideo gagal: hapus folder HLS dan
     * thumbnail yang sudah terlanjur dipindahkan ke USB HDD selama sesi ini.
     *
     * @param string $hdd_target_folder Folder video target di HDD
     * @param string $db_thumb          Nama file thumbnail di database
     * @param bool   $thumb_generated   Apakah thumbnail benar-benar dibuat (bukan default)
     */
    private function rollbackFinalizeVideo(
        string $hdd_target_folder,
        string $db_thumb,
        bool   $thumb_generated
    ): void {
        if (is_dir($hdd_target_folder)) {
            $this->removeDir($hdd_target_folder);
        }
        // Hanya hapus thumbnail bila memang dibuat sesi ini — jangan sentuh
        // default_thumb.webp milik bersama.
        if ($thumb_generated && $db_thumb !== 'default_thumb.webp') {
            $this->removeFile(MEEL_HDD_THUMB_DIR . $db_thumb);
        }
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

        // PRE-FLIGHT: Cek HDD untuk output encode — butuh minimal 500MB
        $music_dir = "{$this->base_path}/music/upload/file";
        if (!is_dir($music_dir)) {
            $this->ensureDir($music_dir);
        }
        $hdd_free = disk_free_space($music_dir);
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

        $this->removeFile($input_path);

        // Bersihkan sisa file temporary dari yt-dlp
        foreach (glob("$temp_dir/$temp_base.*") as $leftover) {
            $this->removeFile($leftover);
        }

        // Generate search_metadata — helper terpusat (romaji + english + alias),
        // 1x MeCab saja (sebelumnya 2x terpisah), konsisten dengan Uploader/backfill
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
    }

    // ─── THUMBNAIL HELPERS ────────────────────────────────────────────────────

    private function extractMusicThumbnail(
        string $audio_file,
        string $temp_dir,
        string $temp_base,
        string $target_name
    ): string {
        $thumb_dir = "{$this->base_path}/music/upload/thumbnail";
        if (!is_dir($thumb_dir)) {
            $this->ensureDir($thumb_dir);
        }

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
                $this->removeFile($source_image);
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
        shell_exec($cmd);

        if (file_exists($target_path) && filesize($target_path) > 0) {
            $this->removeFile($source_image);
            return $target_name;
        }

        // Fallback: copy original
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

        // Ekstrak cover art dari metadata audio, simpan sebagai WebP
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
        if (!is_dir($output_dir)) {
            $this->ensureDir($output_dir);
        }

        // PRE-FLIGHT: Cek RAM disk untuk output transcode — butuh minimal 256MB
        $shm_free = disk_free_space($output_dir);
        if ($shm_free !== false && $shm_free < 256 * 1024 * 1024) {
            return ['status' => 'error', 'msg' => 'RAM disk tidak mencukupi untuk transcode. Hanya tersedia ' . sprintf('%.1f', $shm_free / (1024 ** 3)) . ' GB.'];
        }

        // Bersihkan file lama (> 2 jam)
        foreach (glob($output_dir . "*") as $file) {
            if (is_file($file) && time() - filemtime($file) >= 7200) {
                $this->removeFile($file);
            }
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

        // Marker file — atomic check-then-create untuk cegah duplikat transcode
        // Pendekatan ini lebih baik dari flock() yang di-hold lama karena FFmpeg bisa memakan waktu menit.
        $marker_file = $output_path . '.processing';

        $mtx_path  = sys_get_temp_dir() . '/meel_transcode_marker.lock';
        $mtx_fp    = fopen($mtx_path, 'c');
        $mtx_locked = $mtx_fp !== false && flock($mtx_fp, LOCK_EX);

        if (file_exists($output_path) && filesize($output_path) > 0) {
            if ($mtx_locked) { flock($mtx_fp, LOCK_UN); fclose($mtx_fp); }
            $download_link = "controllers/api/download_transcode.php?file=" . rawurlencode($output_filename);
            // Pastikan overlay ter-inject sebelum done_transcode — bila langsung
            // meelDoneTranscode tanpa overlay, meel* JS belum terdefinisi.
            $this->emit('transcode_start');
            $this->emit('done_transcode', ['title' => $v_data['title'], 'download_link' => $download_link]);
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
        if (!touch($marker_file)) {
            error_log("[MEeL] Gagal membuat marker file: {$marker_file}");
        }

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

        // proc_open dengan array arguments + env vars — bypasses shell entirely
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

        // Kirim event mulai transcode (observer memutuskan tampilan overlay)
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

            while (!feof($tc_out)) {
                $line = fgets($tc_out);
                if (preg_match('/time=((\d+):(\d+):(\d+)\.(\d+))/', $line, $m) && $file_dur > 0) {
                    $cur   = ($m[2] * 3600) + ($m[3] * 60) + $m[4];
                    $pct   = min(100, round(($cur / $file_dur) * 100));
                    $fmt   = strtoupper($format);
                    $label = "$pct% — CONVERTING TO $fmt";
                    $this->emit('transcode_progress', ['pct' => $pct, 'label' => $label]);
                }
            }
            fclose($tc_pipes[2]);
            proc_close($tc_proc);
            $this->untrackChildProcess($tc_pid);
        }

        $this->removeFile($concat_list_path);

        // Update queue status via prepared statement (bukan raw query)
        $stmt_upd = $this->conn->prepare(
            "UPDATE transcode_queue SET status = 'completed' WHERE id = ?"
        );
        $stmt_upd->bind_param("i", $queue_id);
        $stmt_upd->execute();
        $stmt_upd->close();

        $this->removeFile($marker_file); // Hapus marker setelah selesai

        if (!file_exists($output_path) || filesize($output_path) === 0) {
            $this->emit('error', ['message' => 'FFmpeg gagal menghasilkan file output.']);
            return ['status' => 'error', 'msg' => 'FFmpeg gagal menghasilkan file.'];
        }

        $download_link = "controllers/api/download_transcode.php?file=" . rawurlencode($output_filename);
        $this->emit('done_transcode', ['title' => $v_data['title'], 'download_link' => $download_link]);

        return [
            'status'          => 'success',
            'download_link'   => $download_link,
            'output_filename' => $output_filename,
        ];
    }
}
