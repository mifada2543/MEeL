<?php
include '../auth/config.php';
require_once '../modules/media/SearchEngine.php';

$engine = new SearchEngine($conn);
$params = $engine->parseParams();
$result = $engine->searchVideo($params);

if ($result['count'] > 0) {
    foreach ($result['results'] as $v) {
        if ($result['sidebar']) {
?>
            <a href="watch.php?id=<?= $v['id'] ?>"
                class="flex gap-3 group rekomendasi-item htmx-added">
                <div class="w-28 h-[4.5rem] bg-black rounded-xl overflow-hidden flex-shrink-0 border border-white/[.05]">
                    <img src="upload/thumbnail/<?= htmlspecialchars($v['thumbnail']) ?>"
                        class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                        loading="lazy">
                </div>
                <div class="flex-1 min-w-0">
                    <h5 class="text-[11px] font-bold text-gray-300 line-clamp-2 uppercase group-hover:text-red-400 transition leading-snug">
                        <?= htmlspecialchars($v['title']) ?>
                    </h5>
                    <p class="text-[9px] text-gray-700 mt-1"><?= number_format($v['views'] ?? 0) ?> views</p>
                    <?php if (!empty($v['uploader_name'])): ?>
                        <p class="text-[10px] font-bold text-red-600/70 uppercase tracking-widest mt-0.5 truncate">
                            <?= htmlspecialchars($v['uploader_name']) ?>
                        </p>
                    <?php endif; ?>
                </div>
            </a>
        <?php
        } else {
            include 'video_card.php';
        }
    }

    // Load More — gaya, label progress, dan penanda akhir diseragamkan dengan
    // library (video/index.php & video/load_more.php).
    if (!$result['sidebar']) {
        $curPage    = (int)((int)$result['offset'] / max((int)$result['limit'], 1)) + 1;
        $totalPages = max(1, (int)$result['total_pages']);

        if ($result['hasMore']) {
            ?>
            <div id="load-more-area"
                class="aspect-video flex items-center justify-center bg-white/[.02] border border-dashed border-white/[.06] rounded-2xl cursor-pointer hover:border-red-500/30 hover:bg-white/[.03] transition-all group"
                hx-get="search_video.php?search=<?= urlencode($result['query']) ?>&exclude=<?= $result['exclude'] ?>&offset=<?= $result['offset'] + $result['limit'] ?>"
                hx-target="#load-more-area"
                hx-swap="outerHTML">
                <span class="text-[10px] font-bold uppercase tracking-[.2em] text-gray-300 group-hover:text-red-500 transition-colors">
                    Muat Lebih Banyak · <?= $curPage ?>/<?= $totalPages ?>
                </span>
            </div>
            <?php
        } elseif ((int)$result['offset'] > 0) {
            // Batch terakhir sudah dimuat — penanda akhir identik dengan library
            ?>
            <div class="aspect-video flex items-center justify-center border border-dashed border-white/[.04] rounded-2xl">
                <span class="text-[9px] text-gray-800 uppercase tracking-widest">End of Results · <?= $curPage ?>/<?= $totalPages ?></span>
            </div>
            <?php
        }
    }
} elseif ($result['offset'] === 0) {
    echo '<div class="col-span-full py-16 text-center text-[10px] text-gray-700 uppercase tracking-widest">Video tidak ditemukan.</div>';
}
