<?php
require_once '../modules/core/helpers.php';
meel_boot_session();
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

$__v = function($f) {
    static $mtimeCache = [];
    $path = __DIR__ . '/../' . $f;
    if (!isset($mtimeCache[$path])) {
        $mtimeCache[$path] = @filemtime($path);
    }
    return '?v=' . $mtimeCache[$path];
};
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
    <?php foreach (require __DIR__ . '/../assets/css/video/manifest.php' as $__f): ?>
    <link rel="stylesheet" href="../assets/css/video/<?= $__f ?><?= $__v('assets/css/video/' . $__f) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="../assets/css/video/index/main.css">
</head>

<body class="text-gray-400 min-h-screen">

    
    <nav class="meel-nav sticky top-0 z-50" style="border-bottom:1px solid var(--meel-nav-border)">
        <div class="w-full px-3 sm:px-6 xl:px-10 2xl:px-16 h-14 flex items-center justify-between gap-2 sm:gap-4">
            <a href="../" class="flex items-center gap-1 sm:gap-2.5 flex-shrink-0" title="Kembali ke MEeL HUB">
                <div class="w-6 h-6 sm:w-7 sm:h-7 rounded-lg flex items-center justify-center" style="background:var(--meel-red)">
                    <i data-lucide="play" class="nav-logo-icon w-3.5 h-3.5"></i>
                </div>
                <span class="nav-logo-text text-xs sm:text-sm font-bold tracking-tight uppercase hidden sm:block">
                    MEeL<span style="color:var(--meel-red)">Video</span>
                </span>
            </a>

            <form
                    hx-get="search"
                    hx-trigger="submit"
                    hx-target="#video-container"
                    hx-indicator="#search-indicator"
                    class="flex-1 max-w-sm flex items-center gap-1.5 sm:gap-2">
                <div class="relative flex-1 group" title="Cari">
                    <i data-lucide="search" class="absolute left-2.5 sm:left-3.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 transition-colors" style="color:var(--meel-text-secondary)"></i>
                    <input type="text"
                        id="v-search"
                        name="search"
                        placeholder="Cari video..."
                        title="Cari Video"
                        aria-label="Cari video"
                        class="meel-input w-full rounded-xl py-2 pl-8 sm:pl-9 pr-3 sm:pr-4 text-xs transition-all"
                        autocomplete="off"
                        enterkeyhint="search">
                </div>

                <button type="submit"
                    title="Cari"
                    aria-label="Cari video"
                    class="meel-input px-2.5 sm:px-4 py-2 rounded-xl text-[10px] font-bold uppercase tracking-widest transition-all flex-shrink-0"
                    style="color:var(--meel-text-secondary)"
                    onmouseover="this.style.color='var(--meel-red)'"
                    onmouseout="this.style.color='var(--meel-text-secondary)'">
                    <span class="hidden sm:inline">Cari</span>
                    <i data-lucide="search" class="w-3.5 h-3.5 sm:hidden"></i>
                </button>

                <div id="search-indicator" class="htmx-indicator ml-1 sm:ml-2">
                    <div class="animate-spin h-3 w-3 border-2 border-t-transparent rounded-full" style="border-color:var(--meel-red); border-top-color:transparent"></div>
                </div>
            </form>

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
            </span>
        </div>

        
        <div id="video-container" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-5" title="Muat lebih banyak">
            <?php if ($data && $data->num_rows > 0): ?>
                <?php while ($v = $data->fetch_assoc()): ?>
                    <?php include 'video_card.php'; ?>
                <?php endwhile; ?>
            <?php else: ?>
                

                <div class="col-span-full py-16 text-center text-[10px] text-gray-700 uppercase tracking-widest">
                    Video tidak ditemukan.
                </div>
            <?php endif; ?>
            <?php if ($total > $perPage): ?>
                <button type="button" id="load-more-area"
                    class="aspect-video flex items-center justify-center bg-white/[.02] border border-dashed border-white/[.06] rounded-2xl cursor-pointer hover:border-red-500/30 hover:bg-white/[.03] transition-all group"
                    hx-get="load-more?offset=<?= $perPage ?>&page=<?= $page ?>"
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
    <script src="../assets/js/compatibilitas/htmx.min.js"></script>
    <script src="../assets/js/shared/htmx-lucide.js<?= $__v('assets/js/shared/htmx-lucide.js') ?>"></script>
</body>

</html>
