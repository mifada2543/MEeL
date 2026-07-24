<?php
/**
 * controllers/admin_data.php
 *
 * Penyedia data untuk admin dashboard.
 * Mendefinisikan semua variabel yang dipakai di view admin/index.php:
 *   $banned_ips, $stats, $top_media, $sys, $storage_usage,
 *   $ssd_free, $ssd_total, $ssd_used, $ssd_perc,
 *   $hdd_free, $hdd_total,
 *   $sz_vid, $sz_mus, $sz_book, $sz_d_pub, $sz_d_prv, $sz_drive_total,
 *   $p_vid, $p_mus, $p_book, $p_drive,
 *   $orphans, $pending_users, $result_monitor, $all_users
 */

// ─── BANNED IPS ────────────────────────────────────────────────────────────
$banned_ips = $conn->query("SELECT * FROM ip_ban ORDER BY banned_at DESC");

// ─── ALL NON-GUEST USERS ──────────────────────────────────────────────────
$all_users = $conn->query(
    "SELECT id, username, role, is_active, created_at FROM users WHERE role != 'guest' ORDER BY role ASC, username ASC"
);

// ─── STATISTICS ────────────────────────────────────────────────────────────
if (!function_exists('__admin_get_count')) {
    function __admin_get_count(mysqli $conn, string $query): int
    {
        $r = $conn->query($query);
        return ($r) ? (int)($r->fetch_row()[0] ?? 0) : 0;
    }
}

$stats = [
    'video'         => __admin_get_count($conn, "SELECT COUNT(*) FROM video"),
    'music'         => __admin_get_count($conn, "SELECT COUNT(*) FROM music"),
    'books'         => __admin_get_count($conn, "SELECT COUNT(*) FROM books"),
    'total_views'   => __admin_get_count($conn, "SELECT SUM(views) FROM video")
                       + __admin_get_count($conn, "SELECT SUM(views) FROM music"),
    'total_likes'   => __admin_get_count($conn, "SELECT COUNT(*) FROM interactions WHERE type = 'like'"),
    'total_dislikes'=> __admin_get_count($conn, "SELECT COUNT(*) FROM interactions WHERE type = 'dislike'"),
    'pending'       => __admin_get_count($conn, "SELECT COUNT(*) FROM users WHERE is_active = 2"),
];

// ─── TOP MEDIA ─────────────────────────────────────────────────────────────
$top_media = $conn->query("
    (SELECT id, title, views, 'video' AS type FROM video ORDER BY views DESC LIMIT 1)
    UNION ALL
    (SELECT id, title, views, 'music' AS type FROM music ORDER BY views DESC LIMIT 1)
");

// ─── STORAGE USAGE ─────────────────────────────────────────────────────────
require_once __DIR__ . '/../../modules/System.php';
$sys           = new System($conn);
$storage_usage = $sys->getStorageUsage();

$ssd_free  = $storage_usage['ssd']['free'];
$ssd_total = $storage_usage['ssd']['total'];
$ssd_used  = $storage_usage['ssd']['used'];
$ssd_perc  = $storage_usage['ssd']['perc'];

$hdd_free  = $storage_usage['hdd']['free'];
$hdd_total = $storage_usage['hdd']['total'];

$sz_vid       = $storage_usage['sizes']['video'];
$sz_mus       = $storage_usage['sizes']['music'];
$sz_book      = $storage_usage['sizes']['books'];
$sz_d_pub     = $storage_usage['sizes']['drive_pub'];
$sz_d_prv     = $storage_usage['sizes']['drive_prv'];
$sz_drive_total = $storage_usage['sizes']['drive_total'];

$p_vid   = $storage_usage['percentages']['video'];
$p_mus   = $storage_usage['percentages']['music'];
$p_book  = $storage_usage['percentages']['books'];
$p_drive = $storage_usage['percentages']['drive'];

// ─── ORPHAN CHECK ──────────────────────────────────────────────────────────
$orphans   = [];
$check_map = [
    'video/upload/video/'       => 'video',
    'music/upload/file/'        => 'music',
    'video/upload/thumbnail/'   => 'video',
    'music/upload/thumbnail/'   => 'music',
    'books/upload/manga/'       => 'books',
    'books/upload/pdf/'         => 'books',
    'books/upload/thumbnail/'   => 'books',
];

// Ambil data DB sekaligus ke memori
$db_data = ['video_files' => [], 'video_thumbs' => [], 'music_files' => [], 'books_folders' => [], 'books_thumbs' => []];

$res = $conn->query("SELECT filename, thumbnail FROM video");
while ($row = $res->fetch_assoc()) {
    $db_data['video_files'][]  = $row['filename'];
    if (!empty($row['thumbnail'])) $db_data['video_thumbs'][] = $row['thumbnail'];
}

$res = $conn->query("SELECT filename, thumbnail FROM music");
while ($row = $res->fetch_assoc()) {
    $db_data['music_files'][] = $row['filename'];
    $db_data['music_files'][] = $row['thumbnail'];
}

$res = $conn->query("SELECT path_folder, thumbnail FROM books");
while ($row = $res->fetch_assoc()) {
    $db_data['books_folders'][] = $row['path_folder'];
    $db_data['books_thumbs'][]  = $row['thumbnail'];
}

if (!function_exists('__admin_scan_files')) {
    function __admin_scan_files(string $dir): array
    {
        $result = [];
        if (!is_dir($dir)) return $result;
        foreach (scandir($dir) as $v) {
            if ($v === '.' || $v === '..') continue;
            $path = rtrim($dir, '/') . '/' . $v;
            if (is_file($path)) {
                $result[] = $path;
            } elseif (is_dir($path)) {
                $result = array_merge($result, __admin_scan_files($path . '/'));
            }
        }
        return $result;
    }
}

$ignored_files = ['.htaccess', 'default_video.png', 'music_default.png', 'default_cover.jpg'];

$base_dir = realpath(__DIR__ . '/../..') . '/';

foreach ($check_map as $rel_path => $table) {
    $abs_path  = $base_dir . $rel_path;
    $all_files = __admin_scan_files($abs_path);

    foreach ($all_files as $full_path) {
        $fname = basename($full_path);
        if (in_array($fname, $ignored_files, true)) continue;

        $is_orphan = true;

        // Manga folder
        if (str_contains($full_path, '/books/upload/manga/')) {
            $relative = substr($full_path, strlen($abs_path));
            $folder   = explode('/', $relative)[0];
            if (in_array($folder, $db_data['books_folders'], true)) $is_orphan = false;
        }
        // Video
        elseif ($table === 'video') {
            if (str_contains($full_path, '/thumbnail/')) {
                if (in_array($fname, $db_data['video_thumbs'], true)) $is_orphan = false;
            } else {
                if (in_array($fname, $db_data['video_files'], true)) {
                    $is_orphan = false;
                } else {
                    $segments = explode('/', str_replace('\\', '/', $full_path));
                    $parent   = count($segments) >= 2 ? $segments[count($segments) - 2] : '';
                    if (!empty($parent) && !in_array($parent, ['video', 'upload'], true)) {
                        foreach ($db_data['video_files'] as $db_f) {
                            if (str_contains($db_f, $parent)) { $is_orphan = false; break; }
                        }
                    }
                    if ($is_orphan) {
                        foreach ($segments as $seg) {
                            if (empty($seg) || in_array($seg, ['video', 'upload'], true)) continue;
                            foreach ($db_data['video_files'] as $db_f) {
                                if (str_contains($db_f, $seg)) { $is_orphan = false; break 2; }
                            }
                        }
                    }
                }
            }
        }
        // Music / Books
        else {
            if ($table === 'books' && (in_array($fname, $db_data['books_folders'], true) || in_array($fname, $db_data['books_thumbs'], true))) {
                $is_orphan = false;
            } elseif ($table === 'music' && in_array($fname, $db_data['music_files'], true)) {
                $is_orphan = false;
            }
        }

        if ($is_orphan) $orphans[] = $full_path;
    }
}

// ─── PENDING USERS ─────────────────────────────────────────────────────────
$pending_users = $conn->query("SELECT id, username, created_at FROM users WHERE is_active = 2");

// ─── ACTIVITY MONITOR ─────────────────────────────────────────────────────
$result_monitor = $conn->query(
    "SELECT username, role, last_activity, last_page, user_agent, access_via, ip_address
     FROM users ORDER BY last_activity DESC LIMIT 10"
);

// ─── CHART DATA: 7-Day Activity ─────────────────────────────────────────
$chart_activity = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $label = date('D', strtotime("-$i days"));

    // Total views (video + music) per day
    $views = __admin_get_count($conn, "
        SELECT COALESCE(SUM(v.views), 0) + COALESCE(SUM(m.views), 0)
        FROM (
            SELECT SUM(views) AS views FROM video WHERE DATE(upload_date) = '$date'
            UNION ALL
            SELECT SUM(views) AS views FROM music WHERE DATE(upload_date) = '$date'
        ) AS t
    ");

    // Uploads per day
    $uploads = __admin_get_count($conn, "
        SELECT COUNT(*) FROM (
            SELECT id FROM video WHERE DATE(upload_date) = '$date'
            UNION ALL
            SELECT id FROM music WHERE DATE(upload_date) = '$date'
            UNION ALL
            SELECT id FROM books WHERE DATE(upload_date) = '$date'
        ) AS u
    ");

    // Active users (activity_log entries)
    $active_users = __admin_get_count($conn, "
        SELECT COUNT(DISTINCT user_id) FROM activity_log WHERE DATE(created_at) = '$date'
    ");

    // New registrations per day
    $new_users = __admin_get_count($conn, "
        SELECT COUNT(*) FROM users WHERE DATE(created_at) = '$date' AND role != 'guest'
    ");

    $chart_activity[] = [
        'date'  => $date,
        'label' => $label,
        'views' => $views,
        'uploads' => $uploads,
        'users' => $active_users,
        'new_users' => $new_users,
    ];
}
