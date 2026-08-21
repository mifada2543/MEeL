<?php
require_once '../../auth/auth.php';
require_once '../../auth/config.php';
require_once '../../modules/media/MediaLibrary.php';

// Validasi ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id < 1) {
    http_response_code(400);
    die('Invalid request');
}
// Ambil data buku dari database
$repo = new BookRepository($conn);
$book = $repo->getBookById($id);

if (!$book || $book['type'] !== 'pdf') {
    http_response_code(404);
    die('File not found');
}

// Tentukan path fisik file PDF
$file_name = basename($book['path_folder']);
$file_path = meel_media_base_path('books') . '/pdf/' . $file_name;

if (!file_exists($file_path) || !is_readable($file_path)) {
    http_response_code(404);
    die('File not found');
}

$file_size = filesize($file_path);

// Kirim header eksplisit
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $book['title']) . '.pdf"');
header('Content-Length: ' . $file_size);
header('Cache-Control: public, max-age=86400');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
header('Pragma: public');
header('Accept-Ranges: bytes');

// Output file
readfile($file_path);
exit();
