<?php
// Base path root proyek — URL asset absolut & back-link default
// (independen dari kedalaman direktori halaman yang me-include,
// mis. books/read.php yang meng-include file ini).
require_once __DIR__ . '/../modules/core/base_url.php';
$meel_base = meel_base_url_path();

http_response_code(404);
$back_url = $meel_base . '/index.php';

if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
    $host = $_SERVER['HTTP_HOST'];
    if (parse_url($ref, PHP_URL_HOST) === $host) {
        $ref_path = parse_url($ref, PHP_URL_PATH);
        $excluded_pages = ['not_found.php', 'index.php'];
        $should_exclude = false;
        foreach ($excluded_pages as $page) {
            if (strpos($ref_path, $page) !== false) {
                $should_exclude = true;
                break;
            }
        }
        if (!$should_exclude) {
            $back_url = $ref;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Halaman tidak ditemukan.">
    <title>404 Not Found | MEeL</title>
    <link rel="manifest" href="<?= $meel_base ?>/assets/manifest.json">
    <link rel="icon" type="image/png" href="<?= $meel_base ?>/assets/MEeL.png">
    <link href="<?= $meel_base ?>/assets/css/tailwind.min.css" rel="stylesheet">
    <script src="<?= $meel_base ?>/assets/js/compatibilitas/lucide.js"></script>
    <style>
        @import url("<?= $meel_base ?>/assets/css/font.css");

        body {
            font-family: 'JetBrains Mono', monospace, sans-serif;
        }

        .bg-grid {
            background-image: radial-gradient(circle at 2px 2px, rgba(234, 179, 8, 0.04) 1px, transparent 0);
            background-size: 32px 32px;
        }

        .glass-panel {
            background: rgba(15, 15, 10, 0.55);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(234, 179, 8, 0.12);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6),
                inset 0 1px 1px rgba(255, 255, 255, 0.05);
        }

        .glow-effect {
            filter: drop-shadow(0 0 20px rgba(234, 179, 8, 0.45));
        }

        .glitch-text {
            text-shadow: 0 0 10px rgba(234, 179, 8, 0.5), 0 0 20px rgba(250, 204, 21, 0.3);
        }
    </style>
</head>

<body class="bg-black text-slate-300 min-h-screen flex flex-col justify-between bg-grid relative overflow-hidden">

    <!-- Background Ambient Glows -->
    <div class="absolute inset-0 pointer-events-none z-0">
        <div class="absolute top-1/4 left-1/3 -translate-x-1/2 w-[500px] h-[500px] bg-yellow-600/10 rounded-full blur-[140px]"></div>
        <div class="absolute bottom-1/4 right-1/3 translate-x-1/2 w-[400px] h-[400px] bg-amber-600/10 rounded-full blur-[120px]"></div>
    </div>

    <!-- Scanner Lines Grid Visual -->
    <div class="absolute inset-0 pointer-events-none opacity-5 z-0" style="background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%), linear-gradient(90deg, rgba(255, 0, 0, 0.06), rgba(0, 255, 0, 0.02), rgba(0, 0, 255, 0.06)); background-size: 100% 4px, 6px 100%;"></div>

    <!-- Main Content Container -->
    <main class="relative z-10 flex-grow flex items-center justify-center p-6">
        <div class="max-w-md w-full glass-panel rounded-3xl p-8 text-center relative overflow-hidden">

            <!-- Border Glow Accent Line -->
            <div class="absolute top-0 left-0 right-0 h-[2px] bg-gradient-to-r from-transparent via-yellow-500 to-transparent"></div>

            <!-- 404 Icon Section -->
            <div class="relative inline-block mb-8 mt-4">
                <!-- Circular Pulsing Ring -->
                <div class="absolute inset-0 bg-yellow-600/20 blur-2xl rounded-full animate-pulse"></div>
                <div class="relative w-24 h-24 bg-slate-900/80 rounded-2xl flex items-center justify-center mx-auto border border-yellow-500/30 shadow-[0_0_30px_rgba(234,179,8,0.15)]">
                    <!-- Icon Layer -->
                    <i data-lucide="search-x" class="w-12 h-12 text-yellow-500 glow-effect animate-pulse"></i>
                </div>
            </div>

            <!-- Text Content -->
            <p class="text-[10px] font-bold uppercase tracking-[0.5em] text-amber-400 mb-3">
                Error Protocol :: 404_Not_Found
            </p>

            <h1 class="text-3xl sm:text-4xl font-black tracking-tight mb-4 text-white uppercase glitch-text">
                Not Found
            </h1>

            <p class="text-slate-400 text-sm leading-relaxed mb-8 max-w-xs mx-auto">
                Halaman atau berkas yang Anda cari tidak dapat ditemukan di server.
                Mungkin telah dipindahkan, dihapus, atau URL tidak valid.
            </p>

            <!-- Navigation Buttons -->
            <div class="flex flex-col gap-3 mb-4">
                <a href="<?= htmlspecialchars($back_url) ?>"
                    class="group relative w-full inline-flex items-center justify-center gap-3 px-8 py-4 bg-gradient-to-r from-yellow-600 to-amber-600 hover:from-yellow-500 hover:to-amber-500 text-white font-bold uppercase tracking-widest text-xs rounded-2xl transition-all duration-300 shadow-[0_4px_25px_rgba(234,179,8,0.25)] hover:shadow-[0_4px_35px_rgba(234,179,8,0.45)] hover:-translate-y-0.5 active:translate-y-0">
                    <i data-lucide="arrow-left" class="w-4 h-4 transition-transform duration-300 group-hover:-translate-x-1"></i>
                    Kembali
                </a>
                <a href="<?= $meel_base ?>/index.php"
                    class="group relative w-full inline-flex items-center justify-center gap-3 px-8 py-4 bg-white/[.04] border border-white/[.08] hover:bg-white/[.08] text-slate-300 font-bold uppercase tracking-widest text-xs rounded-2xl transition-all duration-300 hover:-translate-y-0.5 active:translate-y-0">
                    <i data-lucide="home" class="w-4 h-4"></i>
                    Ke Halaman Utama
                </a>
            </div>

            <!-- Terminal Info Overlay -->
            <div class="pt-4 border-t border-yellow-500/10 text-[9px] text-slate-500 font-mono tracking-wider flex justify-between items-center px-2">
                <span>SYS_URI: <?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'UNKNOWN') ?></span>
                <span class="text-yellow-500/70 font-semibold animate-pulse">● MISSING</span>
            </div>

        </div>
    </main>

    <!-- Footer Area -->
    <div class="w-full text-center pb-6 relative z-20">
        <?php include __DIR__ . '/../partials/footer.php'; ?>
    </div>

    <!-- Lucide Icon Initialization -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined' && lucide.createIcons) {
                lucide.createIcons();
            } else {
                // Fallback: retry after lucide script loads
                var checkLucide = setInterval(function() {
                    if (typeof lucide !== 'undefined' && lucide.createIcons) {
                        lucide.createIcons();
                        clearInterval(checkLucide);
                    }
                }, 100);
                setTimeout(function() { clearInterval(checkLucide); }, 5000);
            }
        });
    </script>
</body>

</html>
