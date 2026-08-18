<?php
// ─── Guard Direct Access ───
if (!defined('MEEL_ADMIN_CONTEXT')) {
    $_GET['code'] = 'denied';
    die(include __DIR__ . '/../../err/index.php');
}

// ─── BANNED IPS ───
$banned_ips = $conn->query("SELECT * FROM ip_ban ORDER BY banned_at DESC");

// ─── ALL NON-GUEST USERS ───
$all_users = $conn->query(
    "SELECT id, username, role, is_active, created_at FROM users WHERE role != 'guest' ORDER BY role ASC, username ASC"
);

// ─── STATISTICS ───
// 1 query gabungan — menggantikan 7 query COUNT/SUM terpisah per load.
$stats_row = $conn->query("
    SELECT
        (SELECT COUNT(*) FROM video)                                   AS video,
        (SELECT COUNT(*) FROM music)                                   AS music,
        (SELECT COUNT(*) FROM books)                                   AS books,
        (SELECT COALESCE(SUM(views), 0) FROM video)                    AS v_views,
        (SELECT COALESCE(SUM(views), 0) FROM music)                    AS m_views,
        (SELECT COUNT(*) FROM interactions WHERE type = 'like')        AS likes,
        (SELECT COUNT(*) FROM interactions WHERE type = 'dislike')     AS dislikes,
        (SELECT COUNT(*) FROM users WHERE is_active = 2)               AS pending
");
$stats_row = $stats_row ? $stats_row->fetch_assoc() : [];
$stats = [
    'video'         => (int)($stats_row['video'] ?? 0),
    'music'         => (int)($stats_row['music'] ?? 0),
    'books'         => (int)($stats_row['books'] ?? 0),
    'total_views'   => (int)($stats_row['v_views'] ?? 0) + (int)($stats_row['m_views'] ?? 0),
    'total_likes'   => (int)($stats_row['likes'] ?? 0),
    'total_dislikes'=> (int)($stats_row['dislikes'] ?? 0),
    'pending'       => (int)($stats_row['pending'] ?? 0),
];

// ─── TOP MEDIA ───
$top_media = $conn->query("
    (SELECT id, title, views, 'video' AS type FROM video ORDER BY views DESC LIMIT 1)
    UNION ALL
    (SELECT id, title, views, 'music' AS type FROM music ORDER BY views DESC LIMIT 1)
");

// ─── STORAGE USAGE ───
require_once __DIR__ . '/../../modules/core/System.php';
$sys           = new System($conn);
$storage_usage = $sys->getStorageUsage();

// ─── SERVER STATS ───
$server_stats = $sys->getServerStats();

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

// ─── ORPHAN CHECK (Database Sync) ───
// Scan seluruh pohon media (10rb+ file HLS .ts di folder video) sangat mahal
// bila dijalankan di SETIAP buka halaman — apalagi storage di HDD eksternal.
// Hasilnya di-cache 10 menit; admin bisa memaksa cek ulang via tombol.
$orphans           = [];
$orphan_checked_at = null;

$ORPHAN_CACHE_TTL  = 600; // detik (10 menit)
// Path bisa di-override konstanta (dipakai test suite — lihat phpunit.xml)
// agar test tidak bergantung izin tulis temp/cache milik web server.
$orphan_cache_file = defined('MEEL_ADMIN_ORPHANS_CACHE')
    ? MEEL_ADMIN_ORPHANS_CACHE
    : dirname(__DIR__, 2) . '/temp/cache/admin_orphans.json';

if (is_readable($orphan_cache_file)) {
    $cached = json_decode((string) file_get_contents($orphan_cache_file), true);
    if (is_array($cached) && isset($cached['checked_at'], $cached['orphans'])
        && is_array($cached['orphans'])
        && (time() - (int) $cached['checked_at']) < $ORPHAN_CACHE_TTL) {
        $orphans           = $cached['orphans'];
        $orphan_checked_at = (int) $cached['checked_at'];
    }
}

if ($orphan_checked_at === null) {
$check_map = [
    'video/upload/video/'       => 'video',
    'music/upload/file/'        => 'music',
    'video/upload/thumbnail/'   => 'video',
    'music/upload/thumbnail/'   => 'music',
    'books/upload/manga/'       => 'books',
    'books/upload/pdf/'         => 'books',
    'books/upload/thumbnail/'   => 'books',
];

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

// Set pencocokan cepat (hash lookup O(1)) — menggantikan in_array() O(N).
// Gabungan semua nama file DB jadi satu string → str_contains() native
// setara dengan "ada nama file DB yang mengandung segmen ini" tanpa nested
// loop file × filename DB (O(N×M) → O(1) per segmen).
$__video_files_flip   = array_flip($db_data['video_files']);
$__video_thumbs_flip  = array_flip($db_data['video_thumbs']);
$__music_files_flip   = array_flip($db_data['music_files']);
$__books_folders_flip = array_flip($db_data['books_folders']);
$__books_thumbs_flip  = array_flip($db_data['books_thumbs']);
$__ignored_flip       = array_flip($ignored_files);
$__all_video_files    = implode("\n", $db_data['video_files']);

$base_dirs = [
    'video/upload/video/'       => meel_media_base_path('video') . '/video/',
    'music/upload/file/'        => meel_media_base_path('music') . '/file/',
    'video/upload/thumbnail/'   => meel_media_base_path('video') . '/thumbnail/',
    'music/upload/thumbnail/'   => meel_media_base_path('music') . '/thumbnail/',
    'books/upload/manga/'       => meel_media_base_path('books') . '/manga/',
    'books/upload/pdf/'         => meel_media_base_path('books') . '/pdf/',
    'books/upload/thumbnail/'   => meel_media_base_path('books') . '/thumbnail/',
];

foreach ($check_map as $rel_path => $table) {
    $abs_path  = $base_dirs[$rel_path];
    $all_files = __admin_scan_files($abs_path);

    foreach ($all_files as $full_path) {
        $fname = basename($full_path);
        if (isset($__ignored_flip[$fname])) continue;

        $is_orphan = true;

        if (str_contains($full_path, '/books/upload/manga/')) {
            $relative = substr($full_path, strlen($abs_path));
            $folder   = explode('/', $relative)[0];
            if (isset($__books_folders_flip[$folder])) $is_orphan = false;
        }
        elseif ($table === 'video') {
            if (str_contains($full_path, '/thumbnail/')) {
                if (isset($__video_thumbs_flip[$fname])) $is_orphan = false;
            } else {
                if (isset($__video_files_flip[$fname])) {
                    $is_orphan = false;
                } else {
                    $segments = explode('/', str_replace('\\', '/', $full_path));
                    $parent   = count($segments) >= 2 ? $segments[count($segments) - 2] : '';
                    if (!empty($parent) && !in_array($parent, ['video', 'upload'], true)
                        && str_contains($__all_video_files, $parent)) {
                        $is_orphan = false;
                    }
                    if ($is_orphan) {
                        foreach ($segments as $seg) {
                            if (empty($seg) || in_array($seg, ['video', 'upload'], true)) continue;
                            if (str_contains($__all_video_files, $seg)) { $is_orphan = false; break; }
                        }
                    }
                }
            }
        }
        else {
            if ($table === 'books' && (isset($__books_folders_flip[$fname]) || isset($__books_thumbs_flip[$fname]))) {
                $is_orphan = false;
            } elseif ($table === 'music' && isset($__music_files_flip[$fname])) {
                $is_orphan = false;
            }
        }

        if ($is_orphan) $orphans[] = $full_path;
    }
}

    // Scan selesai — simpan hasil ke cache agar load berikutnya instan.
    $orphan_checked_at = time();
    if (function_exists('meel_write_cache_file')) {
        meel_write_cache_file($orphan_cache_file, json_encode([
            'checked_at' => $orphan_checked_at,
            'orphans'    => $orphans,
        ], JSON_UNESCAPED_UNICODE));
    }
}

// ─── PENDING USERS ───
$pending_users = $conn->query("SELECT id, username, created_at FROM users WHERE is_active = 2");

// ─── ACTIVITY MONITOR ───
$result_monitor = $conn->query(
    "SELECT username, role, last_activity, last_page, user_agent, access_via, ip_address
     FROM users ORDER BY last_activity DESC LIMIT 10"
);

// ─── CHART DATA: 7-Day Activity ───
// Dulu 28 query per load (7 hari × 4 metrik, pakai DATE(col) = ... yang tidak
// bisa memakai index). Sekarang 6 query GROUP BY — isi hari tanpa data = 0.
$chart_from = date('Y-m-d', strtotime('-6 days'));

$chart_views = [];
$res = $conn->query("SELECT DATE(upload_date) AS d, COALESCE(SUM(views), 0) AS v FROM video WHERE upload_date >= '$chart_from' GROUP BY DATE(upload_date)");
while ($row = $res->fetch_assoc()) $chart_views[$row['d']] = (int) $row['v'];
$res = $conn->query("SELECT DATE(upload_date) AS d, COALESCE(SUM(views), 0) AS v FROM music WHERE upload_date >= '$chart_from' GROUP BY DATE(upload_date)");
while ($row = $res->fetch_assoc()) $chart_views[$row['d']] += (int) $row['v'];

$chart_uploads = [];
$res = $conn->query("SELECT DATE(upload_date) AS d, COUNT(*) AS c FROM (
    SELECT upload_date FROM video
    UNION ALL SELECT upload_date FROM music
    UNION ALL SELECT upload_date FROM books
) AS u WHERE upload_date >= '$chart_from' GROUP BY DATE(upload_date)");
while ($row = $res->fetch_assoc()) $chart_uploads[$row['d']] = (int) $row['c'];

$chart_active = [];
$res = $conn->query("SELECT DATE(created_at) AS d, COUNT(DISTINCT user_id) AS c FROM activity_log WHERE created_at >= '$chart_from' GROUP BY DATE(created_at)");
while ($row = $res->fetch_assoc()) $chart_active[$row['d']] = (int) $row['c'];

$chart_new = [];
$res = $conn->query("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM users WHERE created_at >= '$chart_from' AND role != 'guest' GROUP BY DATE(created_at)");
while ($row = $res->fetch_assoc()) $chart_new[$row['d']] = (int) $row['c'];

$chart_activity = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_activity[] = [
        'date'  => $date,
        'label' => date('D', strtotime("-$i days")),
        'views'     => $chart_views[$date] ?? 0,
        'uploads'   => $chart_uploads[$date] ?? 0,
        'users'     => $chart_active[$date] ?? 0,
        'new_users' => $chart_new[$date] ?? 0,
    ];
}
