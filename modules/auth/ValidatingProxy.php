<?php
// Manages the validating forward proxy process (validating_proxy_server.php).

// SECURITY BOUNDARY:
// * The proxy binds to 127.0.0.1 on an ephemeral port and applies
// SsrfGuard to EVERY hop (including redirects) — this closes the
// open-redirect → private IP SSRF gap.
// * Download flow (Transcoder) MUST route yt-dlp through this proxy via
// --proxy. If the proxy cannot start, the download is REFUSED (fail
// closed) — we never fall back to an unprotected download.
// * The process is spawned via PHP CLI (PHP_BINARY) with stdin/stdout/
// stderr pipes; readiness is signalled on stdout ("PORT n / READY").
// * Terminated automatically by the destructor / explicit stop().
final class ValidatingProxy
{
    private const START_TIMEOUT_SECONDS = 8;

    private ?int $port = null;
    private $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];

    public function __construct()
    {
        $script = __DIR__ . '/validating_proxy_server.php';
        if (!is_file($script)) {
            throw new \RuntimeException('validating proxy script tidak ditemukan.');
        }

        $phpBin = self::resolvePhpBinary();
        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script);

        $descriptor = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout — READY handshake
            2 => ['pipe', 'w'], // stderr — diagnostic
        ];

        $this->process = @proc_open($cmd, $descriptor, $this->pipes, dirname(__DIR__, 2));
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Validating proxy gagal dijalankan.');
        }

        fclose($this->pipes[0]);
        $this->pipes[0] = null;
        stream_set_blocking($this->pipes[1], true);
        stream_set_timeout($this->pipes[1], self::START_TIMEOUT_SECONDS);

        // Baca handshake "PORT <n>\nREADY\n"
        $handshake = '';
        $deadline = microtime(true) + self::START_TIMEOUT_SECONDS;
        while (strpos($handshake, "READY") === false && microtime(true) < $deadline) {
            $line = fgets($this->pipes[1]);
            if ($line === false) {
                break;
            }
            $handshake .= $line;
            if (preg_match('/^PORT\s+(\d+)/i', $line, $m)) {
                $this->port = (int) $m[1];
            }
        }

        if ($this->port === null || $this->port <= 0 || $this->port > 65535) {
            $this->stop();
            throw new \RuntimeException('Validating proxy tidak siap (handshake gagal).');
        }

        // stderr non-blocking supaya tidak pernah memblokir (drain opsional)
        stream_set_blocking($this->pipes[2], false);
    }

    /**
     * Tentukan binary PHP CLI yang dipakai untuk spawn proxy.
     *
     * Di SAPI CLI, PHP_BINARY benar (mis. /opt/lampp/bin/php). Di mod_php
     * (Apache) PHP_BINARY sering KOSONG, dan PATH milik Apache biasanya tidak
     * menyertakan direktori php (mis. /opt/lampp/bin) — fallback 'php' mentah
     * akan gagal dengan "php: not found" (exit 127), membuat handshake timeout
     * dan download ditolak (fail-closed) padahal environment sehat.
     *
     * Strategi: kandidat dicoba satu per satu, dan setiap kandidat DIVERIFIKASI
     * dengan menjalankannya (`php -r 'echo PHP_VERSION;'`) — bukan menebak dari
     * nama file. Ini menangani PHP_BINARY yang menunjuk ke binary non-CLI
     * (httpd, php-fpm, php-cgi) sekaligus memastikan binary yang dipilih benar-
     * benar bisa mengeksekusi PHP.
     */
    public static function resolvePhpBinary(): string
    {
        $candidates = [];

        // 1) PHP_BINARY — benar saat CLI; bisa menunjuk ke binary non-CLI di
        // SAPI lain (mod_php/FPM/CGI) — verifikasi nyata di bawah menolaknya.
        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            $candidates[] = PHP_BINARY;
        }
        // 2) Lokasi umum php (XAMPP/LAMPP, distro) — PATH Apache sering tidak
        // menyertakan direktori php sehingga 'php' mentah tidak ditemukan.
        foreach (['/opt/lampp/bin/php', '/usr/bin/php', '/usr/local/bin/php'] as $c) {
            $candidates[] = $c;
        }
        // 3) Fallback terakhir: biarkan PATH shell mencarinya.
        $candidates[] = 'php';

        foreach ($candidates as $candidate) {
            if ($candidate === 'php') {
                return 'php';
            }
            if (!is_executable($candidate)) {
                continue;
            }
            // VERIFIKASI NYATA: binary harus mengeksekusi PHP (bukan httpd,
            // php-fpm, atau binary lain yang kebetulan executable).
            $out = [];
            $rc  = 0;
            @exec(escapeshellarg($candidate) . ' -r "echo PHP_VERSION;" 2>&1', $out, $rc);
            if ($rc === 0 && preg_match('/^\d+\.\d+\.\d+$/', trim(implode('', $out)))) {
                return $candidate;
            }
        }
        return 'php';
    }

    /** URL proxy yang harus dipakai yt-dlp (--proxy). */
    public function url(): string
    {
        if ($this->port === null) {
            throw new \RuntimeException('Validating proxy belum siap.');
        }
        return 'http://127.0.0.1:' . $this->port;
    }

    public function isRunning(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }
        $status = proc_get_status($this->process);
        return !empty($status['running']);
    }

    public function stop(): void
    {
        foreach ($this->pipes as $i => $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
            $this->pipes[$i] = null;
        }

        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if (!empty($status['running'])) {
                // Terminate seluruh tree proses (proses + child). posix_kill dan
                // konstanta sinyal TIDAK tersedia di semua environment (mis.
                // runner CI tanpa ext-posix) — guard function_exists agar
                // stop() tidak pernah fatal.
                $pid = (int) ($status['pid'] ?? 0);
                $hasPosix = function_exists('posix_kill') && defined('SIGTERM') && defined('SIGKILL');
                if ($pid > 0 && $hasPosix) {
                    @posix_kill($pid, SIGTERM);
                }
                @proc_terminate($this->process);
                // Grace period lalu SIGKILL
                usleep(150000);
                $status2 = proc_get_status($this->process);
                if (!empty($status2['running']) && $pid > 0 && $hasPosix) {
                    @posix_kill($pid, SIGKILL);
                }
            }
            @proc_close($this->process);
        }
        $this->process = null;
        $this->port = null;
    }

    public function __destruct()
    {
        $this->stop();
    }
}
