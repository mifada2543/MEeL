<?php




while (ob_get_level()) ob_end_clean();

error_reporting(E_ERROR | E_PARSE);

require_once __DIR__ . '/../../modules/core/helpers.php';
require_once __DIR__ . '/../../auth/settings.php';
require_once __DIR__ . '/../../modules/core/bootstrap.php';
meel_boot_session();

$conn = new mysqli($server, $username, $password, $db);
if ($conn->connect_error) {
    http_response_code(500);
    header('Content-Type: text/plain');
    die('Database error.');
}

require_once __DIR__ . '/../../modules/core/Transcoder.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$filename = $_GET['file'] ?? '';
if (empty($filename)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Parameter file diperlukan.');
}

$safe_filename = basename($filename);

if ($safe_filename !== $filename) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Nama file tidak valid.');
}

$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$allowed = ['mp3', 'ogg', 'm4a', 'opus'];
if (!in_array($ext, $allowed, true)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Ekstensi file tidak valid.');
}

$filename = $safe_filename;

$transcoder = new Transcoder($conn, $_SESSION['user_id']);
$file_path = $transcoder->getTranscodeFilePath($filename);

if ($file_path === null) {
    http_response_code(404);
    header('Content-Type: text/plain');
    die('File not found or expired.');
}

$file_size = filesize($file_path);
if ($file_size === false || $file_size < 10240) {
    http_response_code(410);
    header('Content-Type: text/plain');
    die('File transcode tidak valid atau terlalu kecil. Silakan transcode ulang.');
}

$mime_types = [
    'mp3'  => 'audio/mpeg',
    'ogg'  => 'audio/ogg',
    'm4a'  => 'audio/mp4',
    'opus' => 'audio/ogg',
];
$mime = $mime_types[$ext] ?? 'application/octet-stream';

$download_title = $_GET['title'] ?? pathinfo($filename, PATHINFO_FILENAME);
if (empty($download_title)) {
    $download_title = 'untitled-media';
}
$download_title_safe = preg_replace(
    "/[^a-zA-Z0-9_\x{3000}-\x{9fff}\x{30a0}-\x{30ff}\x{3040}-\x{309f}\x{ff00}-\x{ffef}]+/u",
    '-',
    trim($download_title)
);
$download_title_safe = trim($download_title_safe, "- \t\n\r\0\x0B");
$download_title_safe = mb_substr($download_title_safe, 0, 120);
$download_title_safe = trim($download_title_safe, "- \t\n\r\0\x0B");
if (empty($download_title_safe)) {
    $download_title_safe = 'untitled-media';
}
$download_name = $download_title_safe . '.' . $ext;

while (ob_get_level()) ob_end_clean();

header('Content-Type: ' . $mime);
header('Content-Length: ' . $file_size);
header('Content-Disposition: attachment; filename="' . addcslashes($download_name, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($download_name));
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache, must-revalidate');
header('Accept-Ranges: none');

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');

readfile($file_path);
exit;
