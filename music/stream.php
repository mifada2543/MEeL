<?php
error_reporting(0);

require_once __DIR__ . '/../modules/core/helpers.php';
meel_boot_session();

session_write_close();

$referer = $_SERVER['HTTP_REFERER'] ?? '';
$currentHost = $_SERVER['HTTP_HOST'] ?? '';
$refererOk = false;

if ($referer !== '' && $currentHost !== '') {
    $refParts = parse_url($referer);
    if ($refParts && isset($refParts['host'])) {

        $currentHostNorm = strtolower(parse_url('http://' . $currentHost, PHP_URL_HOST) ?: $currentHost);
        if (strtolower($refParts['host']) === $currentHostNorm) {

            $refPath = $refParts['path'] ?? '';
            if (preg_match('#/music(?:/|$)#i', $refPath)) {
                $refererOk = true;
            }
        }
    }
}
if (!$refererOk) {
    header("Location: ../err/?code=denied");
    exit;
}

require_once __DIR__ . '/../modules/core/helpers.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("HTTP/1.1 400 Bad Request");
    exit("ID Media tidak valid.");
}

if (!is_stream_authorized($id)) {
    header("Location: ../err/?code=denied");
    exit;
}

include '../auth/config.php';
require_once '../modules/core/helpers.php';
include '../modules/media/MediaViewer.php';

$viewer = new MediaViewer($conn, $_SESSION['user_id'], 'music', $id);
$v = $viewer->getMediaData();

if (!$v || empty($v['filename'])) {
    header("HTTP/1.1 404 Not Found");
    exit("Data audio tidak ditemukan.");
}

$filePath = meel_media_base_path('music') . '/file/' . basename($v['filename']);

if (!file_exists($filePath) || !is_readable($filePath)) {
    header("HTTP/1.1 404 Not Found");
    exit("File fisik tidak tersedia di server.");
}

$ext      = strtolower(pathinfo($v['filename'], PATHINFO_EXTENSION));
$mimeType = get_audio_mime_type($ext);

if (defined('MEEL_STREAM_DEBUG') && MEEL_STREAM_DEBUG) {
    error_log("[MEeL-Stream] id=$id ext=$ext size=" . (filesize($filePath) ?? 0) . " ip=" . ($_SERVER['REMOTE_ADDR'] ?? '?'));
}

set_time_limit(0);

ignore_user_abort(false);

while (@ob_get_level()) {
    @ob_end_clean();
}
@ob_implicit_flush(true);

$size = @filesize($filePath);
$length = $size;
$start = 0;
$end = $size - 1;

header("Content-Type: " . $mimeType);
header("Accept-Ranges: bytes");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (defined('MEEL_USE_XSENDFILE') && MEEL_USE_XSENDFILE === true) {
    header("X-Sendfile: " . $filePath);
    header("Content-Length: " . $size);
    exit;
}

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

header("Content-Length: " . $length);

$flacChunkSize = ($ext === 'flac') ? 524288 : 262144; // 512KB untuk FLAC, 256KB untuk lainnya
define('STREAM_CHUNK_SIZE', $flacChunkSize);

$fp = @fopen($filePath, 'rb');
if (!$fp) {
    header("HTTP/1.1 500 Internal Server Error");
    exit("Tidak bisa membaca file.");
}
@fseek($fp, $start);
while (!@feof($fp) && ($p = @ftell($fp)) <= $end && $p !== false) {

    if (connection_aborted()) break;

    $remaining = $end - $p + 1;
    if ($remaining <= 0) break;

    $chunkSize = ($remaining > STREAM_CHUNK_SIZE) ? STREAM_CHUNK_SIZE : $remaining;
    $buf = @fread($fp, $chunkSize);
    if ($buf === false || $buf === '') break;
    echo $buf;
    @ob_flush();
    @flush();
}
@fclose($fp);
exit;
