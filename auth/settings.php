<?php
/**
 * MEeL-HUB — Konfigurasi Server Murni
 *
 * ═══════════════════════════════════════════════════════════════════
 * ★ File ini HANYA memuat data konfigurasi (DB credentials + MEEL_*
 *   constants). TIDAK boleh ada logic lain: tanpa session, tanpa
 *   header, tanpa koneksi, tanpa require file lain.
 *
 *   Tanggung jawab:
 *     - auth/settings.php        ← file ini: DATA murni (nilai nyata)
 *     - auth/config.php          ← entry point: me-require file ini
 *                                 + logic bootstrap (koneksi, session,
 *                                   headers, CSRF, dll.)
 *
 * ★ Jangan hapus guard !defined() / !isset() — file ini bisa
 *   di-include dari berbagai entry point; guard mencegah redeclare.
 *
 * Cara pakai: ubah nilai di bawah ini sesuai server Anda.
 * Untuk instalasi baru, copy dari settings.example.php:
 *   cp auth/settings.example.php auth/settings.php
 * ═══════════════════════════════════════════════════════════════════
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
// Dikonsumsi oleh auth/config.php untuk membuat koneksi mysqli.
if (!isset($server))   $server   = "localhost";
if (!isset($username)) $username = "root";
if (!isset($password)) $password = "";
if (!isset($db))       $db       = "MEeL";

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
