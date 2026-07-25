<?php
include '../auth/config.php';
include '../auth/auth.php';
include_once '../modules/core/helpers.php';
include_once '../modules/core/activity_logger.php';
include_once '../modules/core/GarbageCollector.php';
include_once '../modules/core/RateLimiter.php';

if (!isset($_SESSION['user_id'])) {
    die(include '../err/denied.php');
}

// 🔴 Guard independen: verifikasi role admin (tidak bergantung pada side-effect include)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die(include '../err/denied.php');
}

include '../controllers/admin/admin_actions.php';
include '../controllers/admin/admin_data.php';

// Auto-cleanup guest stale (adaptive — throttle 1 jam via GarbageCollector)
GarbageCollector::cleanGuests($conn);

/** * --- IDE Type Hinting for Intelephense ---
 * These variables are initialized in '../controllers/fun.php'
 * * @var float $ssd_free
 * @var float $ssd_used
 * @var float $ssd_total
 * @var float $hdd_free
 * @var float $hdd_total
 * @var float $p_vid
 * @var float $sz_vid
 * @var float $p_mus
 * @var float $sz_mus
 * @var float $p_book
 * @var float $sz_book
 * @var float $p_drive
 * @var float $sz_d_pub
 * @var float $sz_d_prv
 * @var float $sz_drive_total
 * @var array $stats
 * @var array $orphans
 * @var float $ssd_free
 * @var mysqli_result $top_media
 * @var mysqli_result $pending_users
 * @var mysqli_result $all_users
 * @var mysqli_result $result_monitor
 * @var mysqli_result $banned_ips
 * @var object $sys
 * @var array $chart_activity
 */
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>MEeL | System Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="MEeL | System Admin">
    <meta property="og:description" content="Panel administrasi MEeL untuk mengelola konten, pengguna, dan monitoring server.">
    <meta property="og:image" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/assets/MEeL.png">
    <meta property="og:url" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <link href="../assets/css/tailwind.min.css" rel="stylesheet">
    <script src="../assets/js/lucide.js"></script>
    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <script src="../assets/js/script.min.js"></script>
    <script src="../assets/js/chart.umd.min.js"></script>
    <style>
        body {
            background-color: #0b0e14;
        }

        .glass {
            background: rgba(22, 27, 34, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .scrollable-table-wrap {
            overflow: auto;
            scrollbar-width: thin;
            scrollbar-color: #374151 transparent;
        }
        .scrollable-table-wrap::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }
        .scrollable-table-wrap::-webkit-scrollbar-track {
            background: transparent;
        }
        .scrollable-table-wrap::-webkit-scrollbar-thumb {
            background: #374151;
            border-radius: 999px;
        }
        .scrollable-table-wrap::-webkit-scrollbar-thumb:hover {
            background: #4b5563;
        }
        .scrollable-table-wrap thead {
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .scrollable-table-wrap thead th {
            background: #0b0e14;
        }
        .scrollable-table-wrap thead::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.03);
            pointer-events: none;
        }
    </style>
</head>

<body class="text-gray-300 font-sans min-h-screen">

    <?php
    $is_admin = true;
    $page_title = 'Dashboard';
    $media_type = 'dashboard';
    $back_url = '../index.php';
    include 'header-admin.php';
    ?>

    <div class="max-w-5xl mx-auto px-4 md:px-8 py-8">

        <div class="flex items-center gap-4 mb-8">
            <div class="w-12 h-12 rounded-2xl bg-orange-500/15 border border-orange-500/25 flex items-center justify-center shrink-0">
                <i data-lucide="activity" class="w-5 h-5 text-orange-500"></i>
            </div>
            <div>
                <h1 class="text-2xl font-extrabold text-white leading-tight">System Admin</h1>
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500 mt-1">Admin Center</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="glass rounded-3xl lg:col-span-2 flex flex-col md:flex-row divide-y md:divide-y-0 md:divide-x divide-gray-700">
                <div class="p-8 md:w-5/12 flex flex-col justify-center">
                    <h3 class="text-sm font-bold text-gray-400 uppercase mb-4 tracking-wider">SSD Nvme Storage</h3>
                    <div class="flex items-baseline gap-2 mb-4">
                        <span class="text-5xl font-black text-white"><?= number_format($ssd_free, 1) ?></span>
                        <span class="text-lg font-bold text-gray-500">GB Free</span>
                    </div>
                    <p class="text-xs text-gray-500 uppercase font-bold tracking-widest">Usage: <?= number_format($ssd_used, 1) ?> / <?= number_format($ssd_total, 1) ?> GB</p>
                </div>

                <div class="p-8 md:w-7/12">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider">MEeL Media Storage</h3>
                        <div class="flex items-baseline gap-2">
                            <span class="text-3xl font-black text-white"><?= number_format($hdd_free, 1) ?></span>
                            <span class="text-sm font-bold text-gray-500">GB Free</span>
                        </div>
                    </div>

                    <div class="space-y-5">
                        <div>
                            <h4 class="text-sm font-bold text-red-500 mb-1">Video</h4>
                            <div class="w-full bg-gray-800/80 h-2 rounded-full mb-1">
                                <div class="bg-red-500 h-full rounded-full" style="width:<?= $p_vid ?>%"></div>
                            </div>
                            <p class="text-[11px] text-gray-500 font-medium">Size: <?= number_format($sz_vid, 2) ?> GB</p>
                        </div>

                        <div>
                            <h4 class="text-sm font-bold text-orange-500 mb-1">Music</h4>
                            <div class="w-full bg-gray-800/80 h-2 rounded-full mb-1">
                                <div class="bg-orange-500 h-full rounded-full" style="width:<?= $p_mus ?>%"></div>
                            </div>
                            <p class="text-[11px] text-gray-500 font-medium">Size: <?= number_format($sz_mus, 2) ?> GB</p>
                        </div>

                        <div>
                            <h4 class="text-sm font-bold text-green-500 mb-1">Books</h4>
                            <div class="w-full bg-gray-800/80 h-2 rounded-full mb-1">
                                <div class="bg-green-500 h-full rounded-full" style="width:<?= $p_book ?>%"></div>
                            </div>
                            <p class="text-[11px] text-gray-500 font-medium">Size: <?= number_format($sz_book, 2) ?> GB</p>
                        </div>

                        <div>
                            <h4 class="text-sm font-bold text-blue-500 mb-1">Drive</h4>
                            <div class="w-full bg-gray-800/80 h-2 rounded-full mb-1">
                                <div class="bg-blue-500 h-full rounded-full" style="width:<?= $p_drive ?>%"></div>
                            </div>
                            <p class="text-[11px] text-gray-500 font-medium">Public: <?= number_format($sz_d_pub, 2) ?> GB | Private: <?= number_format($sz_d_prv, 2) ?> GB</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="glass p-6 rounded-3xl lg:col-span-1 flex flex-col justify-between border border-white/5">
                <div>
                    <div class="flex items-center gap-2 mb-4">
                        <i data-lucide="bar-chart-3" class="w-3.5 h-3.5 text-blue-400"></i>
                        <h3 class="text-[10px] font-black text-gray-500 uppercase tracking-widest">Global Analytics</h3>
                    </div>

                    <div class="space-y-3 mb-6">
                        <div class="flex justify-between items-center text-[11px]">
                            <span class="text-gray-500">Total Views</span>
                            <span class="text-white font-mono font-bold"><?= number_format($stats['total_views']) ?></span>
                        </div>
                        <div class="flex justify-between items-center text-[11px]">
                            <span class="text-gray-500">Total Likes</span>
                            <span class="text-green-500 font-mono font-bold">+<?= number_format($stats['total_likes']) ?></span>
                        </div>
                        <div class="flex justify-between items-center text-[11px]">
                            <span class="text-gray-500">Total Dislikes</span>
                            <span class="text-red-500 font-mono font-bold">-<?= number_format($stats['total_dislikes']) ?></span>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-white/5">
                        <p class="text-[9px] font-black text-gray-600 uppercase mb-3 tracking-tighter">Most Viewed Content</p>
                        <div class="space-y-2">
                            <?php
                            // Ambil maksimal 1 video & 1 music dengan views tertinggi dari hasil $top_media
                            $top_picks = ['video' => null, 'music' => null];
                            while ($tm = $top_media->fetch_assoc()) {
                                if (array_key_exists($tm['type'], $top_picks) && $top_picks[$tm['type']] === null) {
                                    $top_picks[$tm['type']] = $tm;
                                }
                                if ($top_picks['video'] !== null && $top_picks['music'] !== null) {
                                    break;
                                }
                            }
                            foreach ($top_picks as $type => $tm):
                                if ($tm === null) continue;
                                $link = ($type == 'video') ? "../video/watch.php?id=" : "../music/watch.php?id=";
                                $color = ($type == 'video') ? "text-red-500" : "text-orange-500";
                                $icon = ($type == 'video') ? "play-circle" : "music-2";
                            ?>
                                <a href="<?= $link . $tm['id'] ?>" class="flex items-center gap-3 p-2 rounded-xl bg-white/[0.02] hover:bg-white/5 border border-white/5 transition-all group" title="Lihat konten populer ini">
                                    <div class="p-2 bg-gray-800 rounded-lg group-hover:scale-110 transition-transform">
                                        <i data-lucide="<?= $icon ?>" class="w-3.5 h-3.5 <?= $color ?>"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-[10px] font-bold text-white truncate group-hover:text-blue-400"><?= htmlspecialchars($tm['title']) ?></p>
                                        <div class="flex items-center gap-2">
                                            <span class="text-[8px] uppercase font-black <?= $color ?>"><?= $type ?></span>
                                            <span class="text-[8px] text-gray-600 font-mono"><?= number_format($tm['views']) ?> Views</span>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <a href="activity_log.php" class="block mb-2 text-center text-[9px] text-blue-400 border border-blue-400/20 py-2.5 rounded-xl hover:bg-blue-400 hover:text-white font-black uppercase tracking-widest transition-all" title="Lihat trail audit aktivitas pengguna">
                    <i data-lucide="activity" class="w-3 h-3 inline mr-1"></i> Activity Log
                </a>
                        <a href="cookies.php" class="block text-center text-[9px] text-blue-400 border border-blue-400/20 py-2.5 rounded-xl hover:bg-blue-400 hover:text-white font-black uppercase tracking-widest transition-all" title="Lihat laporan analitik lengkap">
                    Full Reports
                </a>

            </div>
        </div>

        <!-- 7-Day Activity Chart -->
        <div class="glass p-6 rounded-3xl mb-8">
            <div class="flex items-center gap-2 mb-4">
                <i data-lucide="trending-up" class="w-4 h-4 text-emerald-400"></i>
                <h3 class="text-[10px] font-black text-gray-500 uppercase tracking-widest">7-Day Activity</h3>
            </div>
            <div class="h-48">
                <canvas id="activityChart"></canvas>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <?php
            $cards = [
                ['label' => 'Video', 'val' => $stats['video'], 'icon' => 'video', 'color' => 'text-red-500'],
                ['label' => 'Music', 'val' => $stats['music'], 'icon' => 'music', 'color' => 'text-orange-500'],
                ['label' => 'Books', 'val' => $stats['books'], 'icon' => 'book-open', 'color' => 'text-blue-500'],
                ['label' => 'Pending', 'val' => $stats['pending'], 'icon' => 'user-plus', 'color' => 'text-yellow-500']
            ];
            foreach ($cards as $c): ?>
                <div class="glass p-4 rounded-2xl border-l-4 border-gray-700">
                    <p class="text-[9px] font-bold text-gray-500 uppercase mb-1"><?= $c['label'] ?></p>
                    <div class="flex items-center justify-between"><span class="text-xl font-bold text-white"><?= $c['val'] ?></span><i data-lucide="<?= $c['icon'] ?>" class="w-4 h-4 <?= $c['color'] ?> opacity-50"></i></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($pending_users->num_rows > 0): ?>
            <div class="glass rounded-3xl overflow-hidden mb-8 border border-yellow-500/20">
                <div class="p-4 bg-yellow-500/5 border-b border-white/5">
                    <h3 class="text-xs font-bold text-white uppercase">Verification Queue (<?= $stats['pending'] ?>)</h3>
                </div>
                <table class="w-full text-left text-xs">
                    <tbody class="divide-y divide-white/5">
                        <?php while ($u = $pending_users->fetch_assoc()): ?>
                            <tr class="hover:bg-white/[0.02]">
                                <td class="py-4 px-6 font-bold text-white"><?= htmlspecialchars($u['username']) ?></td>
                                <td class="py-4 px-6 text-right space-x-2">
                                    <a href="?approve_id=<?= $u['id'] ?>" class="bg-green-600 text-white px-4 py-1.5 rounded-xl font-bold text-[10px]" title="Setujui pendaftaran <?= htmlspecialchars($u['username']) ?>">APPROVE</a>
                                    <a href="?reject_id=<?= $u['id'] ?>" class="bg-red-600/20 text-red-500 px-4 py-1.5 rounded-xl font-bold text-[10px] border border-red-500/20" title="Tolak pendaftaran <?= htmlspecialchars($u['username']) ?>">REJECT</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="glass p-6 rounded-3xl mb-8">
            <h3 class="text-xs font-bold text-gray-500 uppercase mb-4">Database Sync Check</h3>
            <?php if (count($orphans) > 0): ?>
                <div class="bg-red-500/10 border border-red-500/20 p-4 rounded-2xl">
                    <p class="text-xs text-red-400 mb-2">Ditemukan <?= count($orphans) ?> file sampah (tidak ada di DB):</p>
                    <ul class="text-[9px] font-mono text-gray-500 max-h-24 overflow-y-auto mb-4"><?php foreach ($orphans as $o) echo "<li>- $o</li>"; ?></ul>
                    <form method="POST"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="files_to_delete" value='<?= json_encode($orphans) ?>'>                                <button name="clean_orphans" class="bg-red-600 text-white text-[10px] font-bold px-4 py-2 rounded-xl hover:bg-red-700 transition-all uppercase" title="Hapus file sampah yang tidak ada di database">Bersihkan SSD Thinkpad</button></form>
                </div>
            <?php else: ?>
                <p class="text-xs text-green-500 font-bold uppercase tracking-widest flex items-center gap-2"><i data-lucide="check-circle" class="w-4 h-4"></i> Semua file di SSD sinkron dengan Database</p>
            <?php endif; ?>
        </div>
        <div class="glass rounded-3xl overflow-hidden mb-8 border border-white/5">
            <div class="p-6 border-b border-white/5 bg-white/[0.02] flex justify-between items-center">
                <div class="flex items-center gap-2">
                    <i data-lucide="users" class="w-5 h-5 text-blue-500"></i>
                    <h3 class="text-xs font-bold text-gray-400 uppercase">User Account Management</h3>
                </div>
                <span class="text-[9px] text-gray-600 font-mono">Total: <?= ($all_users) ? $all_users->num_rows : 0 ?> Accounts</span>
            </div>

            <div class="scrollable-table-wrap" style="max-height:300px;">
                <table class="w-full text-left text-xs">
                    <thead class="text-gray-500 uppercase text-[9px] font-black tracking-widest">
                        <tr>
                            <th class="py-3 px-6">ID & Username</th>
                            <th class="py-3 px-4">Role</th>
                            <th class="py-3 px-4 text-center">Status</th>
                            <th class="py-3 px-4">Registered</th>
                            <th class="py-3 px-6 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <?php
                        if ($all_users && $all_users->num_rows > 0):
                            while ($u = $all_users->fetch_assoc()):
                        ?>
                                <tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="py-4 px-6">
                                        <div class="flex flex-col">
                                            <span class="font-bold text-white"><?= htmlspecialchars($u['username']) ?></span>
                                            <span class="text-[10px] text-gray-500 font-mono">#ID-<?= $u['id'] ?></span>
                                        </div>
                                    </td>
                                    <td class="py-4 px-4">
                                        <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase <?= $u['role'] === 'admin' ? 'bg-purple-500/20 text-purple-400 border border-purple-500/30' : 'bg-blue-500/10 text-blue-400 border border-blue-500/20' ?>">
                                            <?= $u['role'] ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-4 text-center">
                                        <span class="text-[10px] font-bold uppercase <?= $u['is_active'] == 1 ? 'text-green-500' : 'text-yellow-500' ?>">
                                            <?= $u['is_active'] == 1 ? 'Active' : 'Pending' ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-4 text-gray-500 font-mono text-[10px]">
                                        <?= date('d/m/Y', strtotime($u['created_at'])) ?>
                                    </td>
                                    <td class="py-4 px-6 text-right">
                                        <?php
                                        // Tombol Hapus HANYA muncul jika role BUKAN admin
                                        if ($u['role'] !== 'admin'):
                                        ?>
                                            <a href="?delete_user_id=<?= $u['id'] ?>"
                                                onclick="return meelConfirmLink(event, { title: 'Hapus User', text: 'Hapus permanen user <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?', confirmButtonText: 'HAPUS' })"
                                                class="bg-red-600/10 text-red-500 border border-red-500/20 px-3 py-1.5 rounded-xl hover:bg-red-600 hover:text-white transition-all font-bold text-[10px] uppercase" title="Hapus permanen user <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>">
                                                Delete
                                            </a>
                                        <?php else: ?>
                                            <span class="text-[9px] text-gray-600 italic">Protected</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                        <?php
                            endwhile;
                        endif;
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="glass rounded-3xl overflow-hidden shadow-2xl mb-8" id="queues">
            <div class="p-6 border-b border-white/5 justify-between flex items-center">
                <div class="flex items-center gap-2">
                    <i data-lucide="server" class="w-5 h-5 text-purple-500"></i>
                    <h3 class="text-xs font-bold text-purple-500 uppercase">Active Background Tasks</h3>
                </div>
                <form method="POST" action="index.php" onsubmit="return meelConfirmForm(event, { title: 'Bersihkan Antrean', text: 'Bersihkan semua antrean yang stuck (> 30 menit)?', confirmButtonText: 'BERSIHKAN' });">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <button type="submit" name="clean_stuck_queues" value="1" class="flex items-center gap-2 text-[9px] bg-purple-600/10 text-purple-400 border border-purple-500/20 px-3 py-1.5 rounded-xl hover:bg-purple-600 hover:text-white transition-all font-bold uppercase cursor-pointer" title="Bersihkan semua antrean yang macet (> 30 menit)">
                        <i data-lucide="refresh-cw" class="w-3 h-3"></i>
                        Clean Stuck Queues
                    </button>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-white/[0.02] text-gray-500 uppercase text-[9px] font-black tracking-widest">
                        <tr>
                            <th class="py-3 px-6">Task ID</th>
                            <th class="py-3 px-4">User</th>
                            <th class="py-3 px-4">Type</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-6 text-right">Started At</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <?php
                        $active_queues = $sys->getActiveQueues();
                        if (!empty($active_queues)):
                            foreach ($active_queues as $q):
                        ?>
                                <tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="py-4 px-6 font-mono text-gray-400">#<?= $q['id'] ?></td>
                                    <td class="py-4 px-4 font-bold text-white"><?= htmlspecialchars($q['username'] ?? 'Unknown') ?></td>
                                    <td class="py-4 px-4">
                                        <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase <?= $q['task_type'] === 'download' ? 'bg-blue-500/20 text-blue-400' : 'bg-orange-500/20 text-orange-400' ?>">
                                            <?= $q['task_type'] ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-4 text-yellow-500 font-bold uppercase text-[10px]"><?= $q['status'] ?></td>

                                    <td class="py-4 px-6 text-right">
                                        <div class="flex items-center justify-end gap-3">
                                            <span class="text-gray-500 font-mono text-[10px]"><?= $q['created_at'] ?></span>

                                            <form method="POST" action="index.php" class="m-0" onsubmit="return meelConfirmForm(event, { title: 'Hentikan Proses', text: 'Hentikan paksa proses spesifik ini?', confirmButtonText: 'HENTIKAN' });">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="queue_id" value="<?= $q['id'] ?>">
                                                <input type="hidden" name="task_type" value="<?= $q['task_type'] ?>">

                                                <button type="submit" name="force_stop_queue" value="1" title="Force Stop" class="text-red-500 hover:text-white bg-red-500/10 hover:bg-red-600 border border-red-500/30 rounded p-1.5 transition-all flex items-center justify-center cursor-pointer">
                                                    <i data-lucide="x" class="w-3 h-3"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php
                            endforeach;
                        else:
                            ?>
                            <tr>
                                <td colspan="5" class="py-6 text-center text-gray-500 text-xs italic">Tidak ada proses yang sedang berjalan.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="glass rounded-3xl overflow-hidden shadow-2xl" id="monitor">
            <div class="p-6 border-b border-white/5 justify-between flex items-center">
                <h3 class="text-xs font-bold text-gray-500 uppercase">Live Activity Monitor</h3>
                <form method="POST" action="index.php" onsubmit="return meelConfirmForm(event, { title: 'Hapus Guest', text: 'Hapus semua Guest?', confirmButtonText: 'HAPUS' });">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <button type="submit" name="clear_all_guests" value="1" class="group flex flex-col items-end gap-1 cursor-pointer" title="Hapus semua user guest yang tidak aktif">
                        <div class="flex items-center gap-2 text-[9px] bg-red-600/10 text-red-500 border border-red-500/20 px-3 py-1.5 rounded-xl hover:bg-red-600 hover:text-white transition-all font-bold uppercase">
                            <i data-lucide="shield-alert" class="w-3 h-3"></i>
                            Clean Inactive Guests
                        </div>
                        <span class="text-[8px] text-gray-600 font-mono tracking-tighter uppercase pr-1">Target: is_active = 0</span>
                    </button>
                </form>
            </div>


            <div class="scrollable-table-wrap" style="max-height:520px;">
                <table class="w-full text-left text-xs">
                    <thead class="text-gray-500 uppercase text-[9px] font-black tracking-widest">
                        <tr>
                            <th class="py-3 px-6">User</th>
                            <th class="py-3 px-4 text-center">Status</th>
                            <th class="py-3 px-4">Last Page</th>
                            <th class="py-3 px-6 text-right">Activity</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <?php while ($row = $result_monitor->fetch_assoc()):
                            $is_online = (time() - strtotime($row['last_activity'])) < 300;
                            $is_cloud = strpos($row['access_via'] ?? '', 'trycloudflare.com') !== false;
                            $is_mobile = strpos($row['user_agent'] ?? '', 'Smartphone') !== false || strpos($row['user_agent'] ?? '', 'Android') !== false;
                        ?>
                            <tr class="group hover:bg-white/[0.02] transition-colors">
                                <td class="py-4 px-2">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-bold <?= $row['role'] === 'guest' ? 'text-gray-500 italic' : 'text-white' ?>">
                                            <a href="profile/?u=<?= $row['username'] ?>"><?= htmlspecialchars($row['username']) ?></a>
                                        </span>
                                        <?php if ($row['role'] === 'guest'): ?>
                                            <span class="text-[7px] bg-white/5 text-gray-500 px-1 rounded border border-white/10 uppercase font-black">Guest</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="text-xs font-bold text-white">
                                            <?= htmlspecialchars($row['user_agent']) ?>
                                        </span>

                                        <div class="flex items-center gap-1 mt-1 flex-wrap">
                                            <?php
                                            $ip_display = $row['ip_address'] ?? 'Unknown';
                                            $is_local = ($ip_display === 'LOCAL' || strpos($ip_display, 'Local') !== false);

                                            // Deteksi tipe IP
                                            $ip_type = 'Unknown';
                                            $ip_color_class = 'bg-gray-800 text-gray-400 border-gray-700';
                                            if ($is_local) {
                                                $ip_color_class = 'bg-amber-800 text-amber-300 border-amber-700';
                                                $ip_type = 'Local';
                                            } elseif (filter_var($ip_display, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                                                $ip_color_class = 'bg-blue-800 text-blue-300 border-blue-700';
                                                $ip_type = 'IPv6';
                                            } elseif (filter_var($ip_display, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                                                $ip_color_class = 'bg-cyan-800 text-cyan-300 border-cyan-700';
                                                $ip_type = 'IPv4';
                                            }

                                            $ip_badge_text = $is_local ? 'LOCAL' : $ip_display;
                                            ?>
                                            <code class="text-[10px] <?= $ip_color_class ?> px-2 py-0.5 rounded border font-mono select-all">
                                                <?= htmlspecialchars($ip_badge_text) ?>
                                            </code>

                                            <?php if ($is_local): ?>
                                                <span class="text-[7px] bg-amber-500/10 text-amber-500 px-1.5 rounded border border-amber-500/30 uppercase font-black tracking-wider">🏠 Lokal</span>
                                            <?php elseif ($ip_type === 'IPv6'): ?>
                                                <span class="text-[7px] bg-blue-500/10 text-blue-500 px-1.5 rounded border border-blue-500/30 uppercase font-black tracking-wider">🌐 IPv6</span>
                                            <?php elseif ($ip_type === 'IPv4'): ?>
                                                <span class="text-[7px] bg-green-500/10 text-green-500 px-1.5 rounded border border-green-500/30 uppercase font-black tracking-wider">🌐 IPv4</span>
                                            <?php endif; ?>
                                        </div>

                                        <span class="text-[9px] text-gray-500 font-semibold mt-1">
                                            🔗 <?= htmlspecialchars($row['access_via']) ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="py-4 px-2">
                                    <div class="flex items-center gap-2 <?= $is_online ? 'text-green-500' : 'text-gray-600' ?>">
                                        <span class="h-1.5 w-1.5 rounded-full <?= $is_online ? 'bg-green-500 animate-pulse' : 'bg-gray-700' ?>"></span>
                                        <span class="text-[10px] font-black uppercase tracking-tighter"><?= $is_online ? 'Online' : 'Offline' ?></span>
                                    </div>
                                </td>
                                <td class="py-4 px-2">
                                    <code class="text-[10px] bg-orange-500/10 text-orange-500 px-2 py-1 rounded border border-orange-500/20 font-mono"><?= htmlspecialchars($row['last_page']) ?></code>
                                </td>
                                <td class="py-4 px-6 text-right">
                                    <div class="flex items-center justify-end gap-3">
                                        <span class="text-xs text-gray-400 font-mono"><?= date('H:i:s', strtotime($row['last_activity'])) ?></span>

                                        <?php
                                        // Hitung ulang status online di dalam loop agar akurat
                                        $is_online = (time() - strtotime($row['last_activity'])) < 300;

                                        if ($is_online && $row['username'] !== $_SESSION['username'] && $row['role'] !== 'guest'):
                                        ?>
                                            <a href="?kick_user=<?= urlencode($row['username']) ?>"
                                                onclick="return meelConfirmLink(event, { title: 'Kick User', text: 'Tendang <?= htmlspecialchars($row['username'], ENT_QUOTES) ?>? User akan langsung offline.', confirmButtonText: 'TENDANG' })"
                                                class="p-1.5 bg-red-600/10 text-red-500 border border-red-500/20 rounded-lg hover:bg-red-600 hover:text-white transition-all"
                                                title="Kick Active User">
                                                <i data-lucide="log-out" class="w-3.5 h-3.5"></i>
                                            </a>
                                        <?php elseif (!$is_online && $row['username'] !== $_SESSION['username']): ?>
                                            <span class="p-1.5 bg-gray-800/30 text-gray-700 rounded-lg border border-gray-800/50 cursor-not-allowed" title="User is already offline">
                                                <i data-lucide="user-minus" class="w-3.5 h-3.5"></i>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="glass rounded-3xl overflow-hidden shadow-2xl mt-8 border border-red-500/20" id="unban">
            <div class="p-6 border-b border-white/5 bg-red-500/5 flex justify-between items-center">
                <div class="flex items-center gap-2">
                    <i data-lucide="shield-alert" class="w-5 h-5 text-red-500"></i>
                    <h3 class="text-xs font-bold text-red-500 uppercase">Firewall & Banned IPs</h3>
                </div>
                <span class="text-[10px] text-gray-500 uppercase">Protected by MEeL Security</span>
            </div>

            <div class="p-6">
                <form method="POST" class="flex flex-col gap-2 mb-6">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div class="flex gap-2">
                        <input type="text" name="ip_target" placeholder="IP Address..."
                            class="bg-gray-800 text-white text-xs px-4 py-2 rounded-xl border border-gray-700 focus:border-red-500 outline-none w-1/3" required>

                        <input type="text" name="ban_reason" placeholder="Alasan pemblokiran (Contoh: Percobaan Brute Force)..."
                            class="bg-gray-800 text-white text-xs px-4 py-2 rounded-xl border border-gray-700 focus:border-red-500 outline-none w-2/3">
                    </div>

                    <button type="submit" name="ban_ip"
                        class="w-full bg-red-600 hover:bg-red-700 text-white text-[10px] font-black py-2 rounded-xl transition-all uppercase tracking-widest" title="Blokir alamat IP ini secara permanen">
                        EKSEKUSI BAN IP 🚫
                    </button>
                </form>

                <?php if ($banned_ips->num_rows > 0): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="text-gray-500 uppercase text-[9px] font-black">
                                <tr>
                                    <th class="py-2">IP Address</th>
                                    <th class="py-2">Reason</th>
                                    <th class="py-2">Time</th>
                                    <th class="py-2 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-800">
                                <?php while ($ban = $banned_ips->fetch_assoc()): ?>
                                    <tr>
                                        <td class="py-3 font-mono text-red-400 font-bold"><?= $ban['ip_address'] ?></td>
                                        <td class="py-3 text-gray-400"><?= $ban['reason'] ?></td>
                                        <td class="py-3 text-gray-500"><?= $ban['banned_at'] ?></td>
                                        <td class="py-3 text-right">
                                            <a href="?unban_ip=<?= $ban['ip_address'] ?>" class="text-[9px] border border-green-500/30 text-green-500 px-3 py-1 rounded hover:bg-green-500 hover:text-white transition" title="Buka blokir IP <?= $ban['ip_address'] ?>">UNBAN</a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center text-xs text-gray-500 py-4">Belum ada IP yang di-banned. Aman terkendali, <?= $_SESSION['username'] ?> 🛡️</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        lucide.createIcons();

        // ── CHART DATA ────────────────────────────────────────────────────────
        var activityData = <?= json_encode($chart_activity) ?>;

        // ── 7-DAY ACTIVITY BAR CHART ───────────────────────────────────────
            var ctx2 = document.getElementById('activityChart');
            if (ctx2 && activityData.length > 0) {
                new Chart(ctx2, {
                    type: 'bar',
                    data: {
                        labels: activityData.map(function(d) { return d.label; }),
                        datasets: [
                            {
                                label: 'Views',
                                data: activityData.map(function(d) { return d.views; }),
                                backgroundColor: 'rgba(59, 130, 246, 0.7)',
                                borderColor: '#3b82f6',
                                borderWidth: 1,
                                borderRadius: 4,
                                order: 1
                            },
                            {
                                label: 'Uploads',
                                data: activityData.map(function(d) { return d.uploads; }),
                                backgroundColor: 'rgba(34, 197, 94, 0.7)',
                                borderColor: '#22c55e',
                                borderWidth: 1,
                                borderRadius: 4,
                                order: 2
                            },
                            {
                                label: 'Active Users',
                                data: activityData.map(function(d) { return d.users; }),
                                type: 'line',
                                borderColor: '#a855f7',
                                backgroundColor: 'rgba(168, 85, 247, 0.1)',
                                pointBackgroundColor: '#a855f7',
                                pointRadius: 3,
                                borderWidth: 2,
                                fill: true,
                                tension: 0.3,
                                order: 0
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
                        plugins: {
                            legend: {
                                labels: {
                                    color: '#9ca3af',
                                    font: { size: 9 },
                                    boxWidth: 12,
                                    padding: 8
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: { color: 'rgba(255,255,255,0.03)' },
                                ticks: { color: '#6b7280', font: { size: 9 } }
                            },
                            y: {
                                beginAtZero: true,
                                grid: { color: 'rgba(255,255,255,0.03)' },
                                ticks: { color: '#6b7280', font: { size: 9 } }
                            }
                        }
                    }
                });
            }
        

        // Auto-refresh every 60 seconds
        setTimeout(function() {
            location.reload();
        }, 60000);
    </script>
</body>

</html>