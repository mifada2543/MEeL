<?php /** @var array $v Data musik dari hasil query (music/index.php) */
// Otorisasi stream: setiap kartu yang dirender menandai id-nya diizinkan
// di-stream (diperiksa oleh music/stream.php — lihat helpers/stream_auth.php)
authorize_stream((int)$v['id']);
?>
<div class="music-item flex items-center gap-3 px-3 py-2.5 rounded-xl htmx-added"
     data-id="<?= $v['id'] ?>"
     data-title="<?= htmlspecialchars($v['title']) ?>"
     data-artist="<?= htmlspecialchars($v['artist'] ?? 'Unknown') ?>"
     data-thumbnail="<?= htmlspecialchars($v['thumbnail']) ?>"
     data-thumbnail-url="<?= htmlspecialchars(music_thumbnail_url($v['thumbnail'])) ?>"
     data-filename="<?= htmlspecialchars($v['filename']) ?>">
    <!-- THUMBNAIL -->
    <a href="watch.php?id=<?= $v['id'] ?>" 
       class="music-item-link relative w-12 h-12 rounded-lg overflow-hidden flex-shrink-0 bg-white/[.04]"
       data-music-id="<?= $v['id'] ?>"
       data-title="<?= htmlspecialchars($v['title']) ?>"
       data-artist="<?= htmlspecialchars($v['artist'] ?? 'Unknown') ?>"
       data-thumbnail="<?= htmlspecialchars($v['thumbnail']) ?>"
       data-thumbnail-url="<?= htmlspecialchars(music_thumbnail_url($v['thumbnail'])) ?>"
       data-filename="<?= htmlspecialchars($v['filename']) ?>"
       title="<?= htmlspecialchars($v['title']) ?> — <?= htmlspecialchars($v['artist'] ?? 'Unknown') ?>">
        <?php if (!empty($v['thumbnail'])): ?>
            <img src="<?= htmlspecialchars(music_thumbnail_url($v['thumbnail'])) ?>"
                 alt="<?= htmlspecialchars($v['title']) ?> thumbnail"
                 width="96" height="96"
                 class="w-full h-full object-cover" loading="lazy" decoding="async">
        <?php else: ?>
            <div class="w-full h-full flex items-center justify-center">
                <i data-lucide="music" class="w-4 h-4 text-gray-700"></i>
            </div>
        <?php endif; ?>
    </a>

    <!-- INFO -->
    <div class="flex-1 min-w-0">
        <a href="watch.php?id=<?= $v['id'] ?>"
           class="music-item-link block text-[12px] font-bold text-gray-300 truncate hover:text-orange-400 transition-colors leading-tight"
           data-music-id="<?= $v['id'] ?>"
           data-title="<?= htmlspecialchars($v['title']) ?>"
           data-artist="<?= htmlspecialchars($v['artist'] ?? 'Unknown') ?>"
           data-thumbnail="<?= htmlspecialchars($v['thumbnail']) ?>"
           data-thumbnail-url="<?= htmlspecialchars(music_thumbnail_url($v['thumbnail'])) ?>"
           data-filename="<?= htmlspecialchars($v['filename']) ?>" title="<?= htmlspecialchars($v['title']) ?>">
            <?= htmlspecialchars($v['title']) ?>
        </a>
        <div class="flex items-center gap-2 mt-0.5">
            <span class="text-[10px] text-gray-500 truncate" title="<?= htmlspecialchars($v['artist'] ?? 'Unknown') ?>">
                <?= htmlspecialchars($v['artist'] ?? 'Unknown') ?>
            </span>
            <span class="text-[9px] px-1.5 py-0.5 rounded bg-white/[.04] text-gray-500 border border-white/[.05] uppercase flex-shrink-0" title="<?= strtolower(pathinfo($v['filename'], PATHINFO_EXTENSION)) ?>">
                <?= strtolower(pathinfo($v['filename'], PATHINFO_EXTENSION)) ?>
            </span>
        </div>
    </div>

    <!-- PLAY BUTTON (desktop hover) -->       <a href="watch.php?id=<?= $v['id'] ?>"
          class="music-item-link play-btn hidden md:flex opacity-0 -translate-x-2 transition-all duration-200
                 text-[9px] font-bold uppercase tracking-widest
                 bg-orange-500 text-white px-4 py-2 rounded-lg hover:bg-orange-400 flex-shrink-0"
       data-music-id="<?= $v['id'] ?>"
       data-title="<?= htmlspecialchars($v['title']) ?>"
       data-artist="<?= htmlspecialchars($v['artist'] ?? 'Unknown') ?>"
       data-thumbnail="<?= htmlspecialchars($v['thumbnail']) ?>"
       data-thumbnail-url="<?= htmlspecialchars(music_thumbnail_url($v['thumbnail'])) ?>"
       data-filename="<?= htmlspecialchars($v['filename']) ?>"
       title="Putar <?= htmlspecialchars($v['title']) ?>">
        Play
    </a>
</div>
