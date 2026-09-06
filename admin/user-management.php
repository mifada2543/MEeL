<?php
include '../auth/config.php';
include '../auth/auth.php';
include_once '../modules/core/helpers.php';
include_once '../modules/core/activity_logger.php';
include_once '../modules/core/GarbageCollector.php';

require_admin($conn);

define('MEEL_ADMIN_CONTEXT', true);

include '../controllers/admin/admin_actions.php';
include '../controllers/admin/admin_data.php';

/** @var \mysqli_result $banned_ips */
/** @var \mysqli_result $all_users */
/** @var array $stats */
/** @var \mysqli_result $pending_users */
/** @var \mysqli_result $result_monitor */

require_once __DIR__ . '/../modules/core/System.php';
$sys = new System($conn);

$msg = $_GET['msg'] ?? null;
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | MEeL Admin</title>
    <?php include '../partials/link.php'; ?>
    <?php foreach (require __DIR__ . '/../assets/css/admin/manifest.php' as $__f): ?>
        <link href="../assets/css/admin/<?= $__f ?>" rel="stylesheet">
    <?php endforeach; ?>
    <link href="../assets/css/admin/index.css" rel="stylesheet">
</head>
<body class="bg-[#0b0e14] min-h-screen">
    <?php
    $is_admin = true;
    $page_title = 'User Management';
    $media_type = 'dashboard';
    $back_url = 'index.php';
    include 'header-admin.php';
    ?>
    <div class="max-w-5xl mx-auto px-4 md:px-8 py-8">

        <div class="flex items-center gap-4 mb-8">
            <div class="w-12 h-12 rounded-2xl bg-blue-500/15 border border-blue-500/25 flex items-center justify-center shrink-0">
                <i data-lucide="users" class="w-5 h-5 text-blue-500"></i>
            </div>
            <div>
                <h1 class="text-2xl font-extrabold text-white leading-tight">User Management</h1>
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500 mt-1">Kelola akun, verifikasi, monitor, dan keamanan</p>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="mb-6 p-3 rounded-xl bg-green-500/10 border border-green-500/20 text-green-400 text-xs font-bold uppercase tracking-widest">
                <?= htmlspecialchars(str_replace('_', ' ', $msg)) ?>
            </div>
        <?php endif; ?>

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
                                        <button type="submit" class="bg-green-600 text-white px-4 py-1.5 rounded-xl font-bold text-[10px] cursor-pointer">APPROVE</button>
                                    </form>
                                    <form method="POST" class="inline" onsubmit="return meelConfirmForm(event, { title: 'Tolak User', text: 'Tolak pendaftaran <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?', confirmButtonText: 'TOLAK' })">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="reject_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="bg-red-600/20 text-red-500 px-4 py-1.5 rounded-xl font-bold text-[10px] border border-red-500/20 cursor-pointer">REJECT</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="glass rounded-3xl overflow-hidden mb-8 border border-white/5">
            <div class="p-6 border-b border-white/5 bg-white/[0.02] flex justify-between items-center">
                <div class="flex items-center gap-2">
                    <i data-lucide="users" class="w-5 h-5 text-blue-500"></i>
                    <h3 class="text-xs font-bold text-gray-400 uppercase">User Accounts</h3>
                </div>
                <span class="text-[9px] text-gray-600 font-mono">Total: <?= ($all_users) ? $all_users->num_rows : 0 ?> Accounts</span>
            </div>

            <div class="scrollable-table-wrap" style="max-height:400px;">
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
                                        <?php if ($u['role'] !== 'admin'): ?>
                                            <form method="POST" class="inline" onsubmit="return meelConfirmForm(event, { title: 'Hapus User', text: 'Hapus permanen user <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>?', confirmButtonText: 'HAPUS' })">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="delete_user_id" value="<?= (int)$u['id'] ?>">
                                                <button type="submit" class="bg-red-600/10 text-red-500 border border-red-500/20 px-3 py-1.5 rounded-xl hover:bg-red-600 hover:text-white transition-all font-bold text-[10px] uppercase cursor-pointer">Delete</button>
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

        <div class="glass rounded-3xl overflow-hidden shadow-2xl mb-8" id="monitor">
            <div class="p-6 border-b border-white/5 justify-between flex items-center">
                <div class="flex items-center gap-2">
                    <i data-lucide="monitor" class="w-5 h-5 text-green-500"></i>
                    <h3 class="text-xs font-bold text-green-500 uppercase">Live Activity Monitor</h3>
                </div>
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
                                                <span class="text-[7px] bg-amber-500/10 text-amber-500 px-1.5 rounded border border-amber-500/30 uppercase font-black tracking-wider">Lokal</span>
                                            <?php elseif ($ip_type === 'IPv6'): ?>
                                                <span class="text-[7px] bg-blue-500/10 text-blue-500 px-1.5 rounded border border-blue-500/30 uppercase font-black tracking-wider">IPv6</span>
                                            <?php elseif ($ip_type === 'IPv4'): ?>
                                                <span class="text-[7px] bg-green-500/10 text-green-500 px-1.5 rounded border border-green-500/30 uppercase font-black tracking-wider">IPv4</span>
                                            <?php endif; ?>
                                        </div>

                                        <span class="text-[9px] text-gray-500 font-semibold mt-1">
                                            <?= htmlspecialchars($row['access_via']) ?>
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
                                                <button type="submit" class="p-1.5 bg-red-600/10 text-red-500 border border-red-500/20 rounded-lg hover:bg-red-600 hover:text-white transition-all cursor-pointer" title="Kick Active User">
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

        <div class="glass rounded-3xl overflow-hidden shadow-2xl mb-8 border border-red-500/20" id="unban">
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
                        class="w-full bg-red-600 hover:bg-red-700 text-white text-[10px] font-black py-2 rounded-xl transition-all uppercase tracking-widest">
                        EKSEKUSI BAN IP
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
                                                <button type="submit" class="text-[9px] border border-green-500/30 text-green-500 px-3 py-1 rounded hover:bg-green-500 hover:text-white transition cursor-pointer">UNBAN</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center text-xs text-gray-500 py-4">Belum ada IP yang di-banned. Aman terkendali, <?= htmlspecialchars($_SESSION['username']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="../assets/js/admin/shared/modal.js?v=<?= filemtime('../assets/js/admin/shared/modal.js') ?>"></script>
    <script src="../assets/js/admin/shared/hover-effects.js?v=<?= filemtime('../assets/js/admin/shared/hover-effects.js') ?>"></script>
    <script src="../assets/js/admin/shared/search.js?v=<?= filemtime('../assets/js/admin/shared/search.js') ?>"></script>
    <script>if (typeof lucide !== 'undefined') lucide.createIcons();</script>
</body>
</html>
