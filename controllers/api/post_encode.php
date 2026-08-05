<?php
/**
 * controllers/api/post_encode.php
 * 
 * GET /api/post_encode — Post-processing encode hasil download yt-dlp.
 *
 * Dipanggil oleh upload_advanced.php setelah download selesai.
 *
 * Metadata (title/artist/album/duration/description) dikirim lewat SESSION
 * (dipasang oleh Transcoder::finalizeMusic dengan kunci = nama file temp),
 * bukan query string — agar judul/deskripsi panjang tidak terpotong atau
 * memicu 414 (batas URL server).
 *
 * Query params:
 *   - temp_file   (string, required) Nama file temp hasil download
 *
 * Fallback (legacy): jika metadata tidak ada di session, dibaca dari
 *   query string: title, artist, album, duration, description.
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

$temp_file = $_GET['temp_file'] ?? '';

if (empty($temp_file)) {
    die("<h1>Error: Parameter temp_file tidak ditemukan.</h1>");
}

// Metadata dikirim lewat session (dipasang oleh Transcoder::finalizeMusic)
// agar judul/deskripsi panjang tidak terpotong oleh query string URL.
// Fallback ke parameter URL tetap ada untuk kompatibilitas.
$meta_key = pathinfo($temp_file, PATHINFO_FILENAME);
$pending  = is_array($_SESSION['meel_pending_music'] ?? null)
    ? ($_SESSION['meel_pending_music'][$meta_key] ?? null)
    : null;

$title       = 'Unknown';
$artist      = 'Unknown';
$album       = 'Single';
$duration    = 0;
$description = 'Upload by MEeL Engine';

if (is_array($pending)) {
    $title       = (string)($pending['title']       ?? $title);
    $artist      = (string)($pending['artist']      ?? $artist);
    $album       = (string)($pending['album']       ?? $album);
    $duration    = (int)($pending['duration']       ?? $duration);
    $description = (string)($pending['description'] ?? $description);
    unset($_SESSION['meel_pending_music'][$meta_key]);
} else {
    $title       = $_GET['title']       ?? $title;
    $artist      = $_GET['artist']      ?? $artist;
    $album       = $_GET['album']       ?? $album;
    $duration    = (int)($_GET['duration'] ?? $duration);
    $description = $_GET['description'] ?? $description;
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