<?php
require_once '../modules/core/helpers.php';
meel_boot_session();
include '../auth/config.php';
require_once '../modules/media/MediaLibrary.php';

$library       = new MediaLibrary($conn);
$format_filter = $_GET['format'] ?? 'all';
$artist_filter = $_GET['artist'] ?? 'all';
$perPageMusic  = 10;
$pageMusic     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$artists        = $library->getArtists();
$meta_music     = $library->getMusicListWithMeta($format_filter, $artist_filter, $pageMusic, $perPageMusic);
$total_music    = $meta_music['total'];
$data_init      = $meta_music['data'];
$pageMusic      = $meta_music['page'];
$totalPagesMusic = $meta_music['total_pages'];
$is_logged_in   = isset($_SESSION['user_id']);

function renderLibraryContent($artist_filter, $total_music, $data_init, $format_filter, $totalPagesMusic = 1, $pageMusic = 1, $perPageMusic = 10)
{
?>
    <!-- HEADER -->
    <div class="flex items-end justify-between mb-6 pb-4 border-b border-white/[.04]">
        <div>
            <div class="text-[9px] text-gray-700 uppercase tracking-[.25em] mb-1">Library</div>
            <div class="section-title">
                <?= $artist_filter === 'all' ? 'DISCOVERY' : strtoupper(htmlspecialchars($artist_filter)) ?>
            </div>
        </div>
        <span class="text-[10px] text-gray-700 uppercase tracking-widest">
            <?= $total_music ?> tracks
        </span>
    </div>

    <!-- MUSIC LIST -->
    <div id="music-list" class="space-y-1">
        <?php if ($data_init && $data_init->num_rows > 0): ?>
            <?php while ($v = $data_init->fetch_assoc()): ?>
                <?php include 'music_item.php'; ?>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="py-16 text-center text-[10px] text-gray-700 uppercase tracking-widest">
                Tidak ada lagu ditemukan.
            </div>
        <?php endif; ?>
    </div>

    <!-- LOAD MORE (outside #music-list, never replaced, only URL updated via JS) -->
    <?php if ($total_music > $perPageMusic): ?>
        <div id="load-more-music" class="pt-6">                <button type="button" id="load-more-btn"                    hx-get="load-more?offset=<?= $perPageMusic ?>&page=<?= $pageMusic ?>&format=<?= $format_filter ?>&artist=<?= urlencode($artist_filter) ?>"
                hx-target="#music-list"
                hx-swap="beforeend"
                title="Muat lebih banyak lagu"
                class="w-full py-4 border border-dashed border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-[.25em] text-gray-700 hover:text-orange-500 hover:border-orange-500/30 transition-all">
                Load More · <?= $pageMusic ?>/<?= $totalPagesMusic ?>
            </button>
        </div>
    <?php endif; ?>
<?php
}

$playlist_id_from_url = isset($_GET['playlist_id']) ? (int)$_GET['playlist_id'] : 0;

$audio_state = null;
if (isset($_GET['audio_state'])) {
    $audio_state = json_decode($_GET['audio_state'], true);
}

if (isset($_GET['content_only'])) {
    renderLibraryContent($artist_filter, $total_music, $data_init, $format_filter, $totalPagesMusic, $pageMusic, $perPageMusic);
    exit;
}

$__v = function($f) {
    static $mtimeCache = [];
    $path = __DIR__ . '/../' . $f;
    if (!isset($mtimeCache[$path])) {
        $mtimeCache[$path] = @filemtime($path);
    }
    return '?v=' . $mtimeCache[$path];
};

$__vdir = function($dir) {
    static $mtimeCache = [];
    $path = __DIR__ . '/../' . $dir;
    if (!isset($mtimeCache[$path])) {
        $max = 0;
        foreach (glob($path . '/*.js') ?: [] as $f) {
            $max = max($max, (int)@filemtime($f));
        }
        $mtimeCache[$path] = $max;
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
    <meta property="og:title" content="MEeL Music | Library">
    <meta property="og:description" content="Jelajahi koleksi musik di MEeL Music Library. Streaming audio lossless dengan kualitas terbaik.">
    <title>MEeL Music | Library</title>
    <?php include '../partials/link.php'; ?>
    <?php foreach (require __DIR__ . '/../assets/css/music/manifest.php' as $__f): ?>
    <link rel="stylesheet" href="../assets/css/music/<?= $__f ?><?= $__v('assets/css/music/' . $__f) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="../assets/css/music/index/main.css">
    <script src="../assets/js/compatibilitas/htmx.min.js" defer></script>
</head>

<body class="text-gray-400 min-h-screen">

    <!-- NAVBAR -->
    <nav class="meel-nav sticky top-0 z-50" style="border-bottom:1px solid var(--meel-nav-border)">
        <div class="w-full px-3 sm:px-6 xl:px-10 2xl:px-16 h-14 flex items-center justify-between gap-2 sm:gap-4">
            <a href="../" class="flex items-center gap-1 sm:gap-2.5 flex-shrink-0" title="MEeL HUB">
                <div class="w-6 h-6 sm:w-7 sm:h-7 rounded-lg flex items-center justify-center" style="background:var(--meel-orange)">
                    <i data-lucide="music" class="nav-logo-icon w-3.5 h-3.5"></i>
                </div>
                <span class="nav-logo-text text-xs sm:text-sm font-bold tracking-tight uppercase hidden sm:block">
                    MEeL<span style="color:var(--meel-orange)">Music</span>
                </span>
            </a>

            <form
                    hx-get="search"
                    hx-trigger="submit"
                    hx-target="#music-list"
                    hx-indicator="#search-indicator"
                    class="flex-1 max-w-sm flex items-center gap-1.5 sm:gap-2">
                <div class="relative flex-1 group">
                    <i data-lucide="search" class="absolute left-2.5 sm:left-3.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 transition-colors" style="color:var(--meel-text-muted)"></i>
                    <input type="text"
                        id="m-search"
                        name="search"
                        placeholder="Cari lagu..."
                        class="meel-input w-full rounded-xl py-2 pl-8 sm:pl-9 pr-3 sm:pr-4 text-xs transition-all"
                        autocomplete="off">
                </div>
                <button type="submit"
                    title="Cari lagu"
                    aria-label="Cari lagu"
                    class="meel-input px-2.5 sm:px-4 py-2 rounded-xl text-[10px] font-bold uppercase tracking-widest transition-all flex-shrink-0"
                    style="color:var(--meel-text-secondary)"
                    onmouseover="this.style.color='var(--meel-orange)'"
                    onmouseout="this.style.color='var(--meel-text-secondary)'">
                    <span class="hidden sm:inline">Cari</span>
                    <i data-lucide="search" class="w-3.5 h-3.5 sm:hidden"></i>
                </button>
                <div id="search-indicator" class="htmx-indicator ml-1 sm:ml-2">
                    <div class="animate-spin h-3 w-3 border-2 border-t-transparent rounded-full" style="border-color:var(--meel-orange); border-top-color:transparent"></div>
                </div>
            </form>

            <div class="flex items-center gap-3 text-[10px] font-bold uppercase tracking-wider flex-shrink-0">
                <?php include '../partials/nav.php'; ?>
            </div>
        </div>
    </nav>

    <div id="library-container" class="w-full px-4 sm:px-6 xl:px-10 2xl:px-16 pt-8 pb-20 grid grid-cols-1 lg:grid-cols-12 gap-8">

        <!-- SIDEBAR -->
        <aside class="lg:col-span-3 xl:col-span-2">
            <div class="sticky top-20 space-y-6">

                <!-- FORMAT PILLS (Desktop) -->
                <div class="hidden lg:block">
                    <div class="text-[9px] font-bold text-gray-700 uppercase tracking-[.25em] mb-3">Format</div>
                    <div class="flex flex-wrap gap-2">
                        <a href="beranda?format=all&artist=<?= urlencode($artist_filter) ?>"
                            hx-get="beranda?format=all&artist=<?= urlencode($artist_filter) ?>" hx-push-url="true"
                            hx-target="#library-container"
                            hx-select="#library-container"
                            hx-swap="outerHTML"
                            class="format-pill <?= $format_filter === 'all' ? 'active-orange' : '' ?>">All</a>
                        <a href="beranda?format=ogg&artist=<?= urlencode($artist_filter) ?>"
                            hx-get="beranda?format=ogg&artist=<?= urlencode($artist_filter) ?>" hx-push-url="true"
                            hx-target="#library-container"
                            hx-select="#library-container"
                            hx-swap="outerHTML"
                            class="format-pill <?= $format_filter === 'ogg' ? 'active-orange' : '' ?>">Opus</a>
                        <a href="beranda?format=m4a&artist=<?= urlencode($artist_filter) ?>"
                            hx-get="beranda?format=m4a&artist=<?= urlencode($artist_filter) ?>" hx-push-url="true"
                            hx-target="#library-container"
                            hx-select="#library-container"
                            hx-swap="outerHTML"
                            class="format-pill <?= $format_filter === 'm4a' ? 'active-green' : '' ?>">M4A</a>
                        <a href="beranda?format=mp3&artist=<?= urlencode($artist_filter) ?>"
                            hx-get="beranda?format=mp3&artist=<?= urlencode($artist_filter) ?>" hx-push-url="true"
                            hx-target="#library-container"
                            hx-select="#library-container"
                            hx-swap="outerHTML"
                            class="format-pill <?= $format_filter === 'mp3' ? 'active-blue' : '' ?>">MP3</a>
                    </div>
                </div>

                <!-- ARTISTS (Desktop) -->
                <div class="hidden lg:block">
                    <div class="text-[9px] font-bold text-gray-700 uppercase tracking-[.25em] mb-3 flex items-center gap-2">
                        <i data-lucide="mic-2" class="w-3 h-3"></i> Artists
                    </div>
                    <div id="desktop-artist-list" class="space-y-0.5 max-h-[45vh] overflow-y-auto no-scrollbar">
                        <a href="beranda?format=<?= $format_filter ?>&artist=all"
                            hx-get="beranda?format=<?= $format_filter ?>&artist=all" hx-push-url="true"
                            hx-target="#library-container"
                            hx-select="#library-container"
                            hx-swap="outerHTML"
                            class="sidebar-link flex items-center justify-between px-3 py-2.5 rounded-lg text-[11px] font-bold transition-all
                                 <?= $artist_filter === 'all' ? 'active' : 'text-gray-600 hover:text-gray-300 hover:bg-white/[.03]' ?>">
                            <span>All Collections</span>
                        </a>
                        <?php
                        $artists->data_seek(0);
                        while ($a = $artists->fetch_assoc()): ?>
                            <a href="beranda?format=<?= $format_filter ?>&artist=<?= urlencode($a['artist']) ?>"
                                hx-get="beranda?format=<?= $format_filter ?>&artist=<?= urlencode($a['artist']) ?>" hx-push-url="true"
                                hx-target="#library-container"
                                hx-select="#library-container"
                                hx-swap="outerHTML"
                                class="sidebar-link flex items-center justify-between px-3 py-2.5 rounded-lg text-[11px] font-bold transition-all
                                     <?= $artist_filter === $a['artist'] ? 'active' : 'text-gray-600 hover:text-gray-300 hover:bg-white/[.03]' ?>">
                                <span class="truncate"><?= htmlspecialchars($a['artist']) ?></span>
                            </a>
                        <?php endwhile; ?>
                    </div>
                </div>

                <!-- PLAYLISTS (Desktop) -->
                <?php if ($is_logged_in): ?>
                    <div class="hidden lg:block">
                        <div class="text-[9px] font-bold text-gray-700 uppercase tracking-[.25em] mb-3 flex items-center gap-2">
                            <i data-lucide="list-music" class="w-3 h-3"></i> Playlists
                        </div>
                        <div class="space-y-0.5 max-h-[30vh] overflow-y-auto no-scrollbar">
                            <?php
                            $pl_routes  = $library->getUserPlaylistRoutes($_SESSION['user_id']);
                            $playlists  = $library->getUserPlaylists($_SESSION['user_id']);
                            while ($pl = $playlists->fetch_assoc()):
                                $pl_route = $pl_routes[$pl['id']] ?? ('playlist?id=' . (int) $pl['id']);
                                $pl_sep   = str_contains($pl_route, '?') ? '&' : '?';
                            ?>
                                <a href="<?= $pl_route ?>"
                                    hx-get="<?= $pl_route . $pl_sep ?>content_only=1"
                                    hx-target="main"
                                    hx-swap="innerHTML"
                                    hx-push-url="<?= $pl_route ?>"
                                    class="sidebar-link flex items-center gap-2 px-3 py-2.5 rounded-lg text-[11px] font-bold text-gray-600 hover:text-gray-300 hover:bg-white/[.03] transition-all pl-link"
                                    data-playlist-id="<?= $pl['id'] ?>"
                                    data-playlist-url="<?= $pl_route ?>"
                                    onclick="setActivePlaylist(<?= $pl['id'] ?>); if (typeof resetLibraryFilters === 'function') resetLibraryFilters()">
                                    <i data-lucide="disc-3" class="w-3 h-3 flex-shrink-0"></i>
                                    <span class="truncate"><?= htmlspecialchars($pl['name']) ?></span>
                                </a>
                            <?php endwhile; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <!-- MOBILE FILTERS & MENUS (Select/Dropdowns) -->
                <div id="mobile-filters" class="lg:hidden flex flex-col gap-4 bg-[#0d1017]/95 backdrop-blur-md p-4 rounded-xl border border-white/[.04] shadow-lg">
                    <!-- Format Pills (Mobile) -->
                    <div class="flex flex-wrap gap-2">
                        <a href="beranda?format=all&artist=<?= urlencode($artist_filter) ?>"
                            hx-get="beranda?format=all&artist=<?= urlencode($artist_filter) ?>" hx-push-url="true"
                            hx-target="#library-container"
                            hx-select="#library-container"
                            hx-swap="outerHTML"
                            class="format-pill <?= $format_filter === 'all' ? 'active-orange' : '' ?>">All</a>
                        <a href="beranda?format=ogg&artist=<?= urlencode($artist_filter) ?>"
                            hx-get="beranda?format=ogg&artist=<?= urlencode($artist_filter) ?>" hx-push-url="true"
                            hx-target="#library-container"
                            hx-select="#library-container"
                            hx-swap="outerHTML"
                            class="format-pill <?= $format_filter === 'ogg' ? 'active-orange' : '' ?>">Opus</a>
                        <a href="beranda?format=m4a&artist=<?= urlencode($artist_filter) ?>"
                            hx-get="beranda?format=m4a&artist=<?= urlencode($artist_filter) ?>" hx-push-url="true"
                            hx-target="#library-container"
                            hx-select="#library-container"
                            hx-swap="outerHTML"
                            class="format-pill <?= $format_filter === 'm4a' ? 'active-green' : '' ?>">M4A</a>
                        <a href="beranda?format=mp3&artist=<?= urlencode($artist_filter) ?>"
                            hx-get="beranda?format=mp3&artist=<?= urlencode($artist_filter) ?>" hx-push-url="true"
                            hx-target="#library-container"
                            hx-select="#library-container"
                            hx-swap="outerHTML"
                            class="format-pill <?= $format_filter === 'mp3' ? 'active-blue' : '' ?>">MP3</a>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Artists Select (Custom Dropdown) -->
                        <div>
                            <div class="text-[9px] font-bold text-gray-700 uppercase tracking-[.25em] mb-1.5 flex items-center gap-1.5">
                                <i data-lucide="mic-2" class="w-3 h-3"></i> Artists
                            </div>
                            <div class="relative w-full z-[100]" id="custom-artist-dropdown">

                                <button type="button"
                                    onclick="toggleArtistDropdown()"
                                    title="Filter berdasarkan artis"
                                    class="w-full bg-white/[.03] border border-white/[.06] rounded-xl pl-3.5 pr-10 py-2.5 text-xs text-gray-300 focus:outline-none focus:border-orange-500/40 cursor-pointer flex items-center justify-between transition-all hover:bg-white/[.05] hover:border-white/[.1] relative z-[100]">
                                    <span class="truncate"><?= $artist_filter === 'all' ? 'All Collections' : htmlspecialchars($artist_filter) ?></span>
                                    <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-gray-500"></i>
                                </button>

                                <div id="artist-options" class="hidden absolute left-0 right-0 mt-1 bg-[#0d1017] border border-white/[.08] rounded-xl shadow-2xl z-[100] max-h-60 overflow-y-auto no-scrollbar backdrop-blur-xl">
                                    <button hx-get="beranda?format=<?= $format_filter ?>&artist=all" hx-push-url="true"
                                        hx-target="#library-container"
                                        hx-select="#library-container"
                                        hx-swap="outerHTML"
                                        onclick="closeArtistDropdown()"
                                        class="w-full text-left px-4 py-2.5 text-xs text-gray-300 hover:bg-white/[.04] transition-colors truncate <?= $artist_filter === 'all' ? 'text-orange-500 font-bold' : '' ?>">
                                        All Collections
                                    </button>
                                    <?php
                                    $artists->data_seek(0);
                                    while ($a = $artists->fetch_assoc()): ?>
                                        <button hx-get="beranda?format=<?= $format_filter ?>&artist=<?= urlencode($a['artist']) ?>" hx-push-url="true"
                                            hx-target="#library-container"
                                            hx-select="#library-container"
                                            hx-swap="outerHTML"
                                            onclick="closeArtistDropdown()"
                                            class="w-full text-left px-4 py-2.5 text-xs text-gray-300 hover:bg-white/[.04] transition-colors truncate <?= $artist_filter === $a['artist'] ? 'text-orange-500 font-bold' : '' ?>">
                                            <?= htmlspecialchars($a['artist']) ?>
                                        </button>
                                    <?php endwhile; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Playlists Select (Custom Dropdown) -->
                        <?php if ($is_logged_in): ?>
                            <div>
                                <div class="text-[9px] font-bold text-gray-700 uppercase tracking-[.25em] mb-1.5 flex items-center gap-1.5">
                                    <i data-lucide="list-music" class="w-3 h-3"></i> Playlists
                                </div>
                                <div class="relative w-full z-[100]" id="custom-playlist-dropdown">
                                    <button type="button"
                                        onclick="togglePlaylistDropdown()"
                                        title="Pilih playlist"
                                        class="w-full bg-white/[.03] border border-white/[.06] rounded-xl pl-3.5 pr-10 py-2.5 text-xs text-gray-300 focus:outline-none focus:border-orange-500/40 cursor-pointer flex items-center justify-between transition-all hover:bg-white/[.05] hover:border-white/[.1] relative z-[100]">
                                        <span class="truncate" id="playlist-dropdown-label">Pilih Playlist...</span>
                                        <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-gray-500"></i>
                                    </button>
                                    <div id="playlist-options" class="hidden absolute left-0 right-0 mt-1 bg-[#0d1017] border border-white/[.08] rounded-xl shadow-2xl z-[100] max-h-60 overflow-y-auto no-scrollbar backdrop-blur-xl">
                                        <?php
                                        $playlists_mobile = $library->getUserPlaylists($_SESSION['user_id']);
                                        while ($pl = $playlists_mobile->fetch_assoc()):
                                            $pl_route = $pl_routes[$pl['id']] ?? ('playlist?id=' . (int) $pl['id']);
                                            $pl_sep   = str_contains($pl_route, '?') ? '&' : '?';
                                        ?>
                                            <button onclick="navigateToPlaylistMobile(<?= $pl['id'] ?>)"
                                                data-playlist-id="<?= $pl['id'] ?>"
                                                data-playlist-url="<?= $pl_route ?>"
                                                class="w-full text-left px-4 py-2.5 text-xs text-gray-300 hover:bg-white/[.04] transition-colors truncate">
                                                <?= htmlspecialchars($pl['name']) ?>
                                            </button>
                                        <?php endwhile; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </aside>

        <!-- MAIN -->
        <main class="lg:col-span-9 xl:col-span-10">
            <?php renderLibraryContent($artist_filter, $total_music, $data_init, $format_filter, $totalPagesMusic, $pageMusic, $perPageMusic); ?>
        </main>
    </div>

    <!-- MINI PLAYER INDEX (Spotify-style) -->
    <div id="mini-player-index" aria-label="Mini Player">

        <!-- Seekbar atas -->
        <div class="mp-seekbar" id="mp-seekbar-index" onclick="event.stopPropagation(); miniSeekIndex(event);" title="Klik untuk seek">
            <div class="mp-seekbar-fill" id="mp-seekbar-fill-index"></div>
            <div class="mp-seekbar-thumb" id="mp-seekbar-thumb-index"></div>
        </div>

        <div class="mp-body">
            <!-- Kiri: art + info -->
            <div class="mp-track">
                <div class="mp-art" onclick="expandPlayerFromMiniPlayer()">
                    <img id="mini-thumbnail-index" title="Buka player penuh" src="<?= htmlspecialchars(music_thumbnail_url('default.png')) ?>" alt="Cover lagu" width="256" height="256" loading="eager" decoding="async">
                    <div class="mp-art-overlay">
                        <i data-lucide="maximize-2" style="width:14px;height:14px;"></i>
                    </div>
                </div>
                <div class="mp-meta">
                    <div class="mp-title" id="mini-title-index">Tidak ada musik</div>
                    <div class="mp-artist" id="mini-artist-index">Unknown</div>
                </div>
            </div>

            <!-- Tengah: kontrol -->
            <div class="mp-controls">
                <button class="mp-btn mp-btn-ghost" id="mini-loop-btn-index" onclick="toggleMiniLoopIndex()" title="Ulangi lagu" aria-label="Ulang">
                    <i data-lucide="repeat" style="width:15px;height:15px;"></i>
                </button>
                <button class="mp-btn mp-btn-ghost" onclick="miniPrevIndex()" id="mp-prev-btn-index" title="Sebelumnya" aria-label="Lagu Sebelumnya">
                    <i data-lucide="skip-back" style="width:16px;height:16px;"></i>
                </button>
                <button class="mp-btn mp-btn-primary" onclick="miniPlayPauseIndex()" id="mini-play-btn-index" title="Putar / Jeda" aria-label="Putar atau jeda">
                    <i data-lucide="play" style="width:18px;height:18px;"></i>
                </button>
                <button class="mp-btn mp-btn-ghost" onclick="miniNextIndex()" id="mp-next-btn-index" title="Berikutnya" aria-label="Lagu Berikutnya">
                    <i data-lucide="skip-forward" style="width:16px;height:16px;"></i>
                </button>
            </div>

            <!-- Kanan: waktu + tutup -->
            <div class="mp-right">
                <div class="mp-time">
                    <span id="mini-current-time-index">0:00</span>
                    <span class="mp-time-sep">/</span>
                    <span id="mini-duration-index">0:00</span>
                </div>
                <button class="mp-btn mp-btn-ghost mp-close" onclick="closeMiniPlayerIndex()" title="Tutup mini player" aria-label="Tutup mini player">
                    <i data-lucide="x" style="width:16px;height:16px;"></i>
                </button>
            </div>
        </div>
    </div>
    <script>
        window.MEEL_INDEX_CONFIG = {
            playlistId: <?= (int)$playlist_id_from_url ?>
        };
    </script>
    <script src="../assets/js/shared/state-keys.js<?= $__v('assets/js/shared/state-keys.js') ?>"></script>
    <script src="../assets/js/shared/format-time.js<?= $__v('assets/js/shared/format-time.js') ?>"></script>
    <script src="../assets/js/shared/keyboard.js<?= $__v('assets/js/shared/keyboard.js') ?>"></script>
    <script src="../assets/js/compatibilitas/plyr.min.js"></script>
    <script src="../assets/js/shared/plyr-config.js<?= $__v('assets/js/shared/plyr-config.js') ?>"></script>
    <script src="../assets/js/shared/audio-engine.js<?= $__v('assets/js/shared/audio-engine.js') ?>"></script>
    <script src="../assets/js/shared/view-router.js<?= $__v('assets/js/shared/view-router.js') ?>"></script>
    <script src="../assets/js/music/shared/mini-player.js<?= $__v('assets/js/music/shared/mini-player.js') ?>"></script>
    <script src="../assets/js/music/index/main.js<?= $__vdir('assets/js/music/index') ?>"></script>
    <?php include '../partials/footer.php'; ?>
</body>

</html>
