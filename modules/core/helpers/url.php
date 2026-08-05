<?php

// ════════════════════════════════════════════════════════════════
// helpers/url.php — URL, Protocol & Format Helpers
//
// Bagian dari pecahan modules/core/helpers.php.
// Dimuat oleh helpers/main.php (jangan require langsung kecuali
// hanya butuh fungsi di file ini).
//
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
        // Fallback: root proyek relatif terhadap DOCUMENT_ROOT, konsisten dengan
        // modules/core/bootstrap.php. BUKAN dirname(SCRIPT_NAME) — karena
        // SCRIPT_NAME ikut direktori halaman aktif (mis. /MEeL/admin/index.php →
        // /MEeL/admin), sehingga base yang dihasilkan salah untuk halaman di
        // subdirektori (admin/, video/, dll).
        if (defined('MEEL_BASE_URL')) {
            $base = rtrim(MEEL_BASE_URL, '/');
        } else {
            // Fallback terpusat di modules/core/base_url.php (root proyek relatif
            // terhadap DOCUMENT_ROOT), konsisten dengan bootstrap.php & config.php.
            require_once __DIR__ . '/../base_url.php';
            $base = meel_base_url_path();
        }
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
