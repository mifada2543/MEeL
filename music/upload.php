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

// Upload hari ini
$stmt_count = $conn->prepare("SELECT COUNT(*) AS c FROM music WHERE user_id = ? AND DATE(upload_date) = CURDATE()");
$stmt_count->bind_param("i", $user_id);
$stmt_count->execute();
$today_count = (int)$stmt_count->get_result()->fetch_assoc()['c'];

// Total upload
$stmt_total = $conn->prepare("SELECT COUNT(*) AS c FROM music WHERE user_id = ?");
$stmt_total->bind_param("i", $user_id);
$stmt_total->execute();
$total_uploads = (int)$stmt_total->get_result()->fetch_assoc()['c'];

$daily_limit = $is_admin ? '∞' : '5';

$uploader = new Uploader($conn, $user_id, $user);

if (isset($_POST['upload'])) {
    verify_csrf();
    $result = $uploader->processMusic($_POST, $_FILES, __DIR__ . "/");

    if ($result['status'] === 'success') {
        $status = "success";
        $today_count++;
        $total_uploads++;
        MediaLibrary::clearCountsCache();
        log_activity($conn, $user_id, 'upload_music', 'music', (int)($result['id'] ?? 0));
    } elseif (isset($result['alert']) && $result['alert'] == true) {
        $alert_message = $result['msg'];
    } else {
        die("<div style='color:red;'>Error: {$result['msg']}</div>");
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Upload musik ke MEeL Music Library. Format audio didukung: FLAC, MP3, WAV, OPUS, OGG, M4A.">
    <meta property="og:title" content="Upload | MEeL Music">
    <meta property="og:description" content="Upload musik ke MEeL Music Library. Format audio: FLAC, MP3, WAV, OPUS, OGG, M4A.">
    <title>Upload | MEeL Music</title>
    <?php include '../partials/link.php'; ?>
    <style>
        @import url('../assets/css/font.css');

        :root {
            --accent: #f97316;
            --accent-dim: rgba(249, 115, 22, .10);
            --accent-border: rgba(249, 115, 22, .22);
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
            color: #cbd5e1;
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

        /* ── Hero — waveform visual ── */
        .hero-waveform {
            width: 100%;
            aspect-ratio: 16/9;
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(249, 115, 22, .14) 0%, rgba(8, 11, 17, 0) 70%);
            border: 1px solid var(--accent-border);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 14px;
            position: relative;
            overflow: hidden;
        }

        /* animated waveform bars */
        .waveform-bars {
            display: flex;
            align-items: center;
            gap: 4px;
            height: 40px;
            position: relative;
            z-index: 1;
        }

        .waveform-bars span {
            display: block;
            width: 4px;
            border-radius: 2px;
            background: var(--accent);
            opacity: .6;
            animation: wavebar 1.2s ease-in-out infinite alternate;
        }

        .waveform-bars span:nth-child(1) {
            height: 10px;
            animation-delay: 0s;
        }

        .waveform-bars span:nth-child(2) {
            height: 22px;
            animation-delay: .1s;
        }

        .waveform-bars span:nth-child(3) {
            height: 36px;
            animation-delay: .2s;
        }

        .waveform-bars span:nth-child(4) {
            height: 28px;
            animation-delay: .3s;
        }

        .waveform-bars span:nth-child(5) {
            height: 40px;
            animation-delay: .15s;
        }

        .waveform-bars span:nth-child(6) {
            height: 22px;
            animation-delay: .25s;
        }

        .waveform-bars span:nth-child(7) {
            height: 32px;
            animation-delay: .05s;
        }

        .waveform-bars span:nth-child(8) {
            height: 18px;
            animation-delay: .35s;
        }

        .waveform-bars span:nth-child(9) {
            height: 28px;
            animation-delay: .1s;
        }

        .waveform-bars span:nth-child(10) {
            height: 14px;
            animation-delay: .2s;
        }

        .waveform-bars span:nth-child(11) {
            height: 38px;
            animation-delay: .3s;
        }

        .waveform-bars span:nth-child(12) {
            height: 20px;
            animation-delay: .15s;
        }

        @keyframes wavebar {
            from {
                transform: scaleY(0.4);
                opacity: .35;
            }

            to {
                transform: scaleY(1);
                opacity: .85;
            }
        }

        /* ── Stats ── */
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

        /* ── Guide ── */
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
            box-shadow: 0 0 0 3px rgba(249, 115, 22, .08);
        }

        .field-input::placeholder {
            color: #3a424f;
        }

        /* ── Two-col ── */
        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        @media (max-width: 480px) {
            .two-col {
                grid-template-columns: 1fr;
            }
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
            color: #4a5568;
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

        .thumb-mini {
            width: 56px;
            height: 56px;
            border-radius: 10px;
            object-fit: cover;
            display: none;
            border: 1px solid var(--border-strong);
        }

        /* ── Toggle (anti-transcode) ── */
        .toggle-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px;
            border-radius: 14px;
            background: var(--accent-dim);
            border: 1px solid var(--accent-border);
        }

        .toggle-switch {
            position: relative;
            display: inline-flex;
            align-items: center;
            cursor: pointer;
        }

        .toggle-switch input {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-track {
            width: 36px;
            height: 20px;
            background: rgba(255, 255, 255, .1);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, .1);
            transition: background .2s;
            position: relative;
        }

        .toggle-switch input:checked+.toggle-track {
            background: var(--accent);
            border-color: var(--accent);
        }

        .toggle-track::after {
            content: '';
            position: absolute;
            left: 2px;
            top: 2px;
            width: 14px;
            height: 14px;
            background: #fff;
            border-radius: 50%;
            transition: transform .2s;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .3);
        }

        .toggle-switch input:checked+.toggle-track::after {
            transform: translateX(16px);
        }

        /* ── Alerts ── */
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

        /* ── Buttons ── */
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--accent);
            color: #000;
            font-family: 'Syne', sans-serif;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
            padding: 15px 28px;
            border-radius: 14px;
            border: none;
            cursor: pointer;
            width: 100%;
            transition: background .2s, transform .15s, box-shadow .2s;
            box-shadow: 0 4px 20px rgba(249, 115, 22, .25);
        }

        .btn-primary:hover {
            background: #fb923c;
            transform: translateY(-1px);
            box-shadow: 0 8px 30px rgba(249, 115, 22, .35);
        }

        /* ── Auto-metadata button ── */
        .btn-auto {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: linear-gradient(135deg, rgba(249, 115, 22, .12) 0%, rgba(168, 85, 247, .12) 100%);
            color: var(--accent);
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
            padding: 6px 12px;
            border-radius: 8px;
            border: 1px solid rgba(249, 115, 22, .2);
            cursor: pointer;
            transition: background .2s, transform .15s, box-shadow .2s, border-color .2s;
            font-family: 'Syne', sans-serif;
        }

        .btn-auto:hover {
            background: linear-gradient(135deg, rgba(249, 115, 22, .2) 0%, rgba(168, 85, 247, .2) 100%);
            border-color: var(--accent);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(249, 115, 22, .15);
        }

        .btn-auto:disabled {
            opacity: .4;
            pointer-events: none;
            transform: none;
        }

        .auto-spinner {
            width: 10px;
            height: 10px;
            border: 2px solid rgba(249, 115, 22, .25);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: autoSpin .7s linear infinite;
        }

        @keyframes autoSpin {
            to { transform: rotate(360deg); }
        }

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

        /* waveform spinner — music themed */
        .upload-wave {
            display: flex;
            align-items: center;
            gap: 5px;
            height: 56px;
        }

        .upload-wave span {
            display: block;
            width: 5px;
            border-radius: 3px;
            background: var(--accent);
            animation: waveUp 1s ease-in-out infinite alternate;
        }

        .upload-wave span:nth-child(1) {
            height: 14px;
            animation-delay: 0s;
        }

        .upload-wave span:nth-child(2) {
            height: 30px;
            animation-delay: .1s;
        }

        .upload-wave span:nth-child(3) {
            height: 46px;
            animation-delay: .2s;
        }

        .upload-wave span:nth-child(4) {
            height: 36px;
            animation-delay: .05s;
        }

        .upload-wave span:nth-child(5) {
            height: 56px;
            animation-delay: .15s;
        }

        .upload-wave span:nth-child(6) {
            height: 40px;
            animation-delay: .25s;
        }

        .upload-wave span:nth-child(7) {
            height: 52px;
            animation-delay: .1s;
        }

        .upload-wave span:nth-child(8) {
            height: 28px;
            animation-delay: .3s;
        }

        .upload-wave span:nth-child(9) {
            height: 44px;
            animation-delay: .2s;
        }

        .upload-wave span:nth-child(10) {
            height: 18px;
            animation-delay: .05s;
        }

        @keyframes waveUp {
            from {
                transform: scaleY(.3);
                opacity: .35;
            }

            to {
                transform: scaleY(1);
                opacity: 1;
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

        .progress-track {
            width: 100%;
            height: 4px;
            background: rgba(255, 255, 255, .06);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--accent), #fb923c);
            border-radius: 4px;
            width: 0%;
            transition: width .4s ease;
            background-size: 200% 100%;
            animation: shimmer 1.5s ease-in-out infinite;
        }

        @keyframes shimmer {
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
            color: #fb923c;
        }

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
            <a href="../index.php" class="nav-brand">MEeL<span>Music</span></a>
            <div class="nav-sep"></div>
            <a href="index.php" class="nav-crumb">Library</a>
            <span class="nav-chevron">›</span>
            <span class="nav-crumb-current">Upload</span>
            <?php if ($is_admin): ?>
                <span class="admin-badge"><i data-lucide="shield" style="width:10px;height:10px;"></i> Admin</span>
            <?php endif; ?>
        </nav>

        <main>
            <div class="upload-layout">

            <!-- ── LEFT: Sidebar ── -->
            <aside class="sidebar-panel">

                <!-- Hero waveform -->
                <div class="hero-waveform">
                    <div class="waveform-bars">
                        <span></span><span></span><span></span><span></span>
                        <span></span><span></span><span></span><span></span>
                        <span></span><span></span><span></span><span></span>
                    </div>
                    <div style="position:relative;z-index:1;text-align:center;">
                        <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:#e2e6ef;text-transform:uppercase;letter-spacing:.1em;">Upload Musik</div>
                        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.16em;color:#cbd5e1;margin-top:3px;">FLAC · MP3 · WAV · OPUS</div>
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
                        <div class="guide-icon"><i data-lucide="file-audio" style="width:13px;height:13px;color:var(--accent);"></i></div>
                        <div>
                            <div class="guide-title">Format Audio</div>
                            <div class="guide-desc">FLAC, MP3, WAV, OPUS, OGG, atau M4A. Auto-transcode ke Opus untuk efisiensi.</div>
                        </div>
                    </div>
                    <div class="guide-item">
                        <div class="guide-icon"><i data-lucide="image" style="width:13px;height:13px;color:var(--accent);"></i></div>
                        <div>
                            <div class="guide-title">Cover Art</div>
                            <div class="guide-desc">Opsional. Jika tidak diupload, cover diambil dari metadata file audio (ID3/FLAC).</div>
                        </div>
                    </div>
                    <div class="guide-item">
                        <div class="guide-icon"><i data-lucide="clock" style="width:13px;height:13px;color:var(--accent);"></i></div>
                        <div>
                            <div class="guide-title">Durasi Maks.</div>
                            <div class="guide-desc"><?= $is_admin ? 'Admin: tidak terbatas durasi.' : 'User: maksimal 5 menit (300 detik).' ?></div>
                        </div>
                    </div>
                    <?php if ($is_admin): ?>
                        <div class="guide-item" style="border-color:var(--accent-border);background:var(--accent-dim);">
                            <div class="guide-icon"><i data-lucide="shield" style="width:13px;height:13px;color:var(--accent);"></i></div>
                            <div>
                                <div class="guide-title" style="color:var(--accent);">Mode Admin</div>
                                <div class="guide-desc">Akses Anti Transcode tersedia. Simpan file audio asli tanpa kompresi Opus.</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </aside>

            <!-- ── RIGHT: Form panel ── -->
            <section class="form-panel">
                <div class="form-header">
                    <div>
                        <h1 class="form-title">Add New <span>Track</span></h1>
                        <p class="form-subtitle">Tambahkan lagu ke music library</p>
                    </div>
                    <i data-lucide="music-2" style="width:36px;height:36px;color:var(--accent);opacity:.3;flex-shrink:0;margin-top:4px;"></i>
                </div>

                <?php if ($status === "success"): ?>
                    <div class="alert alert-success">
                        <i data-lucide="check-circle" style="width:15px;height:15px;flex-shrink:0;"></i>
                        Berhasil ditambahkan ke Music Library!
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" onsubmit="handleSubmit()" style="display:flex;flex-direction:column;gap:20px;flex:1;">
                    <?php if (isset($_SESSION['csrf_token'])): ?>
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                    <?php endif; ?>

                    <!-- Judul + Auto-fill -->
                    <div class="field-group">
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <label class="field-label" for="f-title">Judul Lagu</label>
                            <button type="button" id="btn-auto-meta" class="btn-auto"
                                onclick="autoFillMetadata()"
                                title="Isi otomatis dari metadata file audio (ID3/FLAC)">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 4V2"/><path d="M15 16V8"/><path d="M9 10V2"/><path d="M9 22V16"/><path d="M12 10h.01"/><path d="M12 16h.01"/></svg>
                                Auto
                            </button>
                        </div>
                        <input type="text" id="f-title" name="title" required
                            placeholder="Song Title..."
                            class="field-input">
                    </div>

                    <!-- Artis & Album -->
                    <div class="two-col">
                        <div class="field-group">
                            <label class="field-label" for="f-artist">Artis</label>
                            <input type="text" id="f-artist" name="artist" required
                                placeholder="Artist..." class="field-input">
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="f-album">Album</label>
                            <input type="text" id="f-album" name="album"
                                placeholder="Album (Opsional)..." class="field-input">
                        </div>
                    </div>

                    <!-- Deskripsi -->
                    <div class="field-group" style="flex:1;display:flex;flex-direction:column;">
                        <label class="field-label" for="f-desc">Deskripsi / Keterangan</label>
                        <textarea id="f-desc" name="description"
                            placeholder="Masukkan deskripsi lagu... (opsional)"
                            class="field-input" style="flex:1;min-height:100px;resize:none;"></textarea>
                    </div>

                    <div class="divider" style="margin:0;"></div>

                    <!-- Drop zones -->
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <label class="field-label">File Audio & Cover Art</label>
                        <div class="drop-grid">
                            <!-- Audio file -->
                            <div class="drop-zone" id="audio-zone">
                                <input type="file" name="media" accept="audio/*" required
                                    id="audio-input" onchange="handleAudioFile(this)" aria-label="Pilih atau drop file audio untuk upload lagu">
                                <div class="drop-zone-icon">
                                    <i data-lucide="file-audio" style="width:18px;height:18px;color:var(--accent);"></i>
                                </div>
                                <div class="drop-zone-label" id="audio-label">Drag &amp; Drop Audio</div>
                                <div class="drop-zone-sub">FLAC · MP3 · WAV · OPUS</div>
                            </div>

                            <!-- Cover art -->
                            <div class="drop-zone" id="cover-zone">
                                <input type="file" name="thumbnail" accept="image/*"
                                    id="cover-input" onchange="handleCoverFile(this)" aria-label="Pilih atau drop cover art untuk lagu">
                                <img id="cover-preview" class="thumb-mini" alt="preview">
                                <div class="drop-zone-icon" id="cover-icon-wrap">
                                    <i data-lucide="image" style="width:18px;height:18px;color:#4a5568;"></i>
                                </div>
                                <div class="drop-zone-label" id="cover-label">Cover Art</div>
                                <div class="drop-zone-sub" id="cover-sub">Opsional · Auto dari ID3</div>
                            </div>
                        </div>
                    </div>

                    <?php if ($is_admin): ?>
                        <!-- Anti-transcode toggle (admin only) -->
                        <div class="toggle-card">
                            <div>
                                <div style="font-size:11px;font-weight:700;color:var(--accent);text-transform:uppercase;letter-spacing:.1em;">Anti Transcode</div>
                                <div style="font-size:10px;color:#cbd5e1;margin-top:2px;">Simpan file asli tanpa konversi ke Opus</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="skip_transcode">
                                <div class="toggle-track"></div>
                            </label>
                        </div>
                    <?php endif; ?>

                    <!-- Upload button -->
                    <div style="margin-top:auto;">
                        <button type="submit" name="upload" id="btn-upload" class="btn-primary">
                            <i data-lucide="upload" style="width:15px;height:15px;"></i>
                            Save to MEeL Music
                        </button>
                    </div>

                    <!-- Footer links -->
                    <div class="footer-links">
                        <a href="index.php" class="footer-link">Library</a>
                        <a href="../index.php" class="footer-link">Portal</a>
                        <a href="../video/upload.php" class="footer-link accent">Go to Video</a>
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
            <div class="upload-wave">
                <span></span><span></span><span></span><span></span><span></span>
                <span></span><span></span><span></span><span></span><span></span>
            </div>
            <div style="width:100%;text-align:center;display:flex;flex-direction:column;gap:8px;">
                <div class="overlay-title">Mengupload Musik...</div>
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
                File FLAC besar memerlukan waktu lebih lama.
            </div>
        </div>
    </div>
    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <script src="../assets/js/script.min.js"></script>
    <script>
        lucide.createIcons();

        <?php if ($alert_message !== ""): ?>
            meelAlertRedirect({
                title: 'Upload Music',
                text: <?= json_encode($alert_message) ?>,
                icon: 'warning',
                redirectUrl: 'upload.php'
            });
        <?php endif; ?>

        <?php if ($status === "success"): ?>
            Swal.fire({
                title: 'Berhasil!',
                text: 'Lagu berhasil ditambahkan ke Music Library.',
                icon: 'success',
                confirmButtonColor: '#f97316',
                background: '#0e1118',
                color: '#fff'
            });
        <?php endif; ?>

        function handleAudioFile(input) {
            if (!input.files || !input.files[0]) return;
            const zone = document.getElementById('audio-zone');
            const label = document.getElementById('audio-label');
            label.textContent = input.files[0].name;
            zone.classList.add('has-file');
        }

        function handleCoverFile(input) {
            if (!input.files || !input.files[0]) return;
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('cover-preview');
                const iconWrap = document.getElementById('cover-icon-wrap');
                const label = document.getElementById('cover-label');
                const sub = document.getElementById('cover-sub');
                const zone = document.getElementById('cover-zone');
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
            const audioInput = document.getElementById('audio-input');
            const titleInput = document.getElementById('f-title');
            const overlay = document.getElementById('upload-overlay');
            const fname = document.getElementById('overlay-filename');
            const status = document.getElementById('overlay-status');
            const bar = document.getElementById('progress-bar');
            const pct = document.getElementById('overlay-pct');
            const btn = document.getElementById('btn-upload');

            // Tampilkan nama file
            if (audioInput.files[0]) {
                fname.textContent = audioInput.files[0].name;
            } else if (titleInput.value) {
                fname.textContent = titleInput.value;
            }

            btn.style.opacity = '.5';
            btn.style.pointerEvents = 'none';
            overlay.classList.add('active');

            // Fase animasi — estimasi berdasarkan ukuran file
            const fileSizeMB = audioInput.files[0] ? audioInput.files[0].size / 1024 / 1024 : 20;
            const baseDelay = Math.max(2000, Math.min(fileSizeMB * 200, 18000)); // 2s–18s
            const phases = [{
                    msg: 'Mengirim file ke server…',
                    pctVal: 8
                },
                {
                    msg: 'Memproses audio…',
                    pctVal: 35
                },
                {
                    msg: 'Transcode ke Opus…',
                    pctVal: 65
                },
                {
                    msg: 'Menyimpan ke library…',
                    pctVal: 88
                },
            ];
            const phaseDelay = baseDelay / phases.length;
            let phaseIdx = 0;

            function advancePhase() {
                if (phaseIdx >= phases.length) return;
                const p = phases[phaseIdx];
                status.textContent = p.msg;
                bar.style.width = p.pctVal + '%';
                pct.textContent = p.pctVal + '%';
                phaseIdx++;
                if (phaseIdx < phases.length) setTimeout(advancePhase, phaseDelay);
            }

            advancePhase();
            // Form submit biasa — PHP proses & redirect sendiri
        }

        document.querySelector('form').addEventListener('submit', function() {
            handleSubmit();
        });

        // Drag-and-drop audio
        const audioZone = document.getElementById('audio-zone');
        const audioInput = document.getElementById('audio-input');
        audioZone.addEventListener('dragover', e => {
            e.preventDefault();
            audioZone.classList.add('drag-over');
        });
        audioZone.addEventListener('dragleave', () => audioZone.classList.remove('drag-over'));
        audioZone.addEventListener('drop', e => {
            e.preventDefault();
            audioZone.classList.remove('drag-over');
            const files = e.dataTransfer.files;
            if (files[0]) {
                const dt = new DataTransfer();
                dt.items.add(files[0]);
                audioInput.files = dt.files;
                handleAudioFile(audioInput);
            }
        });

        // Drag-and-drop cover
        const coverZone = document.getElementById('cover-zone');
        const coverInput = document.getElementById('cover-input');
        coverZone.addEventListener('dragover', e => {
            e.preventDefault();
            coverZone.classList.add('drag-over');
        });
        coverZone.addEventListener('dragleave', () => coverZone.classList.remove('drag-over'));
        coverZone.addEventListener('drop', e => {
            e.preventDefault();
            coverZone.classList.remove('drag-over');
            const files = e.dataTransfer.files;
            if (files[0] && files[0].type.startsWith('image/')) {
                const dt = new DataTransfer();
                dt.items.add(files[0]);
                coverInput.files = dt.files;
                handleCoverFile(coverInput);
            }
        });

        /**
         * Auto-fill metadata dari file audio via ffprobe di server.
         * Upload file ke auto_metadata.php → parse response → isi form + cover.
         */
        function autoFillMetadata() {
            const audioInput = document.getElementById('audio-input');
            if (!audioInput.files || !audioInput.files[0]) {
                Swal.fire({
                    title: 'Pilih file dulu!',
                    text: 'Silakan pilih file audio terlebih dahulu sebelum menggunakan Auto-fill.',
                    icon: 'warning',
                    confirmButtonColor: '#f97316',
                    background: '#0e1118',
                    color: '#fff'
                });
                return;
            }

            const btn = document.getElementById('btn-auto-meta');
            btn.disabled = true;
            btn.innerHTML = '<div class="auto-spinner"></div> Memproses...';

            const formData = new FormData();
            formData.append('audio', audioInput.files[0]);

            fetch('../controllers/api/auto_metadata.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    // Isi field text
                    const hasTitle  = data.title  && data.title.trim() !== '';
                    const hasArtist = data.artist && data.artist.trim() !== '';
                    const hasAlbum  = data.album  && data.album.trim() !== '';

                    if (hasTitle)  document.getElementById('f-title').value   = data.title.trim();
                    if (hasArtist) document.getElementById('f-artist').value  = data.artist.trim();
                    if (hasAlbum)  document.getElementById('f-album').value   = data.album.trim();

                    // Isi cover art dari metadata
                    if (data.cover && data.cover.length > 0) {
                        const preview = document.getElementById('cover-preview');
                        const iconWrap = document.getElementById('cover-icon-wrap');
                        const label = document.getElementById('cover-label');
                        const sub = document.getElementById('cover-sub');
                        const zone = document.getElementById('cover-zone');
                        preview.src = 'data:image/jpeg;base64,' + data.cover;
                        preview.style.display = 'block';
                        iconWrap.style.display = 'none';
                        label.textContent = 'Cover dari metadata';
                        sub.textContent = '';
                        zone.classList.add('has-file');
                    }

                    if (!hasTitle && !hasArtist && !hasAlbum && !data.cover) {
                        Swal.fire({
                            title: 'Metadata tidak ditemukan',
                            text: 'File ini tidak memiliki metadata ID3/FLAC yang bisa dibaca.',
                            icon: 'info',
                            confirmButtonColor: '#f97316',
                            background: '#0e1118',
                            color: '#fff'
                        });
                    } else {
                        Swal.fire({
                            title: 'Metadata ditemukan!',
                            text: 'Formulir telah diisi otomatis dari metadata file audio.',
                            icon: 'success',
                            confirmButtonColor: '#f97316',
                            background: '#0e1118',
                            color: '#fff',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    }
                } else {
                    Swal.fire({
                        title: 'Gagal',
                        text: data.message || 'Tidak dapat membaca metadata dari file ini.',
                        icon: 'error',
                        confirmButtonColor: '#f97316',
                        background: '#0e1118',
                        color: '#fff'
                    });
                }
            })
            .catch(err => {
                console.error('Auto-metadata error:', err);
                Swal.fire({
                    title: 'Error',
                    text: 'Terjadi kesalahan koneksi saat memproses metadata.',
                    icon: 'error',
                    confirmButtonColor: '#f97316',
                    background: '#0e1118',
                    color: '#fff'
                });
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 4V2"/><path d="M15 16V8"/><path d="M9 10V2"/><path d="M9 22V16"/><path d="M12 10h.01"/><path d="M12 16h.01"/></svg> Auto';
            });
        }

        const style = document.createElement('style');
        style.textContent = '@keyframes spin { to { transform:rotate(360deg); } }';
        document.head.appendChild(style);
    </script>
</body>

</html>