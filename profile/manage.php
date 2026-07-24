<?php
require_once '../auth/auth.php';
require_once '../auth/config.php';
require_once '../modules/core/helpers.php';

// ── Hanya user login ──
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id   = (int)$_SESSION['user_id'];
$username  = htmlspecialchars($_SESSION['username'] ?? '');
$is_admin  = ($_SESSION['role'] ?? '') === 'admin';

// ── Cek apakah user punya konten ──
$q_vid_count = $conn->prepare("SELECT COUNT(*) FROM video WHERE user_id = ?");
$q_vid_count->bind_param("i", $user_id);
$q_vid_count->execute();
$total_video = (int)$q_vid_count->get_result()->fetch_row()[0];

$q_mus_count = $conn->prepare("SELECT COUNT(*) FROM music WHERE user_id = ?");
$q_mus_count->bind_param("i", $user_id);
$q_mus_count->execute();
$total_music = (int)$q_mus_count->get_result()->fetch_row()[0];

$has_content = ($total_video + $total_music) > 0;

// ── Redirect jika tidak punya konten ──
if (!$has_content) {
    header("Location: ../upload_advanced.php?first=1");
    exit();
}

// ── Load backend functions ──
define('MEEL_MANAGE_ACCESS', true);
require_once '../controllers/profile/fun-manage.php';

// ── Cleanup files >30 menit setiap kali halaman dimuat ──
$cleaned_count = cleanupPendingDeletions();

// ── Handle delete action ──
$delete_msg = '';
if (isset($_GET['delete']) && isset($_GET['type']) && isset($_GET['id'])) {
    if (!verify_csrf()) {
        $delete_msg = 'Token tidak valid.';
    } else {
        $del_id   = (int)$_GET['id'];
        $del_type = $_GET['type'];

        if ($del_type === 'video') {
            $result = handleDeleteVideo($del_id, $user_id, $conn);
        } elseif ($del_type === 'music') {
            $result = handleDeleteMusic($del_id, $user_id, $conn);
        } else {
            $result = ['success' => false, 'message' => 'Tipe tidak dikenal.'];
        }

        $delete_msg = $result['message'];
        if ($result['success']) {
            // Refresh counts
            $q_vid_count->execute();
            $total_video = (int)$q_vid_count->get_result()->fetch_row()[0];
            $q_mus_count->execute();
            $total_music = (int)$q_mus_count->get_result()->fetch_row()[0];
        }
    }
}

// ── Tab aktif ──
$active_tab = $_GET['tab'] ?? 'video';
if (!in_array($active_tab, ['video', 'music'])) $active_tab = 'video';

// ── Ambil data konten ──
$page_size = 20;
$page = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page - 1) * $page_size;

$videos = [];
$music_list = [];

if ($active_tab === 'video') {
    $q = $conn->prepare("SELECT id, title, thumbnail, views, likes, dislikes, upload_date FROM video WHERE user_id = ? ORDER BY upload_date DESC LIMIT ? OFFSET ?");
    $q->bind_param("iii", $user_id, $page_size, $offset);
    $q->execute();
    $videos = $q->get_result()->fetch_all(MYSQLI_ASSOC);

    $q_total = $conn->prepare("SELECT COUNT(*) FROM video WHERE user_id = ?");
    $q_total->bind_param("i", $user_id);
    $q_total->execute();
    $total_items = (int)$q_total->get_result()->fetch_row()[0];
} else {
    $q = $conn->prepare("SELECT id, title, artist, thumbnail, views, likes, dislikes, upload_date FROM music WHERE user_id = ? ORDER BY upload_date DESC LIMIT ? OFFSET ?");
    $q->bind_param("iii", $user_id, $page_size, $offset);
    $q->execute();
    $music_list = $q->get_result()->fetch_all(MYSQLI_ASSOC);

    $q_total = $conn->prepare("SELECT COUNT(*) FROM music WHERE user_id = ?");
    $q_total->bind_param("i", $user_id);
    $q_total->execute();
    $total_items = (int)$q_total->get_result()->fetch_row()[0];
}

$total_pages = max(1, ceil($total_items / $page_size));
$back_url = "../profile/?u=" . urlencode($_SESSION['username']);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Kelola konten Anda.">
    <meta property="og:title" content="Kelola Konten | MEeL">
    <meta property="og:description" content="Kelola konten video dan musik Anda di MEeL. Edit, hapus, dan pantau statistik.">
    <title>Kelola Konten | MEeL</title>
    <?php include '../partials/link.php'; ?>
    <link rel="stylesheet" href="../assets/css/video.css">
    <style>
        body {
            background-color: #080a0f;
        }

        .glass-panel {
            background: rgba(13, 16, 23, 0.85);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 24px;
        }

        /* ── Tabs ── */
        .manage-tabs {
            display: flex;
            gap: 4px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 14px;
            padding: 4px;
        }

        .manage-tab {
            flex: 1;
            padding: 10px 20px;
            border-radius: 11px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: #6b7280;
            text-decoration: none;
            transition: all 0.2s;
            text-align: center;
        }

        .manage-tab:hover {
            color: #d1d5db;
            background: rgba(255, 255, 255, 0.04);
        }

        .manage-tab.active-tab {
            background: rgba(249, 115, 22, 0.1);
            color: #f97316;
            border: 1px solid rgba(249, 115, 22, 0.15);
        }

        .manage-tab.active-video {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.15);
        }

        /* ── Content card ── */
        .content-card {
            background: rgba(20, 24, 32, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.25s ease;
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
        }

        /* ── Action buttons ── */
        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 6px 14px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            text-decoration: none;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }

        .action-btn-edit {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.2);
            color: #60a5fa;
        }

        .action-btn-edit:hover {
            background: rgba(59, 130, 246, 0.2);
            border-color: rgba(59, 130, 246, 0.3);
        }

        .action-btn-delete {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }

        .action-btn-delete:hover {
            background: rgba(239, 68, 68, 0.2);
            border-color: rgba(239, 68, 68, 0.3);
        }

        /* ── Stats bar ── */
        .stats-bar {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            color: #9ca3af;
        }

        .stat-item svg {
            width: 14px;
            height: 14px;
        }

        .stat-video svg {
            color: #ef4444;
        }

        .stat-music svg {
            color: #f97316;
        }

        /* ── Pagination ── */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 4px;
            margin-top: 24px;
        }

        .page-link {
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            color: #6b7280;
            text-decoration: none;
            transition: all 0.2s;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .page-link:hover {
            background: rgba(255, 255, 255, 0.04);
            color: #d1d5db;
        }

        .page-link.active-page {
            background: rgba(249, 115, 22, 0.1);
            border-color: rgba(249, 115, 22, 0.2);
            color: #f97316;
        }

        .empty-state {
            grid-column: 1 / -1;
            padding: 60px 20px;
            text-align: center;
        }

        .empty-state svg {
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

        /* ── Alert ── */
        .alert-bar {
            padding: 12px 18px;
            border-radius: 14px;
            font-size: 11px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.08);
            border: 1px solid rgba(34, 197, 94, 0.2);
            color: #4ade80;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }
    </style>
</head>

<body class="text-gray-400 min-h-screen">

    <!-- NAVBAR -->
    <nav class="border-b border-white/[.04] bg-[#080a0f]/95 sticky top-0 z-50 backdrop-blur-md">
        <div class="w-full px-3 sm:px-6 h-14 flex items-center justify-between gap-2 sm:gap-4">
            <a href="<?= $back_url ?>" class="flex items-center gap-2 flex-shrink-0" title="Kembali ke Profil">
                <img src="../assets/MEeL.png" class="w-7 h-7 bg-blue-600 rounded-lg flex items-center justify-center" title="MEeL - Kembali ke profil">
                <span class="text-sm font-bold tracking-tight text-white uppercase hidden sm:block">
                    Kelola<span class="text-blue-500">Konten</span>
                </span>
            </a>
        </div>
    </nav>

    <main class="w-full max-w-6xl mx-auto px-4 sm:px-6 pt-6 pb-20">

        <!-- HEADER -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
                <div class="text-[9px] text-gray-700 uppercase tracking-[.25em] mb-1">Dashboard</div>
                <h1 class="text-2xl font-black text-white tracking-tight uppercase">
                    <span class="text-blue-500">@<?= $username ?></span>
                </h1>
            </div>

            <div class="stats-bar">
                <div class="stat-item stat-video">
                    <i data-lucide="play"></i>
                    <span><?= $total_video ?></span>
                    <span class="text-[9px] font-normal text-gray-600 uppercase">Video</span>
                </div>
                <div class="stat-item stat-music">
                    <i data-lucide="music"></i>
                    <span><?= $total_music ?></span>
                    <span class="text-[9px] font-normal text-gray-600 uppercase">Music</span>
                </div>
                <?php if ($cleaned_count > 0): ?>
                    <div class="stat-item text-green-500" title="File lama dibersihkan">
                        <i data-lucide="trash-2"></i>
                        <span>+<?= $cleaned_count ?></span>
                        <span class="text-[9px] font-normal text-gray-600 uppercase">Bersih</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ALERT -->
        <?php if (!empty($delete_msg)): ?>
            <div class="alert-bar <?= strpos($delete_msg, 'berhasil') !== false || strpos($delete_msg, 'dibersihkan') !== false ? 'alert-success' : 'alert-error' ?>">
                <i data-lucide="<?= strpos($delete_msg, 'berhasil') !== false || strpos($delete_msg, 'dibersihkan') !== false ? 'check-circle' : 'alert-triangle' ?>" class="w-4 h-4 flex-shrink-0"></i>
                <?= htmlspecialchars($delete_msg) ?>
            </div>
        <?php endif; ?>

        <!-- TABS -->
        <div class="manage-tabs mb-6 max-w-sm">
            <a href="?tab=video<?= isset($_GET['csrf_token']) ? '&csrf_token=' . urlencode($_GET['csrf_token']) : '' ?>"
                class="manage-tab <?= $active_tab === 'video' ? 'active-video' : '' ?>" title="Kelola video Anda">
                <i data-lucide="play" class="w-3.5 h-3.5 inline-block -ml-1 mr-1.5"></i>
                Video
            </a>
            <a href="?tab=music<?= isset($_GET['csrf_token']) ? '&csrf_token=' . urlencode($_GET['csrf_token']) : '' ?>"
                class="manage-tab <?= $active_tab === 'music' ? 'active-tab' : '' ?>" title="Kelola musik Anda">
                <i data-lucide="music" class="w-3.5 h-3.5 inline-block -ml-1 mr-1.5"></i>
                Music
            </a>
        </div>

        <!-- CONTENT GRID -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            <?php if ($active_tab === 'video'): ?>
                <?php if (!empty($videos)): ?>
                    <?php foreach ($videos as $v):
                        $thumb = !empty($v['thumbnail'])
                            ? '../video/upload/thumbnail/' . htmlspecialchars($v['thumbnail'])
                            : '../assets/img/video0.webp';
                    ?>
                        <div class="content-card">
                            <a href="../video/watch.php?id=<?= $v['id'] ?>" class="block card-thumb" title="<?= htmlspecialchars($v['title']) ?>">
                                <img src="<?= $thumb ?>" alt="<?= htmlspecialchars($v['title']) ?>" loading="lazy">
                            </a>
                            <div class="card-body">
                                <a href="../video/watch.php?id=<?= $v['id'] ?>" class="card-title no-underline hover:text-red-400 transition-colors" title="<?= htmlspecialchars($v['title']) ?>">
                                    <?= htmlspecialchars($v['title']) ?>
                                </a>
                                <div class="card-meta">
                                    <span><?= number_format($v['views'] ?? 0) ?> views</span>
                                    <span class="flex items-center gap-1 text-green-500/80">
                                        <i data-lucide="thumbs-up" class="w-3 h-3"></i>
                                        <?= number_format($v['likes'] ?? 0) ?>
                                    </span>
                                    <span class="flex items-center gap-1 text-red-500/80">
                                        <i data-lucide="thumbs-down" class="w-3 h-3"></i>
                                        <?= number_format($v['dislikes'] ?? 0) ?>
                                    </span>
                                    <span>•</span>
                                    <span><?= date('d M Y', strtotime($v['upload_date'])) ?></span>
                                </div>
                                <div class="flex gap-2 mt-3 pt-3 border-t border-white/[.04]">
                                    <a href="../admin/edit-video.php?id=<?= $v['id'] ?>"
                                        class="action-btn action-btn-edit" title="Edit video <?= htmlspecialchars($v['title']) ?>">
                                        <i data-lucide="edit" class="w-3 h-3"></i> Edit
                                    </a>
                                    <a href="?tab=video&type=video&id=<?= $v['id'] ?>&delete=1&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                                        onclick="return confirmHapus(event, '<?= htmlspecialchars(addslashes($v['title']), ENT_QUOTES) ?>', 'video')"
                                        class="action-btn action-btn-delete" title="Hapus video <?= htmlspecialchars($v['title']) ?>">
                                        <i data-lucide="trash-2" class="w-3 h-3"></i> Hapus
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i data-lucide="play" class="w-10 h-10 text-gray-700 mx-auto mb-4 block"></i>
                        <p>Belum ada video di sini.</p>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php if (!empty($music_list)): ?>
                    <?php foreach ($music_list as $m):
                        $thumb = !empty($m['thumbnail'])
                            ? '../music/upload/thumbnail/' . htmlspecialchars($m['thumbnail'])
                            : '../assets/img/music0.webp';
                    ?>
                        <div class="content-card">
                            <a href="../music/watch.php?id=<?= $m['id'] ?>" class="block card-thumb" title="<?= htmlspecialchars($m['title']) ?>">
                                <img src="<?= $thumb ?>" alt="<?= htmlspecialchars($m['title']) ?>" loading="lazy">
                            </a>
                            <div class="card-body">
                                <a href="../music/watch.php?id=<?= $m['id'] ?>" class="card-title no-underline hover:text-orange-400 transition-colors" title="<?= htmlspecialchars($m['title']) ?>">
                                    <?= htmlspecialchars($m['title']) ?>
                                </a>
                                <div class="card-meta">
                                    <span><?= htmlspecialchars($m['artist'] ?? 'Unknown') ?></span>
                                    <span>•</span>
                                    <span><?= number_format($m['views']) ?> views</span>
                                    <span class="flex items-center gap-1 text-green-500/80">
                                        <i data-lucide="thumbs-up" class="w-3 h-3"></i>
                                        <?= number_format($m['likes']) ?>
                                    </span>
                                    <span class="flex items-center gap-1 text-red-500/80">
                                        <i data-lucide="thumbs-down" class="w-3 h-3"></i>
                                        <?= number_format($m['dislikes']) ?>
                                    </span>
                                </div>
                                <div class="flex gap-2 mt-3 pt-3 border-t border-white/[.04]">
                                    <a href="../admin/edit-music.php?id=<?= $m['id'] ?>"
                                        class="action-btn action-btn-edit" title="Edit musik <?= htmlspecialchars($m['title']) ?>">
                                        <i data-lucide="edit" class="w-3 h-3"></i> Edit
                                    </a>
                                    <a href="?tab=music&type=music&id=<?= $m['id'] ?>&delete=1&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                                        onclick="return confirmHapus(event, '<?= htmlspecialchars(addslashes($m['title']), ENT_QUOTES) ?>', 'music')"
                                        class="action-btn action-btn-delete" title="Hapus musik <?= htmlspecialchars($m['title']) ?>">
                                        <i data-lucide="trash-2" class="w-3 h-3"></i> Hapus
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i data-lucide="music" class="w-10 h-10 text-gray-700 mx-auto mb-4 block"></i>
                        <p>Belum ada musik di sini.</p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- PAGINATION -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?tab=<?= $active_tab ?>&p=<?= $i ?>"
                        class="page-link <?= $i === $page ? 'active-page' : '' ?>" title="Halaman <?= $i ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

    </main>

    <?php include '../partials/footer.php'; ?>

    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <script src="../assets/js/script.min.js"></script>
    <script>
        lucide.createIcons();

        function confirmHapus(event, title, type) {
            event.preventDefault();
            const link = event.currentTarget;
            const typeLabel = type === 'video' ? 'Video' : 'Musik';

            Swal.fire({
                title: 'Hapus ' + typeLabel + '?',
                html: '<div style="font-size:12px;color:#9ca3af">' +
                    '"<strong style="color:#e5e7eb">' + title + '</strong>" akan dihapus dari database.<br>' +
                    '<span style="color:#6b7280;font-size:10px">File akan dibersihkan otomatis dalam 30 menit.</span>' +
                    '</div>',
                icon: 'warning',
                iconColor: '#ef4444',
                showCancelButton: true,
                confirmButtonText: 'HAPUS',
                cancelButtonText: 'BATAL',

                background: '#141820',
                color: '#fff',
                reverseButtons: true,
                customClass: {
                    popup: 'border border-red-600/25 border-t-2 border-t-red-600 rounded-2xl shadow-2xl',
                    title: 'text-sm font-black uppercase tracking-wider pt-4 text-red-500',
                    htmlContainer: 'mt-1 mb-4',
                    confirmButton: 'bg-red-600 hover:bg-red-500 text-white text-xs font-black uppercase tracking-wider py-2.5 px-6 rounded-xl transition-all border-none cursor-pointer ml-2',
                    cancelButton: 'bg-white/5 hover:bg-white/10 text-gray-400 text-xs font-black uppercase tracking-wider py-2.5 px-6 rounded-xl border border-white/10 cursor-pointer transition-all mr-2'
                }
            }).then(function(result) {
                if (result.isConfirmed) {
                    window.location.href = link.href;
                }
            });

            return false;
        }
    </script>
</body>

</html>