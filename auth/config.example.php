<?php
/**
 * MEeL-HUB — Contoh Konfigurasi Aplikasi
 * 
 * ★ PERBAIKAN v2: Autoloader terintegrasi
 *    Semua class (Uploader, Transcoder, MediaLibrary, dll) akan
 *    otomatis di-load tanpa require_once manual.
 *    Lihat modules/autoload.php untuk daftar class yang tersedia.
 * 
 * Copy file ini ke config.php dan sesuaikan dengan environment Anda:
 *   cp config.example.php config.php
 * 
 * ─── PORTABILITY TIP ───
 * Semua path penyimpanan media terpusat di konstanta MEEL_HDD_BASE.
 * Cukup ubah nilai MEEL_HDD_BASE, seluruh sistem akan mengikuti.
 * 
 * Contoh:
 *   - HDD eksternal: /media/[user]/MEeL/media
 *   - Lokal SSD:     /var/www/meel-storage/media
 *   - Docker volume: /data/media
 *   - Relative:      __DIR__ . '/../storage/media'
 */

// ════════════════════════════════════════════════════════════════
// BOOTSTRAP (Error Handling Terpusat)
// ════════════════════════════════════════════════════════════════
// Bootstrap menangani display_errors, error_log, dan timezone
// berdasarkan environment (production/development).
// Tidak perlu lagi mengatur ini di file individual.
require_once __DIR__ . '/../modules/core/bootstrap.php';

// ════════════════════════════════════════════════════════════════
// ENVIRONMENT (Timpa auto-detect bootstrap jika perlu)
// ════════════════════════════════════════════════════════════════
// Uncomment salah satu baris di bawah untuk menetapkan environment secara manual:
// define('MEEL_ENV', 'production');
// define('MEEL_ENV', 'development');
// define('MEEL_ENV', 'maintenance');

// ════════════════════════════════════════════════════════════════
// DATABASE CONFIGURATION
// ════════════════════════════════════════════════════════════════

$server   = "localhost";     // Host database (localhost atau IP)
$username = "root";          // Username database
$password = "";              // Password database
$db       = "MEeL";          // Nama database

$conn = new mysqli($server, $username, $password, $db);
if ($conn->connect_error) {
    die("[MEeL SYSTEM ERROR]\nKoneksi ke database gagal: " . $conn->connect_error);
}

// ════════════════════════════════════════════════════════════════
// BASE URL & HOST (PATH PORTABILITY & SECURITY)
// ════════════════════════════════════════════════════════════════
// Gunakan untuk menggantikan hardcoded /MEeL/ prefix di redirect dan link.
// Dihitung dari lokasi file ini (auth/config.php), sehingga konsisten
// meskipun di-include dari berbagai kedalaman direktori.
$project_root = str_replace('\\', '/', dirname(__DIR__));
$doc_root     = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '/');
$relative     = substr($project_root, strlen(rtrim($doc_root, '/')));
define('MEEL_BASE_URL', rtrim($relative, '/'));

// ════════════════════════════════════════════════════════════════
// HOST CONSTANT (CEGAH OPEN REDIRECT)
// ════════════════════════════════════════════════════════════════
// Gunakan untuk validasi referer/open redirect, bukan $_SERVER['HTTP_HOST']
// yang bisa dipalsukan. Set nilai ini sesuai hostname server Anda.
// Contoh:
//   define('MEEL_HOST', 'meel.example.com');
//   define('MEEL_HOST', '192.168.1.100');
// Biarkan kosong untuk fallback ke HTTP_HOST (kurang aman, tapi kompatibel).
if (!defined('MEEL_HOST')) {
    define('MEEL_HOST', $_SERVER['HTTP_HOST'] ?? '');
}

// ════════════════════════════════════════════════════════════════
// BINARY PATH CONSTANTS (CEGAH BINARY-HIJACKING)
// ════════════════════════════════════════════════════════════════
// Set path absolut untuk mencegah binary-hijacking via PATH environment.
// Biarkan kosong untuk auto-discovery (hanya untuk development).
//
// Cara setting:
//   define('MEEL_FFMPEG_PATH', '/usr/bin/ffmpeg');
//   define('MEEL_FFPROBE_PATH', '/usr/bin/ffprobe');
//   define('MEEL_NODE_PATH', '/usr/bin/node');
//   define('MEEL_YTDLP_PATH', '/usr/local/bin/yt-dlp');
if (!defined('MEEL_FFMPEG_PATH')) {
    define('MEEL_FFMPEG_PATH', '');
}
if (!defined('MEEL_FFPROBE_PATH')) {
    define('MEEL_FFPROBE_PATH', '');
}
if (!defined('MEEL_NODE_PATH')) {
    define('MEEL_NODE_PATH', '');
}
if (!defined('MEEL_YTDLP_PATH')) {
    define('MEEL_YTDLP_PATH', '');
}

// ════════════════════════════════════════════════════════════════
// MEDIA STORAGE PATHS (TERPUSAT)
// ════════════════════════════════════════════════════════════════
// ★ UBAH DI SINI jika ingin memindahkan lokasi penyimpanan media
//    Semua modul (Video, Music, Books, Drive) akan mengikuti path ini
// ★ Untuk HDD eksternal, pastikan sudah di-mount dan writable

define('MEEL_HDD_BASE', '/path/to/your/media');

// ── Path turunan (jangan diubah kecuali paham struktur folder) ──
define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
define('MEEL_HDD_VIDEO_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'video/');
define('MEEL_HDD_THUMB_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'thumbnail/');
define('MEEL_HDD_MUSIC_UPLOAD', MEEL_HDD_BASE . '/music/upload/');
define('MEEL_HDD_BOOKS_UPLOAD', MEEL_HDD_BASE . '/books/upload/');
define('MEEL_HDD_DRIVE',        MEEL_HDD_BASE . '/drive/');

// ════════════════════════════════════════════════════════════════
// X-SENDFILE (AKSELERASI STREAMING APACHE)
// ════════════════════════════════════════════════════════════════
// Aktifkan untuk streaming file besar (FLAC 33MB+, MKV 4K, dll)
// tanpa beban PHP. Apache akan mengirim file langsung dari disk
// menggunakan sistem call sendfile() (zero-copy ke socket).
//
// 🚀 Manfaat:
//   - PHP tidak perlu baca file sama sekali → RAM server hemat
//   - Proses PHP tidak terblokir → bisa layani request lain
//   - File besar streaming 2-4x lebih cepat
//   - Skalabel untuk banyak user concurrent
//
// 🔧 Cara aktivasi:
//   1. Download & compile: https://github.com/nmaier/mod_xsendfile
//   2. Copy mod_xsendfile.so ke direktori modules Apache
//   3. Tambahkan di httpd.conf:
//        LoadModule xsendfile_module modules/mod_xsendfile.so
//        <IfModule xsendfile_module>
//            XSendFile on
//            XSendFilePath "/path/ke/music/upload/file"
//        </IfModule>
//   4. Restart Apache: sudo /opt/lampp/lampp restart
//   5. Verifikasi: httpd -M | grep xsend
//   6. Set konstanta di bawah menjadi true:

define('MEEL_USE_XSENDFILE', false);

// ════════════════════════════════════════════════════════════════
// SESSION & SECURITY
// ════════════════════════════════════════════════════════════════

$timeout = 43200; // 12 jam session lifetime
ini_set('session.gc_maxlifetime', $timeout);
session_set_cookie_params($timeout, "/");
session_name('meel');
session_start();

// ════════════════════════════════════════════════════════════════
// AUTOLOADER
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../modules/autoload.php';

// ── Security Headers ──
if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    header("Cross-Origin-Opener-Policy: same-origin");
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header("Strict-Transport-Security: max-age=15552000; includeSubDomains");
    }
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'self'; frame-ancestors 'self'; form-action 'self'; img-src 'self' data: blob:; media-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");
}

// ── CSRF Token ──
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── CSRF Verification ──
if (!function_exists('verify_csrf')) {
    function verify_csrf()
    {
        // Delegasikan ke verify_csrf_token() yang menggunakan hash_equals()
        // untuk timing-attack safety
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return verify_csrf_token($_POST['csrf_token'] ?? '');
        }
        return true;
    }
}

// ── Session Timeout Check ──
if (isset($_SESSION['LAST_ACTIVITY'])) {
    $elapsed_time = time() - $_SESSION['LAST_ACTIVITY'];
    if ($elapsed_time > $timeout) {
        session_unset();
        session_destroy();
        header("Location: ../auth/login.php?reason=expired");
        exit;
    }
}
$_SESSION['LAST_ACTIVITY'] = time();

// ── Activity Logger ──
include_once __DIR__ . '/../modules/core/activity_logger.php';
