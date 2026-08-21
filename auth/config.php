<?php
/**
 * MEeL-HUB — Konfigurasi Aplikasi (Entry Point)
 *
 *
 * PENTING — Jangan hapus guard !defined() di sekitar konstanta.
 * File ini bisa di-include dari berbagai entry point (index.php,
 * auth/auth.php, file admin, dll), guard mencegah redeclare error.
 *
 * File ini HANYA memuat logic inisialisasi. Semua DATA konfigurasi
 * (DB credentials + MEEL_* constants) sudah dipindah ke settings.php:
 * require __DIR__ . '/settings.php';
 * Ubah nilai server di settings.php, JANGAN di file ini.
 *
 */

// PURE CONFIG (DATA) — DB credentials + MEEL_* constants
$meel_settings = __DIR__ . '/settings.php';
if (!file_exists($meel_settings)) {
    die("[MEeL SYSTEM ERROR]\nFile auth/settings.php tidak ditemukan.\n"
        . "Copy dari settings.example.php lalu isi kredensial:\n"
        . "  cp auth/settings.example.php auth/settings.php");
}
require_once $meel_settings;
// BOOTSTRAP (Error Handling)
require_once __DIR__ . '/../modules/core/bootstrap.php';
// DATABASE CONNECTION
// Hanya connect jika $conn belum ada — aman di-include berkali-kali
// Credentials diambil dari settings.php ($server, $username, dll.)
/** @var string $server   Host DB (dari settings.php) */
/** @var string $username User DB (dari settings.php) */
/** @var string $password Password DB (dari settings.php) */
/** @var string $db       Nama DB (dari settings.php) */
if (!isset($conn) || $conn === null) {
    $conn = new mysqli($server, $username, $password, $db);
    if ($conn->connect_error) {
        die("[MEeL SYSTEM ERROR]\nKoneksi ke database gagal: " . $conn->connect_error);
    }
    // Charset koneksi harus utf8mb4 agar cocok dengan schema (emoji, aksara
    // Jepang, dll. tersimpan/terbaca dengan benar).
    $conn->set_charset('utf8mb4');
}
// BASE URL (PATH PORTABILITY)
if (!defined('MEEL_BASE_URL')) {
    require_once __DIR__ . '/../modules/core/base_url.php';
    define('MEEL_BASE_URL', meel_base_url_path());
}
// SESSION CONFIGURATION (terpusat di modules/auth/helpers/session.php — satu sumber kebenaran)
require_once __DIR__ . '/../modules/auth/helpers/session.php';
meel_boot_session();
// AUTOLOADER & HELPERS
require_once __DIR__ . '/../modules/autoload.php';
// Helper functions (verify_csrf_token, get_csrf_token, base_url, dll.)
require_once __DIR__ . '/../modules/core/helpers.php';
// Security Headers
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
    if (defined('MEEL_ENV') && MEEL_ENV === 'development') {
        $csp_script_src .= " 'unsafe-eval'";
    }
    $csp_worker_src = "worker-src 'self' blob:";
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'self'; frame-src 'self' blob:; frame-ancestors 'self'; form-action 'self'; img-src 'self' data: blob:; media-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; style-src 'self' 'unsafe-inline'; {$csp_script_src}; {$csp_worker_src}");
}
// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// Session Timeout Check (12 jam)
if (isset($_SESSION['LAST_ACTIVITY'])) {
    $elapsed_time = time() - $_SESSION['LAST_ACTIVITY'];
    if ($elapsed_time > 43200) {
        session_unset();
        session_destroy();
        // base_url() mutlak — handler bisa disajikan di kedalaman URL mana pun
        // (hub di "/", modul di "/video/", dst.), jadi ../ relatif tidak aman.
        header("Location: " . base_url('/auth/login?reason=expired'));
        exit;
    }
}
$_SESSION['LAST_ACTIVITY'] = time();
// Activity Logger (skip di CLI — tidak ada HTTP request)
if (PHP_SAPI !== 'cli') {
    include_once __DIR__ . '/../modules/core/activity_logger.php';
}
