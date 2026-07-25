<?php
require_once '../modules/core/helpers.php';
require_once '../auth/auth.php';
require_once '../auth/config.php';
// activity_logger loaded via auth/config.php
require_once '../modules/media/MediaLibrary.php';

// ── Bootstrap ────────────────────────────────────────────────────────────────
$repo  = new BookRepository($conn);
$u_id  = (int)$_SESSION['user_id'];
$role  = $repo->getUserRole($u_id);

// Sanitasi filter dari URL — hanya nilai yang diizinkan yang diteruskan
$raw_filter = $_GET['type'] ?? 'all';
$filter     = in_array($raw_filter, ['manga', 'pdf'], true) ? $raw_filter : 'all';
$bookPage   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$bookPerPage = 24;

$meta_books  = $repo->getBooksPaginated($filter, $bookPage, $bookPerPage);
$books       = $meta_books['data'];
$total       = $meta_books['total'];
$bookPage    = $meta_books['page'];
$totalPagesBooks = $meta_books['total_pages'];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="MEeL | Books">
    <meta property="og:description" content="MEeL Books - Perpustakaan digital untuk membaca manga, komik, dan dokumen PDF.">
    <title>MEeL | Books</title>
    <?php include '../partials/link.php'; ?>
    <link rel="stylesheet" href="../assets/css/books.css">
    <script src="../assets/js/htmx.min.js"></script>
    <style>
        body {
            background-color: #080a0f;
        }

        .line-clamp-2 {
            display: -webkit-box;
            -webkit-box-orient: vertical;
            overflow: hidden;
            -webkit-line-clamp: 2;
            line-clamp: 2;
        }

        /* ── Continue Reading Banner ── */
        .continue-banner {
            display: none;
            background: linear-gradient(135deg, rgba(34,197,94,.08) 0%, rgba(124,58,237,.06) 100%);
            border: 1px solid rgba(34,197,94,.15);
            border-radius: 14px;
            padding: 0.75rem 1rem;
            margin-bottom: 1.5rem;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            transition: all 0.3s ease;
        }
        .continue-banner.visible {
            display: flex;
        }
        .continue-banner-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 0;
            flex: 1;
        }
        .continue-badge {
            flex-shrink: 0;
            font-size: 8px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            padding: 3px 8px;
            border-radius: 6px;
            background: rgba(34,197,94,.15);
            color: #22c55e;
        }
        .continue-banner-text {
            font-size: 0.75rem;
            color: #9ca3af;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .continue-banner-text strong {
            color: #e5e7eb;
        }
        .continue-banner-link {
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.85rem;
            background: rgba(34,197,94,.12);
            color: #22c55e;
            border-radius: 8px;
            font-size: 0.65rem;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .continue-banner-link:hover {
            background: rgba(34,197,94,.2);
            transform: translateY(-1px);
        }
        .continue-banner-close {
            flex-shrink: 0;
            padding: 0.3rem;
            color: #6b7280;
            background: none;
            border: none;
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .continue-banner-close:hover {
            color: #e5e7eb;
            background: rgba(255,255,255,.05);
        }
        @media (max-width: 480px) {
            .continue-banner { flex-wrap: wrap; padding: 0.6rem 0.8rem; }
            .continue-badge { font-size: 7px; padding: 2px 6px; }
            .continue-banner-text { font-size: 0.7rem; }
        }
    </style>
</head>

<body class="text-gray-400 min-h-screen">

    <!-- NAVBAR -->
    <nav class="border-b border-white/[.04] bg-[#080a0f]/95 sticky top-0 z-50 backdrop-blur-md">
        <div class="w-full px-3 sm:px-6 xl:px-10 2xl:px-16 h-14 flex items-center justify-between gap-2 sm:gap-4">
            <a href="../index.php" class="flex items-center gap-1 sm:gap-2.5 flex-shrink-0" title="MEeL HUB">
                <div class="w-6 h-6 sm:w-7 sm:h-7 bg-green-600 rounded-lg flex items-center justify-center">
                    <i data-lucide="library" class="w-3.5 h-3.5 text-white fill-current"></i>
                </div>
                <span class="text-xs sm:text-sm font-bold tracking-tight text-white uppercase hidden sm:block">
                    MEeL<span class="text-green-500">Books</span>
                </span>
            </a>

            <!-- Search -->
            <div class="flex-1 max-w-sm flex items-center gap-1.5 sm:gap-2">
                <div class="relative flex-1 group">
                    <i data-lucide="search" class="absolute left-2.5 sm:left-3.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-600 group-focus-within:text-green-500 transition-colors"></i>
                    <input type="text"
                        id="b-search"
                        name="search"
                        placeholder="Cari buku..."
                        class="w-full bg-white/[.04] border border-white/[.06] rounded-xl py-2 pl-8 sm:pl-9 pr-3 sm:pr-4 text-xs focus:outline-none focus:border-green-500/40 transition-all text-gray-300"
                        autocomplete="off">
                </div>
                <button onclick="filterBooks(this)"
                    class="px-2.5 sm:px-4 py-2 bg-white/[.04] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-500 hover:text-green-500 hover:border-green-500/30 transition-all flex-shrink-0">
                    <span class="hidden sm:inline">Cari</span>
                    <i data-lucide="search" class="w-3.5 h-3.5 sm:hidden"></i>
                </button>
            </div>

            <div class="flex items-center gap-3 text-[10px] font-bold uppercase tracking-wider flex-shrink-0">
                <?php include '../partials/nav.php'; ?>
            </div>
        </div>
    </nav>

    <main class="w-full px-4 sm:px-6 xl:px-10 2xl:px-16 pt-8 pb-20">

        <!-- CONTINUE READING BANNER (localStorage-based) -->
        <div id="continueBanner" class="continue-banner" role="alert">
            <div class="continue-banner-left">
                <span class="continue-badge">📖 Lanjutkan</span>
                <span class="continue-banner-text" id="continueText">
                    Membaca <strong id="continueTitle">-</strong>
                </span>
            </div>
            <a id="continueLink" href="#" class="continue-banner-link">
                <i data-lucide="arrow-right" class="w-3 h-3"></i>
                Buka
            </a>
            <button id="continueClose" class="continue-banner-close" title="Tutup">
                <i data-lucide="x" class="w-3.5 h-3.5"></i>
            </button>
        </div>

        <!-- HEADER -->
        <div class="flex items-end justify-between mb-6 pb-4 border-b border-white/[.04]">
            <div>
                <div class="text-[9px] text-gray-700 uppercase tracking-[.25em] mb-1">Library</div>
                <div class="section-title">BOOKS</div>
            </div>
            <span class="text-[10px] text-gray-700 uppercase tracking-widest">
                <?= $total ?> items
                <?php if ($totalPagesBooks > 1): ?>
                    <span class="text-gray-600">· Page <?= $bookPage ?>/<?= $totalPagesBooks ?></span>
                <?php endif; ?>
            </span>
        </div>

        <!-- FILTER PILLS -->
        <div class="flex gap-2 mb-8 flex-wrap">
            <a href="index.php?type=all"
                class="filter-pill <?= $filter === 'all' ? 'active' : '' ?>">
                All
            </a>
            <a href="index.php?type=manga"
                class="filter-pill <?= $filter === 'manga' ? 'active' : '' ?>">
                <i data-lucide="book-open" class="w-3 h-3 inline-block -ml-0.5 mr-1"></i> Manga
            </a>
            <a href="index.php?type=pdf"
                class="filter-pill <?= $filter === 'pdf' ? 'active' : '' ?>">
                <i data-lucide="file-text" class="w-3 h-3 inline-block -ml-0.5 mr-1"></i> PDF
            </a>

            <?php if ($role === 'admin'): ?>
                <a href="upload.php"
                    class="filter-pill ml-auto text-green-500 border-green-500/30 hover:border-green-500 hover:text-green-400 hover:bg-green-500/5">
                    <i data-lucide="upload-cloud" class="w-3 h-3 inline-block -ml-0.5 mr-1"></i> Upload
                </a>
            <?php endif; ?>
        </div>

        <!-- BOOK GRID -->
        <div id="book-container" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4 sm:gap-5">
            <?php if ($total > 0): ?>
                <?php while ($book = $books->fetch_assoc()): ?>
                    <div class="relative group">
                        <?php if ($role === 'admin'): ?>
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
                <?php endwhile; ?>
            <?php else: ?>
                <!-- EMPTY STATE -->
                <div class="col-span-full py-20 flex flex-col items-center justify-center text-center glass rounded-3xl border border-dashed border-white/[.06]">
                    <div class="w-16 h-16 rounded-2xl bg-white/[.03] border border-white/[.06] flex items-center justify-center mb-5">
                        <i data-lucide="book-open" class="w-7 h-7 text-gray-700"></i>
                    </div>
                    <p class="text-gray-600 font-bold uppercase tracking-widest text-xs mb-1">
                        Belum ada koleksi di sini<?php if (isset($_SESSION['username'])): ?>, <?= htmlspecialchars($_SESSION['username']) ?><?php endif; ?>.
                    </p>
                    <p class="text-[10px] text-gray-800 uppercase tracking-widest">
                        Pustaka masih kosong
                    </p>
                    <?php if ($role === 'admin'): ?>
                        <a href="upload.php"
                            class="mt-6 px-6 py-2.5 bg-green-600 hover:bg-green-500 text-white text-[10px] font-black uppercase tracking-widest rounded-xl transition-all shadow-lg shadow-green-900/30">
                            Upload Sekarang
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- PAGINATION -->
    <?php if ($totalPagesBooks > 1): ?>
        <div class="flex items-center justify-center gap-2 mt-10 mb-6">
            <?php if ($bookPage > 1): ?>
                <a href="index.php?type=<?= $filter ?>&page=<?= $bookPage - 1 ?>"
                    class="px-4 py-2 bg-white/[.04] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-400 hover:text-green-500 hover:border-green-500/30 transition-all">
                    <i data-lucide="chevron-left" class="w-3.5 h-3.5 inline -ml-1"></i> Prev
                </a>
            <?php endif; ?>

            <?php
            $startPage = max(1, $bookPage - 2);
            $endPage = min($totalPagesBooks, $bookPage + 2);
            for ($i = $startPage; $i <= $endPage; $i++): ?>
                <a href="index.php?type=<?= $filter ?>&page=<?= $i ?>"
                    class="w-9 h-9 flex items-center justify-center rounded-xl text-[11px] font-bold transition-all <?= $i === $bookPage ? 'bg-green-600 text-white shadow-lg shadow-green-900/30' : 'bg-white/[.04] border border-white/[.06] text-gray-500 hover:text-green-500 hover:border-green-500/30' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>

            <?php if ($bookPage < $totalPagesBooks): ?>
                <a href="index.php?type=<?= $filter ?>&page=<?= $bookPage + 1 ?>"
                    class="px-4 py-2 bg-white/[.04] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-400 hover:text-green-500 hover:border-green-500/30 transition-all">
                    Next <i data-lucide="chevron-right" class="w-3.5 h-3.5 inline -mr-1"></i>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php include '../partials/footer.php'; ?>

    <script>
        lucide.createIcons();

        // ── CONTINUE READING (localStorage) ──
        (function() {
            var banner = document.getElementById('continueBanner');
            var titleEl = document.getElementById('continueTitle');
            var linkEl = document.getElementById('continueLink');
            var closeEl = document.getElementById('continueClose');
            if (!banner || !titleEl || !linkEl) return;

            try {
                var raw = localStorage.getItem('meel_book_progress');
                if (!raw) return;

                var data = JSON.parse(raw);
                if (!data || !data.id || !data.title) return;

                // Cek apakah masih relevan (max 7 hari)
                var age = Date.now() - (data.timestamp || 0);
                if (age > 7 * 24 * 60 * 60 * 1000) {
                    localStorage.removeItem('meel_book_progress');
                    return;
                }

                // Tampilkan banner — dengan chapter jika ada
                var label = data.title;
                if (data.ch) label += ' — ' + data.ch;
                if (data.type !== 'pdf' && data.page && data.total) {
                    label += ' (Halaman ' + data.page + '/' + data.total + ')';
                }
                titleEl.textContent = label;
                linkEl.href = 'read.php?id=' + data.id + (data.ch ? '&ch=' + encodeURIComponent(data.ch) : '');
                banner.classList.add('visible');

            } catch(e) {
                // localStorage corrupt atau tidak tersedia
                console.warn('[Continue] Gagal baca progress:', e);
            }

            // Tombol tutup
            if (closeEl) {
                closeEl.addEventListener('click', function() {
                    banner.classList.remove('visible');
                    try { localStorage.removeItem('meel_book_progress'); } catch(e) {}
                });
            }
        })();

        // Client-side search filter (filters visible cards)
        function filterBooks(btn) {
            const input = document.getElementById('b-search');
            const query = input.value.toLowerCase().trim();
            const cards = document.querySelectorAll('#book-container > .group');

            cards.forEach(card => {
                const title = card.querySelector('h3')?.innerText.toLowerCase() || '';
                const author = card.querySelector('p')?.innerText.toLowerCase() || '';
                const type = card.querySelector('.type-badge')?.innerText.toLowerCase() || '';
                const matches = !query || title.includes(query) || author.includes(query) || type.includes(query);
                card.style.display = matches ? '' : 'none';
            });

            // Show empty message if nothing found
            const visibleCards = document.querySelectorAll('#book-container > .group[style*=\"display: none\"]');
            const existingEmpty = document.getElementById('search-empty-state');
            const totalCards = document.querySelectorAll('#book-container > .group').length;
            const visibleCount = totalCards - visibleCards.length;

            if (visibleCount === 0 && query) {
                if (!existingEmpty) {
                    const empty = document.createElement('div');
                    empty.id = 'search-empty-state';
                    empty.className = 'col-span-full py-20 flex flex-col items-center justify-center text-center';
                    empty.innerHTML = `
                        <div class="w-14 h-14 rounded-2xl bg-white/[.02] border border-white/[.06] flex items-center justify-center mb-4">
                            <i data-lucide="search-x" class="w-6 h-6 text-gray-700"></i>
                        </div>
                        <p class="text-gray-600 font-bold uppercase tracking-widest text-xs">
                            Tidak ada hasil untuk "<span class="text-gray-500">${query}</span>"
                        </p>
                    `;
                    document.getElementById('book-container').appendChild(empty);
                    lucide.createIcons();
                }
            } else if (existingEmpty) {
                existingEmpty.remove();
            }
        }

        // Search on Enter key
        document.getElementById('b-search')?.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') filterBooks(this);
        });

        // Re-init icons after HTMX swaps
        document.body.addEventListener('htmx:afterOnLoad', function() {
            lucide.createIcons();
        });
    </script>
</body>

</html>
