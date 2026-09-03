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

class TranscoderBase
{
    use FfmpegUtils;
    protected \mysqli $conn;
    protected int $user_id;
    protected string $user_role;
    protected string $base_path;
    protected string $cookies_path;
    protected string $user_agent;
    protected string $base_cmd;
    protected string $ffmpeg_bin;
    protected string $ffprobe_bin;

    protected ?ProgressObserver $progressObserver = null;

    
    protected ?ValidatingProxy $validatingProxy = null;
    
    protected string $proxyArgs = '';

    
    protected array $childProcesses = [];

    protected const FFMPEG_THREADS        = 8;

    
    protected const HLS_SEGMENT_DURATION  = 10;

    
    protected const DOWNLOAD_TIMEOUT      = 900;

    
    
    protected const FRAGMENT_RETRY_LIMIT  = 1;

    
    protected const PID_DIR = '/tmp/meel_pids';
    
    protected const TRANSCODE_AUDIO_TIMEOUT = 600;

    
    protected const FFMPEG_LIB_PATH = '/usr/lib/x86_64-linux-gnu:/usr/local/lib';

    protected const ENV_PREFIX = "export LD_LIBRARY_PATH='/usr/lib/x86_64-linux-gnu:/usr/local/lib'; export PATH=/usr/local/bin:/usr/bin:/bin; export LC_ALL=en_US.UTF-8; ";

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

    
    public function __destruct()
    {
        
        
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


    public function getProgressObserver(): ?ProgressObserver
    {
        return $this->progressObserver;
    }
    public function getUserRole(): string
    {
        return $this->user_role;
    }

    
    protected function emit(string $stage, array $data = []): void
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

    
    

    protected function trackChildProcess(int $pid, bool $processGroup, string $label): void
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

    
    protected function untrackChildProcess(int $pid): void
    {
        foreach ($this->childProcesses as $i => $proc) {
            if ($proc['pid'] === $pid) {
                unset($this->childProcesses[$i]);
            }
        }
        $this->childProcesses = array_values($this->childProcesses);
    }

    
    protected function terminateChildProcess(int $pid, string $label, bool $processGroup = false): void
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
            
            shell_exec('kill -TERM -- ' . $prefix . $pid . ' 2>/dev/null');
        }

        usleep(300000); 

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

    

    
    protected function writePidFile(string $taskType, int $queueId, int $pid): void
    {
        $dir = self::PID_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = "$dir/{$taskType}_{$queueId}.pid";
        @file_put_contents($path, (string)$pid);
    }

    
    protected function removePidFile(string $taskType, int $queueId): void
    {
        $path = self::PID_DIR . "/{$taskType}_{$queueId}.pid";
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    

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

        usleep(500000); 

        if (function_exists('posix_kill')) {
            @posix_kill($pid, SIGKILL);
        } else {
            @shell_exec('kill -KILL ' . $pid . ' 2>/dev/null');
        }

        error_log("[MEeL] killByPidFile: killed {$taskType}#{$queueId} (PID {$pid})");
        return true;
    }

    

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

    
    protected function resolveShmPath(string $subdir): string
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

    protected function getShmTempPath(): string
    {
        return $this->resolveShmPath('temp');
    }

    protected function getShmTranscodePath(): string
    {
        return $this->resolveShmPath('transcode');
    }

    public function getTranscodeFilePath(string $filename): ?string
    {
        $path = $this->getShmTranscodePath() . '/' . $filename;
        return file_exists($path) ? $path : null;
    }

    /**
     * Resolve input musik temp secara aman.
     *
     * Menolak path traversal/absolut: hanya nama file polos di dalam direktori
     * temp milik server yang dikembalikan. Caller tidak pernah boleh
     * menyuplai filesystem path mentah.
     */
    public function resolveMusicInputPath(string $tempFile): ?string
    {
        if ($tempFile === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $tempFile) || str_contains($tempFile, '..')) {
            return null;
        }

        $tempDirReal = realpath($this->getShmTempPath());
        if ($tempDirReal === false) {
            return null;
        }

        $candidate = $tempDirReal . '/' . $tempFile;
        $real      = realpath($candidate);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            return null;
        }
        if (!str_starts_with($real, $tempDirReal . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $real;
    }

}
