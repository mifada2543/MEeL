<?php
require_once 'modules/core/helpers.php';
error_reporting(E_ALL);
ini_set('display_errors', 0);
ignore_user_abort(true);
set_time_limit(0);
putenv("LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu:/usr/local/lib");
putenv("PATH=/usr/local/bin:/usr/bin:/bin");

require_once 'auth/auth.php';
require_once 'auth/config.php';
require_once 'modules/core/activity_logger.php';
require_once 'modules/core/Transcoder.php';
require_once 'modules/core/GarbageCollector.php';
require_once 'modules/media/MediaLibrary.php';
GarbageCollector::run();

// ─── GLOBAL ERROR HANDLER ─────────────────────────────────────────────────
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (strpos($errfile, 'node_modules') !== false || strpos($errfile, 'vendor') !== false) return false;
    $safe_msg = "$errstr (Line $errline)";
    echo "<script>meelError(" . json_encode($safe_msg) . ");</script>";
    echo str_repeat(' ', 1024);
    flush();
    return true;
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "<script>meelError(" . json_encode($error['message']) . ");</script>";
        echo str_repeat(' ', 1024);
        flush();
    }
});

$message        = "";
$rate_limit_msg = "";
$transcoder     = new Transcoder($conn, $_SESSION['user_id']);

require_once 'modules/core/System.php';
$sys     = new System($conn);
$is_busy = $sys->isServerBusy();

// Ambil role untuk tampilkan info ekstra
$stmt_role = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stmt_role->bind_param("i", $_SESSION['user_id']);
$stmt_role->execute();
$user_role = $stmt_role->get_result()->fetch_assoc()['role'] ?? 'user';
$is_admin  = ($user_role === 'admin');

// Queue stats
$q_active = $conn->query("SELECT COUNT(*) FROM upload_queue WHERE status='processing'");
$active_count = $q_active ? (int)$q_active->fetch_row()[0] : 0;

// ── Hitung sisa kuota upload per jam ──
$quota_video_used = 0;
$quota_music_used = 0;
$upload_max = 2;

if ($user_role !== 'admin') {
    $q_vid = $conn->prepare("SELECT COUNT(*) FROM video WHERE user_id = ? AND upload_date > NOW() - INTERVAL 1 HOUR");
    $q_vid->bind_param("i", $_SESSION['user_id']);
    $q_vid->execute();
    $quota_video_used = (int)$q_vid->get_result()->fetch_row()[0];

    $q_mus = $conn->prepare("SELECT COUNT(*) FROM music WHERE user_id = ? AND upload_date > NOW() - INTERVAL 1 HOUR");
    $q_mus->bind_param("i", $_SESSION['user_id']);
    $q_mus->execute();
    $quota_music_used = (int)$q_mus->get_result()->fetch_row()[0];
}

$quota_video_remaining = ($user_role === 'admin') ? -1 : $upload_max - $quota_video_used;
$quota_music_remaining = ($user_role === 'admin') ? -1 : $upload_max - $quota_music_used;

if (isset($_GET['success'])) {
    $message = 'success';
    MediaLibrary::clearCountsCache();
    log_activity($conn, (int)$_SESSION['user_id'], 'upload_url', 'media');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['url'])) {
    verify_csrf();
    if ($is_busy) {
        $message = 'busy';
    } else {
        // ── Rate limit check (sama seperti Uploader.php) ────────────────
        $type        = $_POST['type'] ?? '';
        $limit_table = ($type === 'music') ? 'music' : 'video';
        $limit       = $sys->checkRateLimit($_SESSION['user_id'], $limit_table, $user_role);
        if (!$limit['allowed']) {
            $message        = 'rate_limit';
            $rate_limit_msg = "Batas upload tercapai! Tunggu {$limit['minutes']} menit lagi.";
        } else {
            try {
                $url     = trim($_POST['url']);
                $message = $transcoder->processDownload($url, $type);
            } catch (Exception $e) {
                echo "<script>meelError(" . json_encode($e->getMessage()) . ");</script>";
                echo str_repeat(' ', 1024);
                flush();
                exit;
            } catch (Throwable $e) {
                echo "<script>meelError(" . json_encode($e->getMessage()) . ");</script>";
                echo str_repeat(' ', 1024);
                flush();
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>MEeL — Advanced Upload</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="MEeL — Advanced Upload">
    <meta property="og:description" content="Upload video/musik via URL menggunakan yt-dlp dan FFmpeg. Download dari YouTube, SoundCloud, Instagram, dan 1000+ situs.">
    <meta property="og:image" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/assets/MEeL.png">
    <meta property="og:url" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="icon" type="image/png" href="assets/MEeL.png">
    <link rel="manifest" href="assets/manifest.json">
    <link href="assets/css/tailwind.min.css" rel="stylesheet">
    <script src="assets/js/lucide.js"></script>
    <link rel="stylesheet" href="assets/css/up.css">
    <style>
        /* ── Additional page-specific styles ── */

        /* Scanline accent override — blue for advanced page */
        body::after {
            background: linear-gradient(90deg, transparent, #3b82f6, transparent);
        }

        /* ── Two-col layout ── */
        .page-grid {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 2rem;
            align-items: start;
        }

        @media (max-width: 900px) {
            .page-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ── Form card ── */
        .form-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 24px;
            overflow: hidden;
        }

        .form-card-header {
            padding: 2rem 2rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
        }

        .form-card-body {
            padding: 2rem;
        }

        /* ── URL input ── */
        .url-wrap {
            position: relative;
        }

        .url-wrap .url-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #3b82f6;
            pointer-events: none;
        }

        .url-input {
            width: 100%;
            background: rgba(0, 0, 0, .35);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px 16px 14px 46px;
            color: var(--white);
            font-family: var(--font-mono);
            font-size: .8rem;
            transition: border-color .2s, box-shadow .2s;
            outline: none;
        }

        .url-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, .1);
        }

        .url-input::placeholder {
            color: var(--muted);
        }

        /* ── Type selector ── */
        .type-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .type-label {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 20px 16px;
            background: rgba(0, 0, 0, .25);
            border: 1px solid var(--border);
            border-radius: 16px;
            cursor: pointer;
            transition: all .2s;
            overflow: hidden;
        }

        .type-label::before {
            content: '';
            position: absolute;
            inset: 0;
            opacity: 0;
            transition: opacity .2s;
        }

        .type-label.video-label::before {
            background: linear-gradient(135deg, rgba(239, 68, 68, .08), transparent);
        }

        .type-label.music-label::before {
            background: linear-gradient(135deg, rgba(249, 115, 22, .08), transparent);
        }

        .type-label:has(input:checked) {
            border-color: transparent;
        }

        .type-label.video-label:has(input:checked) {
            border-color: rgba(239, 68, 68, .4);
            box-shadow: 0 0 0 1px rgba(239, 68, 68, .15);
        }

        .type-label.music-label:has(input:checked) {
            border-color: rgba(249, 115, 22, .4);
            box-shadow: 0 0 0 1px rgba(249, 115, 22, .15);
        }

        .type-label:has(input:checked)::before {
            opacity: 1;
        }

        .type-label input {
            display: none;
        }

        .type-icon-wrap {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .2s;
        }

        .video-label .type-icon-wrap {
            background: rgba(239, 68, 68, .1);
            border: 1px solid rgba(239, 68, 68, .18);
        }

        .music-label .type-icon-wrap {
            background: rgba(249, 115, 22, .1);
            border: 1px solid rgba(249, 115, 22, .18);
        }

        .type-label-text {
            font-family: var(--font-mono);
            font-size: .62rem;
            letter-spacing: .2em;
            text-transform: uppercase;
            color: var(--muted);
            transition: color .2s;
        }

        .video-label:has(input:checked) .type-label-text {
            color: #ef4444;
        }

        .music-label:has(input:checked) .type-label-text {
            color: #f97316;
        }

        .type-ext {
            font-family: var(--font-mono);
            font-size: .55rem;
            letter-spacing: .12em;
            color: #2a3040;
            text-transform: uppercase;
        }

        /* ── Submit button ── */
        .submit-btn {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 15px;
            background: #3b82f6;
            color: #fff;
            font-family: var(--font-mono);
            font-size: .72rem;
            letter-spacing: .18em;
            text-transform: uppercase;
            border: none;
            border-radius: 14px;
            cursor: pointer;
            transition: opacity .2s, transform .15s, box-shadow .2s;
            box-shadow: 0 4px 20px rgba(59, 130, 246, .25);
        }

        .submit-btn:hover:not(:disabled) {
            opacity: .88;
            transform: translateY(-1px);
            box-shadow: 0 8px 32px rgba(59, 130, 246, .35);
        }

        .submit-btn:disabled {
            opacity: .4;
            cursor: not-allowed;
        }

        /* ── Alert banners ── */
        .alert-banner {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 14px;
            font-family: var(--font-mono);
            font-size: .72rem;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background: rgba(34, 197, 94, .07);
            border: 1px solid rgba(34, 197, 94, .2);
            color: #4ade80;
        }

        .alert-busy {
            background: rgba(249, 115, 22, .07);
            border: 1px solid rgba(249, 115, 22, .2);
            color: #fb923c;
        }

        /* ── Sidebar cards ── */
        .side-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .side-card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: .6rem;
            font-family: var(--font-mono);
            font-size: .6rem;
            letter-spacing: .22em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .side-card-body {
            padding: 1.25rem;
        }

        /* ── Status dot ── */
        .status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .dot-green {
            background: #22c55e;
            box-shadow: 0 0 6px #22c55e;
            animation: pulse-dot 2s infinite;
        }

        .dot-orange {
            background: #f97316;
            box-shadow: 0 0 6px #f97316;
            animation: pulse-dot 1s infinite;
        }

        @keyframes pulse-dot {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: .4;
            }
        }

        /* ── Supported list ── */
        .support-row {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 0;
            border-bottom: 1px solid var(--border);
            font-family: var(--font-mono);
            font-size: .72rem;
            color: var(--text);
        }

        .support-row:last-child {
            border-bottom: none;
        }

        .support-badge {
            font-size: .55rem;
            letter-spacing: .14em;
            text-transform: uppercase;
            padding: 2px 7px;
            border-radius: 6px;
            font-weight: 600;
            margin-left: auto;
        }

        /* ── Queue indicator ── */
        .queue-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-family: var(--font-mono);
            font-size: .58rem;
            letter-spacing: .14em;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 20px;
        }

        /* ── meel-seg ── */
        .meel-seg {
            width: 14px;
            height: 6px;
            background: rgba(249, 115, 22, .12);
            border: 1px solid rgba(249, 115, 22, .18);
            border-radius: 2px;
            transition: background .3s, border-color .3s;
        }

        .meel-seg.done {
            background: #f97316;
            border-color: #f97316;
            box-shadow: 0 0 6px rgba(249, 115, 22, .5);
        }

        .meel-segs {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            justify-content: center;
            width: 100%;
        }

        /* ── meel-icon-wrap (done/error phases) ── */
        .meel-icon-wrap {
            width: 56px;
            height: 56px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ── Hover effects for type labels on mobile ── */
        @media (hover: none) {
            .type-label:active {
                opacity: .85;
            }
        }
    </style>
</head>

<body class="min-h-screen flex flex-col">

    <!-- ── MEeL Engine Overlay (dari ui.php) ── -->
    <?php include 'partials/ui.php'; ?>

    <main class="flex-grow" style="position:relative;z-index:1;">
        <div class="wrap">

            <!-- ── Masthead ── -->
            <div class="masthead">
                <a href="index.php" class="masthead-logo">
                    <img src="assets/MEeL.png" alt="MEeL">
                </a>
                <div>
                    <div class="masthead-title">Advanced<span>Upload</span></div>
                    <div class="masthead-sub">MEeL Engine · yt-dlp + FFmpeg</div>
                </div>
                <div class="masthead-meta">
                    <div><?= htmlspecialchars($_SESSION['username'] ?? '—') ?></div>
                    <div style="color:<?= $is_admin ? 'var(--orange)' : 'var(--muted)' ?>">
                        <?= strtoupper($user_role) ?>
                    </div>
                    <div><?= date('d M Y') ?></div>
                </div>
            </div>

            <!-- ── Admin bar ── -->
            <?php if ($is_admin): ?>
                <div class="admin-bar">
                    <span class="admin-badge">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                        </svg>
                        Admin Mode
                    </span>
                    <span style="font-family:var(--font-mono);font-size:.6rem;color:var(--muted);letter-spacing:.1em;">
                        No queue limit · Extended timeout · Priority processing
                    </span>
                    <a href="admin/index.php" class="admin-btn" style="margin-left:auto;">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="7" height="7" />
                            <rect x="14" y="3" width="7" height="7" />
                            <rect x="14" y="14" width="7" height="7" />
                            <rect x="3" y="14" width="7" height="7" />
                        </svg>
                        Dashboard
                    </a>
                </div>
            <?php endif; ?>

            <!-- ── Main grid ── -->
            <div class="page-grid">

                <!-- ── LEFT: Form ── -->
                <div>
                    <!-- Alert banners -->
                    <?php if ($message === 'success'): ?>
                        <div class="alert-banner alert-success">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="flex-shrink:0;margin-top:1px">
                                <polyline points="20 6 9 17 4 12" />
                            </svg>
                            <div>
                                <div style="font-weight:700;letter-spacing:.1em;margin-bottom:3px;">PROSES SELESAI</div>
                                <div style="color:rgba(74,222,128,.7);">Media berhasil diunduh dan disimpan ke library.</div>
                            </div>
                        </div>
                    <?php elseif ($message === 'busy'): ?>
                        <div class="alert-banner alert-busy">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="flex-shrink:0;margin-top:1px">
                                <circle cx="12" cy="12" r="10" />
                                <line x1="12" y1="8" x2="12" y2="12" />
                                <line x1="12" y1="16" x2="12.01" y2="16" />
                            </svg>
                            <div>
                                <div style="font-weight:700;letter-spacing:.1em;margin-bottom:3px;">SERVER SEDANG SIBUK</div>
                                <div style="color:rgba(251,146,60,.7);">Sistem sedang memproses antrean lain. Coba lagi beberapa saat.</div>
                            </div>
                        </div>
                    <?php elseif ($message === 'rate_limit'): ?>
                        <div class="alert-banner alert-busy">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="flex-shrink:0;margin-top:1px">
                                <circle cx="12" cy="12" r="10" />
                                <line x1="12" y1="8" x2="12" y2="12" />
                                <line x1="12" y1="16" x2="12.01" y2="16" />
                            </svg>
                            <div>
                                <div style="font-weight:700;letter-spacing:.1em;margin-bottom:3px;">BATAS UPLOAD TERCAPAI</div>
                                <div style="color:rgba(251,146,60,.7);"><?= htmlspecialchars($rate_limit_msg) ?></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-card">
                        <!-- Card header -->
                        <div class="form-card-header">
                            <div>
                                <div style="font-family:var(--font-mono);font-size:.6rem;letter-spacing:.22em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem;">
                                    MEeL Engine · yt-dlp
                                </div>
                                <div style="font-family:var(--font-display);font-size:1.4rem;letter-spacing:.06em;color:var(--white);line-height:1.1;">
                                    Download & <span style="color:#3b82f6;">Process</span>
                                </div>
                            </div>
                            <!-- Server status chip -->
                            <div class="queue-chip" style="<?= $is_busy
                                                                ? 'background:rgba(249,115,22,.08);border:1px solid rgba(249,115,22,.2);color:#f97316;'
                                                                : 'background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);color:#22c55e;' ?>">
                                <div class="status-dot <?= $is_busy ? 'dot-orange' : 'dot-green' ?>"></div>
                                <?= $is_busy ? 'Sibuk' : 'Siap' ?>
                            </div>
                        </div>

                        <!-- Card body / form -->
                        <div class="form-card-body">
                            <form method="POST" onsubmit="return startAdvancedUpload(this)" style="display:flex;flex-direction:column;gap:1.25rem;">
                                <?php if (isset($_SESSION['csrf_token'])): ?>
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <?php endif; ?>

                                <!-- URL input -->
                                <div>
                                    <label class="f-label">URL Sumber</label>
                                    <div class="url-wrap">
                                        <i data-lucide="link-2" class="url-icon" style="width:16px;height:16px;"></i>
                                        <input type="url" name="url" id="url-input"
                                            placeholder="https://youtube.com/watch?v=..."
                                            required class="url-input"
                                            <?= $is_busy ? 'disabled' : '' ?>>
                                    </div>
                                    <div id="url-preview" style="display:none;margin-top:8px;padding:8px 12px;border-radius:10px;background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.15);font-family:var(--font-mono);font-size:.65rem;color:#60a5fa;word-break:break-all;"></div>
                                </div>

                                <!-- Type selector -->
                                <div>
                                    <label class="f-label">Tipe Media</label>
                                    <div class="type-grid">
                                        <label class="type-label video-label">
                                            <input type="radio" name="type" value="video" checked>
                                            <div class="type-icon-wrap">
                                                <i data-lucide="clapperboard" style="width:20px;height:20px;color:#ef4444;"></i>
                                            </div>
                                            <div class="type-label-text">Video</div>
                                            <div class="type-ext">MP4 → HLS</div>
                                        </label>
                                        <label class="type-label music-label">
                                            <input type="radio" name="type" value="music">
                                            <div class="type-icon-wrap">
                                                <i data-lucide="music-2" style="width:20px;height:20px;color:#f97316;"></i>
                                            </div>
                                            <div class="type-label-text">Music</div>
                                            <div class="type-ext">Audio → Opus</div>
                                        </label>
                                    </div>
                                </div>

                                <!-- Submit -->
                                <button type="submit" class="submit-btn" id="submit-btn"
                                    <?= $is_busy ? 'disabled' : '' ?>>
                                    <i data-lucide="download-cloud" style="width:16px;height:16px;"></i>
                                    <?= $is_busy ? 'Server Sibuk' : 'Mulai Proses' ?>
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Tips card -->
                    <div class="entry" style="margin-top:1rem;padding:1.5rem 1.75rem;">
                        <div style="font-family:var(--font-mono);font-size:.6rem;letter-spacing:.22em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10" />
                                <line x1="12" y1="16" x2="12" y2="12" />
                                <line x1="12" y1="8" x2="12.01" y2="8" />
                            </svg>
                            Catatan Penting
                        </div>
                        <div style="display:flex;flex-direction:column;gap:.65rem;">
                            <?php
                            $tips = [
                                ['icon' => 'wifi', 'text' => 'Membutuhkan koneksi internet stabil di server untuk mengunduh.'],
                                ['icon' => 'clock', 'text' => 'Proses bisa memakan waktu cukup lama tergantung durasi dan kualitas video.'],
                                ['icon' => 'shield-alert', 'text' => 'Jangan tutup tab ini selama proses berlangsung — overlay akan memberitahu jika selesai.'],
                                ['icon' => 'layers', 'text' => 'Video akan otomatis di-transcode ke format HLS untuk streaming adaptif.'],
                                ['icon' => 'circle-alert', 'text' => 'Ada beberapa video yang tidak kompatibel dengan engine ini, jika terjadi stuck harap matikan server dan hapus queue yang berjalan']
                            ];
                            foreach ($tips as $tip): ?>
                                <div style="display:flex;align-items:flex-start;gap:.65rem;">
                                    <div style="width:22px;height:22px;border-radius:7px;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;">
                                        <i data-lucide="<?= $tip['icon'] ?>" style="width:11px;height:11px;color:#60a5fa;"></i>
                                    </div>
                                    <span style="font-family:var(--font-mono);font-size:.72rem;line-height:1.7;color:var(--text);"><?= $tip['text'] ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- ── RIGHT: Sidebar ── -->
                <aside>
                    <!-- Server status card -->
                    <div class="side-card">
                        <div class="side-card-header">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="2" width="20" height="8" rx="2" />
                                <rect x="2" y="14" width="20" height="8" rx="2" />
                                <line x1="6" y1="6" x2="6.01" y2="6" />
                                <line x1="6" y1="18" x2="6.01" y2="18" />
                            </svg>
                            Status Server
                        </div>
                        <div class="side-card-body" style="display:flex;flex-direction:column;gap:.85rem;">
                            <div style="display:flex;align-items:center;justify-content:space-between;">
                                <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--muted);">Engine</span>
                                <span style="font-family:var(--font-mono);font-size:.7rem;color:#22c55e;display:flex;align-items:center;gap:5px;">
                                    <div class="status-dot dot-green"></div> Online
                                </span>
                            </div>
                            <div style="display:flex;align-items:center;justify-content:space-between;">
                                <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--muted);">Queue Aktif</span>
                                <span style="font-family:var(--font-mono);font-size:.7rem;color:<?= $active_count > 0 ? '#f97316' : 'var(--muted)' ?>;">
                                    <?= $active_count ?> proses
                                </span>
                            </div>
                            <div style="display:flex;align-items:center;justify-content:space-between;">
                                <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--muted);">Status</span>
                                <span style="font-family:var(--font-mono);font-size:.7rem;color:<?= $is_busy ? '#f97316' : '#22c55e' ?>;">
                                    <?= $is_busy ? 'Sibuk' : 'Siap Proses' ?>
                                </span>
                            </div>

                            <!-- ── Quota bar ── -->
                            <div style="height:1px;background:var(--border);"></div>
                            <div>
                                <div style="font-family:var(--font-mono);font-size:.55rem;letter-spacing:.18em;text-transform:uppercase;color:var(--muted);margin-bottom:.5rem;">
                                    Sisa Kuota · <?= $user_role === 'admin' ? 'Tak terbatas' : "{$upload_max} upload/jam" ?>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:.4rem;">
                                    <?php
                                    $quotas = [
                                        ['label' => 'Video', 'used' => $quota_video_used, 'remaining' => $quota_video_remaining, 'color' => '#ef4444'],
                                        ['label' => 'Music', 'used' => $quota_music_used, 'remaining' => $quota_music_remaining, 'color' => '#f97316'],
                                    ];
                                    foreach ($quotas as $q):
                                        $pct  = ($user_role !== 'admin' && $upload_max > 0) ? round(($q['used'] / $upload_max) * 100) : 0;
                                        $stat = $user_role === 'admin' ? '∞' : ($q['remaining'] > 0 ? "{$q['used']}/{$upload_max}" : 'Penuh');
                                        $stat_color = $user_role === 'admin' ? 'var(--muted)' : ($q['remaining'] <= 0 ? '#ef4444' : '#4ade80');
                                    ?>
                                        <div>
                                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px;">
                                                <span style="font-family:var(--font-mono);font-size:.58rem;color:<?= $q['color'] ?>;"><?= $q['label'] ?></span>
                                                <span style="font-family:var(--font-mono);font-size:.58rem;color:<?= $stat_color ?>;"><?= $stat ?></span>
                                            </div>
                                            <?php if ($user_role !== 'admin'): ?>
                                                <div style="height:3px;border-radius:3px;background:rgba(255,255,255,.04);overflow:hidden;">
                                                    <div style="height:100%;width:<?= min($pct, 100) ?>%;border-radius:3px;background:<?= $q['remaining'] <= 0 ? '#ef4444' : $q['color'] ?>;transition:width .3s;"></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <?php if ($is_admin): ?>
                                <div style="height:1px;background:var(--border);"></div>
                                <a href="admin/index.php#queues" style="font-family:var(--font-mono);font-size:.62rem;letter-spacing:.14em;text-transform:uppercase;color:var(--orange);text-decoration:none;display:flex;align-items:center;gap:.4rem;opacity:.8;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.8">
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6" />
                                        <polyline points="15 3 21 3 21 9" />
                                        <line x1="10" y1="14" x2="21" y2="3" />
                                    </svg>
                                    Kelola Queue
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Supported sources card -->
                    <div class="side-card">
                        <div class="side-card-header">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
                            </svg>
                            Sumber Didukung
                        </div>
                        <div class="side-card-body">
                            <?php
                            $sources = [
                                ['name' => 'YouTube',    'badge' => 'Video + Audio', 'bc' => 'rgba(239,68,68,.1)',   'tc' => '#ef4444'],
                                ['name' => 'SoundCloud', 'badge' => 'Audio',         'bc' => 'rgba(249,115,22,.1)', 'tc' => '#f97316'],
                                ['name' => 'Twitter/X',  'badge' => 'Video',         'bc' => 'rgba(59,130,246,.1)', 'tc' => '#60a5fa'],
                                ['name' => 'Instagram',  'badge' => 'Video',         'bc' => 'rgba(168,85,247,.1)', 'tc' => '#c084fc'],
                                ['name' => 'yt-dlp compatible', 'badge' => '1000+ situs', 'bc' => 'rgba(34,197,94,.1)', 'tc' => '#4ade80'],
                            ];
                            foreach ($sources as $s): ?>
                                <div class="support-row">
                                    <span><?= $s['name'] ?></span>
                                    <span class="support-badge" style="background:<?= $s['bc'] ?>;color:<?= $s['tc'] ?>;border:1px solid <?= $s['tc'] ?>33;">
                                        <?= $s['badge'] ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Output format card -->
                    <div class="side-card">
                        <div class="side-card-header">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
                                <polyline points="14 2 14 8 20 8" />
                            </svg>
                            Format Output
                        </div>
                        <div class="side-card-body" style="display:flex;flex-direction:column;gap:.85rem;">
                            <div>
                                <div style="font-family:var(--font-mono);font-size:.58rem;letter-spacing:.18em;text-transform:uppercase;color:#ef4444;margin-bottom:.5rem;">Video</div>
                                <div style="font-family:var(--font-mono);font-size:.7rem;color:var(--text);line-height:1.7;">
                                    MP4 → <strong style="color:var(--white);">HLS (.m3u8)</strong><br>
                                    Resolusi adaptif · TS segments<br>
                                    Sprite VTT preview
                                </div>
                            </div>
                            <div style="height:1px;background:var(--border);"></div>
                            <div>
                                <div style="font-family:var(--font-mono);font-size:.58rem;letter-spacing:.18em;text-transform:uppercase;color:#f97316;margin-bottom:.5rem;">Audio</div>
                                <div style="font-family:var(--font-mono);font-size:.7rem;color:var(--text);line-height:1.7;">
                                    Audio → <strong style="color:var(--white);">Opus (.ogg)</strong><br>
                                    128kbps · Metadata preserved<br>
                                    Cover art extracted
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Nav links -->
                    <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                        <a href="index.php" class="check-btn" style="flex:1;">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="M3 12L12 3l9 9" />
                                <path d="M9 21V12h6v9" />
                            </svg>
                            Portal
                        </a>
                        <a href="video/index.php" class="check-btn" style="flex:1;">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <polygon points="23 7 16 12 23 17 23 7" />
                                <rect x="1" y="5" width="15" height="14" rx="2" />
                            </svg>
                            Video
                        </a>
                        <a href="music/index.php" class="check-btn" style="flex:1;">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="M9 18V5l12-2v13" />
                                <circle cx="6" cy="18" r="3" />
                                <circle cx="18" cy="16" r="3" />
                            </svg>
                            Music
                        </a>
                    </div>
                </aside>
            </div>
        </div>
    </main>

    <?php include 'partials/footer.php'; ?>

    <script>
        lucide.createIcons();

        // ── URL live preview ──
        const urlInput = document.getElementById('url-input');
        const urlPreview = document.getElementById('url-preview');

        if (urlInput) {
            urlInput.addEventListener('input', function() {
                const val = this.value.trim();
                if (val.length > 10 && val.startsWith('http')) {
                    try {
                        const u = new URL(val);
                        urlPreview.textContent = u.hostname + u.pathname.slice(0, 60) + (u.pathname.length > 60 ? '…' : '');
                        urlPreview.style.display = 'block';
                    } catch (e) {
                        urlPreview.style.display = 'none';
                    }
                } else {
                    urlPreview.style.display = 'none';
                }
            });
        }

        // ── Form submit — show overlay, let PHP stream ──
        function startAdvancedUpload(form) {
            const url = document.getElementById('url-input').value.trim();
            if (!url) return false;

            // Transisi tombol
            const btn = document.getElementById('submit-btn');
            btn.disabled = true;
            btn.innerHTML = '<div style="width:14px;height:14px;border:1.5px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:meel-spin .7s linear infinite;"></div> Memproses...';

            // Tampilkan overlay pada fase download
            if (typeof meelPhase === 'function') meelPhase('download');

            return true; // Biarkan form submit biasa (PHP streaming)
        }
    </script>
</body>

</html>