<?php
include '../auth/config.php';
require_once '../modules/media/MediaLibrary.php';

$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 15;
$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit  = 15;

$library = new MediaLibrary($conn);
$data    = $library->getVideos($limit, $offset);
$total   = $library->countVideos();
$totalPages = max(1, (int)ceil($total / $limit));

if ($data && $data->num_rows > 0):
    while ($v = $data->fetch_assoc()):
        include 'video_card.php';
    endwhile;

    $next = $offset + $limit;
    $nextPage = $page + 1;
    if ($next < $total): ?>
        <div id="load-more-area" title="Muat lebih banyak"
            class="aspect-video flex items-center justify-center bg-white/[.02] border border-dashed border-white/[.06] rounded-2xl cursor-pointer hover:border-red-500/30 hover:bg-white/[.03] transition-all group"
            hx-get="load_more.php?offset=<?= $next ?>&page=<?= $nextPage ?>"
            hx-target="#load-more-area"
            hx-swap="outerHTML" title="Muat lebih">
            <span class="text-[10px] font-bold uppercase tracking-[.2em] text-gray-700 group-hover:text-red-500 transition-colors">
                Muat Lebih Banyak · <?= $nextPage ?>/<?= $totalPages ?>
            </span>
        </div>
    <?php else: ?>
        <div class="aspect-video flex items-center justify-center border border-dashed border-white/[.04] rounded-2xl">
            <span class="text-[9px] text-gray-800 uppercase tracking-widest">End of Library · <?= $page ?>/<?= $totalPages ?></span>
        </div>
    <?php endif;
endif;
?>
<script>
lucide.createIcons();
</script>
