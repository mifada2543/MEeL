<?php
require_once '../modules/helpers.php';
session_name("meel");
session_start();
include '../auth/config.php';

// ── Proteksi Admin ──
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$query_user = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$query_user->bind_param("i", $user_id);
$query_user->execute();
$user_data = $query_user->get_result()->fetch_assoc();

if (!$user_data || $user_data['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// ── Back URL (smart referer) ──
$back_url = '../index.php';
if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref      = $_SERVER['HTTP_REFERER'];
    $host     = $_SERVER['HTTP_HOST'];
    if (parse_url($ref, PHP_URL_HOST) === $host) {
        $ref_path       = parse_url($ref, PHP_URL_PATH);
        $excluded_pages = ['cookies.php', 'edit-music.php', 'edit-video.php', 'index.php'];
        $should_exclude = false;
        foreach ($excluded_pages as $page) {
            if (strpos($ref_path, $page) !== false) {
                $should_exclude = true;
                break;
            }
        }
        if (!$should_exclude) $back_url = $ref;
    }
}

// ── Handle DELETE ──
$delete_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!verify_csrf()) {
        $delete_msg = ['type' => 'error', 'text' => 'CSRF Token tidak valid.'];
    } else {
        $del_id   = (int)($_POST['media_id'] ?? 0);
        $del_type = $_POST['media_type'] ?? '';

        if ($del_id > 0 && in_array($del_type, ['video', 'music'])) {
            $del_table = ($del_type === 'video') ? 'video' : 'music';

            $stmt_fetch = $conn->prepare("SELECT filename, thumbnail FROM {$del_table} WHERE id = ? LIMIT 1");
            $stmt_fetch->bind_param("i", $del_id);
            $stmt_fetch->execute();
            $media_row = $stmt_fetch->get_result()->fetch_assoc();
            $stmt_fetch->close();

            $stmt_del = $conn->prepare("DELETE FROM {$del_table} WHERE id = ?");
            $stmt_del->bind_param("i", $del_id);

            if ($stmt_del->execute() && $stmt_del->affected_rows > 0) {
                $files_deleted = 0;
                $files_failed  = [];
                if ($media_row) {
                    $hdd_base = defined('MEEL_HDD_BASE') ? MEEL_HDD_BASE . '/' : "/path/to/your/media/";
                    if ($del_type === 'video') {
                        $filename = $media_row['filename'];
                        $folder_rel = dirname($filename);
                        $folder_abs = $hdd_base . "video/upload/" . basename($folder_rel) . "/";
                        if (is_dir($folder_abs)) {
                            foreach (glob($folder_abs . "*") as $f) {
                                if (@unlink($f)) $files_deleted++;
                                else $files_failed[] = basename($f);
                            }
                            @rmdir($folder_abs);
                        }
                        if (!empty($media_row['thumbnail'])
                            && $media_row['thumbnail'] !== 'default_thumb.jpg'
                            && $media_row['thumbnail'] !== 'default_thumb.webp'
                        ) {
                            $thumb_abs = $hdd_base . "video/upload/thumbnail/" . $media_row['thumbnail'];
                            if (file_exists($thumb_abs)) {
                                if (@unlink($thumb_abs)) $files_deleted++;
                                else $files_failed[] = $media_row['thumbnail'];
                            }
                        }
                    } elseif ($del_type === 'music') {
                        $music_file_abs = $hdd_base . "music/upload/file/" . $media_row['filename'];
                        if (file_exists($music_file_abs)) {
                            if (@unlink($music_file_abs)) $files_deleted++;
                            else $files_failed[] = $media_row['filename'];
                        }
                        if (!empty($media_row['thumbnail']) && $media_row['thumbnail'] !== 'music_default.png') {
                            $thumb_abs = $hdd_base . "music/upload/thumbnail/" . $media_row['thumbnail'];
                            if (file_exists($thumb_abs)) {
                                if (@unlink($thumb_abs)) $files_deleted++;
                                else $files_failed[] = $media_row['thumbnail'];
                            }
                        }
                    }
                }
                $label = ucfirst($del_type);
                if (empty($files_failed)) {
                    $delete_msg = [
                        'type' => 'success',
                        'text' => "{$label} berhasil dihapus dari database dan storage ({$files_deleted} file dihapus)."
                    ];
                } else {
                    $delete_msg = [
                        'type' => 'warning',
                        'text' => "{$label} dihapus dari database, tapi {$files_deleted} file berhasil & " . count($files_failed) . " file gagal dihapus: " . implode(', ', $files_failed)
                    ];
                }
            } else {
                $delete_msg = ['type' => 'error', 'text' => 'Gagal menghapus ' . $del_type . ' dari database.'];
            }
        } else {
            $delete_msg = ['type' => 'error', 'text' => 'ID atau tipe media tidak valid.'];
        }
    }
}

// ── Logika Filter & Sorting & Search ──
$sort        = $_GET['sort'] ?? 'views';
$sort_dir    = strtolower($_GET['dir'] ?? '');
$type_filter = $_GET['type'] ?? 'all';
$search      = $_GET['search'] ?? '';

$allowed_sort_columns = [
    'views'    => 'views',
    'likes'    => 'likes',
    'dislikes' => 'dislikes',
    'title'    => 'title',
];
if (!isset($allowed_sort_columns[$sort])) {
    $sort = 'views';
}
if (!in_array($sort_dir, ['asc', 'desc'], true)) {
    $sort_dir = ($sort === 'title') ? 'asc' : 'desc';
}
$order_by = $allowed_sort_columns[$sort] . ' ' . strtoupper($sort_dir);

function getSortUrl(string $field): string {
    global $sort, $sort_dir, $type_filter, $search;
    $next_dir = ($field === $sort)
        ? ($sort_dir === 'asc' ? 'desc' : 'asc')
        : ($field === 'title' ? 'asc' : 'desc');
    return '?sort=' . $field . '&dir=' . $next_dir . '&type=' . urlencode($type_filter) . '&search=' . urlencode($search);
}

function sortIcon(string $field): string {
    global $sort, $sort_dir;
    if ($field !== $sort) {
        return '';
    }
    return $sort_dir === 'asc' ? '<i data-lucide="chevron-up" class="w-[10px] h-[10px] text-blue-400"></i>' : '<i data-lucide="chevron-down" class="w-[10px] h-[10px] text-blue-400"></i>';
}

// ── Query Utama (Prepared Statement) ──
$query_media = "
    SELECT * FROM (
        SELECT id, title, 'video' AS media_type, views,
            (SELECT COUNT(*) FROM interactions WHERE video_id = video.id AND type = 'like')    AS likes,
            (SELECT COUNT(*) FROM interactions WHERE video_id = video.id AND type = 'dislike') AS dislikes
        FROM video
        UNION ALL
        SELECT id, title, 'music' AS media_type, views,
            (SELECT COUNT(*) FROM interactions WHERE music_id = music.id AND type = 'like')    AS likes,
            (SELECT COUNT(*) FROM interactions WHERE music_id = music.id AND type = 'dislike') AS dislikes
        FROM music
    ) AS combined_media
    WHERE 1=1";

$conditions = [];
$params = [];
$types = '';

if (!empty($search)) {
    $conditions[] = "(title LIKE ? OR id LIKE ?)";
    $like_param = '%' . $search . '%';
    $params[] = $like_param;
    $params[] = $like_param;
    $types .= 'ss';
}

if ($type_filter !== 'all') {
    $conditions[] = "media_type = ?";
    $params[] = $type_filter;
    $types .= 's';
}

if (!empty($conditions)) {
    $query_media .= " AND " . implode(' AND ', $conditions);
}
$query_media .= " ORDER BY $order_by";

$stmt = $conn->prepare($query_media);
if (!$stmt) {
    die("Database error: " . $conn->error);
}
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result_media = $stmt->get_result();
$stmt->close();

$total_counts = ['all' => 0, 'video' => 0, 'music' => 0];
$r = $conn->query("SELECT 'video' as t, COUNT(*) as c FROM video UNION ALL SELECT 'music', COUNT(*) FROM music");
while ($rc = $r->fetch_assoc()) {
    $total_counts[$rc['t']] = (int)$rc['c'];
    $total_counts['all'] += (int)$rc['c'];
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL Admin - Media Analytics. Monitor dan kelola seluruh konten video dan musik, lihat statistik views, likes, dan dislikes.">
    <meta property="og:title" content="MEeL | Media Analytics">
    <meta property="og:description" content="Panel admin MEeL untuk memonitor dan menganalisis statistik konten video dan musik.">
    <title>MEeL | Media Analytics</title>
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <?php include '../partials/link.php'; ?>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <script src="../assets/js/sweetalert2.all.min.js"></script>
</head>

<body class="text-gray-300 min-h-screen">

    <!-- ── Top Nav ── -->
    <nav class="top-nav">
        <a href="../index.php" class="font-sans text-sm font-extrabold text-white no-underline tracking-wider">
            MEeL<span class="text-blue-600">Admin</span>
        </a>
        <div class="w-px h-5 bg-white/[0.08]"></div>
        <a href="index.php" class="text-[11px] font-semibold text-[#555e6e] no-underline">Dashboard</a>
        <span class="text-[#353d4a]">›</span>
        <span class="text-[11px] font-semibold text-[#e2e6ef]">Media Analytics</span>
        <div class="ml-auto flex items-center gap-2">
            <a href="<?= htmlspecialchars($back_url) ?>" class="btn-back">
                <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Kembali
            </a>
        </div>
    </nav>

    <!-- ── Page body ── -->
    <div class="max-w-6xl mx-auto px-4 md:px-6 py-8">

        <!-- Header -->
        <div class="flex items-center gap-4 mb-8">
            <div class="w-12 h-12 rounded-2xl bg-blue-600/15 border border-blue-600/25 flex items-center justify-center shrink-0">
                <i data-lucide="bar-chart-2" class="text-blue-600" style="width:22px;height:22px;"></i>
            </div>
            <div>
                <h1 class="text-[22px] font-extrabold text-white leading-tight">Media Analytics</h1>
                <p class="text-[10px] font-bold uppercase tracking-widest text-[#455060] mt-0.5">Monitor & Kelola Seluruh Konten</p>
            </div>
        </div>

        <?php if ($delete_msg): ?>
            <div class="alert-banner <?= $delete_msg['type'] === 'success' ? 'alert-success' : ($delete_msg['type'] === 'warning' ? 'alert-warning' : 'alert-error') ?>">
                <i data-lucide="<?= $delete_msg['type'] === 'success' ? 'check-circle' : ($delete_msg['type'] === 'warning' ? 'alert-circle' : 'alert-triangle') ?>" class="w-3.5 h-3.5 shrink-0"></i>
                <?= htmlspecialchars($delete_msg['text']) ?>
            </div>
        <?php endif; ?>

        <!-- Summary chips -->
        <div class="flex gap-2.5 flex-wrap mb-6">
            <?php
            $chips = [
                'all'   => ['label' => 'Semua Media', 'color' => '#2563eb', 'bg' => 'rgba(37,99,235,.1)', 'border' => 'rgba(37,99,235,.2)', 'icon' => 'layers'],
                'video' => ['label' => 'Video',        'color' => '#ef4444', 'bg' => 'rgba(239,68,68,.1)',  'border' => 'rgba(239,68,68,.2)',  'icon' => 'film'],
                'music' => ['label' => 'Musik',        'color' => '#f97316', 'bg' => 'rgba(249,115,22,.1)', 'border' => 'rgba(249,115,22,.2)', 'icon' => 'music'],
            ];
            foreach ($chips as $key => $chip): ?>
                <div class="stat-chip">
                    <div class="stat-chip-icon" style="background:<?= $chip['bg'] ?>;border:1px solid <?= $chip['border'] ?>;">
                        <i data-lucide="<?= $chip['icon'] ?>" style="width:14px;height:14px;color:<?= $chip['color'] ?>;"></i>
                    </div>
                    <div>
                        <div class="stat-chip-number" style="color:<?= $chip['color'] ?>;"><?= number_format($total_counts[$key]) ?></div>
                        <div class="stat-chip-label" style="color:#455060;"><?= $chip['label'] ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Filter & Sort bar -->
        <div class="flex flex-wrap gap-3 mb-5 items-center">
            <!-- Search -->
            <div class="relative flex items-center">
                <i data-lucide="search" class="absolute left-3 w-3.5 h-3.5 text-[#455060] pointer-events-none"></i>
                <form method="GET" id="search-form" class="flex gap-0">
                    <input type="hidden" name="sort" value="<?= $sort ?>">
                    <input type="hidden" name="dir" value="<?= $sort_dir ?>">
                    <input type="hidden" name="type" value="<?= $type_filter ?>">
                    <input type="text" name="search" placeholder="Cari judul atau ID..." 
                        value="<?= htmlspecialchars($search) ?>"
                        class="admin-search-input">
                </form>
            </div>

            <!-- Type -->
            <?php
                $type_labels = ['all' => 'Semua', 'video' => 'Video', 'music' => 'Musik'];
                $current_type_label = $type_labels[$type_filter] ?? 'Semua';
            ?>
            <div class="type-dropdown">
                <div class="type-trigger">
                    <span><?= $current_type_label ?></span>
                    <i data-lucide="chevron-down" class="w-3 h-3 text-indigo-300"></i>
                </div>
                <div class="type-menu">
                    <?php foreach ($type_labels as $k => $v): ?>
                        <a href="?sort=<?= $sort ?>&dir=<?= $sort_dir ?>&type=<?= $k ?>&search=<?= urlencode($search) ?>"
                            class="type-option<?= $type_filter === $k ? ' active' : '' ?>">
                            <?= $v ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Count & Clear -->
            <div class="ml-auto flex items-center gap-3">
                <div class="text-[11px] font-semibold text-[#455060]">
                    <?= $result_media->num_rows ?> item ditemukan
                </div>
                <?php if (!empty($search)): ?>
                    <a href="?sort=<?= $sort ?>&dir=<?= $sort_dir ?>&type=<?= $type_filter ?>" class="btn-clear-filter">
                        <i data-lucide="x" class="w-3 h-3"></i> Hapus Filter
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Table -->
        <div class="glass rounded-[20px] overflow-hidden shadow-[0_20px_60px_rgba(0,0,0,.4)]">
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="th-center text-left">
                                <a href="<?= getSortUrl('title') ?>" class="sort-link">
                                    Konten
                                    <?= sortIcon('title') ?>
                                </a>
                            </th>
                            <th class="th-center">
                                <a href="<?= getSortUrl('views') ?>" class="sort-link">
                                    Views
                                    <?= sortIcon('views') ?>
                                </a>
                            </th>
                            <th class="th-center">
                                <a href="<?= getSortUrl('likes') ?>" class="sort-link">
                                    Likes
                                    <?= sortIcon('likes') ?>
                                </a>
                            </th>
                            <th class="th-center">
                                <a href="<?= getSortUrl('dislikes') ?>" class="sort-link">
                                    Dislikes
                                    <?= sortIcon('dislikes') ?>
                                </a>
                            </th>
                            <th class="th-center">Tipe</th>
                            <th class="th-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_media->num_rows > 0):
                            $row_i = 0;
                            while ($row = $result_media->fetch_assoc()):
                                $row_i++;
                                $is_video   = ($row['media_type'] === 'video');
                                $watch_url  = $is_video ? "../video/watch.php?id={$row['id']}" : "../music/watch.php?id={$row['id']}";
                                $edit_url   = $is_video ? "edit-video.php?id={$row['id']}" : "edit-music.php?id={$row['id']}";
                                $type_color = $is_video ? '#ef4444' : '#f97316';
                                $type_bg    = $is_video ? 'rgba(239,68,68,.1)' : 'rgba(249,115,22,.1)';
                                $type_bdr   = $is_video ? 'rgba(239,68,68,.2)' : 'rgba(249,115,22,.2)';
                        ?>
                                <tr title="<?= htmlspecialchars($row['title']) ?>">
                                    <!-- Title -->
                                    <td class="td-left" style="max-width:320px;">
                                        <div class="flex items-center gap-2.5">
                                            <span class="row-num"><?= $row_i ?></span>
                                            <div>
                                                <a href="<?= $watch_url ?>" target="_blank" class="content-title">
                                                    <?= htmlspecialchars($row['title']) ?>
                                                </a>
                                                <span class="content-id">ID #<?= $row['id'] ?></span>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Views -->
                                    <td class="td-center">
                                        <span class="stat-value stat-value-views"><?= number_format($row['views']) ?></span>
                                    </td>

                                    <!-- Likes -->
                                    <td class="td-center">
                                        <span class="stat-value stat-value-likes"><?= number_format($row['likes']) ?></span>
                                    </td>

                                    <!-- Dislikes -->
                                    <td class="td-center">
                                        <span class="stat-value stat-value-dislikes"><?= number_format($row['dislikes']) ?></span>
                                    </td>

                                    <!-- Type badge -->
                                    <td class="td-center">
                                        <span class="type-badge" style="background:<?= $type_bg ?>;color:<?= $type_color ?>;border:1px solid <?= $type_bdr ?>;">
                                            <?= strtoupper($row['media_type']) ?>
                                        </span>
                                    </td>

                                    <!-- Actions -->
                                    <td class="th-right">
                                        <div class="row-actions inline-flex items-center gap-1.5">
                                            <a href="<?= $edit_url ?>" title="Edit" class="action-btn action-btn-edit">
                                                <i data-lucide="edit-2" class="w-2.5 h-2.5"></i> Edit
                                            </a>
                                            <button type="button" title="Hapus"
                                                onclick="confirmDelete(<?= $row['id'] ?>, '<?= $row['media_type'] ?>', '<?= addslashes(htmlspecialchars($row['title'])) ?>')"
                                                class="action-btn action-btn-delete border-0 cursor-pointer">
                                                <i data-lucide="trash-2" class="w-2.5 h-2.5"></i> Hapus
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile;
                        else: ?>
                            <tr>
                                <td colspan="6" class="empty-state">Tidak ada media ditemukan.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /max-w -->

    <!-- ── Delete Confirm Modal ── -->
    <div id="delete-modal">
        <div class="modal-box">
            <div class="modal-icon-wrap">
                <i data-lucide="trash-2" class="text-red-500" style="width:22px;height:22px;"></i>
            </div>
            <h3 class="modal-title">Hapus Konten?</h3>
            <p style="font-size:13px;color:#6b7280;margin:0 0 6px;">Anda akan menghapus:</p>
            <div id="modal-title-display" class="modal-title-display"></div>
            <div id="modal-type-badge" class="modal-type-badge"></div>
            <p class="modal-warning">⚠️ Tindakan ini tidak dapat dibatalkan. File dan semua data terkait akan dihapus permanen.</p>
            <form method="POST" id="delete-form">
                <?php if (isset($_SESSION['csrf_token'])): ?>
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <?php endif; ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="media_id" id="modal-media-id">
                <input type="hidden" name="media_type" id="modal-media-type">
                <div class="modal-actions">
                    <button type="button" onclick="closeDeleteModal()" class="btn-modal-cancel">Batal</button>
                    <button type="submit" class="btn-modal-delete">Ya, Hapus</button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/js/admin.js"></script>
    <script>
        <?php if ($delete_msg && $delete_msg['type'] === 'success'): ?>
            Swal.fire({
                title: 'Berhasil!',
                text: '<?= addslashes($delete_msg['text']) ?>',
                icon: 'success',
                confirmButtonColor: '#2563eb',
                background: '#0e1118',
                color: '#fff'
            });
        <?php elseif ($delete_msg && $delete_msg['type'] === 'warning'): ?>
            Swal.fire({
                title: 'Sebagian Berhasil',
                text: '<?= addslashes($delete_msg['text']) ?>',
                icon: 'warning',
                confirmButtonColor: '#eab308',
                background: '#0e1118',
                color: '#fff'
            });
        <?php elseif ($delete_msg && $delete_msg['type'] === 'error'): ?>
            Swal.fire({
                title: 'Gagal!',
                text: '<?= addslashes($delete_msg['text']) ?>',
                icon: 'error',
                confirmButtonColor: '#ef4444',
                background: '#0e1118',
                color: '#fff'
            });
        <?php endif; ?>
    </script>
</body>

</html>
