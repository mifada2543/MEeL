<?php
error_reporting(0);

require '../auth/auth.php';
require '../auth/config.php';
require '../modules/core/helpers.php';
require __DIR__ . '/DriveService.php';

$user = DriveUserContext::fromSession($_SESSION);
$user->authorize();

if (!isset($_GET['csrf_token']) || !verify_csrf_token($_GET['csrf_token'])) {
    http_response_code(403);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$storage = new DriveStorage(DriveStorage::defaultBasePath(), $user);

try {
    $file = $storage->getFileForDownload(
        isset($_GET['file']) ? basename($_GET['file']) : null,
        isset($_GET['type']) ? basename($_GET['type']) : null,
        $_GET['scope'] ?? DriveStorage::SCOPE_PUBLIC
    );
} catch (RuntimeException $exception) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

$mimeTypes = [
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'mkv'  => 'video/x-matroska',
    'mov'  => 'video/quicktime',
    'avi'  => 'video/x-msvideo',
    'm4v'  => 'video/mp4',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
    'txt'  => 'text/plain; charset=utf-8',
];
$audioExts = ['mp3', 'm4a', 'ogg', 'opus', 'flac', 'wav', 'aac'];
$mimeType = $mimeTypes[$ext] ?? (in_array($ext, $audioExts, true) ? get_audio_mime_type($ext) : 'application/octet-stream');

set_time_limit(0);
ignore_user_abort(false);

while (@ob_get_level()) {
    @ob_end_clean();
}
@ob_implicit_flush(true);

$size = (int) $file['size'];
$length = $size;
$start = 0;
$end = $size - 1;

header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . str_replace('"', '', $file['name']) . '"');
header('Accept-Ranges: bytes');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

if (isset($_SERVER['HTTP_RANGE'])) {
    $c_start = $start;
    $c_end = $end;

    list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
    if (strpos($range, ',') !== false) {
        header('HTTP/1.1 416 Requested Range Not Satisfiable');
        header("Content-Range: bytes $start-$end/$size");
        exit;
    }
    if ($range == '-') {
        $c_start = $size - substr($range, 1);
    } else {
        $range = explode('-', $range);
        $c_start = $range[0];
        $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $size - 1;
    }
    $c_end = ($c_end > $end) ? $end : $c_end;
    if ($c_start > $c_end || $c_start > $size - 1 || $c_end >= $size) {
        header('HTTP/1.1 416 Requested Range Not Satisfiable');
        header("Content-Range: bytes $start-$end/$size");
        exit;
    }
    $start = $c_start;
    $end = $c_end;
    $length = $end - $start + 1;
    header('HTTP/1.1 206 Partial Content');
    header("Content-Range: bytes $start-$end/$size");
}

header('Content-Length: ' . $length);

$fp = @fopen($file['path'], 'rb');
if (!$fp) {
    header('HTTP/1.1 500 Internal Server Error');
    exit;
}

const DRIVE_STREAM_CHUNK_SIZE = 262144;
@fseek($fp, $start);
while (!@feof($fp) && ($p = @ftell($fp)) <= $end && $p !== false) {
    if (connection_aborted()) break;

    $remaining = $end - $p + 1;
    if ($remaining <= 0) break;

    $chunkSize = ($remaining > DRIVE_STREAM_CHUNK_SIZE) ? DRIVE_STREAM_CHUNK_SIZE : $remaining;
    $buf = @fread($fp, $chunkSize);
    if ($buf === false || $buf === '') break;
    echo $buf;
    @ob_flush();
    @flush();
}
@fclose($fp);
exit;
