<?php
session_name('meel');
session_start();

include '../auth/config.php';
require_once '../modules/core/helpers.php';
require_once '../modules/core/CommentRenderer.php';
require_once '../controllers/api/WatchController.php';

$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = $_SESSION['user_id'] ?? null;

$ctrl = new VideoWatchController($conn, $user_id, $id);
$ctrl->handleRequest();

// Semua variabel template diekstrak dari controller
// EXTR_SKIP mencegah overwrite variabel yang sudah ada di scope
extract($ctrl->getViewData(), EXTR_SKIP);

// Lepas session lock sesegera mungkin
session_write_close();
?><!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="<?= htmlspecialchars($v['title']) ?> — MEeL Video">
    <meta property="og:description" content="Tonton <?= htmlspecialchars($v['title']) ?> di MEeL Video - Streaming HLS dengan kualitas terbaik.">
    <title><?= htmlspecialchars($v['title']) ?> — MEeL Video</title>
    <?php include '../partials/link.php'; ?>
    <link rel="stylesheet" href="../assets/css/plyr.css">
    <link rel="stylesheet" href="../assets/css/video.css">
    <script src="../assets/js/htmx.min.js"></script>
    <script src="../assets/js/hls.js"></script>
</head>

<body class="text-gray-400 min-h-screen">

    <nav class="border-b border-white/[.04] sticky top-0 z-50">
        <div class="w-full px-4 sm:px-5 h-14 flex items-center justify-between gap-3">

            <a href="index.php" class="flex items-center gap-2 flex-shrink-0 bg-white/[.04] px-3 py-2 rounded-xl hover:bg-white/[.08] transition-all" title="MEeL Video">
                <div class="w-7 h-7 bg-red-600 rounded-lg flex items-center justify-center">
                    <i data-lucide="play" class="w-3.5 h-3.5 text-white fill-current"></i>
                </div>
                <span class="text-sm font-bold tracking-tight text-white uppercase">
                    MEeL<span class="text-red-500">Video</span>
                </span>
            </a>

            <div id="navbar-search-wrap" class="flex-1 max-w-md flex items-center gap-2">
                <div class="relative flex-1 group">
                    <i data-lucide="search" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-300 group-focus-within:text-red-500 transition-colors"></i>
                    <input type="text"
                        id="v-search-watch"
                        name="search"
                        placeholder="Cari video lain..."
                        class="w-full bg-white/[.04] border border-white/[.06] rounded-xl py-2 pl-9 pr-4 text-xs focus:outline-none focus:border-red-500/40 transition-all text-gray-300"
                        hx-get="search_video.php?exclude=<?= $id ?>"
                        hx-trigger="keyup[key=='Enter']"
                        hx-target="#recommendation-column"
                        hx-indicator="#search-indicator"
                        autocomplete="off"
                        title="Cari Video">
                    <div id="search-indicator" class="htmx-indicator absolute right-3.5 top-1/2 -translate-y-1/2">
                        <div class="animate-spin h-3 w-3 border-2 border-red-500 border-t-transparent rounded-full"></div>
                    </div>
                </div>
                <button hx-get="search_video.php?exclude=<?= $id ?>"
                    title="Cari"
                    hx-include="#v-search-watch"
                    hx-target="#recommendation-column"
                    hx-indicator="#search-indicator"
                    class="px-3 py-2 bg-white/[.04] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase text-gray-500 hover:text-red-400 hover:border-red-500/30 transition-all flex-shrink-0">
                    Cari
                </button>
            </div>

            <div class="flex items-center gap-3 text-[10px] font-bold uppercase tracking-wider flex-shrink-0">
                <button id="navbar-search-icon-btn"
                    onclick="document.getElementById('mobile-search-overlay').classList.toggle('open')"
                    class="hidden items-center justify-center w-8 h-8 text-gray-500 hover:text-red-400 bg-white/[.04] hover:bg-white/[.08] rounded-xl transition-all"
                    title="Cari"
                    aria-label="Cari video">
                    <i data-lucide="search" class="w-4 h-4"></i>
                </button>
                <?php include '../partials/nav.php'; ?>
            </div>
        </div>
    </nav>

    <div id="mobile-search-overlay">
        <button onclick="document.getElementById('mobile-search-overlay').classList.remove('open')"
            class="text-gray-600 flex-shrink-0" title="Tutup pencarian">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </button>
        <div class="relative flex-1">
            <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-600"></i>
            <input type="text"
                id="v-search-mobile"
                name="search"
                placeholder="Cari video..."
                class="w-full bg-white/[.06] border border-white/[.08] rounded-xl py-2.5 pl-9 pr-4 text-sm focus:outline-none focus:border-red-500/40 text-gray-300"
                hx-get="search_video.php?exclude=<?= $id ?>"
                hx-trigger="keyup[key=='Enter']"
                hx-target="#recommendation-column"
                autocomplete="off">
        </div>
    </div>

    <main id="app-content-grid" class="w-full pt-4 sm:pt-8 pb-20 flex flex-col lg:flex-row gap-4">
        <div id="left-column" class="flex-1 space-y-4 sm:space-y-5 px-4 sm:px-5">
            <div id="video-glow-container" class="relative w-full">
                <canvas id="video-glow-canvas" class="hidden sm:block"></canvas>
                <div id="main-video-wrapper" class="relative bg-black rounded-none sm:rounded-none overflow-hidden border-0 shadow-2xl w-full" style="aspect-ratio: 16/9;">
                    <video id="main-video" playsinline controls preload="auto"
                        data-poster="upload/thumbnail/<?= htmlspecialchars($v['thumbnail']) ?>"
                        data-src="<?= htmlspecialchars($video_src) ?>"
                        aria-label="Pemutar video: <?= htmlspecialchars($v['title']) ?>"
                        data-ishls="<?= $is_hls ? 'true' : 'false' ?>"
                        data-vtt="<?= htmlspecialchars($vtt_src ?? '') ?>"
                        class="w-full block">
                        <?php if (!$is_hls): ?>
                            <source src="<?= $video_src ?>" type="video/mp4">
                        <?php endif; ?>
                        <?php if (!empty($vtt_src)): ?>
                            <track kind="metadata" src="<?= $vtt_src ?>" default>
                        <?php endif; ?>
                    </video>
                    <div id="resume-modal" class="hidden">
                        <div class="bg-[#141820] border border-red-600/25 border-t-2 border-t-red-600 rounded-2xl text-center">
                            <div class="font-black text-white uppercase tracking-wider mb-2">Lanjutkan Sesi?</div>
                            <div class="text-[10px] text-gray-400 uppercase tracking-widest mb-1">
                                Menit ke‑ <span id="resume-time" class="text-red-400 font-mono">0:00</span>
                            </div>
                            <p id="resume-countdown" class="text-[10px] text-gray-300 italic mb-5">Otomatis ulang dalam 15s...</p>
                            <div class="flex gap-2">
                                <button id="btn-resume"
                                    class="flex-1 bg-red-600 hover:bg-red-500 text-white font-black uppercase tracking-wider rounded-xl transition-all border-none cursor-pointer"
                                    title="Lanjutkan menonton dari posisi terakhir">
                                    Lanjut
                                </button>
                                <button id="btn-restart"
                                    class="flex-1 bg-white/5 hover:bg-white/10 text-gray-400 font-black uppercase tracking-wider rounded-xl border border-white/10 cursor-pointer transition-all"
                                    title="Mulai ulang dari awal">
                                    Ulang
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="watch-details-wrapper" class="space-y-4 sm:space-y-5">
                <div id="video-info" class="bg-[#0d1017] border border-white/[.06] rounded-xl sm:rounded-2xl p-4 sm:p-6 flex flex-col gap-4">
                    <div class="video-title w-full text-2xl font-bold text-white" id="main-video-title" title="<?= htmlspecialchars($v['title']) ?>"><?= htmlspecialchars($v['title']) ?></div>
                    <?php
                    $can_edit = $is_logged_in && (
                        ($_SESSION['role'] ?? '') === 'admin' ||
                        (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)($v['user_id'] ?? -1))
                    );
                    if ($can_edit): ?>
                        <div class="flex gap-2">
                            <a href="../admin/edit-video.php?id=<?= $id ?>" title="Edit Video" class="flex items-center gap-1.5 px-3 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all bg-red-600/10 border border-red-600/20 text-red-400 hover:bg-red-600 hover:text-white no-underline">
                                <i data-lucide="edit" class="w-3.5 h-3.5"></i> Edit Video
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="h-px bg-white/[.04]"></div>

                <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center sm:justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <a href="../profile/?u=<?= urlencode($v['uploader']) ?>"
                            class="w-10 h-10 rounded-full overflow-hidden border border-red-600/25 flex-shrink-0 block"
                            aria-label="Lihat profil <?= htmlspecialchars($v['uploader']) ?>">
                            <?php if (!empty($v['uploader_pfp'])): ?>
                                <img src="../profile/upload/<?= htmlspecialchars($v['uploader_pfp']) ?>" class="w-full h-full object-cover" alt="Foto profil <?= htmlspecialchars($v['uploader']) ?>">
                            <?php else: ?>
                                <div class="w-full h-full bg-gradient-to-br from-red-600 to-red-900 flex items-center justify-center text-white text-sm font-bold">
                                    <?= strtoupper(substr($v['uploader'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                        </a>
                        <div>
                            <a href="../profile/?u=<?= urlencode($v['uploader']) ?>"
                                id="main-video-uploader"
                                class="text-[10px] font-black uppercase tracking-widest text-red-400 hover:underline block leading-tight">
                                <?= htmlspecialchars($v['uploader']) ?>
                            </a>
                            <div class="text-[10px] text-gray-300 mt-0.5">
                                <?= number_format($v['views'] ?? 0) ?> tayangan &nbsp;•&nbsp; <?= time_ago($v['upload_date']) ?>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <?php if (isset($_SESSION['username'])): ?>
                            <a href="../transcode.php?id=<?= $id ?>"
                                class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all bg-gray-800/50 border border-white/[.05] text-gray-500 hover:bg-gray-700 hover:text-gray-300 no-underline"
                                title="Download audio saja">
                                <i data-lucide="download" class="w-3.5 h-3.5"></i> Audio
                            </a>
                            <div id="like-dislike-container" class="flex items-center gap-2">
                                <button
                                    hx-post="../controllers/api/like.php" hx-target="#like-dislike-container" hx-swap="outerHTML"
                                    hx-vals='{"id":"<?= $id ?>","media_type":"video","type":"like","csrf_token":"<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"}'
                                    title="Suka video"
                                    class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer
                                   <?= $user_interaction === 'like'
                                        ? 'bg-red-600/15 border-red-600/30 text-red-400'
                                        : 'bg-gray-800/50 border-white/[.05] text-gray-500 hover:bg-gray-700 hover:text-gray-300' ?>">
                                    <i data-lucide="thumbs-up" class="w-3.5 h-3.5 <?= $user_interaction === 'like' ? 'fill-current' : '' ?>"></i>
                                    Like<?= ($v['likes'] ?? 0) > 0 ? " <span class='tabular-nums ml-0.5'>{$v['likes']}</span>" : '' ?>
                                </button>
                                <button
                                    hx-post="../controllers/api/like.php" hx-target="#like-dislike-container" hx-swap="outerHTML"
                                    hx-vals='{"id":"<?= $id ?>","media_type":"video","type":"dislike","csrf_token":"<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"}'
                                    title="Tidak suka video"
                                    class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer
                                   <?= $user_interaction === 'dislike'
                                        ? 'bg-white/10 border-white/15 text-white'
                                        : 'bg-gray-800/50 border-white/[.05] text-gray-500 hover:bg-gray-700 hover:text-gray-300' ?>">
                                    <i data-lucide="thumbs-down" class="w-3.5 h-3.5 <?= $user_interaction === 'dislike' ? 'fill-current' : '' ?>"></i>
                                    <?= ($v['dislikes'] ?? 0) > 0 ? "<span class='tabular-nums'>{$v['dislikes']}</span>" : '' ?>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div> <?php if (!empty($v['description'])): ?>
                    <div class="bg-[#0d1017] border border-white/[.06] rounded-xl sm:rounded-2xl p-4 sm:p-6" id="desc-wrapper">
                        <div class="text-[10px] font-bold uppercase tracking-[.25em] text-gray-300 mb-3 flex items-center gap-2">
                            <i data-lucide="align-left" class="w-3.5 h-3.5 text-red-500"></i> Deskripsi
                        </div>
                        <div class="relative">
                            <p id="desc-text" class="text-sm text-gray-400 leading-relaxed break-words whitespace-pre-wrap line-clamp-5 transition-all duration-300"><?= htmlspecialchars($v['description']) ?></p>
                        </div>                                <button id="btn-read-more" onclick="toggleDescription()" class="mt-3 text-[10px] font-bold uppercase tracking-wider text-red-500 hover:text-red-400 transition-colors cursor-pointer border-none bg-transparent p-0 hidden" title="Tampilkan deskripsi lengkap">
                            Selengkapnya
                        </button>
                    </div>
                    <script>
                        requestAnimationFrame(function() {
                            var d = document.getElementById('desc-text'),
                                b = document.getElementById('btn-read-more');
                            if (d && b && d.scrollHeight > d.clientHeight) b.classList.remove('hidden');
                        });
                    </script>

                <?php endif; ?>
                <?php if ($is_logged_in): ?>
                    <section class="bg-[#0d1017] border border-white/[.06] rounded-xl sm:rounded-2xl overflow-hidden" id="comment-section">
                        <div class="px-4 sm:px-6 py-4 border-b border-white/[.04] bg-black/10 flex items-center gap-2">
                            <i data-lucide="message-square" class="w-3.5 h-3.5 text-red-500"></i>
                            <span class="text-[10px] font-bold uppercase tracking-[.25em] text-gray-300">Komentar</span>
                        </div>
                        <div class="p-4 sm:p-6">
                            <form action="watch.php?id=<?= $id ?>" method="post" class="mb-6">                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <textarea name="comments"
                                            class="w-full bg-black/25 border border-white/[.06] rounded-xl p-3 sm:p-4 text-sm text-gray-300 focus:outline-none focus:border-red-500/40 min-h-[80px] resize-y transition-all"
                                            placeholder="Tulis komentar..." required></textarea>
                                <div class="flex justify-end mt-2">
                                    <button name="send"
                                        class="bg-red-600 hover:bg-red-500 text-white text-[10px] font-black uppercase tracking-wider px-5 py-2.5 rounded-xl transition-all border-none cursor-pointer"
                                        title="Kirim komentar">
                                        Kirim
                                    </button>
                                </div>
                            </form>

                            <div class="space-y-1 max-h-[500px] overflow-y-auto pr-1">
                                <?php
                                if (empty($comments_grouped)) {
                                    echo "<div class='py-10 text-center text-[10px] text-gray-300 uppercase tracking-widest'>Belum ada komentar.</div>";
                                } else {
                                    render_comments(0, $comments_grouped, 0, 'video');
                                }
                                ?>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>
            </div>
        </div>

        <div id="recommendation-wrapper" class="w-full lg:w-80 flex-shrink-0 space-y-4 px-4 sm:px-5 lg:px-0">
            <div class="text-[9px] text-gray-300 uppercase tracking-[.25em] px-1 flex items-center gap-2" id="rec-title">
                <i data-lucide="play-circle" class="w-3 h-3 text-red-500"></i>
                Video Lainnya
            </div>
            <div id="recommendation-column" class="grid grid-cols-2 lg:grid-cols-1 gap-3 lg:gap-0 lg:space-y-1">
                <?php while ($r = $rekom->fetch_assoc()): ?>
                    <a href="watch.php?id=<?= $r['id'] ?>"
                        class="rekomendasi-item flex flex-col lg:flex-row gap-2 lg:gap-3 px-2 py-2.5 rounded-xl no-underline"
                        title="<?= htmlspecialchars($r['title']) ?>">
                        <div class="w-full lg:w-32 aspect-video lg:h-20 lg:aspect-auto rounded-xl overflow-hidden flex-shrink-0 bg-white/[.04] border border-white/[.05]">
                            <img src="upload/thumbnail/<?= htmlspecialchars($r['thumbnail']) ?>"
                                class="rec-thumb-img w-full h-full object-cover transition-transform duration-300" loading="lazy" alt="Thumbnail video <?= htmlspecialchars($r['title']) ?>">
                        </div>
                        <div class="flex-1 min-w-0 flex flex-col justify-center">
                            <div class="text-[11px] sm:text-[12px] font-bold text-gray-400 uppercase tracking-tight leading-snug rec-title-text">
                                <?= htmlspecialchars($r['title']) ?>
                            </div>
                            <div class="text-[9px] text-gray-300 mt-1"><?= number_format($r['views'] ?? 0) ?> views</div>
                            <?php if (!empty($r['uploader'])): ?>
                                <div class="text-[9px] font-bold text-red-500/60 uppercase tracking-wider mt-0.5 truncate">
                                    <?= htmlspecialchars($r['uploader']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endwhile; ?>
            </div>
        </div>

    </main>

    <?php include '../partials/footer.php'; ?>

    <script src="../assets/js/plyr.min.js"></script>
    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <script src="../assets/js/script.min.js"></script>

    <script>
        window.playerConfig = <?= json_encode([
                                    'videoSrc' => $video_src,
                                    'isHls' => (bool)$is_hls,
                                    'vttSrc' => $vtt_src ?? '',
                                    'id' => (int)$id,
                                    'title' => $v['title'] ?? '',
                                    'uploader' => $v['uploader'] ?? ''
                                ]); ?>;
    </script>
    <script src="../assets/js/player_video.js"></script>

    <script>
        lucide.createIcons();
        document.body.addEventListener('htmx:afterOnLoad', function() {
            lucide.createIcons();
        });
    </script>
</body>

</html>
