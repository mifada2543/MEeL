<?php
error_reporting(0);

if (is_file(__DIR__ . '/../auth/settings.php')) {
    require_once __DIR__ . '/../auth/settings.php';
}
require_once __DIR__ . '/../modules/core/helpers.php';

// Security: path traversal blocked (realpath) + extension whitelist.
$f = isset($_GET['f']) ? (string) $_GET['f'] : '';
if ($f === '') {
    http_response_code(400);
    exit('Parameter f wajib diisi.');
}

meel_serve_media_file('music', $f);
