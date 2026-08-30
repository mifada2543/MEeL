<?php
require_once '../modules/core/helpers.php';
require_once '../auth/auth.php';
require_once '../auth/config.php';
// activity_logger loaded via auth/config.php
require_once '../modules/media/MediaLibrary.php';

$repo  = new BookRepository($conn);
$u_id  = (int)$_SESSION['user_id'];
$role  = $repo->getUserRole($u_id);

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
    <?php foreach (require __DIR__ . '/../assets/css/books/manifest.php' as $__f): ?>
    <link rel="stylesheet" href="../assets/css/books/<?= $__f ?>?v=<?= filemtime(__DIR__ . '/../assets/css/books/' . $__f) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="../assets/css/books/index/main.css">
    <script src="../assets/js/compatibilitas/htmx.min.js"></script>
</head>

<body class="text-gray-400 min-h-screen">

    <!-- NAVBAR -->
    <nav class="border-b border-white/[.04] bg-[#080a0f]/95 sticky top-0 z-50 backdrop-blur-md">
        <div class="w-full px-3 sm:px-6 xl:px-10 2xl:px-16 h-14 flex items-center justify-between gap-2 sm:gap-4">
            <a href="../" class="flex items-center gap-1 sm:gap-2.5 flex-shrink-0" title="MEeL HUB">
                <div class="w-6 h-6 sm:w-7 sm:h-7 bg-green-600 rounded-lg flex items-center justify-center">
                    <i data-lucide="library" class="w-3.5 h-3.5 text-white fill-current"></i>
                </div>
                <span class="text-xs sm:text-sm font-bold tracking-tight text-white uppercase hidden sm:block">
                    MEeL<span class="text-green-500">Books</span>
                </span>
            </a>

            <!-- Search (server-side FULLTEXT via search_books.php) -->
            <form
                    hx-get="search"
                    hx-trigger="submit"
                    hx-target="#book-container"
                    hx-swap="innerHTML"
                    hx-indicator="#b-search-indicator"
                    class="flex-1 max-w-sm flex items-center gap-1.5 sm:gap-2">
                <!-- Hormati pill filter aktif (All/Manga/PDF) saat searching -->
                <input type="hidden" name="type" value="<?= htmlspecialchars($filter) ?>">
                <div class="relative flex-1 group">
                    <i data-lucide="search" class="absolute left-2.5 sm:left-3.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-600 group-focus-within:text-green-500 transition-colors"></i>
                    <input type="text"
                        id="b-search"
                        name="search"
                        placeholder="Cari buku..."
                        title="Cari buku"
                        aria-label="Cari buku"
                        class="w-full bg-white/[.04] border border-white/[.06] rounded-xl py-2 pl-8 sm:pl-9 pr-3 sm:pr-4 text-xs focus:outline-none focus:border-green-500/40 transition-all text-gray-300"
                        autocomplete="off"
                        enterkeyhint="search">
                </div>
                <button type="submit"
                    title="Cari"
                    aria-label="Cari buku"
                    class="px-2.5 sm:px-4 py-2 bg-white/[.04] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-500 hover:text-green-500 hover:border-green-500/30 transition-all flex-shrink-0">
                    <span class="hidden sm:inline">Cari</span>
                    <i data-lucide="search" class="w-3.5 h-3.5 sm:hidden"></i>
                </button>
                <div id="b-search-indicator" class="htmx-indicator ml-1 sm:ml-2">
                    <div class="animate-spin h-3 w-3 border-2 border-green-500 border-t-transparent rounded-full"></div>
                </div>
            </form>

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
            <a href="?type=all"
                class="filter-pill <?= $filter === 'all' ? 'active' : '' ?>">
                All
            </a>
            <a href="?type=manga"
                class="filter-pill <?= $filter === 'manga' ? 'active' : '' ?>">
                <i data-lucide="book-open" class="w-3 h-3 inline-block -ml-0.5 mr-1"></i> Manga
            </a>
            <a href="?type=pdf"
                class="filter-pill <?= $filter === 'pdf' ? 'active' : '' ?>">
                <i data-lucide="file-text" class="w-3 h-3 inline-block -ml-0.5 mr-1"></i> PDF
            </a>

            <?php if ($role === 'admin'): ?>
                <a href="upload"
                    class="filter-pill ml-auto text-green-500 border-green-500/30 hover:border-green-500 hover:text-green-400 hover:bg-green-500/5">
                    <i data-lucide="upload-cloud" class="w-3 h-3 inline-block -ml-0.5 mr-1"></i> Upload
                </a>
            <?php endif; ?>
        </div>

        <!-- BOOK GRID -->
        <div id="book-container" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4 sm:gap-5">
            <?php if ($total > 0): ?>
                <?php while ($book = $books->fetch_assoc()): ?>
                    <?php include 'book_card.php'; ?>
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
                        <a href="upload"
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
                <a href="?type=<?= $filter ?>&page=<?= $bookPage - 1 ?>"
                    class="px-4 py-2 bg-white/[.04] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-400 hover:text-green-500 hover:border-green-500/30 transition-all">
                    <i data-lucide="chevron-left" class="w-3.5 h-3.5 inline -ml-1"></i> Prev
                </a>
            <?php endif; ?>
            <?php
            $startPage = max(1, $bookPage - 2);
            $endPage = min($totalPagesBooks, $bookPage + 2);
            for ($i = $startPage; $i <= $endPage; $i++): ?>
                <a href="?type=<?= $filter ?>&page=<?= $i ?>"
                    class="w-9 h-9 flex items-center justify-center rounded-xl text-[11px] font-bold transition-all <?= $i === $bookPage ? 'bg-green-600 text-white shadow-lg shadow-green-900/30' : 'bg-white/[.04] border border-white/[.06] text-gray-500 hover:text-green-500 hover:border-green-500/30' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
            <?php if ($bookPage < $totalPagesBooks): ?>
                <a href="?type=<?= $filter ?>&page=<?= $bookPage + 1 ?>"
                    class="px-4 py-2 bg-white/[.04] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-400 hover:text-green-500 hover:border-green-500/30 transition-all">
                    Next <i data-lucide="chevron-right" class="w-3.5 h-3.5 inline -mr-1"></i>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php include '../partials/footer.php'; ?>
    <script>        lucide.createIcons();

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

                var age = Date.now() - (data.timestamp || 0);
                if (age > 7 * 24 * 60 * 60 * 1000) {
                    localStorage.removeItem('meel_book_progress');
                    return;
                }

                var label = data.title;
                if (data.ch) label += ' — ' + data.ch;
                if (data.type !== 'pdf' && data.page && data.total) {
                    label += ' (Halaman ' + data.page + '/' + data.total + ')';
                }
                titleEl.textContent = label;
                linkEl.href = '<?= base_url('/books/read?id=') ?>' + data.id + (data.ch ? '&ch=' + encodeURIComponent(data.ch) : '');
                banner.classList.add('visible');

            } catch(e) {
                console.warn('[Continue] Gagal baca progress:', e);
            }

            if (closeEl) {
                closeEl.addEventListener('click', function() {
                    banner.classList.remove('visible');
                    try { localStorage.removeItem('meel_book_progress'); } catch(e) {}
                });
            }
        })();

        document.body.addEventListener('htmx:afterOnLoad', function() {
            lucide.createIcons();
        });
</script>
</body>

</html>
