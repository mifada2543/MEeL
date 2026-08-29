<?php
/** MEeL-HUB — Contoh Konfigurasi Server (Template)
 * Copy file ini ke settings.php dan sesuaikan dengan environment Anda:
 * cp auth/settings.example.php auth/settings.php
 * File ini HANYA memuat data konfigurasi (DB credentials + MEEL_*
 * constants) — tanpa session, header, atau logic lain.
 */

// define('MEEL_ENV', 'production');
// define('MEEL_ENV', 'development');

// Guard error_log di controllers (pola: if (defined('APP_DEBUG') && APP_DEBUG)).
// define('APP_DEBUG', true); // paksa aktif (debugging)
// define('APP_DEBUG', false); // paksa nonaktif

if (!isset($server))   $server   = "localhost";
if (!isset($username)) $username = "root";
if (!isset($password)) $password = "";
if (!isset($db))       $db       = "MEeL";

if (!defined('MEEL_HOST')) {
    define('MEEL_HOST', $_SERVER['HTTP_HOST'] ?? '');
}

// TRUSTED PROXY (CEGAH IP SPOOFING)
// (mis. Cloudflare, Nginx reverse proxy). Jika diset true padahal server
// diakses langsung, attacker bisa memalsukan IP untuk bypass IP-ban atau
if (!defined('MEEL_TRUST_PROXY_HEADERS')) {
    define('MEEL_TRUST_PROXY_HEADERS', false);
}

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

// Jangan commit path yang mengandung username OS asli Anda.
if (!defined('MEEL_HDD_BASE')) {
    define('MEEL_HDD_BASE', '/media/CHANGE_ME/MEeL/media');

    define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
    define('MEEL_HDD_VIDEO_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'video/');
    define('MEEL_HDD_THUMB_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'thumbnail/');
    define('MEEL_HDD_MUSIC_UPLOAD', MEEL_HDD_BASE . '/music/upload/');
    define('MEEL_HDD_BOOKS_UPLOAD', MEEL_HDD_BASE . '/books/upload/');
    define('MEEL_HDD_DRIVE',        MEEL_HDD_BASE . '/drive/');

    // Aktifkan jika mod_xsendfile sudah terinstall di Apache.
    define('MEEL_USE_XSENDFILE', false);
}
