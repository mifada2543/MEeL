<?php
require_once '../modules/helpers.php';
session_name('meel');
session_start();
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
            <?php if ($totalPagesMusic > 1): ?>
                <span class="text-gray-600">· Page <?= $pageMusic ?>/<?= $totalPagesMusic ?></span>
            <?php endif; ?>
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
        <div id="load-more-music" class="pt-6">                <button type="button" id="load-more-btn"
                hx-get="load_more_music.php?offset=<?= $perPageMusic ?>&page=<?= $pageMusic ?>&format=<?= $format_filter ?>&artist=<?= urlencode($artist_filter) ?>"
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

// Cek playlist_id dari URL (fallback dari redirect goBackToLibrary())
$playlist_id_from_url = isset($_GET['playlist_id']) ? (int)$_GET['playlist_id'] : 0;

// Check audio state dari sessionStorage (via hidden input)
$audio_state = null;
if (isset($_GET['audio_state'])) {
    $audio_state = json_decode($_GET['audio_state'], true);
}

if (isset($_GET['content_only'])) {
    renderLibraryContent($artist_filter, $total_music, $data_init, $format_filter, $totalPagesMusic, $pageMusic, $perPageMusic);
    exit;
}
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
    <link rel="stylesheet" href="../assets/css/music.css">
    <script src="../assets/js/htmx.min.js" defer></script>
    <style>
        .artist-dropdown-active .music-item {
            pointer-events: none !important;
        }

        /* Smoothly blur and dim the main discovery content when the mobile dropdown is active */
        main {
            transition: filter 0.2s ease, opacity 0.2s ease;
        }

        .artist-dropdown-active main {
            position: relative;
            z-index: 10;
            filter: blur(4px);
            opacity: 0.45;
        }

        .artist-dropdown-active aside {
            position: relative;
            z-index: 50;
        }

        /* Pastikan area kosong di mini-player tidak memicu pointer tangan */
        #mini-player-index {
            cursor: default !important;
        }

        /* Cegah browser menggunakan tombol load-more sebagai scroll anchor */
        #load-more-music {
            overflow-anchor: none;
        }

        /* Berikan kursor pointer KHUSUS untuk thumbnail mini player */
        #mini-player-index img,
        .mp-thumbnail {
            cursor: pointer !important;
            transition: transform 0.2s ease;
        }

        /* Opsional: Beri efek sedikit membesar saat thumbnail di-hover agar user tahu itu bisa diklik */
        #mini-player-index img:hover,
        .mp-thumbnail:hover {
            transform: scale(1.05);
        }
    </style>
</head>

<body class="text-gray-400 min-h-screen">

    <!-- NAVBAR -->
    <nav class="border-b border-white/[.04] bg-[#080a0f]/95 sticky top-0 z-50 backdrop-blur-md">
        <div class="w-full px-3 sm:px-6 xl:px-10 2xl:px-16 h-14 flex items-center justify-between gap-2 sm:gap-4">
            <a href="../index.php" class="flex items-center gap-1 sm:gap-2.5 flex-shrink-0" title="MEeL HUB">
                <div class="w-6 h-6 sm:w-7 sm:h-7 bg-orange-600 rounded-lg flex items-center justify-center">
                    <i data-lucide="music" class="w-3.5 h-3.5 text-white fill-current"></i>
                </div>
                <span class="text-xs sm:text-sm font-bold tracking-tight text-white uppercase hidden sm:block">
                    MEeL<span class="text-orange-500">Music</span>
                </span>
            </a>

            <div class="flex-1 max-w-sm flex items-center gap-1.5 sm:gap-2">
                <div class="relative flex-1 group">
                    <i data-lucide="search" class="absolute left-2.5 sm:left-3.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-600 group-focus-within:text-orange-500 transition-colors"></i>
                    <input type="text"
                        id="m-search"
                        name="search"
                        placeholder="Cari lagu..."
                        class="w-full bg-white/[.04] border border-white/[.06] rounded-xl py-2 pl-8 sm:pl-9 pr-3 sm:pr-4 text-xs focus:outline-none focus:border-orange-500/40 transition-all text-gray-300"
                        hx-get="search_music.php"
                        hx-trigger="keyup[key=='Enter']"
                        hx-target="#music-list"
                        hx-indicator="#search-indicator"
                        autocomplete="off">
                </div>
                <button hx-get="search_music.php"
                    hx-include="#m-search"
                    hx-target="#music-list"
                    hx-indicator="#search-indicator"
                    title="Cari lagu"
                    aria-label="Cari lagu"
                    class="px-2.5 sm:px-4 py-2 bg-white/[.04] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-500 hover:text-orange-500 hover:border-orange-500/30 transition-all flex-shrink-0">
                    <span class="hidden sm:inline">Cari</span>
                    <i data-lucide="search" class="w-3.5 h-3.5 sm:hidden"></i>
                </button>
                <div id="search-indicator" class="htmx-indicator ml-1 sm:ml-2">
                    <div class="animate-spin h-3 w-3 border-2 border-orange-500 border-t-transparent rounded-full"></div>
                </div>
            </div>

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
                        <a href="index.php?format=all&artist=<?= urlencode($artist_filter) ?>"
                            hx-get="index.php?format=all&artist=<?= urlencode($artist_filter) ?>"
                            hx-target="#library-container"
                            hx-select="#library-container"
                            hx-swap="outerHTML"
                            class="format-pill <?= $format_filter === 'all' ? 'active-orange' : '' ?>">All</a>
                        <a href="index.php?format=ogg&artist=<?= urlencode($artist_filter) ?>"
                            hx-get="index.php?format=ogg&artist=<?= urlencode($artist_filter) ?>"
                            hx-target="#library-container"
                            hx-select="#library-container"
                            hx-swap="outerHTML"
                            class="format-pill <?= $format_filter === 'ogg' ? 'active-orange' : '' ?>">Opus</a>
                        <a href="index.php?format=m4a&artist=<?= urlencode($artist_filter) ?>"
                            hx-get="index.php?format=m4a&artist=<?= urlencode($artist_filter) ?>"
                            hx-target="#library-container"
                            hx-select="#library-container"
                            hx-swap="outerHTML"
                            class="format-pill <?= $format_filter === 'm4a' ? 'active-green' : '' ?>">M4A</a>
                        <a href="index.php?format=mp3&artist=<?= urlencode($artist_filter) ?>"
                            hx-get="index.php?format=mp3&artist=<?= urlencode($artist_filter) ?>"
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
                        <a href="index.php?format=<?= $format_filter ?>&artist=all"
                            hx-get="index.php?format=<?= $format_filter ?>&artist=all"
                            hx-target="#library-container"
                            hx-select="#library-container"
                            hx-swap="outerHTML"
                            class="sidebar-link flex items-center justify-between px-3 py-2.5 rounded-lg text-[11px] font-bold transition-all
                                 <?= $artist_filter === 'all' ? 'active' : 'text-gray-600 hover:text-gray-300 hover:bg-white/[.03]' ?>">
                            <span>All Collections</span>
                        </a>
                        <?php
                        // reset pointer
                        $artists->data_seek(0);
                        while ($a = $artists->fetch_assoc()): ?>
                            <a href="index.php?format=<?= $format_filter ?>&artist=<?= urlencode($a['artist']) ?>"
                                hx-get="index.php?format=<?= $format_filter ?>&artist=<?= urlencode($a['artist']) ?>"
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
                            $playlists = $library->getUserPlaylists($_SESSION['user_id']);
                            while ($pl = $playlists->fetch_assoc()):
                            ?>
                                <a href="javascript:void(0)"
                                    hx-get="view_playlist.php?id=<?= $pl['id'] ?>&content_only=1"
                                    hx-target="main"
                                    hx-swap="innerHTML"
                                    hx-push-url="view_playlist.php?id=<?= $pl['id'] ?>"
                                    class="sidebar-link flex items-center gap-2 px-3 py-2.5 rounded-lg text-[11px] font-bold text-gray-600 hover:text-gray-300 hover:bg-white/[.03] transition-all pl-link"
                                    data-playlist-id="<?= $pl['id'] ?>"
                                    onclick="setActivePlaylist(<?= $pl['id'] ?>)">
                                    <i data-lucide="disc-3" class="w-3 h-3 flex-shrink-0"></i>
                                    <span class="truncate"><?= htmlspecialchars($pl['name']) ?></span>
                                </a>
                            <?php endwhile; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- MOBILE FILTERS & MENUS (Select/Dropdowns) -->
                <div class="lg:hidden flex flex-col gap-4 bg-[#0d1017]/95 backdrop-blur-md p-4 rounded-xl border border-white/[.04] shadow-lg">
                    <!-- Format Pills (Mobile) -->
                    <div class="flex flex-wrap gap-2">
                        <a href="index.php?format=all&artist=<?= urlencode($artist_filter) ?>"
                            hx-get="index.php?format=all&artist=<?= urlencode($artist_filter) ?>"
                            hx-target="#library-container"
                            hx-select="#library-container"
                            hx-swap="outerHTML"
                            class="format-pill <?= $format_filter === 'all' ? 'active-orange' : '' ?>">All</a>
                        <a href="index.php?format=ogg&artist=<?= urlencode($artist_filter) ?>"
                            hx-get="index.php?format=ogg&artist=<?= urlencode($artist_filter) ?>"
                            hx-target="#library-container"
                            hx-select="#library-container"
                            hx-swap="outerHTML"
                            class="format-pill <?= $format_filter === 'ogg' ? 'active-orange' : '' ?>">Opus</a>
                        <a href="index.php?format=m4a&artist=<?= urlencode($artist_filter) ?>"
                            hx-get="index.php?format=m4a&artist=<?= urlencode($artist_filter) ?>"
                            hx-target="#library-container"
                            hx-select="#library-container"
                            hx-swap="outerHTML"
                            class="format-pill <?= $format_filter === 'm4a' ? 'active-green' : '' ?>">M4A</a>
                        <a href="index.php?format=mp3&artist=<?= urlencode($artist_filter) ?>"
                            hx-get="index.php?format=mp3&artist=<?= urlencode($artist_filter) ?>"
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
                                    <button hx-get="index.php?format=<?= $format_filter ?>&artist=all"
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
                                        <button hx-get="index.php?format=<?= $format_filter ?>&artist=<?= urlencode($a['artist']) ?>"
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
                                        while ($pl = $playlists_mobile->fetch_assoc()): ?>
                                            <button onclick="navigateToPlaylistMobile(<?= $pl['id'] ?>)"
                                                data-playlist-id="<?= $pl['id'] ?>"
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
    <script src="../assets/js/script.min.js"></script>
    <script>
        if (typeof lucide !== 'undefined') lucide.createIcons();

        // =============================================
        // === MINI PLAYER INDEX (Spotify-style) ===
        // =============================================
        const miniPlayerIndex = document.getElementById('mini-player-index');
        let audioPlayer = null;
        let isMiniPlayerIndexActive = false;
        let currentState = null; // state object aktif

        // --- Helpers ---
        function fmtTime(s) {
            if (!s || isNaN(s)) return '0:00';
            return `${Math.floor(s/60)}:${String(Math.floor(s%60)).padStart(2,'0')}`;
        }

        function saveIndexState() {
            if (!currentState || !audioPlayer) return;
            currentState.currentTime = audioPlayer.currentTime;
            currentState.isPlaying = !audioPlayer.paused;
            currentState.isLooping = isMiniLoopIndexActive;
            sessionStorage.setItem('meel_audio_state', JSON.stringify(currentState));
        }

        // --- Buat / ganti audio element ---
        function loadAudio(state, autoplay) {
            if (!audioPlayer) {
                audioPlayer = document.createElement('audio');
                audioPlayer.id = 'hidden-audio-player';
                // preload=none hindari loading FLAC yg berat saat pertama kali play
                audioPlayer.preload = 'none';
                document.body.appendChild(audioPlayer);

                audioPlayer.addEventListener('timeupdate', updateIndexProgress);
                audioPlayer.addEventListener('play', () => setPlayIcon('pause'));
                audioPlayer.addEventListener('pause', () => setPlayIcon('play'));
                audioPlayer.addEventListener('ended', () => miniNextIndex());
            }

            // Hindari memuat ulang audio jika lagu yang sama sedang dimainkan
            if (currentState && currentState.filename === state.filename) {
                return;
            }

            currentState = state;
            // Set src langsung memicu loading — tidak perlu panggil .load()
            // (memanggil .load() setelah .src malah restart loading, double-load untuk file besar)
            audioPlayer.src = `stream.php?id=${state.id}`;

            // Restore loop state dari global key (sumber kebenaran) + state object sebagai fallback
            const _gLoop = localStorage.getItem("meel_global_loop") === "true";
            if (state.isLooping !== undefined && state.isLooping !== _gLoop) {
                // state lebih baru (misalnya baru di-toggle di watch.php) — update global
                isMiniLoopIndexActive = state.isLooping;
                localStorage.setItem("meel_global_loop", String(state.isLooping));
            } else {
                isMiniLoopIndexActive = _gLoop;
            }
            audioPlayer.loop = isMiniLoopIndexActive;
            updateMiniLoopUIIndex();

            if (autoplay) {
                audioPlayer.currentTime = state.currentTime || 0;
                audioPlayer.play().catch(() => {});
            }
        }

        // --- Update seluruh UI ---
        // Elemen di-cache sekali (markup mini-player-index statis, tidak
        // pernah di-swap oleh htmx), jadi tidak perlu getElementById lagi
        // di setiap tick timeupdate.
        let _idxEls = null;

        function _getIdxEls() {
            if (!_idxEls) {
                _idxEls = {
                    fill: document.getElementById('mp-seekbar-fill-index'),
                    thumb: document.getElementById('mp-seekbar-thumb-index'),
                    ct: document.getElementById('mini-current-time-index'),
                    dt: document.getElementById('mini-duration-index'),
                    img: document.getElementById('mini-thumbnail-index'),
                    title: document.getElementById('mini-title-index'),
                    artist: document.getElementById('mini-artist-index'),
                };
            }
            return _idxEls;
        }

        // Bagian "panas": dipanggil di setiap event timeupdate (bisa puluhan
        // kali/detik), jadi hanya menyentuh seekbar + label waktu.
        function updateIndexProgress() {
            if (!audioPlayer) return;
            const els = _getIdxEls();
            const pct = audioPlayer.duration > 0 ?
                (audioPlayer.currentTime / audioPlayer.duration) * 100 : 0;

            if (els.fill) els.fill.style.width = pct + '%';
            if (els.thumb) els.thumb.style.left = pct + '%';
            if (els.ct) els.ct.textContent = fmtTime(audioPlayer.currentTime);
            if (els.dt) els.dt.textContent = fmtTime(audioPlayer.duration);
        }

        // Bagian "dingin": thumbnail/judul/artis hanya berubah saat lagu
        // berganti, jadi dipisah agar tidak ikut ditulis ulang tiap tick.
        function updateIndexMeta() {
            if (!currentState) return;
            const els = _getIdxEls();
            if (els.img) els.img.src = currentState.thumbnailUrl || `upload/thumbnail/${currentState.thumbnail}`;
            if (els.title) els.title.textContent = currentState.title || 'Unknown';
            if (els.artist) els.artist.textContent = currentState.artist || 'Unknown';
        }

        function updateIndexUI() {
            if (!audioPlayer || !currentState) return;
            updateIndexProgress();
            updateIndexMeta();
        }

        function setPlayIcon(icon) {
            const btn = document.getElementById('mini-play-btn-index');
            if (btn) {
                btn.innerHTML = `<i data-lucide="${icon}" style="width:18px;height:18px;"></i>`;
                lucide.createIcons();
            }
        }

        // --- Init: baca sessionStorage ---
        function initMiniPlayerIndex() {
            const miniPlayerBar = document.getElementById('mini-player-index');
            if (miniPlayerBar) {
                miniPlayerBar.style.cursor = 'default';
                miniPlayerBar.addEventListener('click', (e) => {
                    if (e.target.closest('.mp-thumbnail') || e.target.closest('#mini-player-img') || e.target.tagName === 'IMG') {
                        expandPlayerFromMiniPlayer();
                    }
                });
            }
            // Selalu apply global loop key ke UI saat init (bahkan jika tidak ada audio state)
            isMiniLoopIndexActive = localStorage.getItem("meel_global_loop") === "true";
            updateMiniLoopUIIndex();

            const raw = sessionStorage.getItem('meel_audio_state');
            if (!raw) return;
            try {
                const state = JSON.parse(raw);
                isMiniPlayerIndexActive = true;

                // ⚠️ Update meta IMMEDIATELY dari state (sebelum setTimeout) agar
                // title/artist/thumbnail tampil tanpa flash default "Tidak ada musik".
                // Sebelumnya updateIndexUI() dipanggil langsung di sini tetapi
                // currentState masih null → meta tidak terganti.
                const els = _getIdxEls();
                if (els.img) els.img.src = state.thumbnailUrl || `upload/thumbnail/${state.thumbnail}`;
                if (els.title) els.title.textContent = state.title || 'Unknown';
                if (els.artist) els.artist.textContent = state.artist || 'Unknown';

                // Tunggu render selesai dulu baru load audio (hindari blocking saat navigasi dari watch.php dengan FLAC)
                setTimeout(() => {
                    loadAudio(state, state.isPlaying);
                    updateIndexUI();
                }, 100);
                // Prioritaskan global loop key; sinkronisasi dari sessionStorage jika lebih baru
                const globalLoop = localStorage.getItem("meel_global_loop") === "true";
                if (state.isLooping !== undefined) {
                    // Sinkronisasi: jika state dan global berbeda, global key menang
                    isMiniLoopIndexActive = globalLoop;
                    // Tapi jika state.isLooping berbeda dari global, update global dari state
                    // (kasus: toggle di watch.php baru saja terjadi)
                    if (state.isLooping !== globalLoop) {
                        isMiniLoopIndexActive = state.isLooping;
                        localStorage.setItem("meel_global_loop", String(state.isLooping));
                    }
                } else {
                    isMiniLoopIndexActive = globalLoop;
                }
                if (audioPlayer) audioPlayer.loop = isMiniLoopIndexActive;
                updateMiniLoopUIIndex();
                miniPlayerIndex.classList.add('active');

                // Muat konten playlist (prioritas dari state, fallback dari localStorage, lalu URL)
                if (!window._playlistLoaded) {
                    var plId = state.playlistId;
                    if (!plId || plId <= 0) {
                        var lastPl = localStorage.getItem('meel_last_playlist_id');
                        plId = lastPl ? parseInt(lastPl) : 0;
                    }
                    if (!plId || plId <= 0) {
                        plId = parseInt('<?= $playlist_id_from_url ?>');
                    }
                    if (plId > 0) {
                        window._playlistLoaded = true;
                        loadPlaylistById(plId);
                    }
                }
            } catch (e) {
                console.warn('Mini player init error:', e);
            }
        }

        function loadPlaylistById(id) {
            if (!id) return;

            // Simpan state load-more SEBELUM replace <main>
            var savedLMUrl = null;
            var savedBtn = document.getElementById('load-more-btn');
            if (savedBtn) savedLMUrl = savedBtn.getAttribute('hx-get');

            fetch('view_playlist.php?id=' + id + '&content_only=1')
                .then(function(r) {
                    return r.text();
                })
                .then(function(html) {
                    var main = document.querySelector('main');
                    if (!main) return;
                    main.innerHTML = html;
                    setActivePlaylist(id);
                    if (typeof history !== 'undefined') {
                        history.pushState(null, '', 'view_playlist.php?id=' + id);
                    }
                    if (typeof lucide !== 'undefined') lucide.createIcons();

                    // Pulihkan load-more URL setelah replace <main>
                    if (savedLMUrl) {
                        var newBtn = document.getElementById('load-more-btn');
                        if (newBtn) newBtn.setAttribute('hx-get', savedLMUrl);
                    }

                    if (typeof htmx !== 'undefined') htmx.process(main);
                    if (typeof setupPlaylistItemClicks === 'function') setupPlaylistItemClicks();
                })
                .catch(function(err) {
                    console.warn('Gagal load playlist:', err);
                });
        }


        // --- Play / Pause ---
        window.miniPlayPauseIndex = function() {
            if (!audioPlayer) return;
            audioPlayer.paused ? audioPlayer.play() : audioPlayer.pause();
        };

        // --- Seek ---
        window.miniSeekIndex = function(event) {
            if (!audioPlayer) return;
            const rect = event.currentTarget.getBoundingClientRect();
            const pct = (event.clientX - rect.left) / rect.width;
            audioPlayer.currentTime = Math.max(0, Math.min(pct * audioPlayer.duration, audioPlayer.duration));
        };

        // --- Next: Cari lagu berikutnya di DOM (termasuk playlist items) ---
        window.miniNextIndex = function() {
            if (!audioPlayer) return;
            if (audioPlayer.loop) return;
            if (currentState && currentState.filename) {
                const allItems = Array.from(document.querySelectorAll('.music-item, .music-pl-item'));
                const idx = allItems.findIndex(el => el.dataset.filename === currentState.filename);
                if (idx !== -1 && idx < allItems.length - 1) {
                    allItems[idx + 1].click();
                    return;
                }
            }
            audioPlayer.currentTime = 0;
            audioPlayer.pause();
            const btn = document.getElementById('mini-play-btn-index');
            if (btn) {
                btn.innerHTML = `<i data-lucide="play" style="width:18px;height:18px;"></i>`;
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
        };
        // --- Prev: restart jika > 3 detik, else coba lagu sebelumnya ---
        window.miniPrevIndex = function() {
            if (!audioPlayer) return;
            if (audioPlayer.currentTime > 3) {
                audioPlayer.currentTime = 0;
                return;
            }
            // Cari lagu sebelumnya di DOM
            if (currentState && currentState.filename) {
                const allItems = Array.from(document.querySelectorAll('.music-item, .music-pl-item'));
                const idx = allItems.findIndex(el => el.dataset.filename === currentState.filename);
                if (idx > 0) {
                    allItems[idx - 1].click();
                    return;
                }
            }
            audioPlayer.currentTime = 0;
        };

        function expandPlayerFromMiniPlayer() {
            // Simpan detik terakhir di index dulu
            saveIndexState();

            // Ambil data state terakhir untuk mendapatkan ID lagu atau URL-nya
            const savedState = sessionStorage.getItem('meel_audio_state');
            if (savedState) {
                const state = JSON.parse(savedState);

                // Pengecekan disempurnakan untuk membaca 'id' maupun 'musicId'
                if (state.watchUrl) {
                    window.location.href = state.watchUrl;
                } else if (state.id) {
                    window.location.href = `watch.php?id=${state.id}`;
                } else if (state.musicId) { // <-- TAMBAHAN: Baca state dari player_music.js
                    window.location.href = `watch.php?id=${state.musicId}`;
                } else {
                    // Fallback jika tidak ada ID
                    const fallbackItem = document.querySelector(`[data-filename="${state.filename}"]`);
                    if (fallbackItem && fallbackItem.closest('a')) {
                        window.location.href = fallbackItem.closest('a').getAttribute('href');
                    }
                }
            }
        }

        // --- Loop toggle untuk mini player index ---
        // Inisialisasi dari global key agar konsisten dengan watch.php
        let isMiniLoopIndexActive = localStorage.getItem("meel_global_loop") === "true";

        window.toggleMiniLoopIndex = function() {
            isMiniLoopIndexActive = !isMiniLoopIndexActive;
            // Simpan ke global key — ini sumber kebenaran tunggal untuk loop state
            localStorage.setItem("meel_global_loop", String(isMiniLoopIndexActive));
            if (audioPlayer) audioPlayer.loop = isMiniLoopIndexActive;
            updateMiniLoopUIIndex();
            // Sinkronisasi loop state ke sessionStorage juga
            saveIndexState();
        };

        function updateMiniLoopUIIndex() {
            const btn = document.getElementById('mini-loop-btn-index');
            if (!btn) return;
            if (isMiniLoopIndexActive) {
                btn.style.color = '#f97316';
                btn.style.opacity = '1';
            } else {
                btn.style.color = '';
                btn.style.opacity = '0.5';
            }
        }

        // --- Tutup ---
        window.closeMiniPlayerIndex = function() {
            if (audioPlayer) audioPlayer.pause();
            miniPlayerIndex.classList.remove('active');
            sessionStorage.removeItem('meel_audio_state');
            isMiniPlayerIndexActive = false;
            currentState = null;
        };

        function setupMusicItemClicks() {
            const allItems = () => Array.from(document.querySelectorAll('.music-item'));
            document.querySelectorAll('.music-item').forEach(item => {
                if (item.dataset.listenerAdded) return;
                item.dataset.listenerAdded = 'true';
                item.addEventListener('click', function(e) {
                    // Jika klik pada tombol download/share, abaikan
                    if (e.target.closest('.no-player')) return;

                    // Tandai sessionStorage agar tidak men-trigger modal resume di watch.php
                    sessionStorage.setItem('skip_resume_once', 'true');

                    // Cari indeks lagu ini dan tentukan nextSongUrl dari lagu berikutnya
                    const items = allItems();
                    const idx = items.indexOf(this);
                    let nextSongUrl = '';
                    if (idx >= 0 && idx < items.length - 1) {
                        const nextItem = items[idx + 1];
                        const nextId = nextItem.dataset.id;
                        if (nextId) nextSongUrl = `watch.php?id=${nextId}`;
                    }

                    // Hapus playlist context saat user klik lagu dari library biasa
                    localStorage.removeItem('meel_last_playlist_id');

                    const state = {
                        id: this.dataset.id,
                        musicId: this.dataset.id,
                        title: this.dataset.title,
                        artist: this.dataset.artist,
                        thumbnail: this.dataset.thumbnail,
                        thumbnailUrl: this.dataset.thumbnailUrl || `upload/thumbnail/${this.dataset.thumbnail}`,
                        filename: this.dataset.filename,
                        watchUrl: e.target.closest('a') ? e.target.closest('a').getAttribute('href') : `watch.php?id=${this.dataset.id}`,
                        nextSongUrl: nextSongUrl,
                        currentTime: 0,
                        isPlaying: true,
                    };
                    loadAudio(state, true);
                    updateIndexUI();
                    sessionStorage.setItem('meel_audio_state', JSON.stringify(state));
                    // Tampilkan mini player
                    isMiniPlayerIndexActive = true;
                    miniPlayerIndex.classList.add('active');
                });
            });
        }

        // ── Setup klik untuk playlist items (dimuat via HTMX) ──────────────
        function setupPlaylistItemClicks() {
            document.querySelectorAll('.music-pl-item').forEach(function(item) {
                if (item.dataset.plListenerAdded) return;
                item.dataset.plListenerAdded = 'true';

                item.addEventListener('click', function(e) {
                    // Abaikan klik pada form hapus atau link
                    if (e.target.closest('form') || e.target.closest('a')) return;
                    e.preventDefault();

                    sessionStorage.setItem('skip_resume_once', 'true');

                    var allItems = Array.from(document.querySelectorAll('.music-pl-item'));
                    var idx = allItems.indexOf(this);
                    var nextSongUrl = '';
                    if (idx >= 0 && idx < allItems.length - 1) {
                        nextSongUrl = allItems[idx + 1].dataset.watchUrl || '';
                    }

                    var state = {
                        id: this.dataset.id,
                        musicId: this.dataset.id,
                        title: this.dataset.title,
                        artist: this.dataset.artist,
                        thumbnail: this.dataset.thumbnail,
                        thumbnailUrl: this.dataset.thumbnailUrl || `upload/thumbnail/${this.dataset.thumbnail}`,
                        filename: this.dataset.filename,
                        watchUrl: this.dataset.watchUrl || `watch.php?id=${this.dataset.id}&playlist_id=${this.dataset.playlistId}`,
                        nextSongUrl: nextSongUrl,
                        playlistId: this.dataset.playlistId,
                        currentTime: 0,
                        isPlaying: true,
                    };
                    loadAudio(state, true);
                    updateIndexUI();
                    sessionStorage.setItem('meel_audio_state', JSON.stringify(state));
                    isMiniPlayerIndexActive = true;
                    miniPlayerIndex.classList.add('active');
                });

                // Tombol play (ikon ▶ di kolom nomor)
                var playBtn = item.querySelector('.pl-play-btn');
                if (playBtn) {
                    playBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        item.click();
                    });
                }
            });
        }

        // --- Boot & Perbaikan Sinkronisasi ---
        function bootPlayerIndex() {
            initMiniPlayerIndex();
            setupMusicItemClicks();
            scrollToActiveArtistDesktop();
        }

        // 1. Jalankan saat halaman pertama kali dimuat (Hard Reload / F5)
        document.addEventListener('DOMContentLoaded', () => {
            bootPlayerIndex();
        });

        document.addEventListener('htmx:afterSwap', (e) => {
            if (typeof lucide !== 'undefined') lucide.createIcons();

            const targetId = e.target?.id || '';
            const isContentUpdate = targetId.includes('music-list') ||
                targetId.includes('recommendation') ||
                targetId.includes('search') ||
                targetId.includes('load-more-music') ||
                targetId.includes('library-container') ||
                targetId === 'main';

            document.body.classList.remove('artist-dropdown-active');

            if (!isContentUpdate) {
                bootPlayerIndex();
            } else {
                setupMusicItemClicks();
            }
            // Setup playlist items jika ada (dimuat via HTMX ke <main>)
            if (typeof setupPlaylistItemClicks === 'function') {
                setupPlaylistItemClicks();
            }
            // Auto-scroll sidebar desktop ke artist yg aktif HANYA ketika filter/ganti artist,
            // BUKAN saat load-more atau search (target #music-list).
            // Cek via isFromLoadMore + targetId sebagai safeguard ganda.
            const isFromLoadMore = e.detail?.elt?.closest?.('#load-more-music') != null;
            if (isContentUpdate && !isFromLoadMore && !targetId.includes('music-list')) {
                scrollToActiveArtistDesktop();
            }
        });

        // ── Auto-scroll sidebar desktop ke artist yg aktif ────────────
        // Setiap kali HTMX mengganti #library-container, sidebar artist
        // dibuat ulang oleh server dan scroll-nya reset ke 0.
        // Fungsi ini menggeser scroll ke item yg sedang aktif/dipilih.
        function scrollToActiveArtistDesktop() {
            var artistList = document.getElementById('desktop-artist-list');
            if (!artistList) return;
            var activeItem = artistList.querySelector('.sidebar-link.active');
            if (activeItem) {
                activeItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }
        }

        // ── Mobile Playlist Dropdown (custom toggle) ──
        window.togglePlaylistDropdown = function() {
            const dropdown = document.getElementById('playlist-options');
            if (dropdown) {
                const isHidden = dropdown.classList.contains('hidden');
                if (isHidden) {
                    dropdown.classList.remove('hidden');
                    document.body.classList.add('artist-dropdown-active');
                } else {
                    dropdown.classList.add('hidden');
                    setTimeout(function() {
                        document.body.classList.remove('artist-dropdown-active');
                    }, 350);
                }
            }
        };

        window.closePlaylistDropdown = function() {
            const dropdown = document.getElementById('playlist-options');
            if (dropdown) dropdown.classList.add('hidden');
            setTimeout(function() {
                const artistStillOpen = document.getElementById('artist-options') && !document.getElementById('artist-options').classList.contains('hidden');
                const playlistStillOpen = document.getElementById('playlist-options') && !document.getElementById('playlist-options').classList.contains('hidden');
                if (!artistStillOpen && !playlistStillOpen) {
                    document.body.classList.remove('artist-dropdown-active');
                }
            }, 350);
        };

        window.navigateToPlaylistMobile = function(id) {
            closePlaylistDropdown();
            loadPlaylistMobile(id);
            // Update label
            var label = document.getElementById('playlist-dropdown-label');
            var activeBtn = document.querySelector('#playlist-options button[data-playlist-id="' + id + '"]');
            if (label && activeBtn) label.textContent = activeBtn.textContent.trim();
        };

        // Close custom dropdown when clicking outside
        document.addEventListener('click', (e) => {
            const artistDropdown = document.getElementById('artist-options');
            const artistTrigger = e.target.closest('#custom-artist-dropdown');
            if (!artistTrigger && artistDropdown && !artistDropdown.classList.contains('hidden')) {
                closeArtistDropdown();
            }
            const playlistDropdown = document.getElementById('playlist-options');
            const playlistTrigger = e.target.closest('#custom-playlist-dropdown');
            if (!playlistTrigger && playlistDropdown && !playlistDropdown.classList.contains('hidden')) {
                closePlaylistDropdown();
            }
        });

        window.toggleArtistDropdown = function() {
            const dropdown = document.getElementById('artist-options');
            if (dropdown) {
                const isHidden = dropdown.classList.contains('hidden');
                if (isHidden) {
                    dropdown.classList.remove('hidden');
                    document.body.classList.add('artist-dropdown-active');

                    // Auto-scroll ke artist yg sedang dipilih (highligt),
                    // agar user tidak perlu scroll ulang ke bawah/atas
                    var activeItem = dropdown.querySelector('.text-orange-500');
                    if (activeItem) {
                        activeItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    }
                } else {
                    dropdown.classList.add('hidden');
                    setTimeout(function() {
                        document.body.classList.remove('artist-dropdown-active');
                    }, 350);
                }
            }
        };

        window.closeArtistDropdown = function() {
            const dropdown = document.getElementById('artist-options');
            if (dropdown) dropdown.classList.add('hidden');
            setTimeout(() => {
                const artistStillOpen = document.getElementById('artist-options') && !document.getElementById('artist-options').classList.contains('hidden');
                const playlistStillOpen = document.getElementById('playlist-options') && !document.getElementById('playlist-options').classList.contains('hidden');
                if (!artistStillOpen && !playlistStillOpen) {
                    document.body.classList.remove('artist-dropdown-active');
                }
            }, 350);
        };

        // ── Fungsi navigasi playlist ───────────────────────────────────────
        function setActivePlaylist(id) {
            document.querySelectorAll('.pl-link').forEach(function(el) {
                if (el.dataset.playlistId == id) {
                    el.classList.add('active');
                    el.style.color = '#f97316';
                    el.style.background = 'rgba(249,115,22,.08)';
                    el.style.borderColor = 'rgba(249,115,22,.15)';
                } else {
                    el.classList.remove('active');
                    el.style.color = '';
                    el.style.background = '';
                    el.style.borderColor = '';
                }
            });
        }

        window.loadPlaylistMobile = function(id) {
            if (!id) return;
            htmx.ajax('GET', 'view_playlist.php?id=' + id + '&content_only=1', {
                target: 'main',
                swap: 'innerHTML',
                pushUrl: 'view_playlist.php?id=' + id
            });
            setActivePlaylist(id);
        };

        window.resetActivePlaylist = function() {
            document.querySelectorAll('.pl-link').forEach(function(el) {
                el.classList.remove('active');
                el.style.color = '';
                el.style.background = '';
                el.style.borderColor = '';
            });
        };

        window.resetArtistHighlight = function() {
            document.querySelectorAll('#desktop-artist-list .sidebar-link').forEach(function(el) {
                el.classList.remove('active');
            });
            var allArtistBtns = document.querySelectorAll('#artist-options button');
            allArtistBtns.forEach(function(btn) {
                btn.classList.remove('text-orange-500', 'font-bold');
            });
        };

// ── Load More: observasi .lm-meta di <main> ──
        // (tanpa recovery — loadPlaylistById sudah handle save/restore sendiri)
        (function(){
            var _main = document.querySelector('main');
            if (!_main) return;

            var _obs = new MutationObserver(function(muts) {
                for (var i = 0; i < muts.length; i++) {
                    var added = muts[i].addedNodes;
                    for (var j = 0; j < added.length; j++) {
                        var n = added[j];
                        if (n.nodeType !== 1 || !n.classList || !n.classList.contains('lm-meta')) continue;

                        var nextUrl = n.getAttribute('data-next-url');
                        var isEnd   = n.getAttribute('data-end');
                        if (n.parentNode) n.parentNode.removeChild(n);

                        var btn = document.getElementById('load-more-btn');
                        var ld  = document.getElementById('load-more-music');

                        if (nextUrl && btn) {
                            btn.setAttribute('hx-get', nextUrl);
                            // Update button text with current page
                            var pg = n.getAttribute('data-page') || '';
                            var tt = n.getAttribute('data-total') || '';
                            if (pg && tt) {
                                var btnText = btn.querySelector('span') || btn;
                                btnText.textContent = 'Load More \u00b7 ' + pg + '/' + tt;
                            }
                            if (typeof htmx !== 'undefined') htmx.process(btn);

                            // Auto-scroll: HANYA scroll ke BAWAH
                            // Gunakan requestAnimationFrame agar posisi elemen sudah final
                            // setelah browser selesai reflow/rendering.
                            // scrollBy({top:positif}) TIDAK MUNGKIN scroll ke atas.
                            if (ld) {
                                requestAnimationFrame(function(){
                                    var _r2 = ld.getBoundingClientRect();
                                    console.log('[LM] _r2.bottom:', _r2.bottom, 'innerH:', window.innerHeight);
                                    if (_r2.bottom > window.innerHeight) {
                                        window.scrollBy({ top: _r2.bottom - window.innerHeight + 20, behavior: 'smooth' });
                                    }
                                });
                            }
                        } else if (isEnd && ld) {
                            ld.outerHTML = '<div class="py-10 text-center text-[9px] text-gray-800 uppercase tracking-[.4em]">End of Collection</div>';
                        }
                        return;
                    }
                }
            });
            _obs.observe(_main, { childList: true, subtree: true });
        })();

        // Keyboard shortcuts untuk mini player index
        document.addEventListener('keydown', (e) => {
            if (e.target.tagName.toLowerCase() === 'input' ||
                e.target.tagName.toLowerCase() === 'textarea') return;

            const key = e.key.toLowerCase();

            // Keyboard 'i' → Pindah kembali ke full player (watch.php)
            if (key === 'i' && !e.ctrlKey && !e.altKey && !e.metaKey) {
                e.preventDefault();
                expandPlayerFromMiniPlayer();
            }

            // Keyboard 'l' → Toggle loop mini player
            if (key === 'l' && !e.ctrlKey && !e.altKey && !e.metaKey) {
                e.preventDefault();
                window.toggleMiniLoopIndex();
            }
        });

        // Auto-save tiap 5 detik
        setInterval(() => {
            if (isMiniPlayerIndexActive) saveIndexState();
        }, 5000);
    </script>
    <?php include '../partials/footer.php'; ?>
</body>

</html>