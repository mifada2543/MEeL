<?php
// modules/core/ValidatingProxy.php
// ─────────────────────────────────────────────────────────────────────────────
// Manages the validating forward proxy process (validating_proxy_server.php).
//
// SECURITY BOUNDARY:
//   * The proxy binds to 127.0.0.1 on an ephemeral port and applies
//     SsrfGuard to EVERY hop (including redirects) — this closes the
//     open-redirect → private IP SSRF gap.
//   * Download flow (Transcoder) MUST route yt-dlp through this proxy via
//     --proxy. If the proxy cannot start, the download is REFUSED (fail
//     closed) — we never fall back to an unprotected download.
//   * The process is spawned via PHP CLI (PHP_BINARY) with stdin/stdout/
//     stderr pipes; readiness is signalled on stdout ("PORT n / READY").
//   * Terminated automatically by the destructor / explicit stop().
// ─────────────────────────────────────────────────────────────────────────────
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

        $phpBin = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
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
