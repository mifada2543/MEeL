<?php
include '../auth/config.php';
require_once '../modules/helpers.php';
require_once '../modules/media/MediaLibrary.php';

$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 10;
$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$format = $_GET['format'] ?? 'all';
$artist = $_GET['artist'] ?? 'all';
$limit  = 10;

$library = new MediaLibrary($conn);
$data    = $library->getMusicList($format, $artist, $limit, $offset);
$total   = $library->countMusic($format, $artist);
$totalPages = max(1, (int)ceil($total / $limit));

if (!$data || $data->num_rows === 0) return;

$next    = $offset + $limit;
$nextPage = $page + 1;
$qFormat = urlencode($format);
$qArtist = urlencode($artist);

while ($v = $data->fetch_assoc()) include 'music_item.php'; ?>

<?php if ($next < $total): ?>
    <div class="lm-meta" hidden
         data-next-url="load_more_music.php?offset=<?= $next ?>&page=<?= $nextPage ?>&format=<?= $qFormat ?>&artist=<?= $qArtist ?>"
         data-page="<?= $nextPage ?>"
         data-total="<?= $totalPages ?>"></div>
<?php else: ?>
    <div class="lm-meta" hidden data-end="true" data-page="<?= $page ?>" data-total="<?= $totalPages ?>"></div>
<?php endif;
