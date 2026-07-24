<?php
/**
 * modules/core/RateLimiter.php
 *
 * File-based rate limiter untuk proteksi endpoint dari abuse.
 * Menggunakan file storage di temp/ratelimit/ — tanpa perlu schema DB tambahan.
 *
 * Cara pakai:
 *   require_once 'modules/core/RateLimiter.php';
 *   $check = RateLimiter::check('user_'.$userId, 'like');
 *   if (!$check['allowed']) {
 *       http_response_code(429);
 *       header('Retry-After: ' . $check['reset']);
 *       exit;
 *   }
 *
 * @package MEeL\Modules
 */

class RateLimiter
{
    /** Direktori penyimpanan file rate limit */
    private static string $storageDir = '';

    /**
     * Definisi limit per endpoint.
     *   key   => ['requests' => max request, 'window' => window in seconds]
     */
    private static array $limits = [
        // Endpoint spesifik
        'like'        => ['requests' => 30, 'window' => 60],  // 30 likes/menit
        'comment'     => ['requests' => 10, 'window' => 60],  // 10 comments/menit
        'upload'      => ['requests' => 3,  'window' => 3600], // 3 upload/jam
        'transcode'   => ['requests' => 5,  'window' => 3600], // 5 transcode/jam
        // Generic
        'api'         => ['requests' => 60, 'window' => 60],   // 60 request/menit
    ];

    /**
     * Inisialisasi storage directory.
     */
    private static function init(): void
    {
        if (self::$storageDir === '') {
            self::$storageDir = __DIR__ . '/../../temp/ratelimit/';
            if (!is_dir(self::$storageDir)) {
                @mkdir(self::$storageDir, 0755, true);
            }
        }
    }

    /**
     * Dapatkan path file untuk key + endpoint tertentu.
     */
    private static function filePath(string $key, string $endpoint): string
    {
        self::init();
        // Hash key untuk keamanan nama file
        $hash = md5($key . '_' . $endpoint);
        return self::$storageDir . $hash . '.cache';
    }

    /**
     * Parse file cache menjadi array data.
     */
    private static function readFile(string $path): array
    {
        if (!file_exists($path)) {
            return ['count' => 0, 'window_start' => time()];
        }
        $data = @json_decode(@file_get_contents($path), true);
        if (!is_array($data) || !isset($data['count'], $data['window_start'])) {
            return ['count' => 0, 'window_start' => time()];
        }
        return $data;
    }

    /**
     * Single source of truth untuk role-based limit adjustment.
     *
     * @param int    $baseLimit Limit dasar untuk user biasa
     * @param string $role      Role user ('member', 'user', dll)
     * @return int Limit yang sudah disesuaikan dengan role
     */
    public static function getRoleLimit(int $baseLimit, string $role = 'user'): int
    {
        // Member mendapat 2x lipat dari user biasa
        if ($role === 'member') {
            return $baseLimit * 2;
        }
        // User, guest, dan role lain pakai limit dasar
        return $baseLimit;
    }

    /**
     * Periksa apakah request diizinkan.
     *
     * @param string $key      Identifier unik (misal: 'user_5', 'ip_192.168.1.1')
     * @param string $endpoint Nama endpoint ('like', 'comment', 'api', etc.)
     * @param string $role     Role user ('admin', 'member', 'user'). Admin bebas limit, member 2x lipat.
     * @return array ['allowed' => bool, 'remaining' => int, 'reset' => int, 'limit' => int]
     */
    public static function check(string $key, string $endpoint = 'api', string $role = 'user'): array
    {
        // ── Admin bebas dari rate limiter ───────────────────────────────────
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

        // Lock file untuk race condition safety
        $fp = @fopen($filePath, 'c+');
        if (!$fp) {
            // Fallback: jika file tak bisa dibuka, izinkan request
            return ['allowed' => true, 'remaining' => $maxRequests, 'reset' => time() + $window, 'limit' => $maxRequests];
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return ['allowed' => true, 'remaining' => $maxRequests, 'reset' => time() + $window, 'limit' => $maxRequests];
        }

        $data = self::readFile($filePath);
        $now  = time();

        // Reset window jika sudah lewat
        if (($now - $data['window_start']) >= $window) {
            $data = ['count' => 0, 'window_start' => $now];
        }

        $data['count']++;
        $remaining = max(0, $maxRequests - $data['count']);
        $reset     = $data['window_start'] + $window;

        // Tulis ulang file
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

    /**
     * Dapatkan sisa request tanpa increment counter (read-only).
     */
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

    /**
     * Bersihkan file rate limit yang expired (panggil dari GarbageCollector).
     *
     * @return int Jumlah file yang dibersihkan
     */
    public static function cleanup(): int
    {
        self::init();
        $cleaned = 0;
        $files = @scandir(self::$storageDir);
        if (!$files) return 0;

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $path = self::$storageDir . $file;
            if (!is_file($path)) continue;

            $data = self::readFile($path);
            $maxWindow = 3600; // 1 jam max window
            if ((time() - $data['window_start']) > $maxWindow) {
                @unlink($path);
                $cleaned++;
            }
        }
        return $cleaned;
    }

    /**
     * Dapatkan summary rate limit untuk admin dashboard.
     *
     * @return array ['endpoint' => ['key_count' => int, 'active' => int], ...]
     */
    public static function getStats(): array
    {
        self::init();
        $stats = [];
        $files = @scandir(self::$storageDir);
        if (!$files) return $stats;

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $path = self::$storageDir . $file;
            if (!is_file($path)) continue;

            $data = self::readFile($path);
            $now  = time();

            // Ekstrak endpoint dari key — sebenarnya endpoint tidak bisa
            // diekstrak dari hash. Kita baca data dan hitung active count.
            $windowStart = $data['window_start'] ?? 0;
            if (($now - $windowStart) < 3600 && $data['count'] > 0) {
                // Active rate limiter entry
                $stats[] = [
                    'file'  => $file,
                    'count' => $data['count'],
                    'age'   => $now - $windowStart,
                ];
            }
        }

        return $stats;
    }

    /**
     * Dapatkan konfigurasi limits untuk display.
     */
    public static function getLimitsConfig(): array
    {
        return self::$limits;
    }
}
