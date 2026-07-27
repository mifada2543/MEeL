<?php
/**
 * MEeL-HUB — Konfigurasi Aplikasi
 *
 * ═══════════════════════════════════════════════════════════════════
 * ★ PENTING — Jangan hapus guard !defined() di sekitar konstanta.
 *   File ini bisa di-include dari berbagai entry point (index.php,
 *   auth/auth.php, file admin, dll), guard mencegah redeclare error.
 * ═══════════════════════════════════════════════════════════════════
 */

// ════════════════════════════════════════════════════════════════
// BOOTSTRAP (Error Handling Terpusat)
// ════════════════════════════════════════════════════════════════
// Bootstrap menangani display_errors, error_log, dan timezone
// berdasarkan environment (production/development).
// Tidak perlu lagi mengatur ini di file individual.
require_once __DIR__ . '/../modules/core/bootstrap.php';

// ════════════════════════════════════════════════════════════════
// ENVIRONMENT (Override auto-detect bootstrap jika perlu)
// ════════════════════════════════════════════════════════════════
// Uncomment salah satu baris di bawah untuk menetapkan environment
// secara manual (mengalahkan auto-detect dari bootstrap.php):
// define('MEEL_ENV', 'production');
// define('MEEL_ENV', 'development');

// ════════════════════════════════════════════════════════════════
// DATABASE CONNECTION
// ════════════════════════════════════════════════════════════════
// Hanya connect jika $conn belum ada — aman di-include berkali-kali
if (!isset($conn) || $conn === null) {
    $conn = new mysqli("localhost", "root", "", "MEeL");
    if ($conn->connect_error) {
        die("[MEeL SYSTEM ERROR]\nKoneksi ke database gagal: " . $conn->connect_error);
    }
}

// ════════════════════════════════════════════════════════════════
// BASE URL (PATH PORTABILITY)
// ════════════════════════════════════════════════════════════════
// Menggantikan hardcoded /MEeL/ prefix di redirect dan link.
// Dihitung dari lokasi file ini, konsisten di semua kedalaman include.
if (!defined('MEEL_BASE_URL')) {
    $meel_project_root = str_replace('\\', '/', dirname(__DIR__));
    $meel_doc_root     = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '/');
    $meel_relative     = substr($meel_project_root, strlen(rtrim($meel_doc_root, '/')));
    define('MEEL_BASE_URL', rtrim($meel_relative, '/'));
}

// ════════════════════════════════════════════════════════════════
// HOST CONSTANT (CEGAH OPEN REDIRECT)
// ════════════════════════════════════════════════════════════════
// Gunakan untuk validasi referer/open redirect. Set nilai ini
// sesuai hostname server Anda untuk keamanan lebih baik.
// Contoh: define('MEEL_HOST', 'meel.example.com');
// Biarkan tidak di-set untuk fallback ke HTTP_HOST.
if (!defined('MEEL_HOST')) {
    define('MEEL_HOST', $_SERVER['HTTP_HOST'] ?? '');
}

// ════════════════════════════════════════════════════════════════
// BINARY PATH CONSTANTS (CEGAH BINARY-HIJACKING)
// ════════════════════════════════════════════════════════════════
// Set path absolut untuk mencegah binary-hijacking via PATH environment.
// Biarkan kosong untuk auto-discovery (hanya untuk development).
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
// Ubah hanya di sini untuk portabilitas ke server/HDD lain!
// Cukup set MEEL_HDD_BASE, sisanya otomatis mengikuti.
if (!defined('MEEL_HDD_BASE')) {
    define('MEEL_HDD_BASE', '/media/muhammaddaffa/MEeL/media');

    // ── Path turunan (jangan diubah kecuali paham struktur folder) ──
    define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
    define('MEEL_HDD_VIDEO_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'video/');
    define('MEEL_HDD_THUMB_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'thumbnail/');
    define('MEEL_HDD_MUSIC_UPLOAD', MEEL_HDD_BASE . '/music/upload/');
    define('MEEL_HDD_BOOKS_UPLOAD', MEEL_HDD_BASE . '/books/upload/');
    define('MEEL_HDD_DRIVE',        MEEL_HDD_BASE . '/drive/');

    // Aktifkan jika mod_xsendfile sudah terinstall di Apache.
    // 🚀 Untuk FLAC 33MB+, Apache kirim file langsung tanpa sentuh PHP.
    define('MEEL_USE_XSENDFILE', true);
}

// ════════════════════════════════════════════════════════════════
// SESSION CONFIGURATION
// ════════════════════════════════════════════════════════════════
// Hanya set jika session belum jalan — aman dipanggil dari auth/auth.php
if (session_status() === PHP_SESSION_NONE) {
    $timeout = 43200; // 12 jam
    ini_set('session.gc_maxlifetime', $timeout);
    session_set_cookie_params($timeout, "/");
    session_name('meel');
    session_start();
}

// ════════════════════════════════════════════════════════════════
// AUTOLOADER & HELPERS
// ════════════════════════════════════════════════════════════════
// Semua class core (Uploader, Transcoder, MediaLibrary, dll)
// akan otomatis di-load tanpa require_once manual.
require_once __DIR__ . '/../modules/autoload.php';

// Helper functions (verify_csrf_token, get_csrf_token, base_url, dll.)
require_once __DIR__ . '/../modules/core/helpers.php';

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
    $csp_script_src = "script-src 'self' 'unsafe-inline'";
    /* ── Development: tambah unsafe-eval untuk HLS.js Web Worker ── */
    if (defined('MEEL_ENV') && MEEL_ENV === 'development') {
        $csp_script_src .= " 'unsafe-eval'";
    }
    /* ── worker-src eksplisit untuk HLS.js blob worker ── */
    $csp_worker_src = "worker-src 'self' blob:";
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'self'; frame-src 'self' blob:; frame-ancestors 'self'; form-action 'self'; img-src 'self' data: blob:; media-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; style-src 'self' 'unsafe-inline'; {$csp_script_src}; {$csp_worker_src}");
}

// ── CSRF Token ──
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// No wrapper function needed — use verify_csrf_token() directly
// (defined in modules/core/helpers.php with hash_equals safety)

// ── Session Timeout Check (12 jam) ──
if (isset($_SESSION['LAST_ACTIVITY'])) {
    $elapsed_time = time() - $_SESSION['LAST_ACTIVITY'];
    if ($elapsed_time > 43200) {
        session_unset();
        session_destroy();
        header("Location: ../auth/login.php?reason=expired");
        exit;
    }
}
$_SESSION['LAST_ACTIVITY'] = time();

// ── Activity Logger (skip di CLI — tidak ada HTTP request) ──
if (PHP_SAPI !== 'cli') {
    include_once __DIR__ . '/../modules/core/activity_logger.php';
}
