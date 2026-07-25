<?php
/**
 * controllers/api/download_transcode.php
 * 
 * GET /api/download_transcode — Proxy download file hasil transcode dari RAM disk.
 *
 * File hasil transcode disimpan di RAM disk (/dev/shm/meel/transcode/) yang tidak
 * bisa diakses langsung via web server. Controller ini berfungsi sebagai proxy
 * yang membaca file dari RAM disk dan mengirimkannya ke browser.
 *
 * Tidak menggunakan auth/auth.php untuk menghindari redirect (302) yang
 * akan memecah download browser.
 *
 * Query params:
 *   - file (string, required) Nama file (basename, path traversal dicegah)
 *
 * Response:
 *   Binary file download dengan Content-Type & Content-Disposition sesuai
 *   HTTP 401 jika user belum login
 *   HTTP 400 jika parameter tidak valid atau path traversal terdeteksi
 *   HTTP 404 jika file tidak ditemukan / expired
 *
 * Security:
 *   - basename() untuk mencegah path traversal
 *   - Validasi ekstensi dengan regex
 *   - Session check manual tanpa redirect (agar download browser tidak pecah)
 *
 * Dependencies:
 *   - auth/config.php ($conn)
 *   - modules/core/Transcoder.php
 */

require_once __DIR__ . '/../../auth/config.php';
require_once __DIR__ . '/../../modules/core/Transcoder.php';

// Session check manual (tanpa redirect)
if (session_status() === PHP_SESSION_NONE) {
    session_name('meel');
    session_start();
}
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

// Validasi longgar untuk download: basename() + minimal ada ekstensi
// Tidak pakai regex ketat karena nama file bisa mengandung Unicode (aksen, Jepang, dll)
$safe_filename = basename($filename);

// Cegah path traversal — hanya path traversal yang perlu diblokir
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
