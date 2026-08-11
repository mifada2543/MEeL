<?php
require_once __DIR__ . '/../../auth/config.php';
require_once __DIR__ . '/../../modules/core/Transcoder.php';

// Session sudah dimulai oleh auth/config.php (cookie flags aman)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$filename = $_GET['file'] ?? '';
if (empty($filename)) {
    http_response_code(400);
    die('Parameter file diperlukan.');
}

$safe_filename = basename($filename);

if ($safe_filename !== $filename) {
    http_response_code(400);
    die('Nama file tidak valid.');
}

// Pastikan ada ekstensi (minimal 'x.y')
if (!preg_match('/\.[a-zA-Z0-9]+$/u', $safe_filename)) {
    http_response_code(400);
    die('Ekstensi file tidak valid.');
}

$filename = $safe_filename;

$transcoder = new Transcoder($conn, $_SESSION['user_id']);
$file_path = $transcoder->getTranscodeFilePath($filename);

if ($file_path === null) {
    http_response_code(404);
    die('File not found or expired.');
}

$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$mime_types = [
    'mp3'  => 'audio/mpeg',
    'ogg'  => 'audio/ogg',
    'm4a'  => 'audio/mp4',
    'opus' => 'audio/ogg',
];
$mime = $mime_types[$ext] ?? 'application/octet-stream';

$size = filesize($file_path);

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($filename));
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache, must-revalidate');

// Bersihkan output buffer
while (ob_get_level()) ob_end_clean();

readfile($file_path);
exit;
