<?php

if (!function_exists('meel_media_base_path')) {
function meel_media_base_path(string $module): string
{
    $const = [
        'video' => 'MEEL_HDD_VIDEO_UPLOAD',
        'music' => 'MEEL_HDD_MUSIC_UPLOAD',
        'books' => 'MEEL_HDD_BOOKS_UPLOAD',
    ][$module] ?? null;
    if ($const !== null && defined($const)) {
        $v = (string) constant($const);
        if ($v !== '') {
            return rtrim($v, '/\\');
        }
    }
    return dirname(__DIR__, 3) . '/' . $module . '/upload';
}
}

if (!function_exists('music_thumbnail_url')) {
function music_thumbnail_url(?string $thumbnail): string
{
    $thumbnail = trim((string)$thumbnail);
    $thumb_dir = meel_media_base_path('music') . '/thumbnail/';
    $fallback  = '../assets/img/music0.webp';
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
}

if (PHP_SAPI !== 'cli' && !defined('MEEL_HDD_CHECKED')) {
    define('MEEL_HDD_CHECKED', true);
    if (defined('MEEL_HDD_BASE') && !is_dir(MEEL_HDD_BASE)) {
        error_log('[MEeL] Peringatan: MEEL_HDD_BASE tidak dapat diakses: ' . MEEL_HDD_BASE);
    }
}



if (!function_exists('meel_drive_base_path')) {
function meel_drive_base_path(?string $hddDriveOverride = null): string
{
    $hddDrive = $hddDriveOverride ?? (defined('MEEL_HDD_DRIVE') ? (string) MEEL_HDD_DRIVE : '');
    if ($hddDrive !== '') {
        return rtrim($hddDrive, '/\\');
    }
    return dirname(__DIR__, 3) . '/data_drive';
}
}



if (!function_exists('check_disk_space')) {
function check_disk_space(int $required_bytes, string $path): array
{

    if (!is_dir($path)) {
        $path = dirname($path);
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
}



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
}

if (!function_exists('dir_size')) {


function dir_size(string $path, int $cache_ttl = 300): float
{
    $cache_key  = 'dirsize_' . md5($path);
    $cache_file = dirname(__DIR__, 3) . '/temp/' . $cache_key . '.cache';

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

    $output = shell_exec("du -sb " . escapeshellarg($path) . " 2>/dev/null");
    if ($output && preg_match('/^(\d+)/', $output, $m)) {
        $size = (float)$m[1];
        meel_write_cache_file($cache_file, json_encode(['size' => $size, 'time' => time()]));
        return $size;
    }

    $size = 0.0;
    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        meel_write_cache_file($cache_file, json_encode(['size' => $size, 'time' => time()]));
    } catch (RuntimeException $e) {
        return 0.0;
    }
    return $size;
}
}

if (!function_exists('invalidate_dir_size_cache')) {

function invalidate_dir_size_cache(string $username): void
{
    $userPath = meel_drive_base_path() . '/private_admins/' . $username;
    $cacheFile = dirname(__DIR__, 3) . '/temp/dirsize_' . md5($userPath) . '.cache';
    if (is_file($cacheFile)) {
        if (!is_writable(dirname($cacheFile))) {
            error_log("[MEeL] invalidate_dir_size_cache: direktori tidak writable: " . dirname($cacheFile));
        } elseif (!unlink($cacheFile)) {
            error_log("[MEeL] invalidate_dir_size_cache: gagal menghapus cache: {$cacheFile}");
        }
    }
}
}

if (!function_exists('meel_write_cache_file')) {

function meel_write_cache_file(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        error_log("[MEeL] storage.php: cache file tidak bisa ditulis: {$path}");
        return;
    }
    file_put_contents($path, $content, LOCK_EX);
}
}

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
}



if (!function_exists('meel_serve_media_file')) {
function meel_serve_media_file(string $module, string $relPath, array $opts = []): void
{
    $base = meel_media_base_path($module);
    $baseReal = realpath($base);
    if ($baseReal === false) {
        http_response_code(503);
        exit('Storage tidak tersedia.');
    }

    $relPath = str_replace('\\', '/', (string) $relPath);
    $relPath = ltrim($relPath, '/');
    if ($relPath === '' || str_contains($relPath, '..') || str_contains($relPath, "\0")) {
        http_response_code(403);
        exit('Akses ditolak.');
    }

    $full = $base . '/' . $relPath;
    $realFull = realpath($full);
    if ($realFull === false || !str_starts_with($realFull, $baseReal . DIRECTORY_SEPARATOR)) {
        http_response_code(404);
        exit('File tidak ditemukan.');
    }
    if (!is_file($realFull) || !is_readable($realFull)) {
        http_response_code(404);
        exit('File tidak ditemukan.');
    }

    $ext = strtolower(pathinfo($realFull, PATHINFO_EXTENSION));
    $allowed = [
        'm3u8', 'ts', 'vtt', 'mp4', 'webm', 'mkv',
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf',
        'mp3', 'ogg', 'm4a', 'flac', 'wav', 'opus',
    ];
    if (!in_array($ext, $allowed, true)) {
        http_response_code(403);
        exit('Tipe file tidak diizinkan.');
    }

    $mimeMap = [
        'm3u8' => 'application/vnd.apple.mpegurl', 'ts' => 'video/mp2t',
        'vtt'  => 'text/vtt', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
        'mkv'  => 'video/x-matroska', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'pdf'  => 'application/pdf', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg',
        'm4a'  => 'audio/mp4', 'flac' => 'audio/flac', 'wav' => 'audio/wav',
        'opus' => 'audio/ogg',
    ];
    $mime = $mimeMap[$ext] ?? 'application/octet-stream';

    
    
    if (!empty($opts['hls_gate']) && (str_starts_with($relPath, 'video/') || str_contains($relPath, '/video/'))) {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $host    = $_SERVER['HTTP_HOST'] ?? '';
        $refOk   = false;
        if ($referer !== '' && $host !== '') {
            $parts = parse_url($referer);
            $hostNorm = strtolower(parse_url('http://' . $host, PHP_URL_HOST) ?: $host);
            if ($parts && isset($parts['host']) && strtolower($parts['host']) === $hostNorm) {
                $refPath = $parts['path'] ?? '';
                if (preg_match('#/video(?:/(?:watch(?:\.php)?|index(?:\.php)?|beranda))?(?:[?\#]|/?$)#i', $refPath)) {
                    $refOk = true;
                }
            }
        }
        if (!$refOk) {
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            $basePath = rtrim(dirname(dirname($script)), '/');
            header('Location: ' . $basePath . '/err/?code=denied');
            exit;
        }
    }

    $size = (int) @filesize($realFull);
    $start = 0;
    $end   = $size - 1;
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    $isPartial = false;
    if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
        $rStart = $m[1] !== '' ? (int) $m[1] : null;
        $rEnd   = $m[2] !== '' ? (int) $m[2] : null;
        if ($rStart === null && $rEnd === null) {
            $rStart = 0;
        }
        if ($rStart !== null) {
            $start = max(0, $rStart);
            $end   = ($rEnd !== null && $rEnd < $size) ? $rEnd : ($size - 1);
            if ($start > $end) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $isPartial = true;
        } elseif ($rEnd !== null) {
            $start = max(0, $size - $rEnd);
            $end   = $size - 1;
            $isPartial = true;
        }
    }

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($end - $start + 1));
    if ($isPartial) {
        header('HTTP/1.1 206 Partial Content');
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    set_time_limit(0);
    ignore_user_abort(false);

    $fp = @fopen($realFull, 'rb');
    if ($fp === false) {
        http_response_code(500);
        exit('Gagal membuka file.');
    }
    if ($start > 0) {
        fseek($fp, $start);
    }
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, min(8192, $remaining));
        if ($chunk === false) break;
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($fp);
    exit;
}
}
