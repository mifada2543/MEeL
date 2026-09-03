<?php












final class ValidatingProxy
{
    private const START_TIMEOUT_SECONDS = 8;

    private ?int $port = null;
    private $process = null;
    
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
            0 => ['pipe', 'r'], 
            1 => ['pipe', 'w'], 
            2 => ['pipe', 'w'], 
        ];

        $this->process = @proc_open($cmd, $descriptor, $this->pipes, dirname(__DIR__, 2));
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Validating proxy gagal dijalankan.');
        }

        fclose($this->pipes[0]);
        $this->pipes[0] = null;
        stream_set_blocking($this->pipes[1], true);
        stream_set_timeout($this->pipes[1], self::START_TIMEOUT_SECONDS);

        
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

        
        stream_set_blocking($this->pipes[2], false);
    }

    

    public static function resolvePhpBinary(): string
    {
        $candidates = [];

        
        
        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            $candidates[] = PHP_BINARY;
        }
        
        
        foreach (['/opt/lampp/bin/php', '/usr/bin/php', '/usr/local/bin/php'] as $c) {
            $candidates[] = $c;
        }
        
        $candidates[] = 'php';

        foreach ($candidates as $candidate) {
            if ($candidate === 'php') {
                return 'php';
            }
            if (!is_executable($candidate)) {
                continue;
            }
            
            
            $out = [];
            $rc  = 0;
            @exec(escapeshellarg($candidate) . ' -r "echo PHP_VERSION;" 2>&1', $out, $rc);
            if ($rc === 0 && preg_match('/^\d+\.\d+\.\d+$/', trim(implode('', $out)))) {
                return $candidate;
            }
        }
        return 'php';
    }

    
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
                
                
                
                
                $pid = (int) ($status['pid'] ?? 0);
                $hasPosix = function_exists('posix_kill') && defined('SIGTERM') && defined('SIGKILL');
                if ($pid > 0 && $hasPosix) {
                    @posix_kill($pid, SIGTERM);
                }
                @proc_terminate($this->process);
                
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
