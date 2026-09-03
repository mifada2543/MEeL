<?php


class RateLimiter
{
    
    private static string $storageDir = '';

    private static array $limits = [
        'like'        => ['requests' => 30, 'window' => 60],
        'comment'     => ['requests' => 10, 'window' => 60],
        'upload'      => ['requests' => 3,  'window' => 3600],
        'transcode'   => ['requests' => 5,  'window' => 3600],
        'api'         => ['requests' => 60, 'window' => 60],
    ];

    private static function init(): void
    {
        if (self::$storageDir === '') {
            self::$storageDir = __DIR__ . '/../../temp/ratelimit/';
            if (!is_dir(self::$storageDir)
                && !mkdir(self::$storageDir, 0755, true)
                && !is_dir(self::$storageDir)
            ) {
                error_log("[MEeL] RateLimiter: gagal membuat storage dir: " . self::$storageDir);
            }
        }
    }

    private static function filePath(string $key, string $endpoint): string
    {
        self::init();
        $hash = md5($key . '_' . $endpoint);
        return self::$storageDir . $hash . '.cache';
    }

    private static function readFile(string $path): array
    {

        if (!is_readable($path)) {
            return ['count' => 0, 'window_start' => time()];
        }
        $content = file_get_contents($path);
        $data    = $content !== false ? json_decode($content, true) : null;
        if (!is_array($data) || !isset($data['count'], $data['window_start'])) {
            return ['count' => 0, 'window_start' => time()];
        }
        return $data;
    }

    

    public static function getRoleLimit(int $baseLimit, string $role = 'user'): int
    {
        if ($role === 'member') {
            return $baseLimit * 2;
        }
        
        return $baseLimit;
    }

    

    public static function check(string $key, string $endpoint = 'api', string $role = 'user'): array
    {
        
        if ($role === 'admin') {
            $limitConfig = self::$limits[$endpoint] ?? self::$limits['api'];
            $window = $limitConfig['window'];
            return [
                'allowed'   => true,
                'remaining' => -1,
                'reset'     => time() + $window,
                'limit'     => 999999,
                'retry_after' => 0,
            ];
        }
        self::init();

        $limitConfig = self::$limits[$endpoint] ?? self::$limits['api'];
        $maxRequests = self::getRoleLimit($limitConfig['requests'], $role);
        $window      = $limitConfig['window'];
        $filePath    = self::filePath($key, $endpoint);

        $fp = null;
        if (is_dir(self::$storageDir) && is_writable(self::$storageDir)) {
            if (!is_file($filePath) || is_writable($filePath)) {
                $fp = fopen($filePath, 'c+');
            }
        }
        if (!$fp) {
            return ['allowed' => true, 'remaining' => $maxRequests, 'reset' => time() + $window, 'limit' => $maxRequests];
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return ['allowed' => true, 'remaining' => $maxRequests, 'reset' => time() + $window, 'limit' => $maxRequests];
        }

        $data = self::readFile($filePath);
        $now  = time();

        if (($now - $data['window_start']) >= $window) {
            $data = ['count' => 0, 'window_start' => $now];
        }

        $data['count']++;
        $remaining = max(0, $maxRequests - $data['count']);
        $reset     = $data['window_start'] + $window;

        $content = json_encode($data, JSON_UNESCAPED_UNICODE);
        ftruncate($fp, 0);
        fwrite($fp, $content);
        fflush($fp);

        flock($fp, LOCK_UN);
        fclose($fp);

        return [
            'allowed'   => $data['count'] <= $maxRequests,
            'remaining' => $remaining,
            'reset'     => $reset,
            'limit'     => $maxRequests,
            'retry_after' => max(0, $reset - $now),
        ];
    }

    public static function getRemaining(string $key, string $endpoint = 'api'): int
    {
        $filePath = self::filePath($key, $endpoint);
        $data = self::readFile($filePath);
        $limitConfig = self::$limits[$endpoint] ?? self::$limits['api'];
        $maxRequests = $limitConfig['requests'];

        if ((time() - $data['window_start']) >= $limitConfig['window']) {
            return $maxRequests;
        }

        return max(0, $maxRequests - $data['count']);
    }

    
    public static function cleanup(): int
    {
        self::init();
        $cleaned = 0;
        if (!is_dir(self::$storageDir)) {
            error_log("[MEeL] RateLimiter: storage dir tidak ada: " . self::$storageDir);
            return 0;
        }
        $files = scandir(self::$storageDir);
        if ($files === false) {
            error_log("[MEeL] RateLimiter: gagal membaca storage dir: " . self::$storageDir);
            return 0;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $path = self::$storageDir . $file;
            if (!is_file($path)) continue;

            $data = self::readFile($path);
            $maxWindow = 3600;
            if ((time() - $data['window_start']) > $maxWindow) {
                if (!unlink($path)) {
                    error_log("[MEeL] RateLimiter: gagal menghapus file rate limit: {$path}");
                }
                $cleaned++;
            }
        }
        return $cleaned;
    }

    
    public static function getStats(): array
    {
        self::init();
        $stats = [];
        if (!is_dir(self::$storageDir)) {
            error_log("[MEeL] RateLimiter: storage dir tidak ada: " . self::$storageDir);
            return $stats;
        }
        $files = scandir(self::$storageDir);
        if ($files === false) {
            error_log("[MEeL] RateLimiter: gagal membaca storage dir: " . self::$storageDir);
            return $stats;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $path = self::$storageDir . $file;
            if (!is_file($path)) continue;

            $data = self::readFile($path);
            $now  = time();

            $windowStart = $data['window_start'] ?? 0;
            if (($now - $windowStart) < 3600 && $data['count'] > 0) {
                $stats[] = [
                    'file'  => $file,
                    'count' => $data['count'],
                    'age'   => $now - $windowStart,
                ];
            }
        }

        return $stats;
    }

    
    public static function getLimitsConfig(): array
    {
        return self::$limits;
    }
}
