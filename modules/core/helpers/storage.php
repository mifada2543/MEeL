<?php
// helpers/storage.php — Storage, Disk & Thumbnail Helpers
// Bagian dari pecahan modules/core/helpers.php.
// Dimuat oleh helpers/main.php.
// Semua fungsi dibungkus function_exists() guard sebagai
// defense-in-depth terhadap double-include.
if (!function_exists('music_thumbnail_url')) {
function music_thumbnail_url(?string $thumbnail): string
{
    $thumbnail = trim((string)$thumbnail);
    $thumb_dir = __DIR__ . '/../../../music/upload/thumbnail/';
    $fallback  = '../assets/img/music0.webp';

    // Cache default path untuk menghindari is_file() berulang
    static $default_thumb = null;

    if ($thumbnail === '') {
        if ($default_thumb === null) {
            $default_thumb = is_file($thumb_dir . 'default.thumb.webp') ? 'upload/thumbnail/default.thumb.webp'
                : (is_file($thumb_dir . 'default.webp') ? 'upload/thumbnail/default.webp'
                : (is_file($thumb_dir . 'default.png') ? 'upload/thumbnail/default.png' : $fallback));
        }
        return $default_thumb;
    }

    $thumbnail = basename($thumbnail);
    if (str_ends_with($thumbnail, '.thumb.webp') && is_file($thumb_dir . $thumbnail)) {
        return 'upload/thumbnail/' . rawurlencode($thumbnail);
    }

    $base = preg_replace('/\\.thumb$/', '', pathinfo($thumbnail, PATHINFO_FILENAME)) ?: pathinfo($thumbnail, PATHINFO_FILENAME);

    // Cari dalam urutan prioritas
    $candidates = [
        $base . '.thumb.webp',
        $base . '.webp',
        $thumbnail
    ];
    foreach ($candidates as $candidate) {
        if (is_file($thumb_dir . $candidate)) {
            return 'upload/thumbnail/' . rawurlencode($candidate);
        }
    }

    if ($default_thumb === null) {
        $default_thumb = is_file($thumb_dir . 'default.thumb.webp') ? 'upload/thumbnail/default.thumb.webp'
            : (is_file($thumb_dir . 'default.webp') ? 'upload/thumbnail/default.webp'
            : (is_file($thumb_dir . 'default.png') ? 'upload/thumbnail/default.png' : $fallback));
    }
    return $default_thumb;
}
} // end function_exists('music_thumbnail_url')

if (PHP_SAPI !== 'cli' && !defined('MEEL_HDD_CHECKED')) {
    define('MEEL_HDD_CHECKED', true);
    if (defined('MEEL_HDD_BASE') && !is_dir(MEEL_HDD_BASE)) {
        error_log('[MEeL] Peringatan: MEEL_HDD_BASE tidak dapat diakses: ' . MEEL_HDD_BASE);
    }
}

/**
 * @param int $required_bytes Jumlah byte yang dibutuhkan
 * @param string $path Path untuk diperiksa (file atau direktori)
 * @return array ['ok' => bool, 'free' => float, 'required' => float, 'path' => string]
 */
if (!function_exists('check_disk_space')) {
function check_disk_space(int $required_bytes, string $path): array
{

    if (!is_dir($path)) {
        $path = dirname($path);
        // Traverse up jika parent tidak ditemukan
        $parent = dirname($path);
        while ($parent !== '/' && $parent !== '.' && !is_dir($parent)) {
            $parent = dirname($parent);
        }
        $path = $parent;
    }

    $free_bytes = disk_free_space($path);
    if ($free_bytes === false) {
        return [
            'ok'       => false,
            'free'     => 0,
            'required' => $required_bytes,
            'path'     => $path,
            'error'    => 'Tidak dapat membaca kapasitas disk.',
        ];
    }

    return [
        'ok'       => ($free_bytes >= $required_bytes),
        'free'     => $free_bytes,
        'required' => $required_bytes,
        'path'     => $path,
        'error'    => null,
    ];
}
} // end function_exists('check_disk_space')

/**
 * @param int $required_bytes Jumlah byte minimum yang diperlukan
 * @param string $path Path tujuan (folder HDD, RAM disk, dll)
 * @param string $label Label deskriptif (contoh: 'video storage', 'RAM disk')
 * @throws \RuntimeException Jika disk space tidak mencukupi
 */
if (!function_exists('require_disk_space')) {
function require_disk_space(int $required_bytes, string $path, string $label): void
{
    $result = check_disk_space($required_bytes, $path);
    if ($result['ok']) return;

    $free_gb  = sprintf('%.1f', $result['free'] / (1024 ** 3));
    $need_gb  = sprintf('%.1f', $result['required'] / (1024 ** 3));
    $error_ms = $result['error'] ?? "Hanya tersedia {$free_gb} GB, butuh minimal {$need_gb} GB";

    throw new \RuntimeException("Ruang {$label} tidak mencukupi! {$error_ms}");
}
} // end function_exists('require_disk_space')

/* Log drive operations untuk audit trail */
if (!function_exists('dir_size')) {
/**
 * @param string $path Path direktori
 * @param int $cache_ttl Cache TTL dalam detik (default 300 = 5 menit)
 * @return float Ukuran dalam bytes, atau 0 jika gagal
 */
function dir_size(string $path, int $cache_ttl = 300): float
{
    $cache_key  = 'dirsize_' . md5($path);
    $cache_file = dirname(__DIR__, 3) . '/temp/' . $cache_key . '.cache';

    // Cek cache
    if (is_readable($cache_file)) {
        $content = file_get_contents($cache_file);
        $cached  = $content !== false ? json_decode($content, true) : null;
        if ($cached && isset($cached['size'], $cached['time'])) {
            if (time() - $cached['time'] < $cache_ttl) {
                return (float)$cached['size'];
            }
        }
    }

    if (!is_dir($path)) return 0.0;

    // Metode 1: du -sb (cepat)
    $output = shell_exec("du -sb " . escapeshellarg($path) . " 2>/dev/null");
    if ($output && preg_match('/^(\d+)/', $output, $m)) {
        $size = (float)$m[1];
        // Simpan cache
        meel_write_cache_file($cache_file, json_encode(['size' => $size, 'time' => time()]));
        return $size;
    }

    // Metode 2: RecursiveIterator (fallback)
    $size = 0.0;
    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        // Simpan cache
        meel_write_cache_file($cache_file, json_encode(['size' => $size, 'time' => time()]));
    } catch (RuntimeException $e) {
        return 0.0;
    }
    return $size;
}
} // end function_exists('dir_size')

if (!function_exists('invalidate_dir_size_cache')) {
/* @param string $username Nama user */
function invalidate_dir_size_cache(string $username): void
{
    $userPath = dirname(__DIR__, 3) . '/data_drive/private_admins/' . $username;
    $cacheFile = dirname(__DIR__, 3) . '/temp/dirsize_' . md5($userPath) . '.cache';
    if (is_file($cacheFile)) {
        if (!is_writable(dirname($cacheFile))) {
            error_log("[MEeL] invalidate_dir_size_cache: direktori tidak writable: " . dirname($cacheFile));
        } elseif (!unlink($cacheFile)) {
            error_log("[MEeL] invalidate_dir_size_cache: gagal menghapus cache: {$cacheFile}");
        }
    }
}
} // end function_exists('invalidate_dir_size_cache')

if (!function_exists('meel_write_cache_file')) {
/* @param string $path Path file cache; @param string $content Isi file */
function meel_write_cache_file(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        error_log("[MEeL] storage.php: cache file tidak bisa ditulis: {$path}");
        return;
    }
    file_put_contents($path, $content, LOCK_EX);
}
} // end function_exists('meel_write_cache_file')

if (!function_exists('log_drive_operation')) {
function log_drive_operation(int $userId, string $username, string $operation, string $filename, string $type, string $scope, string $status = 'success'): void
{
    global $conn;

    $logDir = dirname(__DIR__, 3) . '/logs';
    if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
        error_log("[MEeL] log_drive_operation: gagal membuat log dir: {$logDir}");
    }

    $logFile = $logDir . '/drive_audit.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 200);

    $logEntry = json_encode([
        'timestamp' => $timestamp,
        'user_id' => $userId,
        'username' => $username,
        'operation' => $operation,
        'filename' => $filename,
        'type' => $type,
        'scope' => $scope,
        'status' => $status,
        'ip' => $ip,
        'user_agent' => $userAgent
    ]) . "\n";

    if (!is_dir($logDir) || !is_writable($logDir)) {
        error_log("[MEeL] log_drive_operation: log dir tidak writable: {$logDir}");
    } elseif (file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
        error_log("[MEeL] log_drive_operation: gagal menulis log: {$logFile}");
    }
}
} // end function_exists('log_drive_operation')
