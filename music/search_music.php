<?php
include '../auth/config.php';
require_once '../modules/core/helpers.php';
require_once '../modules/media/SearchEngine.php';

$engine = new SearchEngine($conn);
$params = $engine->parseParams();
$result = $engine->searchMusic($params);

if ($result['count'] > 0) {
    foreach ($result['results'] as $v) {
        if ($result['sidebar']) {
            // Tampilan rekomendasi di watch.php
            $v_ext = strtolower(pathinfo($v['filename'], PATHINFO_EXTENSION));
            $v_lbl = $v_ext === 'ogg' ? 'opus' : $v_ext;
            ?>
            <a href="watch.php?id=<?= $v['id'] ?>"
               class="rekomendasi-item flex flex-col lg:flex-row gap-2 lg:gap-3 p-2 rounded-xl no-underline htmx-added"
               title="<?= htmlspecialchars($v['title']) ?>">
                <div class="w-full lg:w-16 aspect-square lg:h-12 lg:aspect-auto rounded-lg overflow-hidden flex-shrink-0 bg-white/[.04] border border-white/[.05]">
                    <img src="<?= htmlspecialchars(music_thumbnail_url($v['thumbnail'])) ?>"
                         alt="<?= htmlspecialchars($v['title']) ?> thumbnail"
                         width="96" height="96"
                         class="rec-thumb-img w-full h-full object-cover transition-transform duration-300"
                         loading="lazy" decoding="async">
                </div>
                <div class="flex-1 min-w-0 flex flex-col justify-center">
                    <div class="text-[11px] font-bold text-gray-300 uppercase tracking-tight leading-snug rec-title-text">
                        <?= htmlspecialchars($v['title']) ?>
                    </div>
                    <div class="text-[10px] text-gray-500 mt-0.5 truncate"><?= htmlspecialchars($v['artist']) ?></div>
                    <div class="flex items-center gap-1.5 mt-1">
                        <span class="text-[9px] text-gray-500"><?= number_format($v['views'] ?? 0) ?> views</span>
                        <span class="text-[8px] px-1.5 py-0.5 rounded bg-white/[.04] border border-white/[.05] text-gray-500 uppercase"><?= $v_lbl ?></span>
                    </div>
                </div>
            </a>
            <?php
        } else {
            include 'music_item.php';
        }
    }

    // Load More — gaya & label seragam dengan library (music/index.php):
    // tombol penuh w-full, 'Load More · x/y', dan penanda akhir 'End of
    // Collection' saat batch terakhir sudah dimuat (offset > 0).
    if (!$result['sidebar'] && $result['hasMore']) {
        $curPage    = (int)((int)$result['offset'] / max((int)$result['limit'], 1)) + 1;
        $totalPages = max(1, (int)$result['total_pages']);
        ?>
        <button type="button" id="load-more-music-search"
            class="w-full py-4 border border-dashed border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-[.25em] text-gray-700 hover:text-orange-500 hover:border-orange-500/30 transition-all"
            hx-get="search_music.php?search=<?= urlencode($result['query']) ?>&exclude=<?= $result['exclude'] ?>&offset=<?= $result['offset'] + $result['limit'] ?>"
            hx-target="#load-more-music-search"
            hx-swap="outerHTML"
            title="Muat lebih banyak lagu">
            Load More · <?= $curPage ?>/<?= $totalPages ?>
        </button>
        <?php
    } elseif (!$result['sidebar'] && (int)$result['offset'] > 0) {
        // Batch terakhir sudah dimuat — penanda akhir identik dengan load-more.js
        ?>
        <div class="py-10 text-center text-[9px] text-gray-800 uppercase tracking-[.4em]">End of Collection</div>
        <?php
    }
} elseif ($result['offset'] === 0) {

    echo '<div class="py-16 text-center text-[10px] text-gray-700 uppercase tracking-widest">Tidak ada lagu ditemukan.</div>';
}
