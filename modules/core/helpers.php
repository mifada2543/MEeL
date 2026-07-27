<?php

// ════════════════════════════════════════════════════════════════
// Semua fungsi dibungkus function_exists() guard sebagai
// defense-in-depth terhadap double-include.
// ════════════════════════════════════════════════════════════════

if (!function_exists('resolve_binary')) {
    /**
     * Resolve binary path dari daftar kandidat.
     *
     * Urutan prioritas:
     *   1. Konstanta MEEL_FFMPEG_PATH/MEEL_FFPROBE_PATH/MEEL_NODE_PATH/MEEL_YTDLP_PATH
     *      (path absolut dari config — cegah binary-hijacking)
     *   2. Cek executable path absolut dari kandidat
     *   3. Auto-discovery via command -v (development mode)
     *
     * @param array $candidates Daftar kandidat path binary
     * @return string Path binary yang ditemukan
     */
    function resolve_binary(array $candidates): string
{
    // Level 1: Cek konstanta MEEL_*_PATH dari config (prioritas tertinggi — aman)
    static $const_map = null;
    if ($const_map === null) {
        $const_map = [];
        foreach (['ffmpeg', 'ffprobe', 'node', 'yt-dlp'] as $bin) {
            $const = 'MEEL_' . strtoupper($bin) . '_PATH';
            if (defined($const) && ($val = constant($const)) !== '') {
                $const_map[$bin] = $val;
            }
        }
    }

    foreach ($candidates as $candidate) {
        $base = basename($candidate);
        if (isset($const_map[$base]) && is_executable($const_map[$base])) {
            return $const_map[$base];
        }
    }

    // Level 2: Cek executable path absolut
    foreach ($candidates as $candidate) {
        if (strpos($candidate, '/') !== false) {
            if (is_executable($candidate)) return $candidate;
            continue;
        }
        $resolved = trim((string)shell_exec("command -v " . escapeshellarg($candidate) . " 2>/dev/null"));
        if ($resolved !== '') return $resolved;
    }
    return $candidates[0];
}
} // end function_exists('resolve_binary')

if (!function_exists('base_url')) {
/**
 * Generate base URL untuk path portability.
 * Menggantikan hardcoded /MEeL/ prefix dengan path dinamis.
 */
function base_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        // Prioritas: konstanta MEEL_BASE_URL (didefinisikan di config.php)
        // Fallback: deteksi otomatis dari SCRIPT_NAME
        $base = defined('MEEL_BASE_URL')
            ? rtrim(MEEL_BASE_URL, '/')
            : rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    }
    return $base . '/' . ltrim($path, '/');
}
} // end function_exists('base_url')

if (!function_exists('detectProtocol')) {
/**
 * Deteksi protokol HTTPS/HTTP dengan dukungan proxy/Cloudflare.
 * Cloudflare Tunnel menghubungkan origin via HTTP, sehingga $_SERVER['HTTPS']
 * tidak ter-set. Sebagai gantinya, Cloudflare mengirim header:
 *   - HTTP_X_FORWARDED_PROTO: https
 *   - HTTP_CF_VISITOR: {"scheme":"https"}
 */
function detectProtocol(): string
{
    // 1. Standard HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return 'https';
    }
    // 2. Forwarded proto (Cloudflare, Nginx, Apache mod_proxy, dll)
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return 'https';
    }
    // 3. Cloudflare CF-Visitor header
    if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
        $cf = @json_decode($_SERVER['HTTP_CF_VISITOR'], true);
        if (!empty($cf['scheme']) && $cf['scheme'] === 'https') {
            return 'https';
        }
    }
    // 4. Forwarded scheme
    if (!empty($_SERVER['HTTP_X_FORWARDED_SCHEME']) && strtolower($_SERVER['HTTP_X_FORWARDED_SCHEME']) === 'https') {
        return 'https';
    }
    // 5. Fallback
    return 'http';
}
} // end function_exists('detectProtocol')

if (!function_exists('time_ago')) {
function time_ago(string|int $timestamp): string
{
    $time_diff = time() - (is_int($timestamp) ? $timestamp : strtotime($timestamp));
    if ($time_diff < 1) return 'Baru saja';
    $condition = [31104000 => 'tahun', 2592000 => 'bulan', 86400 => 'hari', 3600 => 'jam', 60 => 'menit', 1 => 'detik'];
    foreach ($condition as $secs => $str) {
        $d = $time_diff / $secs;
        if ($d >= 1) return round($d) . ' ' . $str . ' yang lalu';
    }
    return 'Baru saja';
}
} // end function_exists('time_ago')

if (!function_exists('format_bytes')) {
function format_bytes(int|float $bytes, int $precision = 2): string
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
} // end function_exists('format_bytes')

if (!function_exists('music_thumbnail_url')) {
function music_thumbnail_url(?string $thumbnail): string
{
    $thumbnail = trim((string)$thumbnail);
    $thumb_dir = __DIR__ . '/../../music/upload/thumbnail/';
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

// ─── HDD Check Side Effect ────────────────────────────────────
// Versi ringan: hanya log warning, TIDAK redirect ke maintenance.
// Redirect terlalu agresif — membuat semua page error jika HDD path belum dikonfig.
// Cek HDD yang sesungguhnya sudah dilakukan di Uploader/Transcoder via require_disk_space().
if (PHP_SAPI !== 'cli' && !defined('MEEL_HDD_CHECKED')) {
    define('MEEL_HDD_CHECKED', true);
    if (defined('MEEL_HDD_BASE') && !is_dir(MEEL_HDD_BASE)) {
        error_log('[MEeL] Peringatan: MEEL_HDD_BASE tidak dapat diakses: ' . MEEL_HDD_BASE);
    }
}
if (!function_exists('get_user_usage')) {
function get_user_usage(string $username): int|float
{
    $path = dirname(__DIR__, 2) . "/data_drive/private_admins/" . $username;
    if (!is_dir($path)) return 0;

    // Delegasikan ke dir_size() yang sudah memiliki cache + fallback
    return dir_size($path);
}
} // end function_exists('get_user_usage')

/**
 * Get user role dengan cache session + static cache per request.
 * Prioritas:
 *   1. Static cache (per-request, tercepat)
 *   2. $_SESSION['role'] (lintas request, mengurangi query DB)
 *   3. Query DB (jika belum ada di cache)
 *
 * Setelah role diambil dari DB, simpan ke session agar request
 * berikutnya tidak perlu query ulang.
 *
 * @param \mysqli $conn   Koneksi database
 * @param int     $user_id ID user
 * @return string Role user ('admin', 'member', 'user', 'guest')
 */
if (!function_exists('get_user_role')) {
function get_user_role(mysqli $conn, int $user_id): string
{
    // Level 1: Static cache per-request (paling cepat)
    static $cache = [];
    if (isset($cache[$user_id])) {
        return $cache[$user_id];
    }

    // Level 2: Session cache (lintas request, cegah query tiap halaman)
    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $user_id && isset($_SESSION['role'])) {
        $role = $_SESSION['role'];
        $cache[$user_id] = $role;
        return $role;
    }

    // Level 3: Query database
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $role = $stmt->get_result()->fetch_assoc()['role'] ?? 'user';
    $stmt->close();

    // Simpan ke cache
    $cache[$user_id] = $role;

    // Simpan ke session jika ini user yang sedang login
    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $user_id) {
        $_SESSION['role'] = $role;
    }

    return $role;
}
} // end function_exists('get_user_role')

/**
 * Invalidate role cache di session — panggil saat role user berubah.
 */
if (!function_exists('invalidate_user_role_cache')) {
function invalidate_user_role_cache(): void
{
    unset($_SESSION['role']);
}
} // end function_exists('invalidate_user_role_cache')

/**
 * Get CSRF token dari session (sudah diinisialisasi di config.php)
 */
if (!function_exists('get_csrf_token')) {
function get_csrf_token(): string
{
    return $_SESSION['csrf_token'] ?? '';
}
} // end function_exists('get_csrf_token')

/**
 * Verifikasi CSRF token — fungsi terpadu.
 * Bisa untuk GET (query string) atau POST (form body).
 *
 * @param string|null $token Token CSRF (opsional). Jika null, ambil dari $_POST['csrf_token']
 * @return bool True jika token valid
 */
if (!function_exists('verify_csrf_token')) {
function verify_csrf_token(?string $token = null): bool
{
    if ($token === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
    }
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}
} // end function_exists('verify_csrf_token')

// ════════════════════════════════════════════════════════════════
// PRE-FLIGHT DISK SPACE HELPERS
// ════════════════════════════════════════════════════════════════

/**
 * Periksa apakah path memiliki ruang disk yang cukup.
 *
 * @param int    $required_bytes Jumlah byte yang dibutuhkan
 * @param string $path           Path untuk diperiksa (file atau direktori)
 * @return array ['ok' => bool, 'free' => float, 'required' => float, 'path' => string]
 */
if (!function_exists('check_disk_space')) {
function check_disk_space(int $required_bytes, string $path): array
{
    // Jika path bukan direktori (kemungkinan file), ambil direktori parent-nya
    if (!is_dir($path)) {
        $path = dirname($path);
        // Traverse up jika parent tidak ditemukan
        $parent = dirname($path);
        while ($parent !== '/' && $parent !== '.' && !is_dir($parent)) {
            $parent = dirname($parent);
        }
        $path = $parent;
    }

    $free_bytes = @disk_free_space($path);
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
 * Pre-flight check: lempar RuntimeException jika ruang disk tidak cukup.
 *
 * @param int    $required_bytes Jumlah byte minimum yang diperlukan
 * @param string $path           Path tujuan (folder HDD, RAM disk, dll)
 * @param string $label          Label deskriptif (contoh: 'video storage', 'RAM disk')
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

// ════════════════════════════════════════════════════════════════
// AUDIO MIME & FORMAT HELPERS
// ════════════════════════════════════════════════════════════════

if (!function_exists('get_audio_mime_type')) {
/**
 * Dapatkan MIME type untuk ekstensi file audio.
 *
 * @param string $ext Ekstensi file (mp3, ogg, flac, dll)
 * @return string MIME type yang sesuai
 */
function get_audio_mime_type(string $ext): string
{
    return match (strtolower($ext)) {
        'mp3'        => 'audio/mpeg',
        'm4a'        => 'audio/mp4',
        'ogg', 'opus' => 'audio/ogg',
        'flac'       => 'audio/flac',
        'wav'        => 'audio/wav',
        default      => 'audio/ogg',
    };
}
} // end function_exists('get_audio_mime_type')

if (!function_exists('get_audio_format_label')) {
/**
 * Dapatkan label format audio yang user-friendly.
 *
 * @param string $ext Ekstensi file (mp3, ogg, flac, dll)
 * @return string Label format (MP3, OPUS, FLAC, dll)
 */
function get_audio_format_label(string $ext): string
{
    $lower = strtolower($ext);
    return strtoupper($lower === 'ogg' ? 'OPUS' : $lower);
}
} // end function_exists('get_audio_format_label')

if (!function_exists('get_audio_format_description')) {
/**
 * Dapatkan deskripsi singkat untuk format audio.
 *
 * @param string $ext Ekstensi file
 * @return string Deskripsi format
 */
function get_audio_format_description(string $ext): string
{
    return match (strtolower($ext)) {
        'ogg', 'opus' => 'Opus adalah codec audio modern untuk web',
        'm4a'         => 'M4a adalah codec audio terbaik dalam hal kompatibilitas',
        'mp3'         => 'Ini adalah codec audio universal yang sangat populer',
        'flac'        => 'Ini adalah codec audio yang memiliki kualitas audio terbaik',
        default       => 'Format audio tidak dikenal',
    };
}
} // end function_exists('get_audio_format_description')

/**
 * Log drive operations untuk audit trail
 */
if (!function_exists('dir_size')) {
/**
 * Hitung ukuran direktori dengan cache.
 * Menggantikan duplikasi shell_exec("du -sb ...") di helpers.php dan System.php.
 *
 * @param string $path       Path direktori
 * @param int    $cache_ttl  Cache TTL dalam detik (default 300 = 5 menit)
 * @return float Ukuran dalam bytes, atau 0 jika gagal
 */
function dir_size(string $path, int $cache_ttl = 300): float
{
    $cache_key  = 'dirsize_' . md5($path);
    $cache_file = dirname(__DIR__, 2) . '/temp/' . $cache_key . '.cache';

    // Cek cache
    if (file_exists($cache_file)) {
        $cached = @json_decode(@file_get_contents($cache_file), true);
        if ($cached && isset($cached['size'], $cached['time'])) {
            if (time() - $cached['time'] < $cache_ttl) {
                return (float)$cached['size'];
            }
        }
    }

    if (!is_dir($path)) return 0.0;

    // Metode 1: du -sb (cepat)
    $output = @shell_exec("du -sb " . escapeshellarg($path) . " 2>/dev/null");
    if ($output && preg_match('/^(\d+)/', $output, $m)) {
        $size = (float)$m[1];
        // Simpan cache
        @file_put_contents($cache_file, json_encode(['size' => $size, 'time' => time()]), LOCK_EX);
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
        @file_put_contents($cache_file, json_encode(['size' => $size, 'time' => time()]), LOCK_EX);
    } catch (RuntimeException $e) {
        return 0.0;
    }
    return $size;
}
} // end function_exists('dir_size')

if (!function_exists('log_drive_operation')) {
function log_drive_operation(int $userId, string $username, string $operation, string $filename, string $type, string $scope, string $status = 'success'): void
{
    global $conn;
    
    $logDir = dirname(__DIR__, 2) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
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
    
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}
} // end function_exists('log_drive_operation')

// ════════════════════════════════════════════════════════════════
// MFA / TOTP HELPERS
// ════════════════════════════════════════════════════════════════

if (!function_exists('base32_decode')) {
/**
 * Decode base32 string (RFC 4648) ke binary.
 *
 * @param string $input Base32-encoded string
 * @return string Decoded binary string
 */
function base32_decode(string $input): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper($input);
    $output = '';
    $buffer = 0;
    $bits_left = 0;

    for ($i = 0; $i < strlen($input); $i++) {
        $ch = $input[$i];
        if ($ch === '=') break;
        $pos = strpos($alphabet, $ch);
        if ($pos === false) continue;

        $buffer = ($buffer << 5) | $pos;
        $bits_left += 5;
        if ($bits_left >= 8) {
            $bits_left -= 8;
            $output .= chr(($buffer >> $bits_left) & 0xff);
        }
    }
    return $output;
}
} // end function_exists('base32_decode')

if (!function_exists('generate_mfa_secret')) {
/**
 * Generate random base32 secret untuk TOTP (32 karakter).
 *
 * @return string Base32 secret key
 */
function generate_mfa_secret(): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 32; $i++) {
        $secret .= $chars[random_int(0, 31)];
    }
    return $secret;
}
} // end function_exists('generate_mfa_secret')

if (!function_exists('generate_totp')) {
/**
 * Generate kode TOTP 6-digit (RFC 6238) untuk secret & waktu tertentu.
 *
 * @param string   $secret     Base32 secret key
 * @param int|null $time_slice Unix timestamp / 30 (null = waktu sekarang)
 * @return string Kode 6-digit
 */
function generate_totp(string $secret, ?int $time_slice = null): string
{
    if ($time_slice === null) {
        $time_slice = (int)floor(time() / 30);
    }

    $secret_bin = base32_decode($secret);
    $time_bytes = pack('J', $time_slice);
    $hmac = hash_hmac('sha1', $time_bytes, $secret_bin, true);

    $offset = ord($hmac[19]) & 0x0f;
    $code = (ord($hmac[$offset]) & 0x7f) << 24
          | (ord($hmac[$offset + 1]) & 0xff) << 16
          | (ord($hmac[$offset + 2]) & 0xff) << 8
          | (ord($hmac[$offset + 3]) & 0xff);

    return str_pad((string)($code % 1000000), 6, '0', STR_PAD_LEFT);
}
} // end function_exists('generate_totp')

if (!function_exists('verify_totp')) {
/**
 * Verifikasi kode TOTP dengan toleransi window ±1 interval (total 3 kemungkinan).
 *
 * @param string $secret Base32 secret key
 * @param string $code   Kode 6-digit yang dimasukkan user
 * @return bool True jika valid
 */
function verify_totp(string $secret, string $code): bool
{
    $time_slice = (int)floor(time() / 30);
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(generate_totp($secret, $time_slice + $i), $code)) {
            return true;
        }
    }
    return false;
}
} // end function_exists('verify_totp')

if (!function_exists('generate_otpauth_url')) {
/**
 * Generate otpauth:// URL untuk ditampilkan sebagai QR code.
 *
 * @param string $secret   Base32 secret
 * @param string $username Username pengguna
 * @return string OTP Auth URL
 */
function generate_otpauth_url(string $secret, string $username): string
{
    $issuer = 'MEeL';
    $label = rawurlencode("$issuer:$username");
    return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}";
}
} // end function_exists('generate_otpauth_url')

if (!function_exists('generate_backup_codes')) {
/**
 * Generate 8 backup codes (masing-masing 8 digit angka) dan return
 * array dengan codes plain text + hashed.
 *
 * @return array ['plain' => string[], 'hashed' => string[]]
 */
function generate_backup_codes(): array
{
    $plain = [];
    $hashed = [];
    for ($i = 0; $i < 8; $i++) {
        // 6 digit angka acak — kompatibel dengan input field maxlength=6 di halaman verifikasi
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $plain[] = $code;
        $hashed[] = password_hash($code, PASSWORD_DEFAULT);
    }
    return ['plain' => $plain, 'hashed' => $hashed];
}
} // end function_exists('generate_backup_codes')

if (!function_exists('verify_backup_code')) {
/**
 * Verifikasi backup code, dan return array sisa kode jika valid.
 *
 * @param string $hashed_json JSON array of hashed backup codes dari DB
 * @param string $input       Input user
 * @return array ['valid' => bool, 'remaining' => string[]|null]
 */
function verify_backup_code(string $hashed_json, string $input): array
{
    $codes = json_decode($hashed_json, true);
    if (!is_array($codes)) {
        return ['valid' => false, 'remaining' => null];
    }

    foreach ($codes as $i => $hash) {
        if (password_verify($input, $hash)) {
            // Hapus kode yang sudah dipakai
            array_splice($codes, $i, 1);
            return ['valid' => true, 'remaining' => $codes];
        }
    }
    return ['valid' => false, 'remaining' => null];
}
} // end function_exists('verify_backup_code')
