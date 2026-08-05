<?php /** @var array $book Data buku dari hasil query; @var string|null $role Role user (opsional, untuk tombol admin) */ ?>
<div class="relative group">
    <?php if (isset($role) && $role === 'admin'): ?>
        <div class="absolute top-2 left-2 opacity-0 group-hover:opacity-100 transition-opacity z-30">
            <a href="upload.php?reup=<?= urlencode($book['title']) ?>"
                class="p-1.5 bg-green-600/90 backdrop-blur-md rounded-lg text-white hover:bg-green-500 hover:scale-110 transition-all shadow-lg block"
                title="Tambah Chapter">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i>
            </a>
        </div>
    <?php endif; ?>

    <a href="read.php?id=<?= (int)$book['id'] ?>" class="block">
        <div class="book-card relative aspect-[3/4] overflow-hidden rounded-2xl border border-white/[.06] bg-[#0b0e14] shadow-lg">
            <img src="upload/thumbnail/<?= htmlspecialchars($book['thumbnail']) ?>"
                loading="lazy"
                class="book-thumb w-full h-full object-cover"
                alt="<?= htmlspecialchars($book['title']) ?>"
                onerror="this.style.display='none'; this.parentElement.querySelector('.book-fallback')?.classList.remove('hidden')">
            <div class="book-fallback hidden absolute inset-0 flex items-center justify-center">
                <i data-lucide="book-open" class="w-10 h-10 text-gray-700"></i>
            </div>

            <!-- Type badge -->
            <div class="absolute top-2 right-2">
                <span class="type-badge <?= $book['type'] === 'manga' ? 'type-badge-manga' : 'type-badge-pdf' ?>">
                    <i data-lucide="<?= $book['type'] === 'manga' ? 'book-open' : 'file-text' ?>" class="w-2.5 h-2.5"></i>
                    <?= $book['type'] ?>
                </span>
            </div>

            <!-- Hover overlay -->
            <div class="book-overlay absolute inset-0 flex flex-col justify-end p-3 sm:p-4">
                <h3 class="text-sm font-bold text-white line-clamp-2 drop-shadow-lg leading-tight">
                    <?= htmlspecialchars($book['title']) ?>
                </h3>
                <p class="text-[10px] text-gray-300 mt-1 opacity-80 truncate">
                    <?= htmlspecialchars($book['author'] ?? 'Unknown Author') ?>
                </p>
                <?php if (!empty($book['category'])): ?>
                    <span class="text-[8px] text-green-400/70 uppercase tracking-widest mt-1.5 font-bold">
                        <?= htmlspecialchars($book['category']) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </a>
</div>
