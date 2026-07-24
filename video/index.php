<?php
require_once '../modules/core/helpers.php';
session_name('meel');
session_start();
include '../auth/config.php';
require_once '../modules/media/MediaLibrary.php';

$library    = new MediaLibrary($conn);
$perPage    = 15;
$page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$meta       = $library->getVideosWithMeta($page, $perPage);
$data       = $meta['data'];
$total      = $meta['total'];
$page       = $meta['page'];
$totalPages = $meta['total_pages'];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="MEeL Video | Library">
    <meta property="og:description" content="Jelajahi koleksi video di MEeL Video Library. Streaming HLS dengan kualitas terbaik.">
    <title>MEeL Video | Library</title>
    <?php include '../partials/link.php'; ?>
    <link rel="stylesheet" href="../assets/css/video.css">
</head>

<body class="text-gray-400 min-h-screen">

    <!-- NAVBAR -->
    <nav class="border-b border-white/[.04] bg-[#080a0f]/95 sticky top-0 z-50 backdrop-blur-md">
        <div class="w-full px-3 sm:px-6 xl:px-10 2xl:px-16 h-14 flex items-center justify-between gap-2 sm:gap-4">
            <a href="../index.php" class="flex items-center gap-1 sm:gap-2.5 flex-shrink-0" title="Kembali ke MEeL HUB">
                <div class="w-6 h-6 sm:w-7 sm:h-7 bg-red-600 rounded-lg flex items-center justify-center">
                    <i data-lucide="play" class="w-3.5 h-3.5 text-white fill-current"></i>
                </div>
                <span class="text-xs sm:text-sm font-bold tracking-tight text-white uppercase hidden sm:block">
                    MEeL<span class="text-red-500">Video</span>
                </span>
            </a>

            <div class="flex-1 max-w-sm flex items-center gap-1.5 sm:gap-2">
                <div class="relative flex-1 group" title="Cari">
                    <i data-lucide="search" class="absolute left-2.5 sm:left-3.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-300 group-focus-within:text-red-500 transition-colors"></i>
                    <input type="text"
                        id="v-search"
                        name="search"
                        placeholder="Cari video..."
                        title="Cari Video"
                        aria-label="Cari video"
                        class="w-full bg-white/[.04] border border-white/[.06] rounded-xl py-2 pl-8 sm:pl-9 pr-3 sm:pr-4 text-xs focus:outline-none focus:border-red-500/40 transition-all text-gray-300"
                        hx-get="search_video.php"
                        hx-trigger="keyup[key=='Enter']"
                        hx-target="#video-container"
                        hx-indicator="#search-indicator"
                        autocomplete="off">
                </div>

                <button hx-get="search_video.php"
                    hx-include="#v-search"
                    hx-target="#video-container"
                    hx-indicator="#search-indicator"
                    title="Cari"
                    aria-label="Cari video"
                    class="px-2.5 sm:px-4 py-2 bg-white/[.04] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-300 hover:text-red-500 hover:border-red-500/30 transition-all flex-shrink-0">
                    <span class="hidden sm:inline">Cari</span>
                    <i data-lucide="search" class="w-3.5 h-3.5 sm:hidden"></i>
                </button>

                <div id="search-indicator" class="htmx-indicator ml-1 sm:ml-2">
                    <div class="animate-spin h-3 w-3 border-2 border-red-500 border-t-transparent rounded-full"></div>
                </div>
            </div>

            <div class="flex items-center gap-3 text-[10px] font-bold uppercase tracking-wider flex-shrink-0">
                <?php include '../partials/nav.php'; ?>
            </div>
        </div>
    </nav>

    <main class="w-full px-4 sm:px-6 xl:px-10 2xl:px-16 pt-8 pb-20">

        <div class="flex items-end justify-between mb-6 pb-4 border-b border-white/[.04]">
            <div>
                <div class="text-[9px] text-gray-300 uppercase tracking-[.25em] mb-1">Library</div>
                <div class="section-title">VIDEO</div>
            </div>
            <span class="text-[10px] text-gray-300 uppercase tracking-widest">
                <?= $total ?> clips
                <?php if ($totalPages > 1): ?>
                    <span class="text-gray-600">· Page <?= $page ?>/<?= $totalPages ?></span>
                <?php endif; ?>
            </span>
        </div>

        <!-- [FIX] offset load_more sesuai $limit_init (8), bukan 10 -->
        <div id="video-container" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-5" title="Muat lebih banyak">
            <?php if ($data && $data->num_rows > 0): ?>
                <?php while ($v = $data->fetch_assoc()): ?>
                    <?php include 'video_card.php'; ?>
                <?php endwhile; ?>
            <?php endif; ?>

            <?php if ($total > $perPage): ?>
                <button type="button" id="load-more-area"
                    class="aspect-video flex items-center justify-center bg-white/[.02] border border-dashed border-white/[.06] rounded-2xl cursor-pointer hover:border-red-500/30 hover:bg-white/[.03] transition-all group"
                    hx-get="load_more.php?offset=<?= $perPage ?>&page=<?= $page ?>"
                    hx-target="#load-more-area"
                    hx-swap="outerHTML"
                    aria-label="Muat lebih banyak video">
                    <span class="text-[10px] font-bold uppercase tracking-[.2em] text-gray-300 group-hover:text-red-500 transition-colors">
                        Muat Lebih Banyak · <?= $page ?>/<?= $totalPages ?>
                    </span>
                </button>
            <?php endif; ?>
        </div>
    </main>

    <?php include '../partials/footer.php'; ?>

    <script src="../assets/js/htmx.min.js"></script>
    <script>
        lucide.createIcons();
        document.body.addEventListener('htmx:afterOnLoad', function(evt) {
            lucide.createIcons();
        });
    </script>
</body>

</html>