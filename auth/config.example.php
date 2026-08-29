<?php
/**
 * MEeL-HUB — Contoh Konfigurasi Aplikasi (Entry Point)
 *
 * File ini adalah TEMPLATE entry point. Semua DATA konfigurasi
 * (DB credentials + MEEL_* constants) dipindah ke settings.example.php:
 * require __DIR__ . '/settings.example.php';
 *
 * Cara install baru:
 * cp auth/settings.example.php auth/settings.php
 * cp auth/config.example.php auth/config.php
 * Lalu isi nilai di auth/settings.php sesuai environment Anda.
 *
 * PORTABILITY TIP
 * Semua path penyimpanan media terpusat di konstanta MEEL_HDD_BASE
 * (di settings.example.php). Cukup ubah nilainya, seluruh sistem
 * akan mengikuti.
 */

// PURE CONFIG (DATA) — DB credentials + MEEL_* constants
require_once __DIR__ . '/settings.example.php';

require_once __DIR__ . '/../modules/core/bootstrap.php';

// define('MEEL_ENV', 'production');
// define('MEEL_ENV', 'development');

// Credentials diambil dari settings.example.php ($server, dll.)
if (!isset($conn) || $conn === null) {
    $conn = new mysqli($server, $username, $password, $db);
    if ($conn->connect_error) {
        die("[MEeL SYSTEM ERROR]\nKoneksi ke database gagal: " . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');
}

// BASE URL & HOST (PATH PORTABILITY & SECURITY)
// terhadap DOCUMENT_ROOT), konsisten di semua kedalaman include.
if (!defined('MEEL_BASE_URL')) {
    require_once __DIR__ . '/../modules/core/base_url.php';
    define('MEEL_BASE_URL', meel_base_url_path());
}

// SESSION & SECURITY
if (session_status() === PHP_SESSION_NONE) {
    $timeout = 43200;
    ini_set('session.gc_maxlifetime', $timeout);
    $secure_cookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    session_set_cookie_params([
        'lifetime' => $timeout,
        'path'     => '/',
        'secure'   => $secure_cookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('meel');
    session_start();
}

require_once __DIR__ . '/../modules/autoload.php';
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
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'self'; frame-ancestors 'self'; form-action 'self'; img-src 'self' data: blob:; media-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");
}

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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

if (PHP_SAPI !== 'cli') {
    include_once __DIR__ . '/../modules/core/activity_logger.php';
}
