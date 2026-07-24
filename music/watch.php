<?php
session_name('meel');
session_start();

include '../auth/config.php';
require_once '../modules/core/helpers.php';
require_once '../modules/core/CommentRenderer.php';
require_once '../controllers/api/WatchController.php';

$id          = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id     = $_SESSION['user_id'] ?? null;
$playlist_id = isset($_GET['playlist_id']) ? (int)$_GET['playlist_id'] : 0;

$ctrl = new MusicWatchController($conn, $user_id, $id, $playlist_id);
$ctrl->handleRequest();

// Semua variabel template diekstrak dari controller
// EXTR_SKIP mencegah overwrite variabel yang sudah ada di scope
extract($ctrl->getViewData(), EXTR_SKIP);

// Lepas session lock agar range request streaming tidak terblokir
session_write_close();
?><!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="<?= htmlspecialchars($v['title']) ?> — MEeL Music">
    <meta property="og:description" content="Dengarkan <?= htmlspecialchars($v['title']) ?> oleh <?= htmlspecialchars($v['artist'] ?? 'Unknown') ?> di MEeL Music.">
    <title><?= htmlspecialchars($v['title']) ?> — MEeL Music</title>
    <?php include '../partials/link.php'; ?>
    <?php $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'); ?>
    <link rel="preconnect" href="<?= $base_url ?>/" crossorigin>
    <link rel="stylesheet" href="../assets/css/plyr.css">
    <link rel="stylesheet" href="../assets/css/music.css">
    <script src="../assets/js/htmx.min.js" defer></script>
</head>

<body class="text-gray-400 min-h-screen">
    <a href="#main-content" class="absolute left-3 top-3 z-[999] -translate-y-20 rounded-lg bg-orange-500 px-4 py-2 text-[10px] font-black uppercase tracking-widest text-black transition-transform focus:translate-y-0 focus:outline-none">
        Lewati ke konten
    </a>

    <nav class="border-b border-white/[.04] bg-[#080a0f]/95 sticky top-0 z-50 backdrop-blur-md">
        <div class="w-full px-4 sm:px-5 h-14 flex items-center justify-between gap-3">

            <a href="index.php" class="flex items-center gap-2 flex-shrink-0" title="MEeL Music">
                <div class="w-7 h-7 bg-orange-600 rounded-lg flex items-center justify-center">
                    <i data-lucide="music" class="w-3.5 h-3.5 text-white fill-current"></i>
                </div>
                <span class="text-sm font-bold tracking-tight text-white uppercase">
                    MEeL<span class="text-orange-500">Music</span>
                </span>
            </a>

            <div class="flex flex-1 max-w-sm items-center gap-2">
                <div class="relative flex-1 group">
                    <i data-lucide="search" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-600 group-focus-within:text-orange-500 transition-colors"></i>
                    <input type="text"
                        id="m-search-watch"
                        name="search"
                        placeholder="Cari lagu atau artis..."
                        class="w-full bg-white/[.04] border border-white/[.06] rounded-xl py-2 pl-9 pr-4 text-xs focus:outline-none focus:border-orange-500/40 transition-all text-gray-300"
                        hx-get="search_music.php?exclude=<?= $id ?>"
                        hx-trigger="keyup[key=='Enter']"
                        hx-target="#music-recommendation-column"
                        hx-indicator="#music-search-indicator"
                        autocomplete="off">
                    <div id="music-search-indicator" class="htmx-indicator absolute right-3.5 top-1/2 -translate-y-1/2">
                        <div class="animate-spin h-3 w-3 border-2 border-orange-500 border-t-transparent rounded-full"></div>
                    </div>
                </div>
                <button hx-get="search_music.php?exclude=<?= $id ?>"
                    hx-include="#m-search-watch"
                    hx-target="#music-recommendation-column"
                    hx-indicator="#music-search-indicator"
                    class="px-3 py-2 bg-white/[.04] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase text-gray-500 hover:text-orange-500 hover:border-orange-500/30 transition-all flex-shrink-0">
                    Cari
                </button>
            </div>

            <div class="flex items-center gap-3 text-[10px] font-bold uppercase tracking-wider flex-shrink-0">
                <?php include '../partials/nav.php'; ?>
            </div>
        </div>
    </nav>

    <main id="main-content" class="w-full pt-4 sm:pt-8 pb-20 flex flex-col lg:flex-row gap-4">
        <div class="flex-1 min-w-0 space-y-5 px-4 sm:px-5">
            <div id="player-container" class="bg-[#0d1017] border-0 rounded-none sm:rounded-none overflow-hidden relative">
                <div id="resume-modal" class="hidden rounded-xl sm:rounded-2xl">
                    <div class="bg-[#141820] border border-orange-500/25 border-t-2 border-t-orange-500 rounded-2xl text-center">
                        <div class="bg-orange-500/10 rounded-full flex items-center justify-center mx-auto mb-3" style="width:clamp(36px,8cqw,56px);height:clamp(36px,8cqw,56px)">
                            <i data-lucide="play-circle" class="text-orange-500" style="width:clamp(18px,4cqw,28px);height:clamp(18px,4cqw,28px)"></i>
                        </div>
                        <div class="font-black text-white uppercase tracking-wider mb-2">Lanjut Musik?</div>
                        <div class="text-[10px] text-gray-500 uppercase tracking-widest mb-4">
                            Menit ke‑ <span id="resume-time" class="text-orange-400 font-mono">0:00</span>
                        </div>
                        <div class="flex gap-2">
                            <button id="btn-resume"
                                class="flex-1 bg-orange-500 hover:bg-orange-400 text-black font-black uppercase tracking-wider rounded-xl transition-all border-none cursor-pointer">
                                Lanjut
                            </button>
                            <button id="btn-restart"
                                class="flex-1 bg-white/5 hover:bg-white/10 text-gray-400 font-black uppercase tracking-wider rounded-xl border border-white/10 cursor-pointer transition-all">
                                Ulang
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row gap-5 p-4 sm:p-6 border-b border-white/[.04]">
                    <div class="flex-shrink-0 flex items-center justify-center sm:justify-start">
                        <div class="vinyl-spin vinyl-disc">
                            <img src="<?= htmlspecialchars(music_thumbnail_url($v['thumbnail'])) ?>" alt="<?= htmlspecialchars($v['title']) ?> cover" width="512" height="512" class="w-full h-full object-cover" fetchpriority="high" decoding="async">
                        </div>
                    </div>

                    <div class="flex-1 min-w-0 flex flex-col justify-center gap-3">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div class="text-center sm:text-left flex-1 min-w-0">
                                <div class="track-title truncate" title="<?= htmlspecialchars($v['title']) ?>"><?= htmlspecialchars($v['title']) ?></div>
                                <a href="index.php?artist=<?= urlencode($v['artist']) ?>"
                                    class="text-orange-400 font-bold text-sm uppercase tracking-widest hover:underline block mt-2 truncate">
                                    <?= htmlspecialchars($v['artist']) ?>
                                </a>
                            </div>

                        </div>
                        <div class="flex items-center gap-2 flex-wrap justify-center sm:justify-start">
                            <span class="text-[10px] font-bold uppercase tracking-wider px-3 py-1 rounded-lg bg-orange-500/10 border border-orange-500/20 text-orange-400"
                                title="<?= htmlspecialchars($deskripsi) ?>">
                                <?= $fmt_label ?>
                            </span>
                            <span class="text-[10px] font-bold px-3 py-1 rounded-lg bg-green-500/8 border border-green-500/15 text-green-400 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-400 animate-pulse"></span>
                                <span id="realtime-bitrate">0</span> kbps
                            </span>
                            <button id="btn-loop" onclick="toggleLoop()"
                                class="bg-gray-800 text-gray-400 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider px-3 py-1 rounded-lg border border-transparent transition-all cursor-pointer">
                                <i data-lucide="repeat" class="w-3 h-3"></i>
                                <span id="loop-text">Loop Off</span>
                            </button>
                            <button id="btn-vis" onclick="toggleVisualizer()"
                                class="bg-gray-800 text-gray-400 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider px-3 py-1 rounded-lg border border-transparent transition-all cursor-pointer">
                                <i data-lucide="activity" class="w-3 h-3"></i>
                                <span id="vis-text">Vis On</span>
                            </button>
                            <button id="btn-eq" onclick="toggleEqualizer()"
                                class="bg-gray-800 text-gray-400 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider px-3 py-1 rounded-lg border border-transparent transition-all cursor-pointer">
                                <i data-lucide="sliders-horizontal" class="w-3 h-3"></i>
                                <span id="eq-text">EQ Off</span>
                            </button>
                        </div>
                    </div>

                    <div id="cava-container" class="hidden w-full sm:flex-1 sm:min-w-[160px] bg-black/20 border border-white/[.04] rounded-xl sm:rounded-xl p-2 sm:p-3 items-end justify-center gap-[2px]"></div>
                </div>

                <div id="eq-container" class="px-4 sm:px-6 py-4 border-t border-white/[.04] bg-black/10 hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="text-[10px] font-bold uppercase tracking-[0.25em] text-gray-400">Equalizer</div>
                        <div id="eq-preset-dropdown" class="relative w-full sm:w-auto">
                            <button id="eq-preset-button" type="button" onclick="toggleEqPresetDropdown()"
                                class="w-full sm:w-auto min-w-[140px] flex items-center justify-between gap-2 bg-gray-900/80 border border-white/[.10] text-gray-200 text-[11px] rounded-lg px-3 py-2 outline-none focus:border-orange-500/40 transition-all">
                                <span id="eq-preset-label">Flat</span>
                                <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-gray-400"></i>
                            </button>
                            <div id="eq-preset-options" class="hidden absolute left-0 right-0 mt-1 bg-[#0d1017] border border-white/[.08] rounded-xl shadow-2xl z-50 max-h-60 overflow-y-auto no-scrollbar backdrop-blur-xl">
                                <button type="button" data-preset="flat" onclick="selectEqPreset('flat')" class="w-full text-left px-4 py-3 text-xs text-gray-300 hover:bg-white/[.04] transition-colors">Flat</button>
                                <button type="button" data-preset="bass" onclick="selectEqPreset('bass')" class="w-full text-left px-4 py-3 text-xs text-gray-300 hover:bg-white/[.04] transition-colors">Bass Boost</button>
                                <button type="button" data-preset="treble" onclick="selectEqPreset('treble')" class="w-full text-left px-4 py-3 text-xs text-gray-300 hover:bg-white/[.04] transition-colors">Treble Boost</button>
                                <button type="button" data-preset="vocal" onclick="selectEqPreset('vocal')" class="w-full text-left px-4 py-3 text-xs text-gray-300 hover:bg-white/[.04] transition-colors">Vocal Boost</button>
                                <button type="button" data-preset="rock" onclick="selectEqPreset('rock')" class="w-full text-left px-4 py-3 text-xs text-gray-300 hover:bg-white/[.04] transition-colors">Rock</button>
                                <button type="button" data-preset="classical" onclick="selectEqPreset('classical')" class="w-full text-left px-4 py-3 text-xs text-gray-300 hover:bg-white/[.04] transition-colors">Classical</button>
                                <button type="button" data-preset="pop" onclick="selectEqPreset('pop')" class="w-full text-left px-4 py-3 text-xs text-gray-300 hover:bg-white/[.04] transition-colors">Pop</button>
                                <button type="button" data-preset="jazz" onclick="selectEqPreset('jazz')" class="w-full text-left px-4 py-3 text-xs text-gray-300 hover:bg-white/[.04] transition-colors">Jazz</button>
                                <button type="button" data-preset="electronic" onclick="selectEqPreset('electronic')" class="w-full text-left px-4 py-3 text-xs text-gray-300 hover:bg-white/[.04] transition-colors">Electronic</button>
                                <button type="button" data-preset="acoustic" onclick="selectEqPreset('acoustic')" class="w-full text-left px-4 py-3 text-xs text-gray-300 hover:bg-white/[.04] transition-colors">Acoustic</button>
                                <button type="button" data-preset="gaming" onclick="selectEqPreset('gaming')" class="w-full text-left px-4 py-3 text-xs text-gray-300 hover:bg-white/[.04] transition-colors">Gaming</button>
                                <button type="button" data-preset="podcast" onclick="selectEqPreset('podcast')" class="w-full text-left px-4 py-3 text-xs text-gray-300 hover:bg-white/[.04] transition-colors">Podcast</button>
                            </div>
                            <input id="eq-preset" type="hidden" value="flat">
                        </div>
                    </div>
                    <div id="eq-panel" class="mt-3 hidden">
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                            <?php
                            $eqBands = [60, 170, 350, 1000, 3500, 10000];
                            foreach ($eqBands as $idx => $band) {
                                echo '<div class="rounded-xl border border-white/10 bg-black/20 p-3">';
                                echo '<label for="eq-band-' . $idx . '" class="block text-[10px] font-bold uppercase tracking-[0.25em] text-gray-400 mb-2">' . $band . ' Hz</label>';
                                echo '<input id="eq-band-' . $idx . '" type="range" min="-12" max="12" step="0.5" value="0" oninput="setEqBand(' . $idx . ', this.value)" class="w-full accent-orange-500">';
                                echo '<div id="eq-band-value-' . $idx . '" class="mt-2 text-center text-[11px] text-orange-400">0.0 dB</div>';
                                echo '</div>';
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <div class="p-4 sm:p-5">
                    <audio id="main-player" controls preload="<?= $preloadVal ?>" class="w-full" oncontextmenu="return false;">
                        <source src="stream.php?id=<?= $id ?>" type="<?= $mimeType ?>">
                        Your browser does not support the audio element.
                    </audio>
                </div>

                <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center sm:justify-between gap-3 px-4 sm:px-6 py-4 border-t border-white/[.04] bg-black/10">
                    <div class="flex items-center gap-3">
                        <a href="../profile/?u=<?= urlencode($v['uploader']) ?>"
                            aria-label="Profil @<?= htmlspecialchars($v['uploader']) ?>"
                            title="Profil @<?= htmlspecialchars($v['uploader']) ?>"
                            class="w-9 h-9 rounded-full overflow-hidden border border-orange-500/25 flex-shrink-0 block">
                            <?php if (!empty($v['uploader_pfp'])): ?>
                                <img src="../profile/upload/<?= htmlspecialchars($v['uploader_pfp']) ?>" alt="Foto profil <?= htmlspecialchars($v['uploader']) ?>" width="68" height="68" class="w-full h-full object-cover" loading="lazy" decoding="async">
                            <?php else: ?>
                                <div class="w-full h-full bg-gradient-to-br from-orange-500 to-red-600 flex items-center justify-center text-white text-sm font-bold">
                                    <?= strtoupper(substr($v['uploader'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                        </a>
                        <div>
                            <a href="../profile/?u=<?= urlencode($v['uploader']) ?>"
                                class="text-[10px] font-black uppercase tracking-widest text-orange-400 hover:underline block leading-tight">
                                <?= htmlspecialchars($v['uploader']) ?>
                            </a>
                            <div class="text-[10px] text-gray-500 mt-0.5">
                                <?= number_format($v['views'] ?? 0) ?> tayangan &nbsp;•&nbsp; <?= time_ago($v['upload_date']) ?>
                            </div>
                        </div>
                    </div>

                    <?php if (isset($_SESSION['username'])): ?>
                        <div class="flex items-center gap-2 flex-wrap">
                            <div id="like-dislike-container" class="flex items-center gap-2 flex-wrap">
                                <button
                                    hx-post="../controllers/api/like.php" hx-target="#like-dislike-container" hx-swap="outerHTML"
                                    hx-vals='{"id":"<?= $id ?>","media_type":"music","type":"like","csrf_token":"<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"}'
                                    class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer
                                   <?= $user_interaction === 'like'
                                        ? 'bg-orange-500/15 border-orange-500/30 text-orange-400'
                                        : 'bg-gray-800/50 border-white/[.05] text-gray-500 hover:bg-gray-700 hover:text-gray-300' ?>">
                                    <i data-lucide="thumbs-up" class="w-3.5 h-3.5 <?= $user_interaction === 'like' ? 'fill-current' : '' ?>"></i>
                                    Like<?= ($v['likes'] ?? 0) > 0 ? " <span class='tabular-nums ml-0.5'>{$v['likes']}</span>" : '' ?>
                                </button>
                                <button
                                    hx-post="../controllers/api/like.php" hx-target="#like-dislike-container" hx-swap="outerHTML"
                                    hx-vals='{"id":"<?= $id ?>","media_type":"music","type":"dislike","csrf_token":"<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"}'
                                    class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer
                                   <?= $user_interaction === 'dislike'
                                        ? 'bg-white/10 border-white/15 text-white'
                                        : 'bg-gray-800/50 border-white/[.05] text-gray-500 hover:bg-gray-700 hover:text-gray-300' ?>">
                                    <i data-lucide="thumbs-down" class="w-3.5 h-3.5 <?= $user_interaction === 'dislike' ? 'fill-current' : '' ?>"></i>
                                    <?= ($v['dislikes'] ?? 0) > 0 ? "<span class='tabular-nums'>{$v['dislikes']}</span>" : '' ?>
                                </button>
                                <button onclick="document.getElementById('playlist-modal').classList.remove('hidden')"
                                    class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer bg-gray-800/50 border-white/[.05] text-gray-500 hover:bg-gray-700 hover:text-gray-300">
                                    <i data-lucide="list-plus" class="w-3.5 h-3.5"></i> Simpan
                                </button>
                            </div>
                            <?php
                            $can_edit = $is_logged_in && (
                                ($_SESSION['role'] ?? '') === 'admin' ||
                                (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)($v['user_id'] ?? -1))
                            );
                            if ($can_edit): ?>
                                <a href="../admin/edit-music.php?id=<?= $id ?>" title="Edit Musik"
                                    class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer no-underline bg-orange-600/10 border-orange-600/20 text-orange-400 hover:bg-orange-600 hover:text-white">
                                    <i data-lucide="edit" class="w-3.5 h-3.5"></i> Edit
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($v['description'])): ?>
                <div class="bg-[#0d1017] border border-white/[.06] rounded-xl sm:rounded-2xl p-4 sm:p-6 desc-container" style="min-height:120px;contain-intrinsic-size:120px;content-visibility:auto">
                    <div class="text-[10px] font-bold uppercase tracking-[.25em] text-gray-500 mb-3 flex items-center gap-2">
                        <i data-lucide="align-left" class="w-3.5 h-3.5 text-orange-500"></i> Deskripsi
                    </div>
                    <div class="relative">
                        <p id="desc-text-music" class="text-sm text-gray-300 leading-relaxed break-words whitespace-pre-wrap line-clamp-3 transition-all duration-300"><?= htmlspecialchars($v['description']) ?></p>
                    </div>
                    <button id="btn-read-more-music" onclick="toggleDescriptionMusic()" class="mt-3 text-[10px] font-bold uppercase tracking-wider text-orange-500 hover:text-orange-400 transition-colors cursor-pointer border-none bg-transparent p-0 hidden">
                        Selengkapnya
                    </button>
                </div>

                <script>
                    function toggleDescriptionMusic() {
                        const descText = document.getElementById('desc-text-music');
                        const btn = document.getElementById('btn-read-more-music');

                        if (descText.classList.contains('line-clamp-3')) {
                            descText.classList.remove('line-clamp-3');
                            btn.textContent = 'Lebih Sedikit';
                        } else {
                            descText.classList.add('line-clamp-3');
                            btn.textContent = 'Selengkapnya';
                        }
                    }

                    function checkDescriptionLengthMusic() {
                        const descText = document.getElementById('desc-text-music');
                        const btn = document.getElementById('btn-read-more-music');

                        if (descText && btn) {
                            setTimeout(() => {
                                if (!descText.classList.contains('line-clamp-3')) {
                                    btn.classList.remove('hidden');
                                    return;
                                }

                                const isOverflowing = descText.scrollHeight > descText.offsetHeight;
                                if (isOverflowing) {
                                    btn.classList.remove('hidden');
                                } else {
                                    btn.classList.add('hidden');
                                }
                            }, 50);
                        }
                    }
                    document.addEventListener('DOMContentLoaded', checkDescriptionLengthMusic);
                    document.body.addEventListener('htmx:afterOnLoad', checkDescriptionLengthMusic);
                    window.addEventListener('resize', checkDescriptionLengthMusic);
                </script>
            <?php endif; ?>

            <?php if ($is_logged_in): ?>
                <section class="bg-[#0d1017] border border-white/[.06] rounded-xl sm:rounded-2xl overflow-hidden comment-section" id="comment-section" style="content-visibility:auto;contain-intrinsic-size:200px">
                    <div class="px-4 sm:px-6 py-4 border-b border-white/[.04] bg-black/10 flex items-center gap-2">
                        <i data-lucide="message-square" class="w-3.5 h-3.5 text-orange-500"></i>
                        <span class="text-[10px] font-bold uppercase tracking-[.25em] text-gray-500">Komentar</span>
                    </div>
                    <div class="p-4 sm:p-6">
                        <form action="watch.php?id=<?= $id ?>" method="post" class="mb-6">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <textarea name="comments"
                                class="w-full bg-black/25 border border-white/[.06] rounded-xl p-3 sm:p-4 text-sm text-gray-300 focus:outline-none focus:border-orange-500/40 min-h-[80px] resize-y transition-all"
                                placeholder="Tulis komentar..." required></textarea>
                            <div class="flex justify-end mt-2">
                                <button name="send"
                                    class="bg-orange-500 hover:bg-orange-400 text-black text-[10px] font-black uppercase tracking-wider px-5 py-2.5 rounded-xl transition-all border-none cursor-pointer">
                                    Kirim
                                </button>
                            </div>
                        </form>

                        <div class="space-y-1 max-h-[500px] overflow-y-auto pr-1">
                            <?php
                            if (empty($comments_grouped)) {
                                echo "<div class='py-10 text-center text-[10px] text-gray-700 uppercase tracking-widest'>Belum ada komentar.</div>";
                            } else {
                                render_comments(0, $comments_grouped, 0, 'music', $playlist_context);
                            }
                            ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

        </div>

        <div class="w-full lg:w-80 flex-shrink-0 space-y-6 px-4 sm:px-5 lg:px-0 rekomendasi-sidebar" style="content-visibility:auto;contain-intrinsic-size:500px;min-height:300px">

            <?php if ($playlist_context > 0 && $queue_query && $queue_query->num_rows > 0): ?>
                <div class="bg-[#0d1017] border border-white/[.06] rounded-xl sm:rounded-2xl overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-white/[.04] bg-black/10 flex items-center gap-2">
                        <i data-lucide="list-music" class="w-3.5 h-3.5 text-orange-500"></i>
                        <span class="text-[10px] font-bold uppercase tracking-[.25em] text-gray-500">Up Next</span>
                    </div>
                    <div class="p-3 space-y-0.5 max-h-[320px] overflow-y-auto no-scrollbar">
                        <?php
                        $queue_query->data_seek(0);
                        while ($q = $queue_query->fetch_assoc()):
                            $is_pl = ($q['id'] == $id);
                        ?>
                            <a href="watch.php?id=<?= $q['id'] ?>&playlist_id=<?= $playlist_context ?>"
                                class="flex items-center gap-3 px-2 py-2 rounded-xl transition-all no-underline
                              <?= $is_pl ? 'bg-orange-500/8 border border-orange-500/20' : 'hover:bg-white/[.025] border border-transparent' ?>">
                                <div class="w-10 h-10 rounded-lg overflow-hidden flex-shrink-0 <?= $is_pl ? 'opacity-50' : '' ?>">
                                    <img src="<?= htmlspecialchars(music_thumbnail_url($q['thumbnail'])) ?>" alt="<?= htmlspecialchars($q['title']) ?> thumbnail" width="64" height="48" class="w-full h-full object-cover" loading="lazy" decoding="async">
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-[11px] font-bold truncate uppercase <?= $is_pl ? 'text-orange-400' : 'text-gray-400' ?>">
                                        <?= htmlspecialchars($q['title']) ?>
                                    </div>
                                    <div class="text-[9px] text-gray-500 uppercase tracking-wider"><?= htmlspecialchars($q['artist']) ?></div>
                                </div>
                                <?php if ($is_pl): ?><i data-lucide="volume-2" class="w-3.5 h-3.5 text-orange-400 flex-shrink-0"></i><?php endif; ?>
                            </a>
                        <?php endwhile; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="bg-[#0d1017] border border-white/[.06] rounded-xl sm:rounded-2xl overflow-hidden">
                <div class="px-5 py-3.5 border-b border-white/[.04] bg-black/10 flex items-center gap-2">
                    <i data-lucide="shuffle" class="w-3.5 h-3.5 text-gray-600"></i>
                    <span class="text-[10px] font-bold uppercase tracking-[.25em] text-gray-500">Discover</span>
                </div>
                <div id="music-recommendation-column" class="p-3 grid grid-cols-2 lg:grid-cols-1 gap-2 lg:gap-0 lg:space-y-0.5">
                    <?php while ($r = $rekom->fetch_assoc()):
                        $r_ext = strtolower(pathinfo($r['filename'], PATHINFO_EXTENSION));
                        $r_lbl = $r_ext === 'ogg' ? 'opus' : $r_ext;
                    ?>
                        <a href="watch.php?id=<?= $r['id'] ?>"
                            class="rekomendasi-item flex flex-col lg:flex-row gap-2 lg:gap-3 p-2 rounded-xl no-underline"
                            title="<?= htmlspecialchars($r['title']) ?>">
                            <div class="w-full lg:w-16 aspect-square lg:h-12 lg:aspect-auto rounded-lg overflow-hidden flex-shrink-0 bg-white/[.04] border border-white/[.05]">
                                <img src="<?= htmlspecialchars(music_thumbnail_url($r['thumbnail'])) ?>"
                                    alt="<?= htmlspecialchars($r['title']) ?> thumbnail"
                                    width="96" height="96"
                                    class="rec-thumb-img w-full h-full object-cover transition-transform duration-300"
                                    loading="lazy" decoding="async">
                            </div>
                            <div class="flex-1 min-w-0 flex flex-col justify-center">
                                <div class="text-[11px] font-bold text-gray-300 uppercase tracking-tight leading-snug rec-title-text">
                                    <?= htmlspecialchars($r['title']) ?>
                                </div>
                                <div class="text-[10px] text-gray-500 mt-0.5 truncate"><?= htmlspecialchars($r['artist']) ?></div>
                                <div class="flex items-center gap-1.5 mt-1">
                                    <span class="text-[9px] text-gray-500"><?= number_format($r['views'] ?? 0) ?> views</span>
                                    <span class="text-[8px] px-1.5 py-0.5 rounded bg-white/[.04] border border-white/[.05] text-gray-500 uppercase"><?= $r_lbl ?></span>
                                </div>
                            </div>
                        </a>
                    <?php endwhile; ?>
                </div>
            </div>

        </div>

        <?php if (isset($_SESSION['user_id'])): ?>
            <div id="playlist-modal" class="hidden">
                <div class="absolute inset-0" onclick="document.getElementById('playlist-modal').classList.add('hidden')"></div>
                <div class="relative bg-[#141820] border border-white/[.07] border-t-2 border-t-orange-500 rounded-2xl p-5 sm:p-6 max-w-sm w-full mx-4">
                    <button onclick="document.getElementById('playlist-modal').classList.add('hidden')"
                        class="absolute top-4 right-4 text-gray-600 hover:text-white transition-colors bg-none border-none cursor-pointer">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                    <div class="flex items-center gap-2 mb-5">
                        <i data-lucide="list-music" class="w-4 h-4 text-orange-400"></i>
                        <span class="text-sm font-bold text-white uppercase tracking-wider">Simpan ke Playlist</span>
                    </div>
                    <div class="space-y-1.5 mb-4 max-h-[180px] overflow-y-auto pr-1 no-scrollbar">
                        <?php
                        $stmt_pl = $conn->prepare("SELECT * FROM playlists WHERE user_id = ? ORDER BY id DESC");
                        $stmt_pl->bind_param("i", $_SESSION['user_id']);
                        $stmt_pl->execute();
                        $my_playlists = $stmt_pl->get_result();
                        if ($my_playlists && $my_playlists->num_rows > 0):
                            while ($pl = $my_playlists->fetch_assoc()):
                        ?>
                                <form action="playlist_action.php" method="POST">
                                    <input type="hidden" name="action" value="add_to_playlist">
                                    <input type="hidden" name="music_id" value="<?= $id ?>">
                                    <input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <button type="submit"
                                        class="w-full text-left px-4 py-2.5 rounded-xl bg-white/[.04] border border-white/[.06] text-sm text-gray-400 hover:bg-orange-500/10 hover:border-orange-500/25 hover:text-orange-400 transition-all cursor-pointer font-medium">
                                        <?= htmlspecialchars($pl['name']) ?>
                                    </button>
                                </form>
                            <?php endwhile;
                        else: ?>
                            <p class="text-[11px] text-gray-600 text-center py-3">Belum ada playlist.</p>
                        <?php endif; ?>
                    </div>
                    <div class="border-t border-white/[.05] pt-4">
                        <form action="playlist_action.php" method="POST" class="flex gap-2">
                            <input type="hidden" name="action" value="create_playlist">
                            <input type="hidden" name="music_id" value="<?= $id ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="text" name="playlist_name"
                                class="flex-1 bg-black/30 border border-white/[.06] rounded-xl px-3 py-2 text-xs text-gray-300 focus:outline-none focus:border-orange-500/40 transition-all min-w-0"
                                placeholder="Nama playlist baru..." required>
                            <button type="submit"
                                class="bg-orange-500 hover:bg-orange-400 text-black text-xs font-black uppercase px-4 py-2 rounded-xl border-none cursor-pointer transition-all flex-shrink-0">
                                Buat
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div id="mini-player" aria-label="Mini Player">
            <div class="mp-seekbar" id="mp-seekbar" onclick="miniSeek(event)" title="Klik untuk seek">
                <div class="mp-seekbar-fill" id="mp-seekbar-fill"></div>
                <div class="mp-seekbar-thumb" id="mp-seekbar-thumb"></div>
            </div>

            <div class="mp-body">
                <div class="mp-track" onclick="toggleMiniPlayer()" title="Buka player penuh">
                    <div class="mp-art" onclick="event.stopPropagation(); window.goBackToLibrary();">
                        <img id="mini-thumbnail" src="<?= htmlspecialchars(music_thumbnail_url($v['thumbnail'])) ?>" alt="<?= htmlspecialchars($v['title']) ?> cover" width="256" height="256" loading="eager" decoding="async">
                        <div class="mp-art-overlay">
                            <i data-lucide="maximize-2" style="width:14px;height:14px;"></i>
                        </div>
                    </div>
                    <div class="mp-meta">
                        <div class="mp-title" id="mini-title"><?= htmlspecialchars($v['title']) ?></div>
                        <div class="mp-artist" id="mini-artist"><?= htmlspecialchars($v['artist'] ?? 'Unknown') ?></div>
                    </div>
                </div>

                <div class="mp-controls">
                    <button class="mp-btn mp-btn-ghost" id="mini-loop-btn" onclick="toggleLoop()" title="Ulang">
                        <i data-lucide="repeat" style="width:15px;height:15px;"></i>
                    </button>
                    <button class="mp-btn mp-btn-ghost" onclick="miniPrev()" id="mp-prev-btn" title="Sebelumnya">
                        <i data-lucide="skip-back" style="width:16px;height:16px;"></i>
                    </button>
                    <button class="mp-btn mp-btn-primary" onclick="miniPlayPause()" id="mini-play-btn" title="Play / Pause">
                        <i data-lucide="play" style="width:18px;height:18px;"></i>
                    </button>
                    <button class="mp-btn mp-btn-ghost" onclick="miniNext()" id="mp-next-btn" title="Berikutnya">
                        <i data-lucide="skip-forward" style="width:16px;height:16px;"></i>
                    </button>
                </div>

                <div class="mp-right">
                    <div class="mp-time">
                        <span id="mini-current-time">0:00</span>
                        <span class="mp-time-sep">/</span>
                        <span id="mini-duration">0:00</span>
                    </div>
                    <button class="mp-btn mp-btn-ghost mp-close" onclick="event.stopPropagation(); closeMiniPlayer()" title="Tutup">
                        <i data-lucide="chevron-down" style="width:16px;height:16px;"></i>
                    </button>
                </div>
            </div>
        </div>

    </main> <?php include '../partials/footer.php'; ?>
    <script src="../assets/js/script.min.js"></script>
    <script>
        window.MEEL_MUSIC_CONFIG = {
            id: <?= $id ?>,
            playlistId: <?= (int)$playlist_id ?>,
            fileSizeBytes: <?= (int)$file_size_bytes ?>,
            nextSongUrl: "<?= $next_song_url ?>",
            title: '<?= htmlspecialchars(addslashes($v['title'])) ?>',
            artist: '<?= htmlspecialchars(addslashes($v['artist'] ?? '')) ?>',
            thumbnail: '<?= htmlspecialchars($v['thumbnail']) ?>',
            thumbnailUrl: '<?= htmlspecialchars(music_thumbnail_url($v['thumbnail'])) ?>',
            filename: '<?= htmlspecialchars($v['filename']) ?>'
        };
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });

        document.body.addEventListener('htmx:afterOnLoad', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });

        window.toggleEqPresetDropdown = function() {
            const options = document.getElementById('eq-preset-options');
            if (!options) return;
            options.classList.toggle('hidden');
        };

        function closeEqPresetDropdownOnOutside(event) {
            const options = document.getElementById('eq-preset-options');
            const button = document.getElementById('eq-preset-button');
            if (!options || !button) return;
            if (!button.contains(event.target) && !options.contains(event.target)) {
                options.classList.add('hidden');
            }
        }

        document.addEventListener('click', closeEqPresetDropdownOnOutside);

        window.selectEqPreset = function(preset) {
            if (!preset) return;
            if (typeof window.setEqPreset === 'function') {
                window.setEqPreset(preset);
            }
            const options = document.getElementById('eq-preset-options');
            if (options) options.classList.add('hidden');
        };
    </script>
    <script src="../assets/js/plyr.min.js" defer></script>
    <script src="../assets/js/sweetalert2.all.min.js" defer></script>
    <script src="../assets/js/player_music.js" defer></script>
</body>

</html>
