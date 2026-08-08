<?php /** @var array $v Data video dari hasil query (video/index.php) */ ?>
<div class="video-card htmx-added bg-[#0d1017] border border-white/[.05] rounded-2xl overflow-hidden group"
     title="<?= htmlspecialchars($v['title']) ?>">

    <!-- THUMBNAIL -->
    <div class="aspect-video bg-black relative overflow-hidden">
        <?php
        $thumb_path = "upload/thumbnail/" . $v['thumbnail'];
        $thumb_src  = (file_exists($thumb_path) && !empty($v['thumbnail']))
            ? $thumb_path
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
        <a href="watch.php?id=<?= $v['id'] ?>"
           class="absolute inset-0 flex items-center justify-center
                  opacity-0 group-hover:opacity-100 bg-black/50 transition-opacity duration-300"
           aria-label="Tonton video <?= htmlspecialchars($v['title']) ?>"
           title="Tonton video <?= htmlspecialchars($v['title']) ?>">
            <div class="w-11 h-11 bg-red-600 rounded-full flex items-center justify-center shadow-xl
                        scale-90 group-hover:scale-100 transition-transform duration-300">
                <i data-lucide="play" class="fill-white text-white w-5 h-5 ml-0.5"></i>
            </div>
        </a>

        <!-- VIEWS BADGE — diposisikan dengan absolute di pojok kanan bawah -->
        <div class="absolute bottom-2 right-2 bg-black/70 backdrop-blur-sm px-2 py-0.5 rounded text-[9px] text-gray-400 font-bold">
            <?= number_format($v['views'] ?? 0) ?> views
        </div>
    </div>

    <!-- META -->
    <div class="px-3 py-3">
        <a href="watch.php?id=<?= $v['id'] ?>"
           class="block text-[12px] font-bold text-gray-300 line-clamp-2 leading-snug
                  hover:text-red-400 transition-colors"
           title="<?= htmlspecialchars($v['title']) ?>">
            <?= htmlspecialchars($v['title']) ?>
        </a>
        <?php if (!empty($v['upload_date'])): ?>
        <p class="text-[9px] text-gray-300 mt-1.5 uppercase tracking-wider">
            <?= date('d M Y', strtotime($v['upload_date'])) ?>
        </p>
        <?php endif; ?>
    </div>
</div>
