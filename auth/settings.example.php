<?php

/** MEeL-HUB — Contoh Konfigurasi Server (Template)
 * Copy file ini ke settings.php dan sesuaikan dengan environment Anda:
 *   cp auth/settings.example.php auth/settings.php
 * File ini HANYA memuat data konfigurasi (DB credentials + MEEL_*
 * constants) — tanpa session, header, atau logic lain.
 */

// ════════════════════════════════════════════════════════════════
// ENVIRONMENT (Override auto-detect bootstrap)
// ════════════════════════════════════════════════════════════════
// Uncomment salah satu baris di bawah untuk menetapkan environment
// secara manual (mengalahkan auto-detect dari bootstrap.php):
// define('MEEL_ENV', 'production');
// define('MEEL_ENV', 'development');

// ════════════════════════════════════════════════════════════════
// DEBUG LOGGING (Override auto-detect bootstrap)
// ════════════════════════════════════════════════════════════════
// Guard error_log di controllers (pola: if (defined('APP_DEBUG') && APP_DEBUG)).
// Default otomatis di bootstrap.php: true di development, false di produksi.
// Uncomment untuk memaksa:
// define('APP_DEBUG', true);   // paksa aktif (debugging)
// define('APP_DEBUG', false);  // paksa nonaktif

// ════════════════════════════════════════════════════════════════
// DATABASE CREDENTIALS
// ════════════════════════════════════════════════════════════════
if (!isset($server))   $server   = "localhost";
if (!isset($username)) $username = "root";
if (!isset($password)) $password = "";
if (!isset($db))       $db       = "MEeL";

// ════════════════════════════════════════════════════════════════
// HOST CONSTANT (CEGAH OPEN REDIRECT)
// ════════════════════════════════════════════════════════════════
// Set nilai ini sesuai hostname server Anda untuk keamanan lebih baik.
// Biarkan tidak di-set untuk fallback ke HTTP_HOST.
if (!defined('MEEL_HOST')) {
    define('MEEL_HOST', $_SERVER['HTTP_HOST'] ?? '');
}

// ════════════════════════════════════════════════════════════════
// BINARY PATH CONSTANTS (CEGAH BINARY-HIJACKING)
// ════════════════════════════════════════════════════════════════
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
// Cukup ubah MEEL_HDD_BASE, seluruh sistem akan mengikuti.
//
// WAJIB DIGANTI sebelum produksi — nilai di bawah hanyalah placeholder.
// Sesuaikan dengan lokasi storage server ANDA sendiri:
//   - HDD eksternal: /media/[username]/MEeL/media
//   - Lokal SSD:     /var/www/meel-storage/media
//   - Docker volume: /data/media
// Jangan commit path yang mengandung username OS asli Anda.
if (!defined('MEEL_HDD_BASE')) {
    define('MEEL_HDD_BASE', '/media/CHANGE_ME/MEeL/media');

    // ── Path turunan (jangan diubah kecuali paham struktur folder) ──
    define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
    define('MEEL_HDD_VIDEO_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'video/');
    define('MEEL_HDD_THUMB_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'thumbnail/');
    define('MEEL_HDD_MUSIC_UPLOAD', MEEL_HDD_BASE . '/music/upload/');
    define('MEEL_HDD_BOOKS_UPLOAD', MEEL_HDD_BASE . '/books/upload/');
    define('MEEL_HDD_DRIVE',        MEEL_HDD_BASE . '/drive/');

    // Aktifkan jika mod_xsendfile sudah terinstall di Apache.
    define('MEEL_USE_XSENDFILE', false);
}
