<?php
require_once '../modules/helpers.php';
// Error logging aktif, display_errors dimatikan untuk keamanan production
error_reporting(E_ALL);
ini_set('display_errors', 1);
include '../auth/auth.php';
include '../modules/Uploader.php';
require_once '../modules/GarbageCollector.php';
require_once '../modules/media/MediaLibrary.php';
GarbageCollector::run();

set_time_limit(0);
$status        = "";
$user          = $_SESSION['username'];
$user_id       = $_SESSION['user_id'];
$alert_message = "";

// Ambil role user — via shared helper (cache otomatis per request)
$user_role = get_user_role($conn, $user_id);
$is_admin  = ($user_role === 'admin');

// Ambil jumlah upload user hari ini
$stmt_count = $conn->prepare("SELECT COUNT(*) AS c FROM video WHERE user_id = ? AND DATE(upload_date) = CURDATE()");
$stmt_count->bind_param("i", $user_id);
$stmt_count->execute();
$today_count = (int)$stmt_count->get_result()->fetch_assoc()['c'];

// Total semua upload user
$stmt_total = $conn->prepare("SELECT COUNT(*) AS c FROM video WHERE user_id = ?");
$stmt_total->bind_param("i", $user_id);
$stmt_total->execute();
$total_uploads = (int)$stmt_total->get_result()->fetch_assoc()['c'];

// Limit per hari
$daily_limit = $is_admin ? '∞' : '3';

$uploader = new Uploader($conn, $user_id, $user);

if (isset($_POST['upload'])) {
    verify_csrf();
    $result = $uploader->processVideo($_POST, $_FILES, __DIR__ . "/");

    if ($result['status'] === 'success') {
        $status = "success";
        $today_count++;
        $total_uploads++;
        MediaLibrary::clearCountsCache();
        log_activity($conn, $user_id, 'upload_video', 'video', (int)($result['id'] ?? 0));
    } elseif (isset($result['alert']) && $result['alert'] == true) {
        $alert_message = $result['msg'];
    } else {
        die("<div style='color:red; padding:20px; background:#000;'><h2>$user, Error!</h2><p>{$result['msg']}</p></div>");
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Upload video ke MEeL Video Library. Format video didukung: MP4, WEBM, MKV. Transcoding otomatis ke HLS.">
    <meta property="og:title" content="MEeL Video | Upload">
    <meta property="og:description" content="Upload video ke MEeL Video Library. Format: MP4, WEBM, MKV. Transcoding otomatis ke HLS.">
    <title>MEeL Video | Upload</title>
    <?php include '../partials/link.php'; ?>
    <style>
        @import url('../assets/css/font.css');

        :root {
            --accent: #ef4444;
            --accent-dim: rgba(239, 68, 68, .10);
            --accent-border: rgba(239, 68, 68, .22);
            --bg-base: #080b11;
            --bg-card: #0e1118;
            --bg-panel: #131720;
            --border: rgba(255, 255, 255, .06);
            --border-strong: rgba(255, 255, 255, .10);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: var(--bg-base);
            font-family: 'DM Sans', sans-serif;
            min-height: 100vh;
            color: #c9cdd6;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 0;
        }

        .page-wrap {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: grid;
            grid-template-rows: 56px 1fr;
        }

        /* ── Nav ── */
        .top-nav {
            height: 56px;
            background: rgba(8, 11, 17, .9);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            padding: 0 20px;
            gap: 12px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-brand {
            font-family: 'Syne', sans-serif;
            font-size: 14px;
            font-weight: 800;
            color: #fff;
            text-decoration: none;
            letter-spacing: .05em;
        }

        .nav-brand span {
            color: var(--accent);
        }

        .nav-sep {
            width: 1px;
            height: 20px;
            background: var(--border-strong);
        }

        .nav-crumb {
            font-size: 11px;
            font-weight: 600;
            color: #cbd5e1;
            text-decoration: none;
            transition: color .2s;
        }

        .nav-crumb:hover {
            color: var(--accent);
        }

        .nav-crumb-current {
            font-size: 11px;
            font-weight: 600;
            color: #e2e6ef;
        }

        .nav-chevron {
            color: #353d4a;
            font-size: 14px;
        }

        /* ── Layout ── */
        .upload-layout {
            display: grid;
            grid-template-columns: 1fr;
        }

        @media (min-width: 900px) {
            .upload-layout {
                grid-template-columns: 320px 1fr;
                min-height: calc(100vh - 56px);
            }
        }

        @media (min-width: 1200px) {
            .upload-layout {
                grid-template-columns: 360px 1fr;
            }
        }

        /* ── Sidebar ── */
        .sidebar-panel {
            background: var(--bg-panel);
            border-right: 1px solid var(--border);
            padding: 32px 28px;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        @media (max-width: 899px) {
            .sidebar-panel {
                border-right: none;
                border-bottom: 1px solid var(--border);
                padding: 20px 16px;
                gap: 16px;
            }
        }

        /* ── Video icon hero ── */
        .hero-icon {
            width: 100%;
            aspect-ratio: 16/9;
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(239, 68, 68, .15) 0%, rgba(8, 11, 17, 0) 70%);
            border: 1px solid var(--accent-border);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            position: relative;
            overflow: hidden;
        }

        .hero-icon::before {
            content: '';
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(0deg,
                    transparent,
                    transparent 28px,
                    rgba(239, 68, 68, .04) 28px,
                    rgba(239, 68, 68, .04) 29px);
        }

        .hero-icon-ring {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: var(--accent-dim);
            border: 2px solid var(--accent-border);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 1;
        }

        /* ── Stat chips ── */
        .stats-strip {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .stat-chip {
            flex: 1;
            min-width: 80px;
            padding: 10px 12px;
            border-radius: 12px;
            background: rgba(255, 255, 255, .03);
            border: 1px solid var(--border);
            text-align: center;
        }

        .stat-number {
            font-family: 'Syne', sans-serif;
            font-size: 18px;
            font-weight: 800;
            color: var(--accent);
            line-height: 1;
        }

        .stat-label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: #cbd5e1;
            margin-top: 4px;
        }

        /* ── Guide list ── */
        .guide-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .guide-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            background: rgba(255, 255, 255, .03);
            border: 1px solid var(--border);
        }

        .guide-icon {
            width: 26px;
            height: 26px;
            border-radius: 8px;
            background: var(--accent-dim);
            border: 1px solid var(--accent-border);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .guide-title {
            font-size: 11px;
            font-weight: 700;
            color: #e2e6ef;
            margin-bottom: 2px;
        }

        .guide-desc {
            font-size: 10px;
            color: #cbd5e1;
            line-height: 1.4;
        }

        /* ── Form panel ── */
        .form-panel {
            background: var(--bg-card);
            padding: 36px 40px;
            display: flex;
            flex-direction: column;
        }

        @media (max-width: 899px) {
            .form-panel {
                padding: 20px 16px;
            }
        }

        @media (max-width: 599px) {
            .form-panel {
                padding: 16px 14px;
            }
        }

        @media (min-width: 900px) {
            .form-panel {
                overflow-y: auto;
                max-height: calc(100vh - 56px);
            }
        }

        /* ── Form header ── */
        .form-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border);
        }

        .form-title {
            font-family: 'Syne', sans-serif;
            font-size: clamp(22px, 4vw, 30px);
            font-weight: 800;
            color: #fff;
            line-height: 1.1;
        }

        .form-title span {
            color: var(--accent);
        }

        .form-subtitle {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .2em;
            color: #454e5e;
            margin-top: 4px;
        }

        /* ── Inputs ── */
        .field-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .field-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .16em;
            color: #cbd5e1;
            padding-left: 4px;
        }

        .field-input {
            width: 100%;
            background: rgba(255, 255, 255, .035);
            border: 1px solid rgba(255, 255, 255, .08);
            border-radius: 14px;
            padding: 14px 18px;
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
            color: #e2e6ef;
            outline: none;
            transition: border-color .2s, background .2s, box-shadow .2s;
        }

        .field-input:focus {
            border-color: var(--accent);
            background: var(--accent-dim);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, .08);
        }

        .field-input::placeholder {
            color: #3a424f;
        }

        /* ── Drop zones ── */
        .drop-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        @media (max-width: 480px) {
            .drop-grid {
                grid-template-columns: 1fr;
            }
        }

        .drop-zone {
            position: relative;
            border: 2px dashed rgba(255, 255, 255, .09);
            border-radius: 16px;
            padding: 24px 16px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-align: center;
            cursor: pointer;
            transition: border-color .2s, background .2s, transform .15s;
            min-height: 130px;
            background: rgba(255, 255, 255, .02);
        }

        .drop-zone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        .drop-zone:hover,
        .drop-zone.drag-over {
            border-color: var(--accent);
            background: var(--accent-dim);
            transform: translateY(-1px);
        }

        .drop-zone-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: var(--accent-dim);
            border: 1px solid var(--accent-border);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform .2s;
        }

        .drop-zone:hover .drop-zone-icon {
            transform: scale(1.1);
        }

        .drop-zone-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: #cbd5e1;
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .drop-zone-sub {
            font-size: 9px;
            color: #cbd5e1;
            font-weight: 600;
            letter-spacing: .08em;
        }

        .drop-zone.has-file .drop-zone-label {
            color: var(--accent);
        }

        /* Thumb preview inside drop zone */
        .thumb-mini {
            width: 56px;
            height: 36px;
            border-radius: 8px;
            object-fit: cover;
            display: none;
            border: 1px solid var(--border-strong);
        }

        /* ── Alert banners ── */
        .alert {
            padding: 14px 16px;
            border-radius: 14px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: rgba(34, 197, 94, .08);
            border: 1px solid rgba(34, 197, 94, .2);
            color: #4ade80;
        }

        /* ── Primary button ── */
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--accent);
            color: #fff;
            font-family: 'Syne', sans-serif;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
            padding: 15px 28px;
            border-radius: 14px;
            border: none;
            cursor: pointer;
            transition: background .2s, transform .15s, box-shadow .2s;
            box-shadow: 0 4px 20px rgba(239, 68, 68, .22);
            width: 100%;
        }

        .btn-primary:hover {
            background: #f87171;
            transform: translateY(-1px);
            box-shadow: 0 8px 30px rgba(239, 68, 68, .32);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        /* ── Divider ── */
        .divider {
            height: 1px;
            background: var(--border);
            margin: 20px 0;
        }

        /* ── Upload overlay ── */
        #upload-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 300;
            background: rgba(8, 11, 17, .92);
            backdrop-filter: blur(18px);
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 0;
        }

        #upload-overlay.active {
            display: flex;
            animation: overlayIn .3s ease;
        }

        @keyframes overlayIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .overlay-card {
            background: #0e1118;
            border: 1px solid rgba(255, 255, 255, .08);
            border-radius: 28px;
            padding: 40px 44px;
            width: 100%;
            max-width: 440px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 24px;
            animation: cardUp .35s cubic-bezier(.34, 1.56, .64, 1);
        }

        @keyframes cardUp {
            from {
                transform: translateY(24px) scale(.97);
                opacity: 0;
            }

            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }

        /* spinning ring */
        .upload-ring {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            border: 3px solid rgba(239, 68, 68, .15);
            border-top-color: var(--accent);
            border-right-color: rgba(239, 68, 68, .5);
            animation: ringSpin 1s linear infinite;
            position: relative;
            flex-shrink: 0;
        }

        .upload-ring-inner {
            position: absolute;
            inset: 8px;
            border-radius: 50%;
            border: 2px solid rgba(239, 68, 68, .1);
            border-top-color: rgba(239, 68, 68, .4);
            animation: ringSpin .7s linear infinite reverse;
        }

        @keyframes ringSpin {
            to {
                transform: rotate(360deg);
            }
        }

        .overlay-title {
            font-family: 'Syne', sans-serif;
            font-size: 18px;
            font-weight: 800;
            color: #fff;
            text-align: center;
        }

        .overlay-filename {
            font-size: 11px;
            font-weight: 600;
            color: #555e6e;
            text-align: center;
            max-width: 320px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* progress bar */
        .progress-track {
            width: 100%;
            height: 4px;
            background: rgba(255, 255, 255, .06);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--accent), #f87171);
            border-radius: 4px;
            width: 0%;
            transition: width .4s ease;
            animation: progressShimmer 1.5s ease-in-out infinite;
            background-size: 200% 100%;
        }

        @keyframes progressShimmer {
            0% {
                background-position: 200% center;
            }

            100% {
                background-position: -200% center;
            }
        }

        .overlay-status {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .14em;
            color: var(--accent);
            text-align: center;
            min-height: 16px;
        }

        .overlay-note {
            font-size: 10px;
            color: #353d4a;
            text-align: center;
            line-height: 1.5;
        }

        /* Footer links */
        .footer-links {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            justify-content: center;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            margin-top: 8px;
        }

        .footer-link {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: #cbd5e1;
            text-decoration: none;
            transition: color .2s;
        }

        .footer-link:hover {
            color: #e2e6ef;
        }

        .footer-link.accent {
            color: var(--accent);
        }

        .footer-link.accent:hover {
            color: #f87171;
        }

        /* ── Admin badge ── */
        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--accent-dim);
            border: 1px solid var(--accent-border);
            color: var(--accent);
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .12em;
            padding: 4px 10px;
            border-radius: 20px;
            margin-left: auto;
        }
    </style>
</head>

<body>
    <div class="page-wrap">

        <!-- Nav -->
        <nav class="top-nav">
            <a href="../index.php" class="nav-brand">MEeL<span>Video</span></a>
            <div class="nav-sep"></div>
            <a href="index.php" class="nav-crumb">Library</a>
            <span class="nav-chevron">›</span>
            <span class="nav-crumb-current">Upload</span>
            <?php if ($is_admin): ?>
                <span class="admin-badge"><i data-lucide="shield" style="width:10px;height:10px;"></i> Admin</span>
            <?php endif; ?>
        </nav>

        <div class="upload-layout">

            <!-- ── LEFT: Sidebar ── -->
            <aside class="sidebar-panel">

                <!-- Hero visual -->
                <div class="hero-icon">
                    <div class="hero-icon-ring">
                        <i data-lucide="clapperboard" style="width:28px;height:28px;color:var(--accent);"></i>
                    </div>
                    <div style="position:relative;z-index:1;text-align:center;">
                        <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:#e2e6ef;text-transform:uppercase;letter-spacing:.1em;">Upload Video</div>
                        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.16em;color:#455060;margin-top:3px;">MP4 · WEBM · MKV</div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-strip">
                    <div class="stat-chip">
                        <div class="stat-number"><?= $today_count ?></div>
                        <div class="stat-label">Hari Ini</div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-number"><?= $total_uploads ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-number" style="font-size:15px;"><?= $daily_limit ?></div>
                        <div class="stat-label">Limit/Hari</div>
                    </div>
                </div>

                <!-- Guide -->
                <div class="guide-list">
                    <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.16em;color:#455060;padding-left:2px;">Panduan Upload</div>
                    <div class="guide-item">
                        <div class="guide-icon"><i data-lucide="file-video" style="width:13px;height:13px;color:var(--accent);"></i></div>
                        <div>
                            <div class="guide-title">Format Video</div>
                            <div class="guide-desc">MP4, WEBM, atau MKV. Akan di-transcode otomatis ke HLS.</div>
                        </div>
                    </div>
                    <div class="guide-item">
                        <div class="guide-icon"><i data-lucide="image" style="width:13px;height:13px;color:var(--accent);"></i></div>
                        <div>
                            <div class="guide-title">Thumbnail</div>
                            <div class="guide-desc">Opsional. Jika tidak diupload, thumbnail digenerate otomatis dari frame video.</div>
                        </div>
                    </div>
                    <div class="guide-item">
                        <div class="guide-icon"><i data-lucide="clock" style="width:13px;height:13px;color:var(--accent);"></i></div>
                        <div>
                            <div class="guide-title">Proses Upload</div>
                            <div class="guide-desc">Video besar memerlukan waktu lebih lama. Jangan tutup tab saat proses berlangsung.</div>
                        </div>
                    </div>
                    <?php if ($is_admin): ?>
                        <div class="guide-item" style="border-color:var(--accent-border);background:var(--accent-dim);">
                            <div class="guide-icon"><i data-lucide="shield" style="width:13px;height:13px;color:var(--accent);"></i></div>
                            <div>
                                <div class="guide-title" style="color:var(--accent);">Mode Admin</div>
                                <div class="guide-desc">Tidak ada limit upload harian. Ukuran & durasi maksimum ditingkatkan.</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </aside>

            <!-- ── RIGHT: Form panel ── -->
            <section class="form-panel">
                <div class="form-header">
                    <div>
                        <h1 class="form-title">Halo, <span><?= htmlspecialchars($user) ?></span></h1>
                        <p class="form-subtitle">Tambahkan koleksi video ke library</p>
                    </div>
                    <i data-lucide="upload-cloud" style="width:36px;height:36px;color:var(--accent);opacity:.3;flex-shrink:0;margin-top:4px;"></i>
                </div>

                <?php if ($status === "success"): ?>
                    <div class="alert alert-success">
                        <i data-lucide="check-circle" style="width:15px;height:15px;flex-shrink:0;"></i>
                        Video berhasil diupload dan sedang diproses!
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" onsubmit="handleSubmit()" style="display:flex;flex-direction:column;gap:20px;flex:1;">
                    <?php if (isset($_SESSION['csrf_token'])): ?>
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                    <?php endif; ?>

                    <!-- Judul -->
                    <div class="field-group">
                        <label class="field-label" for="f-title">Judul Video</label>
                        <input type="text" id="f-title" name="title" required
                            placeholder="Masukkan judul video..."
                            class="field-input">
                    </div>

                    <!-- Deskripsi -->
                    <div class="field-group" style="flex:1;display:flex;flex-direction:column;">
                        <label class="field-label" for="f-desc">Deskripsi / Keterangan</label>
                        <textarea id="f-desc" name="description"
                            placeholder="Masukkan deskripsi video... (opsional)"
                            class="field-input" style="flex:1;min-height:100px;resize:none;"></textarea>
                    </div>

                    <div class="divider" style="margin:0;"></div>

                    <!-- Drop zones -->
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <label class="field-label">File & Thumbnail</label>
                        <div class="drop-grid">
                            <!-- Video file -->
                            <div class="drop-zone" id="video-zone">
                                <input type="file" name="video" accept=".mp4,.webm,.mkv" required
                                    id="video-input" onchange="handleVideoFile(this)" aria-label="Pilih atau drop file video (format: MP4, WEBM, MKV)">
                                <div class="drop-zone-icon">
                                    <i data-lucide="file-video" style="width:18px;height:18px;color:var(--accent);"></i>
                                </div>
                                <div class="drop-zone-label" id="video-label">Pilih / Drop Video</div>
                                <div class="drop-zone-sub">MP4 · WEBM · MKV</div>
                            </div>

                            <!-- Thumbnail -->
                            <div class="drop-zone" id="thumb-zone">
                                <input type="file" name="thumbnail" accept="image/*"
                                    id="thumb-input" onchange="handleThumbFile(this)" aria-label="Pilih atau drop file thumbnail (opsional)">
                                <img id="thumb-preview" class="thumb-mini" alt="preview">
                                <div class="drop-zone-icon" id="thumb-icon-wrap">
                                    <i data-lucide="image" style="width:18px;height:18px;color:#4a5568;"></i>
                                </div>
                                <div class="drop-zone-label" id="thumb-label">Thumbnail</div>
                                <div class="drop-zone-sub" id="thumb-sub">Opsional · Auto-generate</div>
                            </div>
                        </div>
                    </div>

                    <!-- Upload button -->
                    <div style="margin-top:auto;">
                        <button type="submit" name="upload" id="btn-upload" class="btn-primary">
                            <i data-lucide="upload" style="width:15px;height:15px;"></i>
                            Mulai Upload
                        </button>
                    </div>

                    <!-- Footer links -->
                    <div class="footer-links">
                        <a href="index.php" class="footer-link">Library</a>
                        <a href="../index.php" class="footer-link">Portal</a>
                        <a href="../music/upload.php" class="footer-link accent">Go to Music</a>
                        <a href="../upload_advanced.php" class="footer-link"
                            onclick="return meelAlertRedirect({ title:'Upload Lanjutan', text:'Anda dan Server memerlukan koneksi internet', icon:'info', redirectUrl:'../upload_advanced.php' })">
                            Upload Lanjutan
                        </a>
                    </div>
                </form>
            </section>

        </div>
        </main>

    </div>

    <?php include '../partials/footer.php'; ?>

    <!-- ── Upload Overlay ── -->
    <div id="upload-overlay">
        <div class="overlay-card">
            <div class="upload-ring">
                <div class="upload-ring-inner"></div>
            </div>
            <div style="width:100%;text-align:center;display:flex;flex-direction:column;gap:8px;">
                <div class="overlay-title">Mengupload Video...</div>
                <div class="overlay-filename" id="overlay-filename">Mempersiapkan file</div>
            </div>
            <div style="width:100%;display:flex;flex-direction:column;gap:8px;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div class="overlay-status" id="overlay-status">Mengirim ke server</div>
                    <div id="overlay-pct" style="font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:#e2e6ef;">0%</div>
                </div>
                <div class="progress-track">
                    <div class="progress-bar" id="progress-bar"></div>
                </div>
            </div>
            <div class="overlay-note">
                Jangan tutup atau refresh halaman ini.<br>
                Video besar memerlukan waktu lebih lama.
            </div>
        </div>
    </div>
    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <script src="../assets/js/script.min.js"></script>
    <script>
        lucide.createIcons();

        <?php if ($alert_message !== ""): ?>
            meelAlertRedirect({
                title: 'Upload Video',
                text: <?= json_encode($alert_message) ?>,
                icon: 'warning',
                redirectUrl: 'upload.php'
            });
        <?php endif; ?>

        <?php if ($status === "success"): ?>
            Swal.fire({
                title: 'Berhasil!',
                text: 'Video telah diupload dan sedang diproses.',
                icon: 'success',
                confirmButtonColor: '#ef4444',
                background: '#0e1118',
                color: '#fff'
            });
        <?php endif; ?>

        function handleVideoFile(input) {
            const file = input.files[0];
            if (!file) return;
            const ext = file.name.split('.').pop().toLowerCase();
            const allowed = ['mp4', 'webm', 'mkv'];
            if (!allowed.includes(ext)) {
                meelAlert({
                    title: 'Format Ditolak',
                    text: 'Gunakan MP4, WEBM, atau MKV.',
                    icon: 'error'
                });
                input.value = '';
                return;
            }
            const zone = document.getElementById('video-zone');
            const label = document.getElementById('video-label');
            label.textContent = file.name;
            zone.classList.add('has-file');
        }

        function handleThumbFile(input) {
            if (!input.files || !input.files[0]) return;
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('thumb-preview');
                const iconWrap = document.getElementById('thumb-icon-wrap');
                const label = document.getElementById('thumb-label');
                const sub = document.getElementById('thumb-sub');
                const zone = document.getElementById('thumb-zone');
                preview.src = e.target.result;
                preview.style.display = 'block';
                iconWrap.style.display = 'none';
                label.textContent = input.files[0].name;
                sub.textContent = '';
                zone.classList.add('has-file');
            };
            reader.readAsDataURL(input.files[0]);
        }

        function handleSubmit() {
            const videoInput = document.getElementById('video-input');
            const titleInput = document.getElementById('f-title');
            const overlay = document.getElementById('upload-overlay');
            const fname = document.getElementById('overlay-filename');
            const status = document.getElementById('overlay-status');
            const bar = document.getElementById('progress-bar');
            const pct = document.getElementById('overlay-pct');
            const btn = document.getElementById('btn-upload');

            // Tampilkan nama file
            if (videoInput.files[0]) {
                fname.textContent = videoInput.files[0].name;
            } else if (titleInput.value) {
                fname.textContent = titleInput.value;
            }

            // Nonaktifkan tombol
            btn.style.opacity = '.5';
            btn.style.pointerEvents = 'none';

            // Tampilkan overlay
            overlay.classList.add('active');

            // Status messages — cycling manual karena tidak ada real-time progress
            // (upload biasa, bukan streaming Transcoder)
            const phases = [{
                    msg: 'Mengirim file ke server…',
                    pctVal: 5
                },
                {
                    msg: 'File sedang diproses…',
                    pctVal: 30
                },
                {
                    msg: 'Menyimpan ke library…',
                    pctVal: 60
                },
                {
                    msg: 'Menyelesaikan proses…',
                    pctVal: 85
                },
            ];

            // Estimasi waktu berdasarkan ukuran file
            const fileSizeMB = videoInput.files[0] ? videoInput.files[0].size / 1024 / 1024 : 50;
            const baseDelay = Math.max(3000, Math.min(fileSizeMB * 120, 20000)); // 3s–20s
            const phaseDelay = baseDelay / phases.length;

            let phaseIdx = 0;

            function advancePhase() {
                if (phaseIdx >= phases.length) return;
                const p = phases[phaseIdx];
                status.textContent = p.msg;
                bar.style.width = p.pctVal + '%';
                pct.textContent = p.pctVal + '%';
                phaseIdx++;
                if (phaseIdx < phases.length) {
                    setTimeout(advancePhase, phaseDelay);
                }
            }

            advancePhase();

            // Biarkan form submit biasa berjalan — jangan intercept dengan XHR
            // PHP akan memproses dan redirect/reload sendiri
        }

        // Pastikan form submit normal (tidak diblock)
        document.querySelector('form').addEventListener('submit', function() {
            handleSubmit();
            // return true — biarkan browser submit form seperti biasa
        });

        // Drag-and-drop for video zone
        const videoZone = document.getElementById('video-zone');
        const videoInput = document.getElementById('video-input');
        videoZone.addEventListener('dragover', e => {
            e.preventDefault();
            videoZone.classList.add('drag-over');
        });
        videoZone.addEventListener('dragleave', () => videoZone.classList.remove('drag-over'));
        videoZone.addEventListener('drop', e => {
            e.preventDefault();
            videoZone.classList.remove('drag-over');
            const files = e.dataTransfer.files;
            if (files[0]) {
                const dt = new DataTransfer();
                dt.items.add(files[0]);
                videoInput.files = dt.files;
                handleVideoFile(videoInput);
            }
        });

        // Drag-and-drop for thumb zone
        const thumbZone = document.getElementById('thumb-zone');
        const thumbInput = document.getElementById('thumb-input');
        thumbZone.addEventListener('dragover', e => {
            e.preventDefault();
            thumbZone.classList.add('drag-over');
        });
        thumbZone.addEventListener('dragleave', () => thumbZone.classList.remove('drag-over'));
        thumbZone.addEventListener('drop', e => {
            e.preventDefault();
            thumbZone.classList.remove('drag-over');
            const files = e.dataTransfer.files;
            if (files[0] && files[0].type.startsWith('image/')) {
                const dt = new DataTransfer();
                dt.items.add(files[0]);
                thumbInput.files = dt.files;
                handleThumbFile(thumbInput);
            }
        });

        const style = document.createElement('style');
        style.textContent = '@keyframes spin { to { transform:rotate(360deg); } }';
        document.head.appendChild(style);
    </script>
</body>

</html>