<?php
require_once 'modules/core/helpers.php';
require_once 'auth/auth.php';
require_once 'auth/config.php';
require_once 'modules/core/Transcoder.php';

$transcoder      = new Transcoder($conn, $_SESSION['user_id']);
$download_link   = null;
$output_filename = "";
$format          = "mp3";
$alert_message   = "";
$video_title     = "";

if (isset($_POST['start_transcode'])) {
    // 🔒 FIX CSRF: Verifikasi token
    if (!verify_csrf()) {
        $alert_message = 'CSRF Token tidak valid.';
    } else {
    $video_id = (int)($_POST['video_id'] ?? 0);
    $format   = $_POST['format'] ?? 'mp3';

    if ($video_id <= 0) {
        $alert_message = 'ID Video harus berupa angka valid!';
    } else {
        // Ambil judul video untuk ditampilkan di hasil
        $stmt_title = $conn->prepare("SELECT title FROM video WHERE id = ? LIMIT 1");
        $stmt_title->bind_param("i", $video_id);
        $stmt_title->execute();
        $title_row  = $stmt_title->get_result()->fetch_assoc();
        $video_title = $title_row['title'] ?? "Video #$video_id";

        $result = $transcoder->transcodeVideo($video_id, $format);

        if ($result['status'] === 'success') {
            $download_link   = $result['download_link'];
            $output_filename = $result['output_filename'];
        } else {
            $alert_message = $result['msg'];
        }
    }
    } // tutup else verify_csrf
}

$video_id_value = isset($_GET['id']) ? (int)$_GET['id'] : "";

// Format meta info
$format_meta = [
    'mp3' => ['label' => 'MP3',  'desc' => '128 kbps · MPEG Audio',    'color' => '#ef4444', 'dim' => 'rgba(239,68,68,.12)',  'icon' => 'music', 'textClass' => 'text-red-500'],
    'ogg' => ['label' => 'OGG',  'desc' => 'Opus · Efisien & Modern',  'color' => '#f97316', 'dim' => 'rgba(249,115,22,.12)', 'icon' => 'radio', 'textClass' => 'text-orange-500'],
    'm4a' => ['label' => 'M4A',  'desc' => 'AAC · Apple Compatible',   'color' => '#a78bfa', 'dim' => 'rgba(167,139,250,.12)', 'icon' => 'headphones', 'textClass' => 'text-purple-400'],
];
$chosen = $format_meta[$format] ?? $format_meta['mp3'];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL Transcoder - Konversi video ke format audio MP3, OGG, dan M4A. Ekstrak audio dari library video dengan mudah.">
    <meta property="og:title" content="MEeL Transcoder">
    <meta property="og:description" content="Konversi video ke format audio MP3, OGG, dan M4A. Ekstrak audio dari library video dengan mudah.">
    <meta property="og:image" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/assets/MEeL.png">
    <meta property="og:url" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <title>MEeL Transcoder</title>
    <link rel="icon" type="image/png" href="assets/MEeL.png">
    <link rel="manifest" href="assets/manifest.json">
    <link href="assets/css/fonts.css" rel="stylesheet">
    <script src="assets/js/lucide.js"></script>
    <script src="assets/js/sweetalert2.all.min.js"></script>
    <script src="assets/js/script.min.js"></script>

    <link href="assets/css/tailwind.min.css" rel="stylesheet">
    <style>
        /* Efek khusus murni CSS yang sulit dilakukan dengan utilitas Tailwind standar */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 0;
        }

        body::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, #ef4444, transparent);
            z-index: 100;
            animation: glow 3s ease-in-out infinite;
        }
    </style>
</head>

<body class="bg-bg text-[#c9cdd6] font-sans min-h-screen flex flex-col relative">

    <nav class="sticky top-0 z-50 h-[52px] bg-[#080b11]/90 backdrop-blur-md border-b border-white/[.06] flex items-center px-5 gap-2.5">
        <a href="index.php" class="font-syne text-[13px] font-extrabold text-[#f0f2f7] no-underline tracking-[.05em]">MEeL<span class="text-red-500">.</span></a>
        <div class="w-px h-[18px] bg-white/10"></div>
        <a href="video/index.php" class="text-[11px] font-semibold text-muted no-underline transition-colors hover:text-red-500">Video</a>
        <span class="text-[#2c3440] text-[13px]">›</span>
        <span class="text-[11px] font-semibold text-[#f0f2f7]">Transcoder</span>
    </nav>

    <div class="flex-1 flex items-center justify-center p-4 md:py-12 relative z-10 w-full">
        <div class="w-full max-w-[420px] bg-card border border-white/[.06] rounded-[28px] overflow-hidden shadow-[0_32px_80px_rgba(0,0,0,.5)] animate-card-in">

            <?php if ($download_link): ?>
                <div class="p-7 pb-6 border-b border-white/[.06] flex items-start justify-between gap-3">
                    <div>
                        <div class="font-syne text-[22px] font-extrabold text-[#f0f2f7] leading-tight">Siap <span class="text-red-500">Diunduh</span></div>
                        <div class="text-[10px] font-bold uppercase tracking-[.2em] text-muted mt-1">Transcode selesai · File tersedia</div>
                    </div>
                    <i data-lucide="check-circle" class="w-7 h-7 text-green-500 opacity-60 flex-shrink-0 mt-1"></i>
                </div>

                <div class="p-7 pt-6 flex flex-col gap-5">
                    <div class="flex flex-col items-center gap-5 text-center py-2">
                        <div class="w-[72px] h-[72px] rounded-[22px] flex items-center justify-center animate-icon-pop" style="background:<?= $chosen['dim'] ?>;border:1px solid <?= $chosen['color'] ?>33;">
                            <i data-lucide="<?= $chosen['icon'] ?>" class="w-8 h-8" style="color:<?= $chosen['color'] ?>;"></i>
                        </div>

                        <div>
                            <div class="inline-flex items-center gap-1.5 text-[9px] font-extrabold uppercase tracking-[.18em] px-3 py-1 rounded-full" style="background:<?= $chosen['dim'] ?>;border:1px solid <?= $chosen['color'] ?>33;color:<?= $chosen['color'] ?>;">
                                <i data-lucide="file-audio" class="w-2.5 h-2.5"></i>
                                <?= $chosen['label'] ?> · <?= htmlspecialchars($chosen['desc']) ?>
                            </div>
                        </div>

                        <?php if ($video_title): ?>
                            <div class="font-syne text-[20px] font-extrabold text-[#f0f2f7] leading-snug">
                                <?= htmlspecialchars(mb_substr($video_title, 0, 40)) ?><?= mb_strlen($video_title) > 40 ? '…' : '' ?>
                            </div>
                        <?php endif; ?>

                        <div class="text-[11px] text-muted max-w-[300px] overflow-hidden text-ellipsis whitespace-nowrap">
                            <?= htmlspecialchars($output_filename) ?>
                        </div>
                    </div>

                    <a href="<?= htmlspecialchars($download_link) ?>" download="<?= htmlspecialchars($output_filename) ?>" class="w-full flex items-center justify-center gap-2.5 p-4 bg-green-500 text-black font-syne text-[12px] font-extrabold tracking-[.12em] uppercase rounded-xl transition-all shadow-[0_4px_20px_rgba(34,197,94,.22)] hover:bg-green-400 hover:-translate-y-[1px] hover:shadow-[0_8px_28px_rgba(34,197,94,.32)]">
                        <i data-lucide="download" class="w-[15px] h-[15px]"></i>
                        Unduh Sekarang
                    </a>

                    <div class="text-center">
                        <a href="transcode.php" class="inline-block text-[10px] font-bold uppercase tracking-[.14em] text-muted transition-colors hover:text-[#f0f2f7]">
                            ← Transcode video lain
                        </a>
                    </div>
                </div>

            <?php else: ?>
                <div class="p-7 pb-6 border-b border-white/[.06] flex items-start justify-between gap-3">
                    <div>
                        <div class="font-syne text-[22px] font-extrabold text-[#f0f2f7] leading-tight">Transcode<span class="text-red-500">.</span></div>
                        <div class="text-[10px] font-bold uppercase tracking-[.2em] text-muted mt-1">Ekstrak audio dari video library</div>
                    </div>
                    <i data-lucide="wand-2" class="w-7 h-7 text-red-500 opacity-40 flex-shrink-0 mt-1"></i>
                </div>

                <div class="p-7 pt-6">
                    <form method="POST" id="tc-form" onsubmit="startProcess()" class="flex flex-col gap-5">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                        <div class="flex flex-col gap-1.5">
                            <label class="text-[9px] font-bold uppercase tracking-[.18em] text-muted pl-0.5" for="vid-id">Video ID</label>
                            <input type="number" name="video_id" id="vid-id"
                                value="<?= htmlspecialchars($video_id_value) ?>"
                                placeholder="00" min="1" step="1"
                                oninput="this.value=this.value.replace(/[^0-9]/g,'')"
                                required
                                class="w-full bg-white/[.035] border border-white/[.08] rounded-xl px-4 py-3 text-[15px] font-bold font-syne text-[#f0f2f7] outline-none transition-all text-center tracking-[.08em] focus:border-red-500 focus:ring-[3px] focus:ring-red-500/10 placeholder:text-[#2c3440] placeholder:font-normal">
                        </div>

                        <div class="flex flex-col gap-1.5 mt-1">
                            <label class="text-[9px] font-bold uppercase tracking-[.18em] text-muted pl-0.5">Format Output</label>
                            <div class="grid grid-cols-3 gap-2">
                                <?php
                                $fmts = [
                                    'mp3' => [
                                        'icon' => 'music',
                                        'name' => 'MP3',
                                        'desc' => "128kbps\nMPEG",
                                        'activeBorder' => 'has-[:checked]:border-red-500/40',
                                        'activeBg' => 'has-[:checked]:bg-red-500/10',
                                        'iconBg' => 'group-has-[:checked]:bg-red-500/15',
                                        'textActive' => 'group-has-[:checked]:text-red-500',
                                        'color' => 'text-red-500'
                                    ],
                                    'ogg' => [
                                        'icon' => 'radio',
                                        'name' => 'OGG',
                                        'desc' => "Opus\nModern",
                                        'activeBorder' => 'has-[:checked]:border-orange-500/40',
                                        'activeBg' => 'has-[:checked]:bg-orange-500/10',
                                        'iconBg' => 'group-has-[:checked]:bg-orange-500/15',
                                        'textActive' => 'group-has-[:checked]:text-orange-500',
                                        'color' => 'text-orange-500'
                                    ],
                                    'm4a' => [
                                        'icon' => 'headphones',
                                        'name' => 'M4A',
                                        'desc' => "AAC\nApple",
                                        'activeBorder' => 'has-[:checked]:border-purple-400/40',
                                        'activeBg' => 'has-[:checked]:bg-purple-400/10',
                                        'iconBg' => 'group-has-[:checked]:bg-purple-400/15',
                                        'textActive' => 'group-has-[:checked]:text-purple-400',
                                        'color' => 'text-purple-400'
                                    ],
                                ];
                                foreach ($fmts as $val => $f): ?>
                                    <label class="group flex flex-col items-center justify-center gap-2 p-3.5 rounded-2xl border border-white/[.07] bg-white/[.02] cursor-pointer transition-all hover:border-white/[.14] hover:bg-white/[.04] overflow-hidden <?= $f['activeBorder'] ?> <?= $f['activeBg'] ?>">
                                        <input type="radio" name="format" value="<?= $val ?>" class="hidden" <?= $val === 'mp3' ? 'checked' : '' ?>>

                                        <div class="w-[34px] h-[34px] rounded-[10px] flex items-center justify-center transition-all <?= $f['iconBg'] ?>">
                                            <i data-lucide="<?= $f['icon'] ?>" class="w-4 h-4 <?= $f['color'] ?>"></i>
                                        </div>
                                        <div class="font-syne text-[11px] font-extrabold tracking-[.1em] text-muted transition-colors <?= $f['textActive'] ?>"><?= $f['name'] ?></div>
                                        <div class="text-[8px] font-semibold uppercase tracking-[.1em] text-[#2c3440] text-center leading-[1.4] transition-colors"><?= nl2br($f['desc']) ?></div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div id="progress-strip" class="hidden w-full h-[3px] bg-white/5 rounded-full overflow-hidden">
                            <div class="h-full w-0 bg-gradient-to-r from-red-500 to-red-400 rounded-full animate-indeterminate"></div>
                        </div>

                        <button type="submit" name="start_transcode" id="btn-submit" class="w-full flex items-center justify-center gap-2 p-3.5 bg-red-500 text-white font-syne text-[12px] font-extrabold tracking-[.12em] uppercase rounded-xl transition-all shadow-[0_4px_20px_rgba(239,68,68,.22)] hover:bg-red-400 hover:-translate-y-[1px] hover:shadow-[0_8px_28px_rgba(239,68,68,.32)]">
                            <i data-lucide="zap" class="w-3.5 h-3.5"></i>
                            Mulai Proses
                        </button>
                    </form>
                </div>

            <?php endif; ?>

            <div class="h-px bg-white/[.06]"></div>
            <div class="p-4 pb-5 flex items-center justify-center gap-5">
                <a href="video/index.php" class="text-[10px] font-bold uppercase tracking-[.14em] text-muted no-underline transition-colors hover:text-[#f0f2f7]">Video</a>
                <a href="music/index.php" class="text-[10px] font-bold uppercase tracking-[.14em] text-muted no-underline transition-colors hover:text-[#f0f2f7]">Musik</a>
                <a href="index.php" class="text-[10px] font-bold uppercase tracking-[.14em] text-red-500 no-underline transition-colors hover:text-red-400">Portal</a>
            </div>
        </div>
    </div>

    <?php include 'partials/footer.php'; ?>

    <script>
        lucide.createIcons();

        <?php if ($alert_message !== ""): ?>
            meelAlertRedirect({
                title: 'Transcode',
                text: <?= json_encode($alert_message) ?>,
                icon: 'warning',
                redirectUrl: 'transcode.php<?= $video_id_value ? "?id=$video_id_value" : "" ?>'
            });
        <?php endif; ?>

        // ── Submit animation ──
        function startProcess() {
            const btn = document.getElementById('btn-submit');
            const progress = document.getElementById('progress-strip');

            if (progress) progress.classList.remove('hidden');

            if (btn) {
                btn.innerHTML = '<div class="w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin"></div> Memproses...';
                btn.classList.add('opacity-60', 'pointer-events-none');
            }
        }
    </script>
</body>

</html>