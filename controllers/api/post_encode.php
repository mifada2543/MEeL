<?php
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
    // NOTE: entri meel_pending_music di-unset SETELAH encode sukses (di bawah),
    // bukan di sini — request duplikat yang tiba bersamaan tetap bisa membaca
    // metadata asli, dan encode yang gagal bisa di-retry tanpa kehilangan data.
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
    unset($_SESSION['meel_pending_music'][$meta_key]);
    header("Location: ../../upload?success=1&file=" . urlencode($result['filename']));
    exit;
} else {
    echo "<h1>FFmpeg Gagal Menghasilkan Ogg!</h1>";
    echo "<pre>" . htmlspecialchars($result['msg']) . "</pre>";
}
