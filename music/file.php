<?php
error_reporting(0);

// Muat konstanta storage (MEEL_HDD_*) — auth/settings.php pure config.
if (is_file(__DIR__ . '/../auth/settings.php')) {
    require_once __DIR__ . '/../auth/settings.php';
}
require_once __DIR__ . '/../modules/core/helpers.php';

// Endpoint serve file upload music — dipanggil via internal rewrite di .htaccess
// (URL publik `music/upload/...` → music/file.php?f=...). Membaca dari base path
// terpusat MEEL_HDD_MUSIC_UPLOAD (atau folder fallback <root>/music/upload).

// Catatan: file audio tetap disajikan lewat music/stream.php?id=... (dengan
// otorisasi + referer gate ketat). Endpoint ini menangani aset publik seperti
// thumbnail & file pendukung yang direferensikan langsung oleh <img>/<source>.
// Keamanan: path traversal diblokir (realpath) + whitelist ekstensi.
$f = isset($_GET['f']) ? (string) $_GET['f'] : '';
if ($f === '') {
    http_response_code(400);
    exit('Parameter f wajib diisi.');
}

meel_serve_media_file('music', $f);
