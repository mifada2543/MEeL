<?php
require_once '../modules/core/helpers.php';
session_name('meel');
session_start();
include '../auth/config.php';
require_once '../modules/media/MediaLibrary.php';

$playlist_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id     = $_SESSION['user_id'] ?? 0;
$format_filter = $_GET['format'] ?? 'all';

// Validasi playlist milik user
$pl_stmt = $conn->prepare("SELECT * FROM playlists WHERE id = ? AND user_id = ?");
$pl_stmt->bind_param("ii", $playlist_id, $user_id);
$pl_stmt->execute();
$playlist = $pl_stmt->get_result()->fetch_assoc();

if (!$playlist) {
    include '../err/denied.php';
    exit;
}

// Daftar lagu — prepared statement untuk keamanan
$songs_stmt = $conn->prepare("
    SELECT m.*, pt.id as pivot_id
    FROM music m
    JOIN playlist_tracks pt ON m.id = pt.music_id
    WHERE pt.playlist_id = ?
    ORDER BY pt.added_at DESC
");
$songs_stmt->bind_param("i", $playlist_id);
$songs_stmt->execute();
$songs_query = $songs_stmt->get_result();
$total_songs = $songs_query->num_rows;

$first_song = null;
if ($total_songs > 0) {
    $first_song = $songs_query->fetch_assoc();
    $songs_query->data_seek(0);
}

$library       = new MediaLibrary($conn);
$artists       = $library->getArtists();
$is_logged_in  = isset($_SESSION['user_id']);

// ─── Fungsi render konten utama ───────────────────────────────────────────────
function renderPlaylistContent($playlist, $playlist_id, $total_songs, $songs_query, $first_song, $include_script = true)
{
?>
    <!-- BACK TO LIBRARY (when loaded via HTMX into index.php) -->
    <?php if (!$include_script): ?>
        <div class="mb-6">
            <a href="javascript:void(0)"
                hx-get="index.php?content_only=1"
                hx-target="main"
                hx-swap="innerHTML"
                hx-push-url="index.php"
                onclick="if (typeof resetActivePlaylist === 'function') resetActivePlaylist(); if (typeof resetArtistHighlight === 'function') resetArtistHighlight();"
                class="inline-flex items-center gap-2 text-[10px] font-bold uppercase tracking-widest text-gray-600 hover:text-orange-400 transition-all">
                <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Library
            </a>
        </div>
    <?php endif; ?>

    <!-- PLAYLIST HEADER -->
    <div class="flex items-start sm:items-end gap-5 mb-8 pb-6 border-b border-white/[.04]">
        <div class="relative flex-shrink-0">
            <div class="w-24 h-24 sm:w-32 sm:h-32 bg-gradient-to-br from-orange-500 via-orange-600 to-red-700
                        rounded-2xl shadow-2xl shadow-orange-900/40 flex items-center justify-center overflow-hidden">
                <?php if ($first_song && !empty($first_song['thumbnail'])): ?>
                    <img src="<?= htmlspecialchars(music_thumbnail_url($first_song['thumbnail'])) ?>"
                        alt="cover" class="w-full h-full object-cover">
                <?php endif; ?>
            </div>
            <div class="absolute -inset-2 bg-orange-500/15 rounded-3xl blur-xl -z-10"></div>
        </div>

        <div class="flex-1 min-w-0">
            <div class="text-[9px] font-black uppercase tracking-[.4em] text-orange-500 mb-1.5">Playlist</div>
            <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-white leading-none truncate mb-2">
                <?= htmlspecialchars($playlist['name']) ?>
            </h1>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[10px] text-gray-500 font-bold uppercase tracking-wider">
                <span><?= $total_songs ?> track<?= $total_songs !== 1 ? 's' : '' ?></span>
                <span class="text-gray-700">•</span>
                <span>Milikmu</span>
            </div>
            <div class="flex flex-wrap items-center gap-2 mt-4">
                <?php if ($first_song): ?>
                    <a href="watch.php?id=<?= $first_song['id'] ?>&playlist_id=<?= $playlist_id ?>"
                        class="flex items-center gap-2 bg-orange-600 hover:bg-orange-500 text-white
                              px-5 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest
                              transition-all shadow-lg shadow-orange-600/20 border border-orange-500/20">
                        <i data-lucide="play" class="w-3.5 h-3.5 fill-current"></i> Play All
                    </a>
                <?php endif; ?>
                <form action="playlist_action.php" method="POST"
                    onsubmit="return confirm('Hapus seluruh playlist ini?')">
                    <input type="hidden" name="action" value="delete_playlist">
                    <input type="hidden" name="playlist_id" value="<?= $playlist_id ?>">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <button type="submit"
                        class="flex items-center gap-2 px-4 py-2 rounded-xl text-[10px] font-black
                                   uppercase tracking-widest text-red-500 hover:text-white
                                   hover:bg-red-600/20 border border-red-500/15 hover:border-red-500/30 transition-all">
                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> Hapus
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- TRACK LIST -->
    <?php if ($total_songs > 0): ?>
        <div class="hidden sm:grid grid-cols-[2rem_1fr_auto_2rem] gap-4 px-3 mb-2">
            <span class="text-[9px] font-bold uppercase tracking-[.3em] text-gray-700 text-center">#</span>
            <span class="text-[9px] font-bold uppercase tracking-[.3em] text-gray-700">Judul</span>
            <span class="text-[9px] font-bold uppercase tracking-[.3em] text-gray-700 text-right pr-1">Format</span>
            <span></span>
        </div>
        <div class="space-y-0.5">
            <?php
            $songs_query->data_seek(0);
            $idx = 0;
            while ($s = $songs_query->fetch_assoc()):
                $idx++;
                $s_ext   = strtolower(pathinfo($s['filename'], PATHINFO_EXTENSION));
                $s_lbl   = $s_ext === 'ogg' ? 'opus' : $s_ext;
                $watch_url = "watch.php?id={$s['id']}&playlist_id={$playlist_id}";
            ?>
                <div class="group grid grid-cols-[2rem_1fr_auto_2rem] items-center gap-4 px-3 py-2 rounded-xl
                            hover:bg-white/[.04] border border-transparent hover:border-white/[.05] transition-all duration-150
                            music-pl-item"
                    data-id="<?= $s['id'] ?>"
                    data-title="<?= htmlspecialchars($s['title']) ?>"
                    data-artist="<?= htmlspecialchars($s['artist'] ?? 'Unknown') ?>"
                    data-thumbnail="<?= htmlspecialchars($s['thumbnail'] ?? '') ?>"
                    data-thumbnail-url="<?= htmlspecialchars(music_thumbnail_url($s['thumbnail'])) ?>"
                    data-filename="<?= htmlspecialchars($s['filename']) ?>"
                    data-watch-url="<?= htmlspecialchars($watch_url) ?>"
                    data-playlist-id="<?= $playlist_id ?>">

                    <!-- Nomor / play icon -->
                    <div class="flex items-center justify-center w-8 flex-shrink-0">
                        <span class="group-hover:hidden block text-[10px] font-mono text-gray-600"><?= $idx ?></span>
                        <button type="button"
                            class="hidden group-hover:flex items-center justify-center pl-play-btn"
                            aria-label="Putar <?= htmlspecialchars($s['title']) ?>">
                            <i data-lucide="play" class="w-3.5 h-3.5 text-orange-400 fill-current"></i>
                        </button>
                    </div>

                    <!-- Thumbnail + Info -->
                    <a href="<?= htmlspecialchars($watch_url) ?>"
                        class="flex items-center gap-3 min-w-0 no-underline">
                        <div class="w-10 h-10 rounded-lg overflow-hidden flex-shrink-0
                                    bg-white/[.04] border border-white/[.05]">
                            <?php if (!empty($s['thumbnail'])): ?>
                                <img src="<?= htmlspecialchars(music_thumbnail_url($s['thumbnail'])) ?>"
                                    alt="<?= htmlspecialchars($s['title']) ?>"
                                    width="80" height="80"
                                    class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-300"
                                    loading="lazy" decoding="async">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center">
                                    <i data-lucide="music" class="w-4 h-4 text-gray-600"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="min-w-0">
                            <div class="text-[12px] font-bold text-gray-300 group-hover:text-orange-400
                                        truncate transition-colors leading-tight">
                                <?= htmlspecialchars($s['title']) ?>
                            </div>
                            <div class="text-[10px] text-gray-600 font-bold uppercase tracking-wider truncate mt-0.5">
                                <?= htmlspecialchars($s['artist'] ?? 'Unknown') ?>
                            </div>
                        </div>
                    </a>

                    <!-- Format -->
                    <span class="text-[8px] px-1.5 py-0.5 rounded bg-white/[.04] border border-white/[.05]
                                 text-gray-600 uppercase font-bold tracking-wide text-right">
                        <?= $s_lbl ?>
                    </span>

                    <!-- Hapus dari playlist -->
                    <form action="playlist_action.php" method="POST"
                        onsubmit="return confirm('Hapus lagu ini dari playlist?')">
                        <input type="hidden" name="action" value="remove_from_playlist">
                        <input type="hidden" name="pivot_id" value="<?= $s['pivot_id'] ?>">
                        <input type="hidden" name="playlist_id" value="<?= $playlist_id ?>">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <button type="submit"
                            class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-700
                                       opacity-0 group-hover:opacity-100
                                       hover:text-red-500 hover:bg-red-500/10 transition-all duration-150">
                            <i data-lucide="x" class="w-3.5 h-3.5"></i>
                        </button>
                    </form>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="py-24 flex flex-col items-center justify-center gap-4
                    border-2 border-dashed border-white/[.04] rounded-2xl">
            <div class="w-16 h-16 rounded-2xl bg-white/[.03] border border-white/[.05]
                        flex items-center justify-center">
                <i data-lucide="music-off" class="w-7 h-7 text-gray-700"></i>
            </div>
            <div class="text-center">
                <div class="text-[11px] font-bold uppercase tracking-widest text-gray-600 mb-1">Playlist Kosong</div>
                <div class="text-[10px] text-gray-700">Tambahkan lagu dari halaman player</div>
            </div>
            <a href="index.php"
                class="mt-2 flex items-center gap-2 px-4 py-2 rounded-xl bg-orange-600/10 border border-orange-500/20
                      text-orange-400 text-[10px] font-black uppercase tracking-widest hover:bg-orange-600/20 transition-all">
                <i data-lucide="library" class="w-3.5 h-3.5"></i> Buka Library
            </a>
        </div>
    <?php endif; ?>

    <?php if ($include_script): ?>
        <!-- Script: setup klik track di playlist untuk mini player (menyertakan playlist_id) -->
        <script>
            (function() {
                function setupPlaylistClicks() {
                    document.querySelectorAll('.music-pl-item').forEach(function(item) {
                        if (item.dataset.plListenerAdded) return;
                        item.dataset.plListenerAdded = 'true';

                        // Klik seluruh row (kecuali form hapus) → play via mini player
                        item.addEventListener('click', function(e) {
                            // Abaikan klik pada tombol hapus / form
                            if (e.target.closest('form') || e.target.closest('button[type="submit"]')) return;
                            // Kalau klik link langsung (judul/thumbnail) → biarkan navigasi biasa
                            if (e.target.closest('a')) return;

                            e.preventDefault();
                            playViaPlayer(item);
                        });

                        // Tombol play (ikon ▶ di kolom nomor)
                        var playBtn = item.querySelector('.pl-play-btn');
                        if (playBtn) {
                            playBtn.addEventListener('click', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                playViaPlayer(item);
                            });
                        }
                    });
                }

                function playViaPlayer(item) {
                    var playlistId = item.dataset.playlistId;
                    var musicId = item.dataset.id;
                    var watchUrl = item.dataset.watchUrl; // sudah mengandung &playlist_id=X

                    // Cari nextSong: item berikutnya di DOM
                    var allItems = Array.from(document.querySelectorAll('.music-pl-item'));
                    var idx = allItems.indexOf(item);
                    var nextSongUrl = '';
                    if (idx >= 0 && idx < allItems.length - 1) {
                        nextSongUrl = allItems[idx + 1].dataset.watchUrl || '';
                    }

                    var state = {
                        id: musicId,
                        musicId: musicId,
                        title: item.dataset.title,
                        artist: item.dataset.artist,
                        thumbnail: item.dataset.thumbnail,
                        thumbnailUrl: item.dataset.thumbnailUrl,
                        filename: item.dataset.filename,
                        // ↓ KEY FIX: watchUrl menyertakan playlist_id
                        watchUrl: watchUrl,
                        nextSongUrl: nextSongUrl,
                        playlistId: playlistId,
                        currentTime: 0,
                        isPlaying: true,
                    };

                    sessionStorage.setItem('skip_resume_once', 'true');
                    sessionStorage.setItem('meel_audio_state', JSON.stringify(state));

                    // Trigger mini player index jika ada di halaman ini
                    if (typeof loadAudio === 'function') {
                        loadAudio(state, true);
                        updateIndexUI();
                        isMiniPlayerIndexActive = true;
                        document.getElementById('mini-player-index').classList.add('active');
                    }
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', setupPlaylistClicks);
                } else {
                    setupPlaylistClicks();
                }
                document.addEventListener('htmx:afterSwap', setupPlaylistClicks);
            })();
        </script>
    <?php endif; ?>
<?php
}

// ─── Mode content_only untuk HTMX swap ───────────────────────────────────────
if (isset($_GET['content_only'])) {
    renderPlaylistContent($playlist, $playlist_id, $total_songs, $songs_query, $first_song, false);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="<?= htmlspecialchars($playlist['name']) ?> — MEeL Playlist">
    <meta property="og:description" content="Dengarkan playlist <?= htmlspecialchars($playlist['name']) ?> di MEeL Music.">
    <title><?= htmlspecialchars($playlist['name']) ?> — MEeL Playlist</title>
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <?php include '../partials/link.php'; ?>
    <link rel="stylesheet" href="../assets/css/music.css">
    <link href="../assets/css/tailwind.min.css" rel="stylesheet">
    <script src="../assets/js/htmx.min.js"></script>
    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <style>
        .artist-dropdown-active #library-container > main {
            position: relative;
            z-index: 10;
            filter: blur(4px);
            opacity: 0.45;
            pointer-events: none !important;
            user-select: none !important;
        }
        .artist-dropdown-active #library-container > aside {
            position: relative;
            z-index: 50;
        }
        .artist-dropdown-active #library-container > aside *,
        .artist-dropdown-active #library-container > aside *::before,
        .artist-dropdown-active #library-container > aside *::after {
            pointer-events: auto;
        }
        #mini-player-index {
            cursor: default !important;
        }
        #mini-player-index img,
        .mp-thumbnail {
            cursor: pointer !important;
            transition: transform 0.2s ease;
        }
        #mini-player-index img:hover,
        .mp-thumbnail:hover {
            transform: scale(1.05);
        }
    </style>
</head>

<body class="text-gray-400 min-h-screen">

    <!-- NAVBAR — identik dengan index.php -->
    <nav class="border-b border-white/[.04] bg-[#080a0f]/95 sticky top-0 z-50 backdrop-blur-md">
        <div class="w-full px-3 sm:px-5 h-14 flex items-center justify-between gap-2 sm:gap-4">
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
                        autocomplete="off">
                </div>
                <a href="index.php"
                    class="px-2.5 sm:px-4 py-2 bg-white/[.04] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-500 hover:text-orange-500 hover:border-orange-500/30 transition-all flex-shrink-0">
                    <span class="hidden sm:inline">Library</span>
                    <i data-lucide="library" class="w-3.5 h-3.5 sm:hidden"></i>
                </a>
            </div>

            <div class="flex items-center gap-3 sm:gap-5 text-[10px] font-bold uppercase tracking-wider flex-shrink-0">
                <?php include '../partials/nav.php'; ?>
            </div>
        </div>
    </nav>

    <!-- LAYOUT GRID — identik dengan index.php -->
    <div id="library-container"
        class="w-full px-4 sm:px-6 xl:px-10 2xl:px-16 pt-8 pb-20 grid grid-cols-1 lg:grid-cols-12 gap-8">

        <!-- SIDEBAR -->
        <aside class="lg:col-span-3 xl:col-span-2">
            <div class="sticky top-20 space-y-6">

                <!-- FORMAT PILLS (Desktop) -->
                <div class="hidden lg:block">
                    <div class="text-[9px] font-bold text-gray-700 uppercase tracking-[.25em] mb-3">Format</div>
                    <div class="flex flex-wrap gap-2">
                        <a href="index.php?format=all"
                            class="format-pill <?= $format_filter === 'all' ? 'active-orange' : '' ?>">All</a>
                        <a href="index.php?format=ogg"
                            class="format-pill <?= $format_filter === 'ogg' ? 'active-orange' : '' ?>">Opus</a>
                        <a href="index.php?format=m4a"
                            class="format-pill <?= $format_filter === 'm4a' ? 'active-green' : '' ?>">M4A</a>
                        <a href="index.php?format=mp3"
                            class="format-pill <?= $format_filter === 'mp3' ? 'active-blue' : '' ?>">MP3</a>
                    </div>
                </div>

                <!-- ARTISTS (Desktop) -->
                <div class="hidden lg:block">
                    <div class="text-[9px] font-bold text-gray-700 uppercase tracking-[.25em] mb-3 flex items-center gap-2">
                        <i data-lucide="mic-2" class="w-3 h-3"></i> Artists
                    </div>
                    <div class="space-y-0.5 max-h-[45vh] overflow-y-auto no-scrollbar">
                        <a href="index.php"
                            class="sidebar-link flex items-center justify-between px-3 py-2.5 rounded-lg text-[11px] font-bold transition-all
                                 text-gray-600 hover:text-gray-300 hover:bg-white/[.03]">
                            <span>All Collections</span>
                        </a>
                        <?php
                        $artists->data_seek(0);
                        while ($a = $artists->fetch_assoc()): ?>
                            <a href="index.php?artist=<?= urlencode($a['artist']) ?>"
                                class="sidebar-link flex items-center justify-between px-3 py-2.5 rounded-lg text-[11px] font-bold transition-all
                                     text-gray-600 hover:text-gray-300 hover:bg-white/[.03]">
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
                            $my_pls = $library->getUserPlaylists($user_id);
                            while ($pl = $my_pls->fetch_assoc()):
                                $is_active = ($pl['id'] == $playlist_id);
                            ?>
                                <a href="view_playlist.php?id=<?= $pl['id'] ?>"
                                    hx-get="view_playlist.php?id=<?= $pl['id'] ?>&content_only=1"
                                    hx-target="#playlist-main"
                                    hx-swap="innerHTML"
                                    hx-push-url="view_playlist.php?id=<?= $pl['id'] ?>"
                                    class="sidebar-link flex items-center gap-2 px-3 py-2.5 rounded-lg text-[11px] font-bold transition-all
                                         <?= $is_active
                                               ? 'active'
                                               : 'text-gray-600 hover:text-gray-300 hover:bg-white/[.03]' ?>
                                         pl-link"
                                    data-playlist-id="<?= $pl['id'] ?>"
                                    onclick="setActivePlaylistSidebar(<?= $pl['id'] ?>)">
                                    <i data-lucide="disc-3" class="w-3 h-3 flex-shrink-0"></i>
                                    <span class="truncate"><?= htmlspecialchars($pl['name']) ?></span>
                                    <?php if ($is_active): ?>
                                        <i data-lucide="volume-2" class="w-2.5 h-2.5 text-orange-400 flex-shrink-0 ml-auto animate-pulse"></i>
                                    <?php endif; ?>
                                </a>
                            <?php endwhile; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- MOBILE FILTERS & MENUS -->
                <div class="lg:hidden flex flex-col gap-4 bg-[#0d1017]/95 backdrop-blur-md p-4 rounded-xl border border-white/[.04] shadow-lg">

                    <!-- Format Pills (Mobile) -->
                    <div class="flex flex-wrap gap-2">
                        <a href="index.php?format=all"
                            class="format-pill <?= $format_filter === 'all' ? 'active-orange' : '' ?>">All</a>
                        <a href="index.php?format=ogg"
                            class="format-pill <?= $format_filter === 'ogg' ? 'active-orange' : '' ?>">Opus</a>
                        <a href="index.php?format=m4a"
                            class="format-pill <?= $format_filter === 'm4a' ? 'active-green' : '' ?>">M4A</a>
                        <a href="index.php?format=mp3"
                            class="format-pill <?= $format_filter === 'mp3' ? 'active-blue' : '' ?>">MP3</a>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Artists Select (Custom Dropdown) -->
                        <div>
                            <div class="text-[9px] font-bold text-gray-700 uppercase tracking-[.25em] mb-1.5 flex items-center gap-1.5">
                                <i data-lucide="mic-2" class="w-3 h-3"></i> Artists
                            </div>
                            <div class="relative w-full z-[100]" id="custom-artist-dropdown-pl">
                                <button type="button"
                                    onclick="toggleArtistDropdownPL()"
                                    class="w-full bg-white/[.03] border border-white/[.06] rounded-xl pl-3.5 pr-10 py-2.5 text-xs text-gray-300 focus:outline-none focus:border-orange-500/40 cursor-pointer flex items-center justify-between transition-all hover:bg-white/[.05] hover:border-white/[.1] relative z-[100]">
                                    <span class="truncate">All Collections</span>
                                    <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-gray-500"></i>
                                </button>
                                <div id="artist-options-pl" class="hidden absolute left-0 right-0 mt-1 bg-[#0d1017] border border-white/[.08] rounded-xl shadow-2xl z-[100] max-h-60 overflow-y-auto no-scrollbar backdrop-blur-xl">
                                    <button onclick="navigateToArtistPL('all')"
                                        class="w-full text-left px-4 py-2.5 text-xs text-gray-300 hover:bg-white/[.04] transition-colors truncate">
                                        All Collections
                                    </button>
                                    <?php
                                    $artists->data_seek(0);
                                    while ($a = $artists->fetch_assoc()): ?>
                                        <button onclick="navigateToArtistPL('<?= htmlspecialchars($a['artist'], ENT_QUOTES) ?>')"
                                            class="w-full text-left px-4 py-2.5 text-xs text-gray-300 hover:bg-white/[.04] transition-colors truncate">
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
                                <div class="relative w-full z-[100]" id="custom-playlist-dropdown-pl">
                                    <button type="button"
                                        onclick="togglePlaylistDropdownPL()"
                                        class="w-full bg-white/[.03] border border-white/[.06] rounded-xl pl-3.5 pr-10 py-2.5 text-xs text-gray-300 focus:outline-none focus:border-orange-500/40 cursor-pointer flex items-center justify-between transition-all hover:bg-white/[.05] hover:border-white/[.1] relative z-[100]">
                                        <span class="truncate" id="playlist-dropdown-label-pl"><?= htmlspecialchars($playlist['name']) ?></span>
                                        <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-gray-500"></i>
                                    </button>
                                    <div id="playlist-options-pl" class="hidden absolute left-0 right-0 mt-1 bg-[#0d1017] border border-white/[.08] rounded-xl shadow-2xl z-[100] max-h-60 overflow-y-auto no-scrollbar backdrop-blur-xl">
                                        <?php
                                        $my_pls2 = $library->getUserPlaylists($user_id);
                                        while ($pl = $my_pls2->fetch_assoc()):
                                            $pl_active = ($pl['id'] == $playlist_id);
                                        ?>
                                            <button onclick="navigateToPlaylistPL(<?= $pl['id'] ?>)"
                                                data-playlist-id="<?= $pl['id'] ?>"
                                                class="w-full text-left px-4 py-2.5 text-xs transition-colors truncate <?= $pl_active ? 'text-orange-500 font-bold' : 'text-gray-300 hover:bg-white/[.04]' ?>">
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

        <!-- MAIN — bisa di-swap HTMX tanpa reload halaman/player -->
        <main id="playlist-main" class="lg:col-span-9 xl:col-span-10">
            <?php renderPlaylistContent($playlist, $playlist_id, $total_songs, $songs_query, $first_song); ?>
        </main>
    </div>

    <!-- MINI PLAYER — identik dengan index.php agar state audio tidak terputus -->
    <div id="mini-player-index" aria-label="Mini Player">
        <div class="mp-seekbar" id="mp-seekbar-index" onclick="miniSeekIndex(event)" title="Klik untuk seek">
            <div class="mp-seekbar-fill" id="mp-seekbar-fill-index"></div>
            <div class="mp-seekbar-thumb" id="mp-seekbar-thumb-index"></div>
        </div>
        <div class="mp-body">
            <div class="mp-track" title="Buka player penuh">
                <div class="mp-art" onclick="expandPlayerFromMiniPlayer()">
                    <img id="mini-thumbnail-index" src="<?= htmlspecialchars(music_thumbnail_url('default.png')) ?>"
                        alt="Cover lagu" width="256" height="256" loading="eager" decoding="async">
                    <div class="mp-art-overlay">
                        <i data-lucide="maximize-2" style="width:14px;height:14px;"></i>
                    </div>
                </div>
                <div class="mp-meta">
                    <div class="mp-title" id="mini-title-index">Tidak ada musik</div>
                    <div class="mp-artist" id="mini-artist-index">Unknown</div>
                </div>
            </div>
            <div class="mp-controls">
                <button class="mp-btn mp-btn-ghost" id="mini-loop-btn-index" onclick="toggleMiniLoopIndex()" title="Ulang">
                    <i data-lucide="repeat" style="width:15px;height:15px;"></i>
                </button>
                <button class="mp-btn mp-btn-ghost" onclick="miniPrevIndex()" id="mp-prev-btn-index" title="Sebelumnya">
                    <i data-lucide="skip-back" style="width:16px;height:16px;"></i>
                </button>
                <button class="mp-btn mp-btn-primary" onclick="miniPlayPauseIndex()" id="mini-play-btn-index" title="Play / Pause">
                    <i data-lucide="play" style="width:18px;height:18px;"></i>
                </button>
                <button class="mp-btn mp-btn-ghost" onclick="miniNextIndex()" id="mp-next-btn-index" title="Berikutnya">
                    <i data-lucide="skip-forward" style="width:16px;height:16px;"></i>
                </button>
            </div>
            <div class="mp-right">
                <div class="mp-time">
                    <span id="mini-current-time-index">0:00</span>
                    <span class="mp-time-sep">/</span>
                    <span id="mini-duration-index">0:00</span>
                </div>
                <button class="mp-btn mp-btn-ghost mp-close" onclick="closeMiniPlayerIndex()" title="Tutup">
                    <i data-lucide="chevron-down" style="width:16px;height:16px;"></i>
                </button>
            </div>
        </div>
    </div>

    <?php include '../partials/footer.php'; ?>

    <script>
        lucide.createIcons();

        // ── Sidebar: Playlist Active Highlight ──
        window.setActivePlaylistSidebar = function(id) {
            document.querySelectorAll('.sidebar-link.pl-link').forEach(function(el) {
                if (parseInt(el.dataset.playlistId) === id) {
                    el.classList.add('active');
                } else {
                    el.classList.remove('active');
                }
            });
            // Update mobile playlist dropdown label + active state
            var label = document.getElementById('playlist-dropdown-label-pl');
            var options = document.querySelectorAll('#playlist-options-pl button');
            options.forEach(function(btn) {
                btn.classList.remove('text-orange-500', 'font-bold');
                btn.classList.add('text-gray-300', 'hover:bg-white/[.04]');
            });
            options.forEach(function(btn) {
                if (parseInt(btn.dataset.playlistId) === id) {
                    btn.classList.remove('text-gray-300', 'hover:bg-white/[.04]');
                    btn.classList.add('text-orange-500', 'font-bold');
                    if (label) label.textContent = btn.textContent.trim();
                }
            });
        };

        // ── Mobile Artist Dropdown (custom toggle) ──
        window.toggleArtistDropdownPL = function() {
            var dd = document.getElementById('artist-options-pl');
            if (!dd) return;
            var hidden = dd.classList.contains('hidden');
            dd.classList.toggle('hidden');
            if (hidden) {
                document.body.classList.add('artist-dropdown-active');
                var active = dd.querySelector('.text-orange-500');
                if (active) active.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } else {
                setTimeout(function() { document.body.classList.remove('artist-dropdown-active'); }, 350);
            }
        };

        window.closeArtistDropdownPL = function() {
            const dropdown = document.getElementById('artist-options-pl');
            if (dropdown) dropdown.classList.add('hidden');
            setTimeout(function() {
                const artistStillOpen = document.getElementById('artist-options-pl') && !document.getElementById('artist-options-pl').classList.contains('hidden');
                const playlistStillOpen = document.getElementById('playlist-options-pl') && !document.getElementById('playlist-options-pl').classList.contains('hidden');
                if (!artistStillOpen && !playlistStillOpen) {
                    document.body.classList.remove('artist-dropdown-active');
                }
            }, 350);
        };

        window.navigateToArtistPL = function(artist) {
            closeArtistDropdownPL();
            if (artist === 'all') {
                window.location.href = 'index.php';
            } else {
                window.location.href = 'index.php?artist=' + encodeURIComponent(artist);
            }
        };

        // ── Mobile Playlist Dropdown (custom toggle) ──
        window.togglePlaylistDropdownPL = function() {
            const dropdown = document.getElementById('playlist-options-pl');
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

        window.closePlaylistDropdownPL = function() {
            const dropdown = document.getElementById('playlist-options-pl');
            if (dropdown) dropdown.classList.add('hidden');
            setTimeout(function() {
                const artistStillOpen = document.getElementById('artist-options-pl') && !document.getElementById('artist-options-pl').classList.contains('hidden');
                const playlistStillOpen = document.getElementById('playlist-options-pl') && !document.getElementById('playlist-options-pl').classList.contains('hidden');
                if (!artistStillOpen && !playlistStillOpen) {
                    document.body.classList.remove('artist-dropdown-active');
                }
            }, 350);
        };

        window.navigateToPlaylistPL = function(id) {
            closePlaylistDropdownPL();
            setActivePlaylistSidebar(id);
            htmx.ajax('GET', 'view_playlist.php?id=' + id + '&content_only=1', {
                target: '#playlist-main',
                swap: 'innerHTML',
                pushUrl: 'view_playlist.php?id=' + id
            });
        };

        // ── Close dropdowns on outside click ──
        document.addEventListener('click', function(e) {
            const artistDropdown = document.getElementById('artist-options-pl');
            const artistTrigger = e.target.closest('#custom-artist-dropdown-pl');
            if (!artistTrigger && artistDropdown && !artistDropdown.classList.contains('hidden')) {
                closeArtistDropdownPL();
            }
            const playlistDropdown = document.getElementById('playlist-options-pl');
            const playlistTrigger = e.target.closest('#custom-playlist-dropdown-pl');
            if (!playlistTrigger && playlistDropdown && !playlistDropdown.classList.contains('hidden')) {
                closePlaylistDropdownPL();
            }
        });

        // =============================================
        // === MINI PLAYER — identik dengan index.php ===
        // =============================================
        const miniPlayerIndex = document.getElementById('mini-player-index');
        let audioPlayer = null;
        let isMiniPlayerIndexActive = false;
        let currentState = null;

        function fmtTime(s) {
            if (!s || isNaN(s)) return '0:00';
            return `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;
        }

        function saveIndexState() {
            if (!currentState || !audioPlayer) return;
            currentState.currentTime = audioPlayer.currentTime;
            currentState.isPlaying = !audioPlayer.paused;
            currentState.isLooping = isMiniLoopIndexActive;
            sessionStorage.setItem('meel_audio_state', JSON.stringify(currentState));
        }

        function loadAudio(state, autoplay) {
            if (!audioPlayer) {
                audioPlayer = document.createElement('audio');
                audioPlayer.id = 'hidden-audio-player';
                audioPlayer.preload = 'metadata';
                document.body.appendChild(audioPlayer);
                audioPlayer.addEventListener('timeupdate', updateIndexUI);
                audioPlayer.addEventListener('play', () => setPlayIcon('pause'));
                audioPlayer.addEventListener('pause', () => setPlayIcon('play'));
                audioPlayer.addEventListener('ended', () => miniNextIndex());
            }
            if (currentState && currentState.filename === state.filename) return;
            currentState = state;
            audioPlayer.src = `stream.php?id=${state.id}`;
            audioPlayer.load();
            const _gLoop = localStorage.getItem('meel_global_loop') === 'true';
            isMiniLoopIndexActive = (state.isLooping !== undefined) ? state.isLooping : _gLoop;
            localStorage.setItem('meel_global_loop', String(isMiniLoopIndexActive));
            audioPlayer.loop = isMiniLoopIndexActive;
            updateMiniLoopUIIndex();
            if (autoplay) {
                audioPlayer.currentTime = state.currentTime || 0;
                audioPlayer.play().catch(() => {});
            }
        }

        function updateIndexUI() {
            if (!audioPlayer || !currentState) return;
            const pct = audioPlayer.duration > 0 ?
                (audioPlayer.currentTime / audioPlayer.duration) * 100 : 0;
            const fill = document.getElementById('mp-seekbar-fill-index');
            const thumb = document.getElementById('mp-seekbar-thumb-index');
            if (fill) fill.style.width = pct + '%';
            if (thumb) thumb.style.left = pct + '%';
            const ct = document.getElementById('mini-current-time-index');
            const dt = document.getElementById('mini-duration-index');
            if (ct) ct.textContent = fmtTime(audioPlayer.currentTime);
            if (dt) dt.textContent = fmtTime(audioPlayer.duration);
            const img = document.getElementById('mini-thumbnail-index');
            const title = document.getElementById('mini-title-index');
            const artist = document.getElementById('mini-artist-index');
            if (img) img.src = currentState.thumbnailUrl || `upload/thumbnail/${currentState.thumbnail}`;
            if (title) title.textContent = currentState.title || 'Unknown';
            if (artist) artist.textContent = currentState.artist || 'Unknown';
        }

        function setPlayIcon(icon) {
            const btn = document.getElementById('mini-play-btn-index');
            if (btn) {
                btn.innerHTML = `<i data-lucide="${icon}" style="width:18px;height:18px;"></i>`;
                lucide.createIcons();
            }
        }

        window.miniSeekIndex = function(e) {
            if (!audioPlayer || !audioPlayer.duration) return;
            const rect = e.currentTarget.getBoundingClientRect();
            const pct = (e.clientX - rect.left) / rect.width;
            audioPlayer.currentTime = Math.max(0, Math.min(pct * audioPlayer.duration, audioPlayer.duration));
        };

        window.miniPlayPauseIndex = function() {
            if (!audioPlayer || !currentState) return;
            audioPlayer.paused ? audioPlayer.play() : audioPlayer.pause();
        };

        window.miniNextIndex = function() {
            if (!audioPlayer || (audioPlayer.loop)) return;
            if (currentState && currentState.nextSongUrl) {
                saveIndexState();
                window.location.href = currentState.nextSongUrl;
            }
        };

        window.miniPrevIndex = function() {
            if (!audioPlayer) return;
            if (audioPlayer.currentTime > 3) {
                audioPlayer.currentTime = 0;
                return;
            }
            if (currentState && currentState.prevSongUrl) {
                saveIndexState();
                window.location.href = currentState.prevSongUrl;
            } else {
                audioPlayer.currentTime = 0;
            }
        };

        // KEY FIX: expandPlayerFromMiniPlayer selalu menyertakan playlist_id dari state
        function expandPlayerFromMiniPlayer() {
            saveIndexState();
            const raw = sessionStorage.getItem('meel_audio_state');
            if (!raw) return;
            try {
                const state = JSON.parse(raw);
                // watchUrl sudah mengandung &playlist_id=X karena disimpan dari data-watch-url
                if (state.watchUrl) {
                    window.location.href = state.watchUrl;
                } else if (state.id) {
                    const plId = state.playlistId ? `&playlist_id=${state.playlistId}` : '';
                    window.location.href = `watch.php?id=${state.id}${plId}`;
                }
            } catch (e) {}
        }

        let isMiniLoopIndexActive = localStorage.getItem('meel_global_loop') === 'true';

        window.toggleMiniLoopIndex = function() {
            isMiniLoopIndexActive = !isMiniLoopIndexActive;
            localStorage.setItem('meel_global_loop', String(isMiniLoopIndexActive));
            if (audioPlayer) audioPlayer.loop = isMiniLoopIndexActive;
            updateMiniLoopUIIndex();
            saveIndexState();
        };

        function updateMiniLoopUIIndex() {
            const btn = document.getElementById('mini-loop-btn-index');
            if (!btn) return;
            btn.style.color = isMiniLoopIndexActive ? '#f97316' : '';
            btn.style.opacity = isMiniLoopIndexActive ? '1' : '0.5';
        }

        window.closeMiniPlayerIndex = function() {
            if (audioPlayer) audioPlayer.pause();
            miniPlayerIndex.classList.remove('active');
            sessionStorage.removeItem('meel_audio_state');
            isMiniPlayerIndexActive = false;
            currentState = null;
        };

        function initMiniPlayerIndex() {
            isMiniLoopIndexActive = localStorage.getItem('meel_global_loop') === 'true';
            updateMiniLoopUIIndex();
            const raw = sessionStorage.getItem('meel_audio_state');
            if (!raw) return;
            try {
                const state = JSON.parse(raw);
                isMiniPlayerIndexActive = true;
                loadAudio(state, state.isPlaying);
                updateIndexUI();
                miniPlayerIndex.classList.add('active');
            } catch (e) {}
        }

        document.addEventListener('DOMContentLoaded', initMiniPlayerIndex);
        document.addEventListener('htmx:afterSwap', function() {
            if (typeof lucide !== 'undefined') lucide.createIcons();
        });

        setInterval(() => {
            if (isMiniPlayerIndexActive) saveIndexState();
        }, 5000);
    </script>
</body>

</html>