<?php
require_once '../modules/auth/helpers/session.php';
meel_boot_session();
require_once '../auth/config.php';
require_once '../modules/media/ProfileRepository.php';
$back_url = '../';
$is_logged_in = isset($_SESSION['user_id']);
$is_guest_profile = false;

$allowed_hosts = [
    defined('MEEL_HOST') && !empty(MEEL_HOST) ? MEEL_HOST : ($_SERVER['HTTP_HOST'] ?? ''),
    'localhost',
    '127.0.0.1',
    '::1',
];

if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
    $ref_host = parse_url($ref, PHP_URL_HOST);

    $host_valid = false;
    foreach ($allowed_hosts as $allowed) {
        if ($allowed !== '' && $ref_host === $allowed) {
            $host_valid = true;
            break;
        }
    }

    if ($host_valid) {
        $ref_path = parse_url($ref, PHP_URL_PATH);
        $excluded_pages = ['profile_edit.php', 'edit', 'index.php', 'manage.php', 'manage', 'mfa_setup.php', 'mfa-setup', 'mfa_backup.php', 'edit-music.php', 'edit-video.php', '/profile'];
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
$target_user = $_GET['u'] ?? '';

if (empty($target_user)) {
    if ($is_logged_in) {
        header("Location: " . base_url('/profile/' . rawurlencode($_SESSION['username'])));
        exit;
    }
    $is_guest_profile = true;
    $u = [
        'id' => 0,
        'username' => 'Guest',
        'bio' => 'Akun Guest',
        'role' => 'guest',
        'profile_picture' => 'default_avatar.png',
        'last_activity' => date('Y-m-d H:i:s'),
    ];
    $profile_id = 0;
    $total_video = 0;
    $total_music = 0;
    $total_uploads = 0;
    $is_online = false;
} elseif ($target_user === 'guest') {
    $is_guest_profile = true;
    $u = [
        'id' => 0,
        'username' => 'Guest',
        'bio' => 'Akun Guest',
        'role' => 'guest',
        'profile_picture' => 'default_avatar.png',
        'last_activity' => date('Y-m-d H:i:s'),
    ];
    $profile_id = 0;
    $total_video = 0;
    $total_music = 0;
    $total_uploads = 0;
    $is_online = false;
} else {
    $profileRepo = new ProfileRepository($conn);
    $u = $profileRepo->findByUsername($target_user);

    if (!$u) {
        header("Location: ../err/?code=not_found");
        exit;
    }

    $profile_id = $u['id'];

    $total_video  = $profileRepo->countVideo($profile_id);
    $total_music  = $profileRepo->countMusic($profile_id);

    $total_uploads = $total_video + $total_music;
    $is_online = (strtotime($u['last_activity']) > strtotime("-5 minutes"));
}

// Tab konten: all | video | music (default all — halaman profil sekaligus channel)
$active_tab = $_GET['tab'] ?? 'all';
if (!in_array($active_tab, ['all', 'video', 'music'], true)) {
    $active_tab = 'all';
}

// Satu feed konten: tab "all" mencampur video+musik (UNION) dalam satu grid
// & satu tombol load-more. Batch awal dirender di sini, sisanya via htmx
// (channel_more.php) dijajarkan di grid yang sama.
$items        = [];
$initial_batch = 12;
$has_more      = false;

if (!$is_guest_profile) {
    if ($active_tab === 'video') {
        $items    = $profileRepo->getVideosPaginated($profile_id, $initial_batch, 0);
        $has_more = count($items) < $total_video;
    } elseif ($active_tab === 'music') {
        $items    = $profileRepo->getMusicPaginated($profile_id, $initial_batch, 0);
        $has_more = count($items) < $total_music;
    } else {
        $items    = $profileRepo->getFeedPaginated($profile_id, $initial_batch, 0);
        $has_more = count($items) < ($total_video + $total_music);
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="<?= htmlspecialchars($u['username']) ?> — MEeL Profile">
    <meta property="og:description" content="Profil <?= htmlspecialchars($u['username']) ?> di MEeL - Platform Media Hub Pribadi.">
    <title><?= htmlspecialchars($u['username']) ?> | MEeL</title>
    <?php include '../partials/link.php'; ?>
    <style>        body {
            background-color: var(--meel-bg, #0b0e14);
        }

        .glass {
            background: var(--meel-surface, rgba(22, 27, 34, 0.7));
            backdrop-filter: blur(12px);
            border: 1px solid var(--meel-border, rgba(255, 255, 255, 0.05));
        }

        html[data-theme="light"] .glass {
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid rgba(0, 0, 0, 0.08);
        }

        .profile-banner-gradient {
            background: linear-gradient(to bottom, rgba(0,0,0,0.2), rgba(0,0,0,0.3) 50%, var(--meel-bg, #0b0e14));
        }
        html[data-theme="light"] .profile-banner-gradient {
            background: linear-gradient(to bottom, rgba(0,0,0,0.05), rgba(0,0,0,0.08) 50%, var(--meel-bg, #fafafa));
        }

        .mfa-switch {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            padding: 0;
            background: none;
            border: none;
            text-decoration: none;
            transition: opacity 0.2s;
        }
        .mfa-switch:hover { opacity: 0.85; }
        .mfa-switch:active { transform: scale(0.97); }
        .mfa-switch:focus-visible {
            outline: 2px solid #a855f7;
            outline-offset: 4px;
            border-radius: 6px;
        }

        .mfa-track {
            position: relative;
            width: 44px;
            height: 24px;
            border-radius: 99px;
            transition: background 0.3s ease;
            flex-shrink: 0;
        }
        .mfa-track--on  { background: #22c55e; box-shadow: 0 0 10px rgba(34,197,94,0.25); }
        .mfa-track--off { background: #374151; }

        .mfa-knob {
            position: absolute;
            top: 2px;
            left: 2px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 1px 4px rgba(0,0,0,0.3);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .mfa-track--on .mfa-knob {
            transform: translateX(20px);
        }

        .mfa-label {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
            white-space: nowrap;
        }
        .mfa-label--on  { color: #22c55e; }
        .mfa-label--off { color: #6b7280; }

        .mfa-label-sub {
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #6b7280;
            display: block;
            margin-top: -1px;
        }

        .stat-total,
        .stat-video,
        .stat-music {
            text-decoration: none;
            cursor: pointer;
        }
        .stat-total:hover,
        .stat-video:hover,
        .stat-music:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }
        .stat-total:hover {
            border-color: rgba(59, 130, 246, 0.5) !important;
        }
        .stat-video:hover {
            border-color: rgba(239, 68, 68, 0.5) !important;
        }
        .stat-music:hover {
            border-color: rgba(249, 115, 22, 0.5) !important;
        }

        .stat-active-total {
            border: 1px solid rgba(59, 130, 246, 0.5) !important;
            background: rgba(59, 130, 246, 0.08);
            text-decoration: none;
        }
        .stat-active-total:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.15);
        }
        .stat-active-video {
            border: 1px solid rgba(239, 68, 68, 0.5) !important;
            background: rgba(239, 68, 68, 0.08);
            text-decoration: none;
        }
        .stat-active-video:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(239, 68, 68, 0.15);
        }
        .stat-active-music {
            border: 1px solid rgba(249, 115, 22, 0.5) !important;
            background: rgba(249, 115, 22, 0.08);
            text-decoration: none;
        }
        .stat-active-music:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(249, 115, 22, 0.15);
        }

        .content-card {
            background: var(--meel-surface, rgba(20, 24, 32, 0.85));
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            overflow: hidden;
            transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }

        .content-card:hover {
            border-color: rgba(255, 255, 255, 0.12);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }

        .content-card .card-thumb {
            aspect-ratio: 16/9;
            overflow: hidden;
            background: #0b0e14;
        }

        .content-card .card-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s;
        }

        .content-card:hover .card-thumb img {
            transform: scale(1.05);
        }

        .content-card .card-body {
            padding: 12px 14px 14px;
        }

        .content-card .card-title {
            font-size: 12px;
            font-weight: 700;
            color: #e5e7eb;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .content-card .card-meta {
            font-size: 10px;
            color: #6b7280;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .type-badge {
            position: absolute;
            top: 8px;
            left: 8px;
            z-index: 2; /* tetap di atas img saat hover (img di-scale & naik layer paint) */
            font-size: 8px;
            font-weight: 900;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            padding: 3px 8px;
            border-radius: 8px;
            background: rgba(0, 0, 0, 0.65);
            backdrop-filter: blur(4px);
        }

        .type-badge.video {
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.4);
        }

        .type-badge.music {
            color: #f97316;
            border: 1px solid rgba(249, 115, 22, 0.4);
        }

        .empty-state {
            grid-column: 1 / -1;
            padding: 60px 20px;
            text-align: center;
        }

        .empty-state i {
            width: 40px;
            height: 40px;
            color: #374151;
            margin: 0 auto 16px;
            display: block;
        }

        .empty-state p {
            font-size: 11px;
            color: #6b7280;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.15em;
        }

</style>
    <link rel="stylesheet" href="../assets/css/shared/light-theme.css?v=<?= @filemtime(__DIR__ . '/../assets/css/shared/light-theme.css') ?>">
</head>

<body class="text-gray-300">

    <div class="max-w-5xl mx-auto mt-10 p-4">
        <div class="glass rounded-[2.5rem] overflow-hidden shadow-2xl">
            <?php
            $banner_pic = $u['profile_picture'] ?: 'default_avatar.png';
            $banner_path = __DIR__ . '/upload/' . $banner_pic;
            $banner_v = is_file($banner_path) ? (int)@filemtime($banner_path) : time();
            ?>
            <div class="relative h-32 w-full overflow-hidden bg-gray-800">
                <img src="upload/<?= htmlspecialchars($banner_pic, ENT_QUOTES, 'UTF-8') ?>?v=<?= $banner_v ?>" alt="Foto sampul <?= htmlspecialchars($u['username']) ?>" class="block h-full w-full scale-110" style="object-fit:cover;object-position:center;filter:blur(2px)" loading="eager" decoding="async" onerror="this.onerror=null;this.src='upload/default_avatar.png'">
                <div class="absolute inset-0 profile-banner-gradient"></div>
            </div>

            <div class="px-8 pb-8">
                <div class="relative flex justify-between items-end -mt-12">
                    <div class="relative">
                        <img src="upload/<?= htmlspecialchars($u['profile_picture'] ?: 'default_avatar.png', ENT_QUOTES, 'UTF-8') ?>"
                            class="w-32 h-32 rounded-3xl border-4 border-[var(--meel-bg,#0b0e14)] object-cover bg-gray-800 shadow-xl" title="Foto profil <?= htmlspecialchars($u['username']) ?>">
                        <?php if ($is_online): ?>
                            <div class="absolute bottom-2 right-2 w-5 h-5 bg-green-500 border-4 border-[var(--meel-bg,#0b0e14)] rounded-full"></div>
                        <?php endif; ?>
                    </div>

                    <?php
                    $is_owner = $is_logged_in && !empty($u['id']) && ($_SESSION['username'] === $u['username']);
                    if ($is_owner):
                        $_mfa_on = $profileRepo->isMfaEnabled($profile_id);
                    ?>
                        <div class="grid grid-cols-2 gap-3 mb-2">
                            <a href="../profile/edit"
                               class="bg-white/10 hover:bg-white/20 text-white px-4 py-3 rounded-2xl text-sm font-bold transition-all flex items-center justify-center gap-2"
                               title="Edit profil dan bio Anda">
                                <i data-lucide="edit-3" class="w-4 h-4"></i> Edit Profile
                            </a>
                            <a href="manage"
                               class="bg-blue-600/20 hover:bg-blue-600/30 text-blue-400 border border-blue-600/30 hover:border-blue-500/50 px-4 py-3 rounded-2xl text-sm font-bold transition-all flex items-center justify-center gap-2"
                               title="Kelola konten video dan musik Anda">
                                <i data-lucide="layout-dashboard" class="w-4 h-4"></i> Kelola Konten
                            </a>

                            <a href="../auth/mfa-setup"
                               class="mfa-switch justify-center"
                               role="link"
                               aria-label="MFA: saat ini <?= $_mfa_on ? 'aktif' : 'nonaktif' ?>. Klik untuk kelola."
                               title="Atur autentikasi dua faktor (MFA)">
                                <span class="mfa-track <?= $_mfa_on ? 'mfa-track--on' : 'mfa-track--off' ?>">
                                    <span class="mfa-knob"></span>
                                </span>
                                <span class="mfa-label <?= $_mfa_on ? 'mfa-label--on' : 'mfa-label--off' ?>">
                                    MFA
                                    <span class="mfa-label-sub"><?= $_mfa_on ? 'Aktif' : 'Nonaktif' ?></span>
                                </span>
                            </a>

                            <button type="button" id="theme-toggle" onclick="MEELTheme.toggle()"
                               class="mfa-switch justify-center cursor-pointer ml-auto"
                               style="background:none;border:none;padding:0;margin-left:auto;outline:none"
                               title="Ganti tema tampilan">
                                <span class="mfa-track mfa-track--off" id="theme-track">
                                    <span id="theme-icon" class="mfa-knob" style="font-size:14px;display:flex;align-items:center;justify-content:center;width:20px;height:20px;line-height:1">🌙</span>
                                </span>
                                <span class="mfa-label mfa-label--off" id="theme-text">
                                    Tema
                                    <span class="mfa-label-sub" id="theme-label">Gelap</span>
                                </span>
                            </button>

                            <?php if ($_mfa_on): ?>
                            <button type="button" onclick="showBackupModal()"
                                    class="bg-yellow-600/10 hover:bg-yellow-600/20 text-yellow-400 border border-yellow-600/20 hover:border-yellow-500/40 px-4 py-3 rounded-2xl text-sm font-bold transition-all flex items-center justify-center gap-2"
                                    title="Lihat atau download kode cadangan MFA">
                                <i data-lucide="key-round" class="w-4 h-4"></i>
                                Backup Codes
                            </button>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($is_guest_profile && !$is_logged_in): ?>
                        <div class="flex justify-end mb-2">
                            <button type="button" id="theme-toggle" onclick="MEELTheme.toggle()"
                               class="mfa-switch justify-center cursor-pointer"
                               style="background:none;border:none;padding:0;outline:none"
                               title="Ganti tema tampilan">
                                <span class="mfa-track mfa-track--off" id="theme-track">
                                    <span id="theme-icon" class="mfa-knob" style="font-size:14px;display:flex;align-items:center;justify-content:center;width:20px;height:20px;line-height:1">🌙</span>
                                </span>
                                <span class="mfa-label mfa-label--off" id="theme-text">
                                    Tema
                                    <span class="mfa-label-sub" id="theme-label">Gelap</span>
                                </span>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mt-6">
                    <h1 class="text-3xl font-black text-white tracking-tight italic">
                        <?= htmlspecialchars($u['username']) ?>
                        <?php if ($u['role'] === 'admin'): ?>
                            <span class="ml-2 text-[10px] bg-blue-500/20 text-blue-400 px-2 py-1 rounded-lg uppercase tracking-widest border border-blue-500/30">Staff</span>
                        <?php elseif ($u['role'] === 'member'): ?>
                            <span class="ml-2 text-[10px] bg-green-500/20 text-green-400 px-2 py-1 rounded-lg uppercase tracking-widest border border-green-500/30" title="Jadilah member untuk mendapatkan benefit berupa akses Drive">Berlangganan</span>
                        <?php elseif ($is_guest_profile): ?>
                            <span class="ml-2 text-[10px] bg-gray-500/20 text-gray-400 px-2 py-1 rounded-lg uppercase tracking-widest border border-gray-500/30">Guest</span>
                        <?php endif; ?>
                    </h1>
                    <p class="text-gray-500 text-sm mt-1">@<?= strtolower($u['username']) ?> • Profile</p>

                    <div class="mt-6 p-4 bg-white/5 rounded-2xl border border-white/5">
                        <p class="text-gray-400 text-sm italic leading-relaxed">
                            <?= $is_guest_profile ? 'Akun Guest' : htmlspecialchars($u['bio'] ?: "Pengguna ini belum menulis bio.", ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>

                    <?php if (!$is_guest_profile): ?>
                    <div class="flex gap-4 mt-8">
                        <a href="?tab=all" class="flex-1 glass p-4 rounded-2xl text-center group transition-all <?= $active_tab === 'all' ? 'stat-active-total' : 'stat-total' ?>">
                            <span class="block text-xl font-bold text-white"><?= $total_uploads ?></span>
                            <span class="text-[10px] text-gray-500 uppercase tracking-widest transition">Total Uploads</span>
                        </a>
                        <a href="?tab=video" class="flex-1 glass p-4 rounded-2xl text-center group transition-all <?= $active_tab === 'video' ? 'stat-active-video' : 'stat-video' ?>">
                            <span class="block text-xl font-bold text-white"><?= $total_video ?></span>
                            <span class="text-[10px] text-gray-500 uppercase tracking-widest transition">Videos</span>
                        </a>
                        <a href="?tab=music" class="flex-1 glass p-4 rounded-2xl text-center group transition-all <?= $active_tab === 'music' ? 'stat-active-music' : 'stat-music' ?>">
                            <span class="block text-xl font-bold text-white"><?= $total_music ?></span>
                            <span class="text-[10px] text-gray-500 uppercase tracking-widest transition">Music</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!$is_guest_profile): ?>
        <main class="mt-8">
            <?php if (empty($items)): ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    <div class="empty-state">
                        <i data-lucide="<?= $active_tab === 'music' ? 'music' : ($active_tab === 'video' ? 'play' : 'inbox') ?>" class="w-10 h-10"></i>
                        <p><?= $active_tab === 'video' ? 'Belum ada video di sini.' : ($active_tab === 'music' ? 'Belum ada musik di sini.' : 'Belum ada konten di channel ini.') ?></p>
                    </div>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    <?php foreach ($items as $item):
                        $is_music = ($item['type'] ?? $active_tab) === 'music';
                        $thumb = !empty($item['thumbnail'])
                            ? ($is_music ? '../music/upload/thumbnail/' : '../video/upload/thumbnail/') . htmlspecialchars($item['thumbnail'])
                            : ($is_music ? '../assets/img/music0.webp' : '../assets/img/video0.webp');
                        $watch = base_url(($is_music ? '/music' : '/video') . '/watch?id=' . (int)$item['id']);
                    ?>
                        <div class="content-card">
                            <a href="<?= $watch ?>" class="block card-thumb relative" title="<?= htmlspecialchars($item['title']) ?>">
                                <span class="type-badge <?= $is_music ? 'music' : 'video' ?>"><?= $is_music ? 'Music' : 'Video' ?></span>
                                <img src="<?= $thumb ?>" alt="<?= htmlspecialchars($item['title']) ?>" loading="lazy" width="640" height="360">
                            </a>
                            <div class="card-body">
                                <a href="<?= $watch ?>" class="card-title no-underline hover:text-<?= $is_music ? 'orange' : 'red' ?>-400 transition-colors" title="<?= htmlspecialchars($item['title']) ?>">
                                    <?= htmlspecialchars($item['title']) ?>
                                </a>
                                <div class="card-meta">
                                    <?php if ($is_music): ?>
                                        <span><?= htmlspecialchars($item['artist'] ?? 'Unknown') ?></span>
                                        <span>•</span>
                                        <span><?= number_format($item['views'] ?? 0) ?> views</span>
                                    <?php else: ?>
                                        <span><?= number_format($item['views'] ?? 0) ?> views</span>
                                        <span>•</span>
                                        <span><?= date('d M Y', strtotime($item['upload_date'])) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($has_more): ?>
                    <button type="button" id="channel-more-area"
                        class="col-span-full h-16 flex items-center justify-center gap-2 bg-white/[.02] border border-dashed border-white/[.06] rounded-2xl cursor-pointer hover:border-white/10 hover:bg-white/[.03] transition-all group"
                        hx-get="<?= htmlspecialchars('channel-more?u=' . rawurlencode($u['username']) . '&tab=' . $active_tab . '&offset=' . $initial_batch) ?>"
                        hx-target="#channel-more-area"
                        hx-swap="outerHTML"
                        aria-label="Muat lebih banyak konten">
                        <span class="text-[10px] font-bold uppercase tracking-[.2em] text-gray-300 group-hover:text-white transition-colors">
                            <i data-lucide="chevrons-down" class="w-3.5 h-3.5 inline-block -mr-1 mr-1.5"></i>
                            Muat Lebih Banyak <?= $active_tab === 'all' ? 'Konten' : ($active_tab === 'video' ? 'Video' : 'Musik') ?>
                        </span>
                    </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
        <?php endif; ?>

        <div class="text-center mt-10">
            <a href="<?= htmlspecialchars($back_url); ?>" class="text-gray-600 hover:text-blue-500 transition text-xs flex items-center justify-center gap-2" title="Kembali ke halaman sebelumnya">
                <i data-lucide="arrow-left" class="w-4 h-4"></i> Kembali ke Dashboard
            </a>
        </div>
    </div>    <?php include '../partials/footer.php'; ?>
    <script src="../assets/js/compatibilitas/sweetalert2.all.min.js"></script>
    <script src="../assets/js/shared/download-backup-codes.js"></script>
    <script src="../assets/js/compatibilitas/htmx.min.js"></script>
    <script src="../assets/js/shared/htmx-lucide.js?v=<?= @filemtime(__DIR__ . '/../assets/js/shared/htmx-lucide.js') ?>"></script>
    <script>
        lucide.createIcons();

        (function(){
            if (typeof MEELTheme !== 'undefined') {
                MEELTheme.init({
                    isLoggedIn: <?= json_encode(isset($_SESSION['username'])) ?>,
                    csrfToken: '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>'
                });
            }
        })();

        function showBackupModal() {
            Swal.fire({
                title: 'Kode Cadangan MFA',
                html: '<div style="font-size:12px;color:#9ca3af;margin-bottom:12px">Masukkan <strong style="color:#e5e7eb">password</strong> untuk verifikasi. Kode cadangan LAMA akan <strong style="color:#fbbf24">dinonaktifkan</strong> dan diganti dengan yang baru.</div>' +
                      '<div style="position:relative">' +
                      '  <i data-lucide="lock" style="position:absolute;left:14px;top:13px;width:16px;height:16px;color:#6b7280"></i>' +
                      '  <input id="backup-pwd-input" type="password" placeholder="Password Anda" style="width:100%;background:#0b0e14;border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:12px 12px 12px 42px;color:#fff;font-size:14px;outline:none">' +
                      '</div>',
                focusConfirm: false,
                showCancelButton: true,
                confirmButtonText: 'VERIFIKASI',
                cancelButtonText: 'BATAL',
                background: '#141820',
                color: '#fff',
                reverseButtons: true,
                didOpen: function() {
                    var input = document.getElementById('backup-pwd-input');
                    if (input) input.focus();
                    lucide.createIcons();
                },
                customClass: {
                    popup: 'border border-yellow-600/25 rounded-2xl shadow-2xl',
                    title: 'text-sm font-black uppercase tracking-wider pt-4 text-yellow-400',
                    htmlContainer: 'mt-1 mb-4 text-left',
                    confirmButton: 'bg-yellow-600 hover:bg-yellow-500 text-black text-xs font-black uppercase tracking-wider py-2.5 px-6 rounded-xl transition-all border-none cursor-pointer ml-2',
                    cancelButton: 'bg-white/5 hover:bg-white/10 text-gray-400 text-xs font-black uppercase tracking-wider py-2.5 px-6 rounded-xl border border-white/10 cursor-pointer transition-all mr-2'
                },
                preConfirm: function() {
                    var pwd = document.getElementById('backup-pwd-input');
                    if (!pwd || !pwd.value) {
                        Swal.showValidationMessage('Masukkan password Anda');
                        return false;
                    }
                    return pwd.value;
                }
            }).then(function(result) {
                if (!result.isConfirmed || !result.value) return;

                                fetch('../system/mfa', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=generate_backup&password=' + encodeURIComponent(result.value) + '&csrf_token=<?= $_SESSION['csrf_token'] ?>'
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.status === 'success' && data.codes) {
                        var codesHtml = data.codes.map(function(c) {
                            return '<div style="font-family:monospace;background:rgba(0,0,0,0.3);padding:8px 16px;border-radius:10px;border:1px solid rgba(255,255,255,0.06);color:#d1d5db;text-align:center;font-size:13px;letter-spacing:0.15em;user-select:all">' + c + '</div>';
                        }).join('');

                        Swal.fire({
                            title: 'Kode Cadangan Baru',
                            html: '<div style="font-size:11px;color:#fbbf24;margin-bottom:12px;font-weight:700">⚠️ Simpan di tempat aman. Kode TIDAK bisa ditampilkan lagi!</div>' +
                                  '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px">' + codesHtml + '</div>' +
                                  '<button onclick="downloadBackupCodes()" style="background:rgba(251,191,36,0.1);color:#fbbf24;border:1px solid rgba(251,191,36,0.2);padding:10px 20px;border-radius:12px;font-size:12px;font-weight:700;cursor:pointer;transition:all 0.2s" onmouseover="this.style.background=\'rgba(251,191,36,0.2)\';" onmouseout="this.style.background=\'rgba(251,191,36,0.1)\'">' +
                                  '  <i data-lucide="download" style="width:14px;height:14px;display:inline-block;vertical-align:middle;margin-right:6px"></i> Download (.txt)' +
                                  '</button>',
                            showConfirmButton: true,
                            confirmButtonText: 'SIMPAN',
                            background: '#141820',
                            color: '#fff',
                            didOpen: function() { lucide.createIcons(); },
                            customClass: {
                                popup: 'border border-yellow-600/25 rounded-2xl shadow-2xl',
                                title: 'text-sm font-black uppercase tracking-wider pt-4 text-yellow-400',
                                htmlContainer: 'mt-1 mb-4',
                                confirmButton: 'bg-yellow-600 hover:bg-yellow-500 text-black text-xs font-black uppercase tracking-wider py-2.5 px-6 rounded-xl transition-all border-none cursor-pointer'
                            }
                        });

                                                window._lastBackupCodes = data.codes;
                        window._meelBackupCodes = data.codes;
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: data.message || 'Terjadi kesalahan.',
                            background: '#141820',
                            color: '#fff',
                            customClass: { popup: 'border border-red-600/25 rounded-2xl shadow-2xl' }
                        });
                    }
                })
                .catch(function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Gagal terhubung ke server.',
                        background: '#141820',
                        color: '#fff',
                        customClass: { popup: 'border border-red-600/25 rounded-2xl shadow-2xl' }
                    });
                });
            });
        }

        window._meelBackupUser = '<?= htmlspecialchars($_SESSION['username'] ?? 'user') ?>';
    </script>
</body>

</html>