<?php
require_once __DIR__ . '/../modules/core/base_url.php';
$meel_base = meel_base_url_path();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="Session Revoked | MEeL">
    <meta property="og:description" content="Sesi Anda telah dihentikan. Silakan login kembali untuk melanjutkan.">
    <title>Session Revoked | MEeL</title>
    <?php include __DIR__ . '/../partials/link.php'; ?>
    <style>
        @import url("<?= $meel_base ?>/assets/css/font.css");

        body {
            font-family: 'JetBrains Mono', monospace, sans-serif;
        }

        .bg-grid {
            background-image: radial-gradient(circle at 2px 2px, rgba(249, 115, 22, 0.04) 1px, transparent 0);
            background-size: 32px 32px;
        }

        .glass-panel {
            background: rgba(15, 12, 10, 0.55);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(249, 115, 22, 0.12);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6),
                inset 0 1px 1px rgba(255, 255, 255, 0.05);
        }

        .glow-effect {
            filter: drop-shadow(0 0 20px rgba(249, 115, 22, 0.45));
        }

        .glitch-text {
            text-shadow: 0 0 10px rgba(249, 115, 22, 0.5), 0 0 20px rgba(234, 179, 8, 0.3);
        }
    </style>
</head>

<body class="bg-[#0c0805] text-slate-300 min-h-screen flex flex-col justify-between bg-grid relative overflow-hidden">

    <!-- Background Ambient Glows -->
    <div class="absolute inset-0 pointer-events-none z-0">
        <div class="absolute top-1/4 left-1/3 -translate-x-1/2 w-[500px] h-[500px] bg-orange-600/10 rounded-full blur-[140px]"></div>
        <div class="absolute bottom-1/4 right-1/3 translate-x-1/2 w-[400px] h-[400px] bg-yellow-600/10 rounded-full blur-[120px]"></div>
    </div>

    <!-- Scanner Lines Grid Visual -->
    <div class="absolute inset-0 pointer-events-none opacity-5 z-0" style="background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%), linear-gradient(90deg, rgba(255, 0, 0, 0.06), rgba(0, 255, 0, 0.02), rgba(0, 0, 255, 0.06)); background-size: 100% 4px, 6px 100%;"></div>

    <!-- Main Content Container -->
    <div class="relative z-10 flex-grow flex items-center justify-center p-6">
        <div class="max-w-md w-full glass-panel rounded-3xl p-8 text-center relative overflow-hidden">

            <!-- Border Glow Accent Line -->
            <div class="absolute top-0 left-0 right-0 h-[2px] bg-gradient-to-r from-transparent via-orange-500 to-transparent"></div>

            <!-- Revoked/Key Icon Section -->
            <div class="relative inline-block mb-8 mt-4">
                <!-- Circular Pulsing Ring -->
                <div class="absolute inset-0 bg-orange-600/20 blur-2xl rounded-full animate-pulse"></div>
                <div class="relative w-24 h-24 bg-slate-900/80 rounded-2xl flex items-center justify-center mx-auto border border-orange-500/30 shadow-[0_0_30px_rgba(249,115,22,0.15)]">
                    <!-- Icon Layer -->
                    <i data-lucide="key-round" class="w-12 h-12 text-orange-500 glow-effect animate-pulse"></i>
                </div>
            </div>

            <!-- Text Content -->
            <p class="text-[10px] font-bold uppercase tracking-[0.5em] text-yellow-500 mb-3">
                Session Protocol :: REVOKED
            </p>

            <h1 class="text-3xl sm:text-4xl font-black tracking-tight mb-4 text-white uppercase glitch-text">
                Session Revoked
            </h1>

            <p class="text-slate-400 text-sm leading-relaxed mb-8 max-w-xs mx-auto">
                Akses sesi ini telah dihentikan oleh Admin atau karena login dari perangkat/browser lain.
            </p>

            <!-- Navigation Button -->
            <div class="flex flex-col gap-4 mb-4">
                <a href="../auth/login.php"
                    class="group relative w-full inline-flex items-center justify-center gap-3 px-8 py-4 bg-gradient-to-r from-orange-600 to-yellow-600 hover:from-orange-500 hover:to-yellow-500 text-white font-bold uppercase tracking-widest text-xs rounded-2xl transition-all duration-300 shadow-[0_4px_25px_rgba(249,115,22,0.25)] hover:shadow-[0_4px_35px_rgba(249,115,22,0.45)] hover:-translate-y-0.5 active:translate-y-0">
                    <i data-lucide="log-in" class="w-4 h-4 transition-transform duration-300 group-hover:translate-x-0.5"></i>
                    Kembali Ke Login
                </a>
            </div>

            <!-- Terminal Info Overlay -->
            <div class="pt-4 border-t border-orange-500/10 text-[9px] text-slate-500 font-mono tracking-wider flex justify-between items-center px-2">
                <span>CLIENT_IP: <?= $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN' ?></span>
                <span class="text-orange-500 font-semibold animate-pulse">● REVOKED</span>
            </div>

        </div>
    </div>

    <!-- Footer Area -->
    <div class="w-full text-center pb-6 relative z-20">
        <?php include __DIR__ . '/../partials/footer.php'; ?>
    </div>

    <!-- Lucide Icon Initialization -->
    <script>        lucide.createIcons();
</script>
</body>

</html>
