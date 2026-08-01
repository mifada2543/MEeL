<?php
/**
 * MEeL-HUB — Konfigurasi Aplikasi (Entry Point)
 *
 * ═══════════════════════════════════════════════════════════════════
 * PENTING — Jangan hapus guard !defined() di sekitar konstanta.
 *   File ini bisa di-include dari berbagai entry point (index.php,
 *   auth/auth.php, file admin, dll), guard mencegah redeclare error.
 *
 * File ini HANYA memuat logic inisialisasi. Semua DATA konfigurasi
 *   (DB credentials + MEEL_* constants) sudah dipindah ke settings.php:
 *     require __DIR__ . '/settings.php';
 *   Ubah nilai server di settings.php, JANGAN di file ini.
 * ═══════════════════════════════════════════════════════════════════
 */

// ════════════════════════════════════════════════════════════════
// PURE CONFIG (DATA) — DB credentials + MEEL_* constants
// ════════════════════════════════════════════════════════════════
$meel_settings = __DIR__ . '/settings.php';
if (!file_exists($meel_settings)) {
    die("[MEeL SYSTEM ERROR]\nFile auth/settings.php tidak ditemukan.\n"
        . "Copy dari settings.example.php lalu isi kredensial:\n"
        . "  cp auth/settings.example.php auth/settings.php");
}
require_once $meel_settings;

// ════════════════════════════════════════════════════════════════
// BOOTSTRAP (Error Handling Terpusat)
// ════════════════════════════════════════════════════════════════
// Bootstrap menangani display_errors, error_log, dan timezone
// berdasarkan environment (production/development).
// Tidak perlu lagi mengatur ini di file individual.
require_once __DIR__ . '/../modules/core/bootstrap.php';

// ════════════════════════════════════════════════════════════════
// DATABASE CONNECTION
// ════════════════════════════════════════════════════════════════
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
}

// ════════════════════════════════════════════════════════════════
// BASE URL (PATH PORTABILITY)
// ════════════════════════════════════════════════════════════════
// Menggantikan hardcoded /MEeL/ prefix di redirect dan link.
// Perhitungan dipusatkan di modules/core/base_url.php (root proyek relatif
// terhadap DOCUMENT_ROOT), konsisten di semua kedalaman include.
if (!defined('MEEL_BASE_URL')) {
    require_once __DIR__ . '/../modules/core/base_url.php';
    define('MEEL_BASE_URL', meel_base_url_path());
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
