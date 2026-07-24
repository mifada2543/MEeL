<?php
require_once 'modules/core/helpers.php';
session_name('meel');
session_start();
include 'auth/config.php';
require_once 'modules/media/MediaLibrary.php';

$is_logged_in = isset($_SESSION['user_id']);

$library = new MediaLibrary($conn);
$counts  = $library->getCounts();
?>
<!DOCTYPE html>
<html lang="id">

<head>
<?php
    $_META_TITLE = 'MEeL | Media Hub';
    $_META_DESC  = 'MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.';
    include 'partials/head.php';
?>
    <link rel="stylesheet" href="assets/css/index(hub).css">
    <link href="assets/css/tailwind.min.css" rel="stylesheet">
    <script src="assets/js/lucide.js"></script>
    <script src="assets/js/sweetalert2.all.min.js"></script>
    <script src="assets/js/script.min.js"></script>
</head>

<body class="text-gray-300 min-h-screen" style="background:#05070c">

    <!-- NAVBAR -->
    <?php include 'partials/navbar.php'; ?>

    <main class="relative z-10 max-w-6xl mx-auto px-6 pt-32 pb-20 flex flex-col items-center">

        <!-- HERO -->
        <div class="text-center mb-20">
            <div class="inline-block mb-6">
                <img onclick="window.location.href='arcade/'" src="assets/MEeL.png" class="w-14 h-14 object-contain mx-auto opacity-80 hover:opacity-100 transition" alt="MEeL" title="MEeL Arcade">
            </div>
            <div class="station-id mb-5">Local Media Station</div>
            <h1 class="hero-title">MEeL <span class="accent">HUB</span></h1>
            <p class="text-xs text-gray-400 mt-4 tracking-[.25em] uppercase">Streaming &amp; Archive Platform</p>
        </div>

        <!-- MEDIA CARDS -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 w-full mb-20">

            <!-- MUSIC (diperbesar di tengah) -->
            <div class="media-card card-music flex flex-col gap-4 md:h-64"
                onclick="window.location.href='music/index.php'"
                title="MEeL Music">
                <div class="flex items-start justify-between">
                    <div class="card-icon-wrap">
                        <i data-lucide="music" class="w-5 h-5" style="color:#f97316"></i>
                    </div>
                    <div class="text-right">
                        <div class="card-count" style="color:#f97316"><?= $counts['music'] ?></div>
                        <div class="card-label">Tracks</div>
                    </div>
                </div>
                <div class="mt-auto">
                    <div class="card-name">MUSIC</div>
                    <div class="card-desc">Audio tinggi dengan kualitas terbaik.</div>
                </div>
                <div class="flex justify-end">
                    <div class="card-arrow">
                        <i data-lucide="arrow-right" class="w-4 h-4" style="color:#9ca3af"></i>
                    </div>
                </div>
            </div>

            <!-- VIDEO -->
            <div class="media-card card-video flex flex-col gap-4 md:h-64"
                onclick="window.location.href='video/index.php'"
                title="MEeL Video" hx-boost="true">
                <div class="flex items-start justify-between">
                    <div class="card-icon-wrap">
                        <i data-lucide="play" class="w-5 h-5" style="color:#dc2626"></i>
                    </div>
                    <div class="text-right">
                        <div class="card-count" style="color:#dc2626"><?= $counts['video'] ?></div>
                        <div class="card-label">Clips</div>
                    </div>
                </div>
                <div class="mt-auto">
                    <div class="card-name">VIDEO</div>
                    <div class="card-desc">Streaming lokal koleksi video.</div>
                </div>
                <div class="flex justify-end">
                    <div class="card-arrow">
                        <i data-lucide="arrow-right" class="w-4 h-4" style="color:#9ca3af"></i>
                    </div>
                </div>
            </div>

            <!-- BOOKS -->
            <?php if ($is_logged_in): ?>
                <div class="media-card card-books flex flex-col gap-4 md:h-64"
                    onclick="window.location.href='books/index.php'"
                    title="MEeL Books">
                    <div class="flex items-start justify-between">
                        <div class="card-icon-wrap">
                            <i data-lucide="book-open" class="w-5 h-5" style="color:#22c55e"></i>
                        </div>
                        <div class="text-right">
                            <div class="card-count" style="color:#22c55e"><?= $counts['books'] ?></div>
                            <div class="card-label">Books</div>
                        </div>
                    </div>
                    <div class="mt-auto">
                        <div class="card-name">BOOKS</div>
                        <div class="card-desc">Komik dan buku digital.</div>
                    </div>
                    <div class="flex justify-end">
                        <div class="card-arrow">
                            <i data-lucide="arrow-right" class="w-4 h-4" style="color:#9ca3af"></i>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- BOTTOM LINKS -->
        <div class="flex flex-wrap items-center justify-center gap-3">
            <?php if ($is_logged_in && isset($_SESSION['role'])): ?>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="admin/index.php" class="bottom-link" title="Panel Admin untuk mengelola konten dan pengguna">
                        <i data-lucide="settings" class="w-3 h-3"></i> Admin Panel
                    </a>
                    <a href="upload_advanced.php" class="bottom-link" title="Unggah media baru ke platform">
                        <i data-lucide="upload-cloud" class="w-3 h-3"></i> Upload Media
                    </a>
                <?php endif; ?>
                <?php if (in_array($_SESSION['role'], ['member', 'admin'])): ?>
                    <a href="drive/index.php" class="bottom-link" title="Akses drive Anda untuk mengelola file dan dokumen">
                        <i data-lucide="hard-drive" class="w-3 h-3"></i> Drive
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            <a href="update.php" class="bottom-link" title="Lihat perubahan terbaru dan pembaruan platform">
                <i data-lucide="radio" class="w-3 h-3"></i> Changelog
            </a>
            <a href="docs/index.html" class="bottom-link" title="Lihat dokumentasi platform">
                <i data-lucide="book-open" class="w-3 h-3"></i> Documentation
            </a>
        </div>

        <!-- MODE SEHAT -->
        <div class="mt-10 flex items-center gap-3">
            <span class="text-[10px] text-gray-200 uppercase tracking-widest">Mode 20-20-20</span>
            <button id="healthToggle"
                class="px-3 py-1 rounded-full text-[10px] font-bold border border-white/5 text-gray-300 hover:text-white transition-all"
                title="Mode Sehat"
                aria-label="Aktifkan atau nonaktifkan mode sehat 20-20-20">
                OFF
            </button>
        </div>
        <p class="text-[9px] text-gray-300 tracking-[0.6em] uppercase mt-8" onclick="window.location.href='index.html'">MEeL • 2025</p>
    </main>

    <!-- DEMO TOP BANNER -->
    <div id="demoBanner" class="demo-banner" role="alert" aria-label="Pemberitahuan website demo">
        <div class="demo-banner-inner">
            <div class="demo-banner-left">
                <span class="demo-badge">⚠️ DEMO</span>
                <span class="demo-banner-text">
                    <strong>Website Demo</strong> — data, konten, dan pengguna di sini bersifat <u title="diperuntukan untuk penggunaan pribadi">tidak nyata</u>. Hanya untuk keperluan showcase &amp; uji coba.
                </span>
            </div>
            <button id="demoBannerClose" class="demo-banner-close" title="Tutup banner" aria-label="Tutup pemberitahuan">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
    </div>

    <script>
        lucide.createIcons();

        // ── BANNER LOGIC ──
        (function() {
            const banner = document.getElementById('demoBanner');
            const closeBtn = document.getElementById('demoBannerClose');

            // Selalu tampilkan banner setiap kali halaman di-refresh
            if (banner) {
                // Ukur tinggi aktual banner (menangani text wrap di layar kecil)
                banner.style.visibility = 'hidden';
                banner.style.display = 'block';
                const h = banner.scrollHeight;
                document.body.style.setProperty('--demo-banner-h', h + 'px');
                banner.style.visibility = '';
                banner.style.display = '';

                document.body.classList.add('demo-banner-active');
                requestAnimationFrame(() => {
                    banner.classList.add('demo-banner-visible');
                });
            }

            if (closeBtn && banner) {
                closeBtn.addEventListener('click', function() {
                    // Kembalikan navbar ke posisi semula
                    document.body.classList.remove('demo-banner-active');
                    banner.classList.remove('demo-banner-visible');
                    banner.classList.add('demo-banner-hiding');
                    // Hanya sembunyikan untuk sesi ini — saat refresh akan muncul lagi
                    setTimeout(() => {
                        banner.style.display = 'none';
                    }, 400);
                });
            }
        })();

        // ── SWEETALERT2 ──
        (function() {
            // Hanya tampilkan sekali per sesi browser
            if (sessionStorage.getItem('meelDemoAlertShown')) return;
            sessionStorage.setItem('meelDemoAlertShown', '1');

            // Tunda sedikit agar banner sempat render dulu
            setTimeout(() => {
                Swal.fire({
                    icon: 'warning',
                    iconHtml: '<div style="font-size:1.8rem">⚠️</div>',
                    title: '<span style="font-size:0.9rem;font-weight:800;letter-spacing:0.08em;color:#fbbf24">⚠️ INI WEBSITE DEMO</span>',
                    html: `
                        <div style="text-align:center;font-size:0.8rem;color:#94a3b8;line-height:1.6">
                            <strong style="color:#f97316;font-size:0.95rem">MEeL Hub</strong><br>
                            adalah <strong>demo project</strong> pribadi.<br><br>
                            Konten &amp; data di sini <strong style="color:#f87171" title="diperuntukan untuk penggunaan pribadi">tidak nyata</strong>.<br>
                            Hanya untuk <em style="color:#fde68a">showcase &amp; uji coba</em>.
                        </div>
                    `,
                    confirmButtonText: 'Saya Mengerti',
                    confirmButtonColor: '#f97316',
                    timer: 120000,
                    timerProgressBar: true,
                    background: '#0f172a',
                    color: '#e2e8f0',
                    backdrop: 'rgba(5, 7, 12, 0.7)',
                    customClass: {
                        popup: 'demo-modal-popup'
                    },
                    didOpen: (modal) => {
                        modal.addEventListener('mouseenter', () => Swal.stopTimer());
                        modal.addEventListener('mouseleave', () => Swal.resumeTimer());
                    }
                });
            }, 800);
        })();
    </script>
</body>

</html>