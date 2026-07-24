<?php
require_once 'modules/core/helpers.php';
include 'auth/config.php';
$back_url = 'index.php';

if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref  = $_SERVER['HTTP_REFERER'];
    $host = $_SERVER['HTTP_HOST'];
    if (parse_url($ref, PHP_URL_HOST) === $host) {
        $ref_path        = parse_url($ref, PHP_URL_PATH);
        $excluded_pages  = ['profile_edit.php', 'index.php'];
        $should_exclude  = false;
        foreach ($excluded_pages as $page) {
            if (strpos($ref_path, $page) !== false) {
                $should_exclude = true;
                break;
            }
        }
        if (!$should_exclude) $back_url = $ref;
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Panduan penggunaan fitur video & musik MEeL.">
    <meta property="og:title" content="MEeL | Panduan Penggunaan">
    <meta property="og:description" content="Panduan lengkap penggunaan fitur video, musik, dan navigasi di platform MEeL.">
    <meta property="og:image" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/assets/MEeL.png">
    <meta property="og:url" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <title>MEeL | Panduan Penggunaan</title>
    <link rel="manifest" href="assets/manifest.json">
    <link rel="icon" type="image/png" href="assets/MEeL.png">
    <link rel="stylesheet" href="assets/css/introduction.css">
    <link href="assets/css/tailwind.min.css" rel="stylesheet">
    <script src="assets/js/lucide.js"></script>
</head>

<body>

    <!-- ── READING PROGRESS ── -->
    <div id="reading-progress"></div>

    <!-- ── MOBILE HAMBURGER ── -->
    <button id="hamburger" onclick="toggleSidebar()" aria-label="Toggle sidebar" title="Buka menu samping">
        <i data-lucide="menu"></i>
    </button>

    <!-- ── SIDEBAR OVERLAY (mobile) ── -->
    <div id="sidebar-overlay" onclick="toggleSidebar()"></div>

    <!-- ── SIDEBAR ── -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="<?= htmlspecialchars($back_url) ?>" class="back-link">
                <div class="back-icon">
                    <i data-lucide="home" style="width:16px;height:16px;color:#6b7280;"></i>
                </div>
                <div>
                    <div class="brand-title">MEeL <span>Guide</span></div>
                    <div class="brand-sub">Pusat Bantuan</div>
                </div>
            </a>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-label">Panduan</div>

            <button class="nav-btn active-video" id="nav-video" onclick="showGuide('video', this)" title="Panduan fitur Video">
                <div class="nav-btn-icon">
                    <i data-lucide="play-square" style="width:15px;height:15px;color:#ef4444;"></i>
                </div>
                <span class="nav-btn-label">Video</span>
            </button>

            <button class="nav-btn" id="nav-music" onclick="showGuide('music', this)" title="Panduan fitur Musik">
                <div class="nav-btn-icon">
                    <i data-lucide="music-2" style="width:15px;height:15px;color:#f97316;"></i>
                </div>
                <span class="nav-btn-label">Musik</span>
            </button>
        </nav>

        <div class="sidebar-footer">
            <div class="version-chip">
                <i data-lucide="book-open" style="width:10px;height:10px;"></i>
                MEeL Docs
            </div>
        </div>
    </aside>

    <!-- ── MAIN ── -->
    <main class="main">
        <div class="main-inner">

            <!-- ══ VIDEO GUIDE ══ -->
            <div id="guide-video" class="guide-section active">
                <div class="guide-header">
                    <div class="guide-eyebrow">Dokumentasi · Fitur</div>
                    <h1 class="guide-title">Panduan <span class="red">Video</span></h1>
                    <p class="guide-desc">Kenali cara bernavigasi dan menggunakan fitur pemutar video MEeL.</p>
                </div>

                <!-- Halaman Index -->
                <div class="content-card">
                    <div class="content-card-header">
                        <div class="card-header-icon" style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.2);">
                            <i data-lucide="layout-grid" style="width:14px;height:14px;color:#ef4444;"></i>
                        </div>
                        <div>
                            <div class="card-header-title">Halaman Index</div>
                            <div class="card-header-sub">Tampilan daftar & navigasi utama</div>
                        </div>
                    </div>
                    <div class="screenshot-wrap">
                        <img src="assets/img/video0.webp" alt="Index Video" class="screenshot-img"
                            onclick="openLightbox(this.src)" loading="lazy" title="Klik untuk memperbesar">
                    </div>
                    <div class="annotation-list">
                        <?php
                        $video_index = [
                            ['Menu HUB',           'Kembali ke halaman utama MEeL',                       '#ef4444'],
                            ['Search',             'Cari video berdasarkan judul atau kata kunci',         '#f97316'],
                            ['Navbar',             'Berpindah antar halaman — Video, Books, FikaAI',       '#3b82f6'],
                            ['Daftar Video',       'Grid video yang tersedia di library',                  '#a78bfa'],
                            ['Muat Lebih Banyak',  'Memuat batch video berikutnya secara lazy',            '#22c55e'],
                        ];
                        foreach ($video_index as $a): ?>
                            <div class="annotation-item">
                                <div class="annotation-dot" style="background:<?= $a[2] ?>;box-shadow:0 0 5px <?= $a[2] ?>;"></div>
                                <div class="annotation-key"><?= $a[0] ?></div>
                                <div class="annotation-val"><?= $a[1] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Halaman Watch -->
                <div class="content-card">
                    <div class="content-card-header">
                        <div class="card-header-icon" style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.2);">
                            <i data-lucide="play-circle" style="width:14px;height:14px;color:#ef4444;"></i>
                        </div>
                        <div>
                            <div class="card-header-title">Halaman Watch</div>
                            <div class="card-header-sub">Pemutar video dengan Plyr HLS</div>
                        </div>
                    </div>
                    <div class="screenshot-wrap">
                        <img src="assets/img/video1.webp" alt="Watch Video" class="screenshot-img"
                            onclick="openLightbox(this.src)" loading="lazy" title="Klik untuk memperbesar">
                        <img src="assets/img/video2.webp" alt="Watch Video" class="screenshot-img"
                            onclick="openLightbox(this.src)" loading="lazy" title="Klik untuk memperbesar">
                        <img src="assets/img/video3.webp" alt="Watch Video" class="screenshot-img"
                            onclick="openLightbox(this.src)" loading="lazy" title="Klik untuk memperbesar">
                    </div>
                    <div class="annotation-list">
                        <?php
                        $video_watch = [
                            ['Kembali ke Index',   'Tombol navigasi ke halaman daftar video',    '#ef4444'],
                            ['Video Player',       'Pemutar video HLS adaptif berbasis Plyr',    '#f97316'],
                            ['Search',             'Cari video lain tanpa keluar dari halaman',  '#3b82f6'],
                        ];
                        foreach ($video_watch as $a): ?>
                            <div class="annotation-item">
                                <div class="annotation-dot" style="background:<?= $a[2] ?>;box-shadow:0 0 5px <?= $a[2] ?>;"></div>
                                <div class="annotation-key"><?= $a[0] ?></div>
                                <div class="annotation-val"><?= $a[1] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Keyboard shortcuts -->
                <div class="content-card">
                    <div class="content-card-header">
                        <div class="card-header-icon" style="background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);">
                            <i data-lucide="keyboard" style="width:14px;height:14px;color:#60a5fa;"></i>
                        </div>
                        <div>
                            <div class="card-header-title">Kontrol Keyboard</div>
                            <div class="card-header-sub">Shortcut untuk pemutar video</div>
                        </div>
                    </div>
                    <div class="shortcuts-grid">
                        <?php
                        $shortcuts_video = [
                            ['0–9',       'Loncat ke 0–90% durasi'],
                            ['Space / K', 'Play / Pause'],
                            ['←',         'Mundur (seekTime)'],
                            ['→',         'Maju (seekTime)'],
                            ['↑',         'Volume naik'],
                            ['↓',         'Volume turun'],
                            ['M',         'Mute / Unmute'],
                            ['F',         'Layar penuh'],
                            ['C',         'Toggle caption'],
                            ['L',         'Toggle loop'],
                        ];
                        foreach ($shortcuts_video as $s): ?>
                            <div class="shortcut-item">
                                <span class="kbd"><?= $s[0] ?></span>
                                <span class="shortcut-desc"><?= $s[1] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- ══ MUSIC GUIDE ══ -->
            <div id="guide-music" class="guide-section">
                <div class="guide-header">
                    <div class="guide-eyebrow">Dokumentasi · Fitur</div>
                    <h1 class="guide-title">Panduan <span class="orange">Musik</span></h1>
                    <p class="guide-desc">Kenali cara bernavigasi dan menggunakan fitur pemutar musik MEeL.</p>
                </div>

                <!-- Halaman Index -->
                <div class="content-card">
                    <div class="content-card-header">
                        <div class="card-header-icon" style="background:rgba(249,115,22,.12);border:1px solid rgba(249,115,22,.2);">
                            <i data-lucide="layout-grid" style="width:14px;height:14px;color:#f97316;"></i>
                        </div>
                        <div>
                            <div class="card-header-title">Halaman Index</div>
                            <div class="card-header-sub">Tampilan library & navigasi musik</div>
                        </div>
                    </div>
                    <div class="screenshot-wrap">
                        <img src="assets/img/music0.webp" alt="Index Musik" class="screenshot-img"
                            onclick="openLightbox(this.src)" loading="lazy" title="Klik untuk memperbesar">
                        <img src="assets/img/music2.webp" alt="Index Musik" class="screenshot-img"
                            onclick="openLightbox(this.src)" loading="lazy" title="Klik untuk memperbesar">
                    </div>
                    <div class="annotation-list">
                        <?php
                        $music_index = [
                            ['Menu HUB',        'Kembali ke halaman utama MEeL',                       '#f97316'],
                            ['Search',          'Cari lagu berdasarkan judul, artis, atau album',      '#ef4444'],
                            ['Navbar',          'Berpindah antar halaman — Video, Books, FikaAI',      '#3b82f6'],
                            ['Daftar Musik',    'Grid lagu yang tersedia di music library',            '#a78bfa'],
                        ];
                        foreach ($music_index as $a): ?>
                            <div class="annotation-item">
                                <div class="annotation-dot" style="background:<?= $a[2] ?>;box-shadow:0 0 5px <?= $a[2] ?>;"></div>
                                <div class="annotation-key"><?= $a[0] ?></div>
                                <div class="annotation-val"><?= $a[1] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Halaman Watch -->
                <div class="content-card">
                    <div class="content-card-header">
                        <div class="card-header-icon" style="background:rgba(249,115,22,.12);border:1px solid rgba(249,115,22,.2);">
                            <i data-lucide="headphones" style="width:14px;height:14px;color:#f97316;"></i>
                        </div>
                        <div>
                            <div class="card-header-title">Halaman Watch</div>
                            <div class="card-header-sub">Pemutar musik dengan Plyr</div>
                        </div>
                    </div>
                    <div class="screenshot-wrap">
                        <img src="assets/img/music1.webp" alt="Watch Musik" class="screenshot-img"
                            onclick="openLightbox(this.src)" loading="lazy" title="Klik untuk memperbesar">
                    </div>
                    <div class="annotation-list">
                        <?php
                        $music_watch = [
                            ['Kembali ke Index',  'Tombol navigasi ke halaman library musik',    '#f97316'],
                            ['Music Player',      'Pemutar audio Opus berbasis Plyr',             '#ef4444'],
                            ['Search',            'Cari lagu lain tanpa keluar dari halaman',     '#3b82f6'],
                        ];
                        foreach ($music_watch as $a): ?>
                            <div class="annotation-item">
                                <div class="annotation-dot" style="background:<?= $a[2] ?>;box-shadow:0 0 5px <?= $a[2] ?>;"></div>
                                <div class="annotation-key"><?= $a[0] ?></div>
                                <div class="annotation-val"><?= $a[1] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Keyboard shortcuts -->
                <div class="content-card">
                    <div class="content-card-header">
                        <div class="card-header-icon" style="background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);">
                            <i data-lucide="keyboard" style="width:14px;height:14px;color:#60a5fa;"></i>
                        </div>
                        <div>
                            <div class="card-header-title">Kontrol Keyboard</div>
                            <div class="card-header-sub">Shortcut untuk pemutar musik</div>
                        </div>
                    </div>
                    <div class="shortcuts-grid">
                        <?php
                        $shortcuts_music = [
                            ['0–9',       'Loncat ke 0–90% durasi'],
                            ['Space / K', 'Play / Pause'],
                            ['←',         'Mundur (seekTime)'],
                            ['→',         'Maju (seekTime)'],
                            ['↑',         'Volume naik'],
                            ['↓',         'Volume turun'],
                            ['M',         'Mute / Unmute'],
                            ['L',         'Toggle loop'],
                        ];
                        foreach ($shortcuts_music as $s): ?>
                            <div class="shortcut-item">
                                <span class="kbd"><?= $s[0] ?></span>
                                <span class="shortcut-desc"><?= $s[1] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div><!-- /main-inner -->
    </main>

    <!-- ── LIGHTBOX ── -->
    <div id="lightbox" onclick="closeLightbox()" title="Klik untuk menutup">
        <div id="lightbox-close" onclick="closeLightbox()" title="Tutup">
            <i data-lucide="x" style="width:14px;height:14px;color:#9ca3af;"></i>
        </div>
        <img id="lightbox-img" src="" alt="Preview" onclick="event.stopPropagation()">
    </div>

    <script>
        lucide.createIcons();

        // ── Mobile sidebar toggle ──
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            const hamburger = document.getElementById('hamburger');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('open');
            hamburger.classList.toggle('open');
        }

        // ── Close sidebar on nav click (mobile) ──
        document.querySelectorAll('.nav-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    toggleSidebar();
                }
            });
        });

        // ── Reading progress bar ──
        const mainEl = document.querySelector('.main');
        const progressBar = document.getElementById('reading-progress');

        function updateProgress() {
            let scrollTop, scrollHeight, clientHeight;

            if (window.innerWidth <= 768) {
                // Mobile: body/html is the scroll container
                scrollTop = window.scrollY || document.documentElement.scrollTop;
                scrollHeight = document.documentElement.scrollHeight;
                clientHeight = window.innerHeight;
            } else if (mainEl) {
                // Desktop: .main is the scroll container
                scrollTop = mainEl.scrollTop;
                scrollHeight = mainEl.scrollHeight;
                clientHeight = mainEl.clientHeight;
            } else {
                return;
            }

            const maxScroll = scrollHeight - clientHeight;
            if (maxScroll > 0) {
                progressBar.style.width = ((scrollTop / maxScroll) * 100) + '%';
            } else {
                progressBar.style.width = '0%';
            }
        }

        // Throttled scroll listener
        let ticking = false;
        function onScroll() {
            if (!ticking) {
                requestAnimationFrame(function() {
                    updateProgress();
                    ticking = false;
                });
                ticking = true;
            }
        }

        if (mainEl) {
            mainEl.addEventListener('scroll', onScroll);
        }
        window.addEventListener('scroll', onScroll);
        window.addEventListener('resize', updateProgress);
        setTimeout(updateProgress, 100);

        function showGuide(id, btn) {
            document.querySelectorAll('.guide-section').forEach(s => s.classList.remove('active'));
            document.querySelectorAll('.nav-btn').forEach(b => {
                b.className = b.className.replace(/\bactive-\S+/g, '').trim() || 'nav-btn';
                if (!b.classList.contains('nav-btn')) b.classList.add('nav-btn');
            });
            document.getElementById('guide-' + id).classList.add('active');
            btn.classList.add('active-' + id);
        }

        function openLightbox(src) {
            const lb = document.getElementById('lightbox');
            const img = document.getElementById('lightbox-img');
            img.src = src;
            lb.classList.add('open');
        }

        function closeLightbox() {
            document.getElementById('lightbox').classList.remove('open');
        }

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeLightbox();
        });
    </script>
</body>

</html>