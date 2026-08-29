<?php /** @var array $v Data video dari hasil query (video/index.php) */ ?>
<div class="video-card htmx-added meel-card rounded-2xl overflow-hidden group"
     title="<?= htmlspecialchars($v['title']) ?>">

    <!-- THUMBNAIL -->
    <div class="aspect-video relative overflow-hidden" style="background:#000">
        <?php
        $thumb_name = $v['thumbnail'] ?? '';
        $thumb_ok   = $thumb_name !== '' && is_file(meel_media_base_path('video') . '/thumbnail/' . basename($thumb_name));
        $thumb_src  = $thumb_ok
            ? 'upload/thumbnail/' . rawurlencode($thumb_name)
            : '../assets/img/video0.webp';
        ?>
        <img src="<?= $thumb_src ?>"
             class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
             loading="lazy"
             decoding="async"
             alt="Thumbnail video <?= htmlspecialchars($v['title']) ?>"
             width="420"
             height="236">

        <!-- PLAY OVERLAY -->
        <a href="<?= base_url('/video/watch?id=' . (int)$v['id']) ?>"
           class="absolute inset-0 flex items-center justify-center
                  opacity-0 group-hover:opacity-100 transition-opacity duration-300"
           style="background:rgba(0,0,0,0.5)"
           aria-label="Tonton video <?= htmlspecialchars($v['title']) ?>"
           title="Tonton video <?= htmlspecialchars($v['title']) ?>">
            <div class="w-11 h-11 rounded-full flex items-center justify-center
                        scale-90 group-hover:scale-100 transition-transform duration-300"
                 style="background:var(--meel-red); box-shadow:var(--meel-shadow-lg)">
                <i data-lucide="play" class="fill-white text-white w-5 h-5 ml-0.5"></i>
            </div>
        </a>

        <!-- VIEWS BADGE -->
        <div class="absolute bottom-2 right-2 backdrop-blur-sm px-2 py-0.5 rounded text-[9px] font-bold"
             style="background:rgba(0,0,0,0.6); color:var(--meel-text-secondary)">
            <?= number_format($v['views'] ?? 0) ?> views
        </div>
    </div>

    <!-- META -->
    <div class="px-3 py-3">
        <a href="<?= base_url('/video/watch?id=' . (int)$v['id']) ?>"
           class="block text-[12px] font-bold line-clamp-2 leading-snug transition-colors"
           style="color:var(--meel-text)"
           onmouseover="this.style.color='var(--meel-red)'"
           onmouseout="this.style.color='var(--meel-text)'"
           title="<?= htmlspecialchars($v['title']) ?>">
            <?= htmlspecialchars($v['title']) ?>
        </a>
        <?php if (!empty($v['upload_date'])): ?>
        <p class="text-[9px] mt-1.5 uppercase tracking-wider"
           style="color:var(--meel-text-secondary)">
            <?= date('d M Y', strtotime($v['upload_date'])) ?>
        </p>
        <?php endif; ?>
    </div>
</div>
