<?php
/**
 * modules/core/bootstrap.php — Bootstrap Terpusat MEeL-HUB
 *
 * Satu titik masuk untuk:
 * - Environment detection (MEEL_ENV) & display_errors
 * - Error logging konfigurasi
 * - Definisi APP_DEBUG (mengikuti MEEL_ENV)
 *
 * Catatan: HTTP security headers TIDAK di-set di sini — itu tanggung jawab
 * auth/config.php (X-Frame-Options, CSP, HSTS, dll).
 *
 * Cara pakai:
 * Di setiap file entry-point (index.php, watch.php, dll), ganti:
 * error_reporting(E_ALL);
 * ini_set('display_errors', 1);
 * menjadi:
 * require_once __DIR__ . '/../modules/core/bootstrap.php';
 *
 * @package MEeL\Core
 */

// Environment Detection
// MEEL_ENV: 'production' | 'development' | 'maintenance'
// Default ke production jika tidak didefinisikan di auth/config.php
if (!defined('MEEL_ENV')) {
    // Auto-detect: jika file ada di folder htdocs dan bukan localhost, anggap production
    $is_local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'], true)
             || (isset($_SERVER['SERVER_NAME']) && in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1'], true));
    define('MEEL_ENV', $is_local ? 'development' : 'production');
}

// APP_DEBUG (Debug Logging Guard)
// Guard untuk error_log di controllers/modules, pola pemakaian:
// if (defined('APP_DEBUG') && APP_DEBUG) { error_log(...); }
// Default otomatis: true di development, false di produksi.
// Override manual bisa dilakukan di auth/settings.php (sebelum bootstrap jalan):
// define('APP_DEBUG', true); // paksa aktif (debugging)
// define('APP_DEBUG', false); // paksa nonaktif
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', MEEL_ENV === 'development');
}

// Error Reporting
error_reporting(E_ALL);

switch (MEEL_ENV) {
    case 'production':
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('error_log', __DIR__ . '/../../logs/php_error.log');
        break;

    case 'development':
        ini_set('display_errors', '1');
        ini_set('log_errors', '1');
        ini_set('error_log', __DIR__ . '/../../logs/php_error.log');
        break;

    case 'maintenance':
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('error_log', __DIR__ . '/../../logs/php_error.log');
        break;

    default:
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        break;
}

// Base URL Constant Helper
// Pastikan MEEL_BASE_URL terdefinisi (fallback jika config.php belum di-load).
// Perhitungan dipusatkan di modules/core/base_url.php (root proyek relatif
// terhadap DOCUMENT_ROOT), BUKAN dari dirname(SCRIPT_NAME) — karena SCRIPT_NAME
// ikut direktori halaman aktif (mis. /MEeL/admin/index.php → /MEeL/admin),
// sehingga base_url() yang dihasilkan salah untuk halaman di subdirektori.
if (!defined('MEEL_BASE_URL') && isset($_SERVER['SCRIPT_NAME'])) {
    require_once __DIR__ . '/base_url.php';
    define('MEEL_BASE_URL', meel_base_url_path());
}

// Timezone
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Jakarta');
}
