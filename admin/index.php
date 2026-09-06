<?php
include '../auth/config.php';
include '../auth/auth.php';
include_once '../modules/core/helpers.php';
include_once '../modules/core/activity_logger.php';
include_once '../modules/core/GarbageCollector.php';
include_once '../modules/auth/RateLimiter.php';


require_admin($conn);

define('MEEL_ADMIN_CONTEXT', true);

include '../controllers/admin/admin_actions.php';
include '../controllers/admin/admin_data.php';

// Variabel berikut di-set oleh controllers/admin/admin_data.php (include di
// atas). Anotasi @var untuk intelephense — variabel valid saat runtime.
/** @var System $sys                        Instance System (dibuat admin_data). */
/** @var int|null $orphan_checked_at         Waktu cache scan storage. */
/** @var \mysqli_result $banned_ips      Hasil query ip_ban. */
/** @var \mysqli_result $all_users      Daftar seluruh user. */
/** @var \mysqli_result $top_media      Media terpopuler. */
/** @var \mysqli_result $pending_users  User menunggu aktivasi. */
/** @var \mysqli_result $result_monitor Hasil query monitor queue. */
/** @var array $stats                   Statistik agregat (views/likes/dll). */
/** @var array $server_stats            Statistik server (cpu/ram/swap/net). */
/** @var array $orphans                 Daftar file yatim. */
/** @var array $chart_activity          Data chart aktivitas. */
/** @var float $ssd_free */
/** @var float $ssd_used */
/** @var float $ssd_total */
/** @var float $hdd_free */
/** @var float $sz_vid */
/** @var float $sz_mus */
/** @var float $sz_book */
/** @var float $sz_d_pub */
/** @var float $sz_d_prv */
/** @var float $p_vid */
/** @var float $p_mus */
/** @var float $p_book */
/** @var float $p_drive */

GarbageCollector::cleanGuests($conn);

GarbageCollector::cleanChessRooms($conn);

GarbageCollector::syncViews($conn);
?>
<!DOCTYPE html>
<html lang="id">

<head>
<?php
$_META_TITLE = 'MEeL | System Admin';
$_META_DESC  = 'Panel administrasi MEeL untuk mengelola konten, pengguna, dan monitoring server.';
include __DIR__ . '/../partials/link.php';
$scripts_root = '../';
include __DIR__ . '/../partials/scripts.php';
?>
    <?php foreach (require __DIR__ . '/../assets/css/admin/manifest.php' as $__f): ?>
    <link rel="stylesheet" href="../assets/css/admin/<?= $__f ?>?v=<?= filemtime(__DIR__ . '/../assets/css/admin/' . $__f) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="../assets/css/admin/index.css?v=<?= filemtime('../assets/css/admin/index.css') ?>">
    <script defer src="../assets/js/compatibilitas/chart.umd.min.js"></script>
</head>

<body class="text-gray-300 font-sans min-h-screen">

    <?php
    $is_admin = true;
    $page_title = 'Dashboard';
    $media_type = 'dashboard';
    $back_url = '../';
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
                                $link = ($type == 'video') ? base_url('/video/watch?id=') : base_url('/music/watch?id=');
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

                <div class="flex gap-0 rounded-xl overflow-hidden border border-white/10 mb-2">
                    <a href="activity-log" 
                       class="flex-1 text-center text-[9px] text-blue-400 border-r border-white/10 py-2.5 hover:bg-blue-400 hover:text-white font-black uppercase tracking-widest transition-all" 
                       title="Lihat trail audit aktivitas pengguna">
                        <i data-lucide="activity" class="w-3 h-3 inline mr-1"></i> Activity Log
                    </a>
                    <a href="activity-log?tab=views" 
                       class="flex-1 text-center text-[9px] text-purple-400 py-2.5 hover:bg-purple-400 hover:text-white font-black uppercase tracking-widest transition-all" 
                       title="Monitoring view logs dan statistik">
                        <i data-lucide="bar-chart-3" class="w-3 h-3 inline mr-1"></i> View Analytics
                    </a>
                </div>
                <a href="mfa-reset" class="block mb-2 text-center text-[9px] text-purple-400 border border-purple-400/20 py-2.5 rounded-xl hover:bg-purple-400 hover:text-white font-black uppercase tracking-widest transition-all" title="Kelola autentikasi dua faktor (MFA) user">
                    <i data-lucide="shield" class="w-3 h-3 inline mr-1"></i> MFA Management
                </a>
                        <a href="cookies" class="block text-center text-[9px] text-blue-400 border border-blue-400/20 py-2.5 rounded-xl hover:bg-blue-400 hover:text-white font-black uppercase tracking-widest transition-all" title="Lihat laporan analitik lengkap">
                    Full Reports
                </a>

            </div>
        </div>

        
        <div class="glass p-6 rounded-3xl mb-8">
            <div class="flex items-center gap-2 mb-6">
                <i data-lucide="cpu" class="w-4 h-4 text-cyan-400"></i>
                <h3 class="text-[10px] font-black text-gray-500 uppercase tracking-widest">Server Stats — <?= htmlspecialchars($server_stats['info']['hostname'] ?? 'Unknown') ?></h3>
                <span id="stats-live" class="flex items-center gap-1.5 text-[8px] font-black uppercase tracking-widest text-green-500 bg-green-500/10 border border-green-500/25 px-2 py-1 rounded-lg" title="Data diperbarui otomatis setiap 3 detik">
                    <span class="h-1.5 w-1.5 rounded-full bg-green-500 animate-pulse"></span>
                    Live
                </span>
                <span id="stats-updated" class="text-[8px] text-gray-600 font-mono"></span>
                <label class="flex items-center gap-1.5 text-[8px] font-bold uppercase tracking-widest text-gray-500 cursor-pointer" title="Interval pembaruan realtime">
                    <i data-lucide="timer" class="w-3 h-3 text-cyan-400"></i>
                    Polling
                    <select id="stats-poll-interval" class="bg-gray-800/80 text-gray-300 text-[9px] font-bold uppercase tracking-widest border border-white/10 rounded-lg px-1.5 py-1 outline-none focus:border-cyan-500 cursor-pointer">
                        <option value="1000">1s</option>
                        <option value="3000" selected>3s</option>
                        <option value="5000">5s</option>
                        <option value="10000">10s</option>
                    </select>
                </label>
                <span class="ml-auto text-[8px] text-gray-600 font-mono">Uptime: <span id="stat-uptime"><?= $server_stats['uptime']['text'] ?></span></span>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <?php
                $cpu_color = $server_stats['cpu']['usage_perc'] > 80 ? 'red' : ($server_stats['cpu']['usage_perc'] > 50 ? 'yellow' : 'green');
                $ram_color = $server_stats['ram']['usage_perc'] > 80 ? 'red' : ($server_stats['ram']['usage_perc'] > 50 ? 'yellow' : 'cyan');
                $swap_color = $server_stats['swap']['usage_perc'] > 50 ? 'red' : 'gray';
                $net_rx = $server_stats['network']['rx'];
                $net_tx = $server_stats['network']['tx'];
                $net_fmt = function ($bytes) {
                    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
                    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
                    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
                    return $bytes . ' B';
                };

                $stat_cards = [
                    [
                        'id'    => 'cpu',
                        'label' => 'CPU Load',
                        'value' => $server_stats['cpu']['load_1m'],
                        'sub'   => $server_stats['cpu']['cores'] . ' Cores • ' . $server_stats['cpu']['usage_perc'] . '%',
                        'icon'  => 'cpu',
                        'color' => $cpu_color,
                        'bar'   => $server_stats['cpu']['usage_perc'],
                    ],
                    [
                        'id'    => 'ram',
                        'label' => 'RAM Usage',
                        'value' => $net_fmt($server_stats['ram']['used']),
                        'sub'   => $net_fmt($server_stats['ram']['total']) . ' Total • ' . $server_stats['ram']['usage_perc'] . '%',
                        'icon'  => 'memory-stick',
                        'color' => $ram_color,
                        'bar'   => $server_stats['ram']['usage_perc'],
                    ],
                    [
                        'id'    => 'swap',
                        'label' => 'Swap',
                        'value' => $net_fmt($server_stats['swap']['used']),
                        'sub'   => $net_fmt($server_stats['swap']['total']) . ' Total • ' . $server_stats['swap']['usage_perc'] . '%',
                        'icon'  => 'hard-drive',
                        'color' => $swap_color,
                        'bar'   => $server_stats['swap']['usage_perc'],
                    ],
                    [
                        'id'    => 'net',
                        'label' => 'Network',
                        'value' => '↓ —',
                        'sub'   => '↑ —',
                        'icon'  => 'network',
                        'color' => 'blue',
                        'bar'   => 0,
                    ],
                ];

                foreach ($stat_cards as $c):
                    $bar_color = match($c['color']) {
                        'red'    => 'bg-red-500',
                        'yellow' => 'bg-yellow-500',
                        'green'  => 'bg-green-500',
                        'cyan'   => 'bg-cyan-500',
                        'blue'   => 'bg-blue-500',
                        default  => 'bg-gray-500',
                    };
                    $text_color = match($c['color']) {
                        'red'    => 'text-red-400',
                        'yellow' => 'text-yellow-400',
                        'green'  => 'text-green-400',
                        'cyan'   => 'text-cyan-400',
                        'blue'   => 'text-blue-400',
                        default  => 'text-gray-400',
                    };
                ?>
                    <div class="bg-white/[0.02] border border-white/5 rounded-2xl p-4<?= $c['id'] === 'net' ? ' md:col-span-4' : '' ?>">
                        <div class="flex items-center gap-2 mb-3">
                            <i data-lucide="<?= $c['icon'] ?>" id="stat-<?= $c['id'] ?>-icon" class="w-3.5 h-3.5 <?= $text_color ?>"></i>
                            <span class="text-[9px] font-bold text-gray-500 uppercase tracking-widest"><?= $c['label'] ?></span>
                            <?php if ($c['id'] === 'net'): ?>
                                <span id="stat-net-ping" class="ml-auto text-[9px] font-mono font-bold text-gray-500" title="Latensi koneksi ke server (RTT polling / handshake SSE)">—</span>
                            <?php endif; ?>
                        </div>
                        <p id="stat-<?= $c['id'] ?>-value" class="text-xl font-black text-white mb-1"><?= $c['value'] ?></p>
                        <p id="stat-<?= $c['id'] ?>-sub" class="text-[10px] text-gray-500 font-medium mb-3"<?= $c['id'] === 'net' ? ' title="Total: ↓ ' . $net_fmt($net_rx) . ' / ↑ ' . $net_fmt($net_tx) . '"' : '' ?>><?= $c['sub'] ?></p>
                        <?php if ($c['bar'] > 0): ?>
                            <div class="w-full bg-gray-800/80 h-1.5 rounded-full">
                                <div id="stat-<?= $c['id'] ?>-bar" class="<?= $bar_color ?> h-full rounded-full transition-all" style="width:<?= $c['bar'] ?>%"></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($c['id'] === 'net'): ?>
                            
                            <div class="h-24 mt-2">
                                <canvas id="netChart"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            
            <div class="flex flex-wrap gap-4 text-[10px]">
                <span class="text-gray-500"><span class="text-gray-400 font-bold">OS:</span> <?= htmlspecialchars($server_stats['info']['os']) ?></span>
                <span class="text-gray-600">•</span>
                <span class="text-gray-500"><span class="text-gray-400 font-bold">Kernel:</span> <?= htmlspecialchars($server_stats['info']['kernel']) ?></span>
                <span class="text-gray-600">•</span>
                <span class="text-gray-500"><span class="text-gray-400 font-bold">PHP:</span> <?= $server_stats['info']['php_version'] ?></span>
                <span class="text-gray-600">•</span>
                <span class="text-gray-500"><span class="text-gray-400 font-bold">Load Avg:</span> <span id="stat-load"><?= $server_stats['cpu']['load_1m'] ?> / <?= $server_stats['cpu']['load_5m'] ?> / <?= $server_stats['cpu']['load_15m'] ?></span></span>
                <span class="text-gray-600">•</span>
                <span class="text-gray-500"><span class="text-gray-400 font-bold">Processes:</span> <span id="stat-procs"><?= $server_stats['info']['processes'] ?></span></span>
            </div>
        </div>

        
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
                                    <form method="POST" class="inline" onsubmit="return meelConfirmForm(event, { title: 'Setujui User', text: 'Setujui pendaftaran <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?', confirmButtonText: 'APPROVE' })">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="approve_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="bg-green-600 text-white px-4 py-1.5 rounded-xl font-bold text-[10px] cursor-pointer" title="Setujui pendaftaran <?= htmlspecialchars($u['username']) ?>">APPROVE</button>
                                    </form>
                                    <form method="POST" class="inline" onsubmit="return meelConfirmForm(event, { title: 'Tolak User', text: 'Tolak pendaftaran <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?', confirmButtonText: 'TOLAK' })">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="reject_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="bg-red-600/20 text-red-500 px-4 py-1.5 rounded-xl font-bold text-[10px] border border-red-500/20 cursor-pointer" title="Tolak pendaftaran <?= htmlspecialchars($u['username']) ?>">REJECT</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <div class="glass p-6 rounded-3xl mb-8" id="system_check">
            <div class="flex items-center gap-3 mb-4">
                <h3 class="text-xs font-bold text-gray-500 uppercase">Database Sync Check</h3>
                <span class="text-[9px] text-gray-600 font-mono" title="Hasil scan storage di-cache 10 menit agar halaman tetap responsif">Dicek: <?= $orphan_checked_at ? date('d/m/Y H:i:s', $orphan_checked_at) : '—' ?></span>
                <form method="POST" class="ml-auto">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <button type="submit" name="recheck_orphans" value="1" class="text-[9px] border border-white/10 text-gray-400 px-2.5 py-1 rounded-lg hover:bg-white/5 hover:text-white font-bold uppercase tracking-wider cursor-pointer" title="Paksa scan ulang seluruh storage media sekarang">Cek Ulang</button>
                </form>
            </div>
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
                                        if ($u['role'] !== 'admin'):
                                        ?>
                                            <form method="POST" class="inline" onsubmit="return meelConfirmForm(event, { title: 'Hapus User', text: 'Hapus permanen user <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?', confirmButtonText: 'HAPUS' })">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="delete_user_id" value="<?= (int)$u['id'] ?>">
                                                <button type="submit"
                                                    class="bg-red-600/10 text-red-500 border border-red-500/20 px-3 py-1.5 rounded-xl hover:bg-red-600 hover:text-white transition-all font-bold text-[10px] uppercase cursor-pointer" title="Hapus permanen user <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>">
                                                    Delete
                                                </button>
                                            </form>
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
                <form method="POST" onsubmit="return meelConfirmForm(event, { title: 'Bersihkan Antrean', text: 'Bersihkan semua antrean yang stuck (> 30 menit)?', confirmButtonText: 'BERSIHKAN' });">
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
                                            <?= htmlspecialchars($q['task_type'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td class="py-4 px-4 text-yellow-500 font-bold uppercase text-[10px]"><?= htmlspecialchars($q['status'], ENT_QUOTES, 'UTF-8') ?></td>

                                    <td class="py-4 px-6 text-right">
                                        <div class="flex items-center justify-end gap-3">
                                            <span class="text-gray-500 font-mono text-[10px]"><?= $q['created_at'] ?></span>

                                            <form method="POST" class="m-0" onsubmit="return meelConfirmForm(event, { title: 'Hentikan Proses', text: 'Hentikan paksa proses spesifik ini?', confirmButtonText: 'HENTIKAN' });">
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
                <form method="POST" onsubmit="return meelConfirmForm(event, { title: 'Hapus Guest', text: 'Hapus semua Guest?', confirmButtonText: 'HAPUS' });">
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
                            <tr class="group hover:bg-white/[0.02] transition-colors" data-sec-since="<?= max(0, time() - strtotime($row['last_activity'])) ?>">
                                <td class="py-4 px-2">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-bold <?= $row['role'] === 'guest' ? 'text-gray-500 italic' : 'text-white' ?>">
                                            <a href="profile/<?= htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['username']) ?></a>
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
                                    <div class="monitor-status flex items-center gap-2 <?= $is_online ? 'text-green-500' : 'text-gray-600' ?>">
                                        <span class="monitor-dot h-1.5 w-1.5 rounded-full <?= $is_online ? 'bg-green-500 animate-pulse' : 'bg-gray-700' ?>"></span>
                                        <span class="monitor-label text-[10px] font-black uppercase tracking-tighter"><?= $is_online ? 'Online' : 'Offline' ?></span>
                                    </div>
                                </td>
                                <td class="py-4 px-2">
                                    <code class="text-[10px] bg-orange-500/10 text-orange-500 px-2 py-1 rounded border border-orange-500/20 font-mono"><?= htmlspecialchars($row['last_page']) ?></code>
                                </td>
                                <td class="py-4 px-6 text-right">
                                    <div class="flex items-center justify-end gap-3">
                                        <span class="text-xs text-gray-400 font-mono"><?= date('H:i:s', strtotime($row['last_activity'])) ?></span>

                                        <?php
                                        $is_online = (time() - strtotime($row['last_activity'])) < 300;

                                        if ($is_online && $row['username'] !== $_SESSION['username'] && $row['role'] !== 'guest'):
                                        ?>
                                            <form method="POST" class="inline" onsubmit="return meelConfirmForm(event, { title: 'Kick User', text: 'Tendang <?= htmlspecialchars($row['username'], ENT_QUOTES) ?>? User akan langsung offline.', confirmButtonText: 'TENDANG' })">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="kick_user" value="<?= htmlspecialchars($row['username'], ENT_QUOTES) ?>">
                                                <button type="submit"
                                                    class="p-1.5 bg-red-600/10 text-red-500 border border-red-500/20 rounded-lg hover:bg-red-600 hover:text-white transition-all cursor-pointer"
                                                    title="Kick Active User">
                                                    <i data-lucide="log-out" class="w-3.5 h-3.5"></i>
                                                </button>
                                            </form>
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
                                        <td class="py-3 font-mono text-red-400 font-bold"><?= htmlspecialchars($ban['ip_address'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="py-3 text-gray-400"><?= htmlspecialchars($ban['reason'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="py-3 text-gray-500"><?= htmlspecialchars($ban['banned_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="py-3 text-right">
                                            <form method="POST" class="inline" onsubmit="return meelConfirmForm(event, { title: 'Unban IP', text: 'Buka blokir IP <?= htmlspecialchars($ban['ip_address'], ENT_QUOTES) ?>?', confirmButtonText: 'UNBAN' })">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="unban_ip" value="<?= htmlspecialchars($ban['ip_address'], ENT_QUOTES) ?>">
                                                <button type="submit" class="text-[9px] border border-green-500/30 text-green-500 px-3 py-1 rounded hover:bg-green-500 hover:text-white transition cursor-pointer" title="Buka blokir IP <?= htmlspecialchars($ban['ip_address']) ?>">UNBAN</button>
                                            </form>
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
        var activityData = <?= json_encode($chart_activity) ?>;
        var serverStatsUrl = <?= json_encode(base_url('/api/server-stats')) ?>;
        var serverStatsSseUrl = <?= json_encode(base_url('/api/server-stats-sse')) ?>;
    </script>
    <script src="../assets/js/admin/shared/modal.js?v=<?= filemtime('../assets/js/admin/shared/modal.js') ?>"></script>
    <script src="../assets/js/admin/shared/hover-effects.js?v=<?= filemtime('../assets/js/admin/shared/hover-effects.js') ?>"></script>
    <script src="../assets/js/admin/shared/search.js?v=<?= filemtime('../assets/js/admin/shared/search.js') ?>"></script>
    <script src="../assets/js/admin/index.js?v=<?= filemtime('../assets/js/admin/index.js') ?>"></script>
</body>

</html>
