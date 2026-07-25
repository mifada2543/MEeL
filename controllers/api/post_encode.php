<?php
/**
 * controllers/api/post_encode.php
 * 
 * GET /api/post_encode — Post-processing encode hasil download yt-dlp.
 *
 * Dipanggil oleh upload_advanced.php setelah download selesai.
 * Meneruskan parameter dari query string ke Transcoder::encodeMusic().
 *
 * Query params:
 *   - temp_file   (string, required) Path file temp hasil download
 *   - title       (string, optional) Judul media
 *   - artist      (string, optional) Nama artis
 *   - album       (string, optional) Nama album
 *   - duration    (int,    optional) Durasi dalam detik
 *   - description (string, optional) Deskripsi
 *
 * Response:
 *   302 Redirect ke upload_advanced.php?success=1 pada sukses
 *   HTML error page pada gagal
 *
 * Dependencies:
 *   - modules/core/helpers.php
 *   - auth/auth.php ($_SESSION, login check)
 *   - auth/config.php ($conn)
 *   - modules/core/Transcoder.php
 *   - modules/core/GarbageCollector.php
 */

require_once '../../modules/core/helpers.php';
require_once '../../auth/auth.php';
require_once '../../auth/config.php';
require_once '../../modules/core/Transcoder.php';
require_once '../../modules/core/GarbageCollector.php';
GarbageCollector::run();

$temp_file   = $_GET['temp_file']   ?? '';
$title       = $_GET['title']       ?? 'Unknown';
$artist      = $_GET['artist']      ?? 'Unknown';
$album       = $_GET['album']       ?? 'Single';
$duration    = (int)($_GET['duration'] ?? 0);
$description = $_GET['description'] ?? 'Upload by MEeL Engine';

if (empty($temp_file)) {
    die("<h1>Error: Parameter temp_file tidak ditemukan.</h1>");
}

$transcoder = new Transcoder($conn, $_SESSION['user_id']);
$result = $transcoder->encodeMusic($temp_file, $title, $artist, $album, $duration, $description);

if ($result['status'] === 'success') {
    header("Location: ../../upload_advanced.php?success=1&file=" . urlencode($result['filename']));
    exit;
} else {
    echo "<h1>FFmpeg Gagal Menghasilkan Ogg!</h1>";
    echo "<pre>" . htmlspecialchars($result['msg']) . "</pre>";
}