<?php

include '../auth/config.php';
include '../auth/auth.php';
include_once '../modules/core/helpers.php';
include_once '../modules/core/activity_logger.php';

require_admin($conn);

define('MEEL_ADMIN_CONTEXT', true);
include '../controllers/admin/admin_actions.php';
$msg      = $_GET['msg'] ?? '';
$msg_user = $_GET['user'] ?? '';
$mfa_users = $conn->query("
    SELECT id, username, role, is_active, last_activity, created_at
    FROM users
    WHERE mfa_enabled = 1
    ORDER BY last_activity DESC
");
$total_mfa = $conn->query("SELECT COUNT(*) AS c FROM users WHERE mfa_enabled = 1")->fetch_assoc()['c'] ?? 0;
$total_all = $conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'] ?? 0;
?>
<!DOCTYPE html>
<html lang="id">

<head>
<?php
$_META_TITLE = 'MFA Reset | Admin MEeL';
$_META_DESC  = 'MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.';
include __DIR__ . '/../partials/link.php';
$scripts_root = '../';
include __DIR__ . '/../partials/scripts.php';
?>
    <?php foreach (require __DIR__ . '/../assets/css/admin/manifest.php' as $__f): ?>
        <link rel="stylesheet" href="../assets/css/admin/<?= $__f ?>?v=<?= filemtime(__DIR__ . '/../assets/css/admin/' . $__f) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="../assets/css/admin/mfa_reset.css?v=<?= filemtime('../assets/css/admin/mfa_reset.css') ?>">
</head>

<body class="text-gray-300 min-h-screen">
    <?php
    $is_admin   = true;
    $page_title = 'MFA Reset';
    $media_type = 'analytics';
    $back_url   = 'index.php';
    include 'header-admin.php';
    ?>
    <div class="max-w-4xl mx-auto px-4 md:px-8 py-8">
        
        <div class="flex items-center gap-4 mb-8">
            <div class="w-12 h-12 rounded-2xl bg-purple-500/15 border border-purple-500/25 flex items-center justify-center shrink-0">
                <i data-lucide="shield" class="w-5 h-5 text-purple-500"></i>
            </div>
            <div>
                <h1 class="text-2xl font-extrabold text-white leading-tight">MFA Management</h1>
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500 mt-1">
                    <?= $total_mfa ?> / <?= $total_all ?> users have MFA enabled
                </p>
            </div>
        </div>
        
        <?php if ($msg === 'reset_ok' && $msg_user): ?>
            <div class="mb-6 p-4 rounded-2xl text-sm flex items-center gap-3 bg-green-500/10 text-green-400 border border-green-500/20">
                <i data-lucide="check-circle" class="w-5 h-5"></i>
                MFA untuk <strong class="text-white"><?= htmlspecialchars($msg_user) ?></strong> berhasil di-reset.
            </div>
        <?php elseif ($msg === 'user_not_found'): ?>
            <div class="mb-6 p-4 rounded-2xl text-sm flex items-center gap-3 bg-red-500/10 text-red-400 border border-red-500/20">
                <i data-lucide="alert-circle" class="w-5 h-5"></i>
                User tidak ditemukan.
            </div>
        <?php elseif ($msg === 'cannot_reset_admin'): ?>
            <div class="mb-6 p-4 rounded-2xl text-sm flex items-center gap-3 bg-red-500/10 text-red-400 border border-red-500/20">
                <i data-lucide="shield-off" class="w-5 h-5"></i>
                Tidak bisa mereset MFA admin lain. Minta admin yang bersangkutan untuk menonaktifkan sendiri.
            </div>
        <?php elseif ($msg === 'csrf_invalid'): ?>
            <div class="mb-6 p-4 rounded-2xl text-sm flex items-center gap-3 bg-red-500/10 text-red-400 border border-red-500/20">
                <i data-lucide="alert-triangle" class="w-5 h-5"></i>
                Sesi keamanan kadaluarsa. Silakan refresh halaman dan coba lagi.
            </div>
        <?php elseif ($msg === 'reset_failed'): ?>
            <div class="mb-6 p-4 rounded-2xl text-sm flex items-center gap-3 bg-red-500/10 text-red-400 border border-red-500/20">
                <i data-lucide="x-circle" class="w-5 h-5"></i>
                Gagal mereset MFA. Coba lagi atau periksa database.
            </div>
        <?php endif; ?>
        
        <div class="glass p-5 rounded-2xl mb-6 border border-purple-500/10 space-y-2">
            <div class="flex items-start gap-3">
                <i data-lucide="info" class="w-4 h-4 text-purple-400 mt-0.5"></i>
                <div class="text-xs text-gray-400 leading-relaxed">
                    <strong class="text-white">Reset MFA</strong> akan menonaktifkan autentikasi dua faktor untuk user tersebut.
                    User perlu melakukan <strong class="text-yellow-400">setup ulang MFA</strong> dari halaman profil mereka.
                    Backup codes lama juga akan dihapus.
                </div>
            </div>
        </div>
        
        <div class="glass rounded-2xl overflow-hidden">
            <div class="p-5 border-b border-white/5 bg-white/[0.02] flex items-center gap-2">
                <i data-lucide="users" class="w-4 h-4 text-purple-400"></i>
                <h3 class="text-xs font-bold text-gray-400 uppercase">Users with MFA Active</h3>
            </div>
            <?php if ($mfa_users && $mfa_users->num_rows > 0): ?>
                <div class="scroll-table" style="max-height:400px">
                    <table class="w-full text-left text-xs">
                        <thead class="text-gray-500 uppercase text-[9px] font-black tracking-widest">
                            <tr>
                                <th class="py-3 px-5">Username</th>
                                <th class="py-3 px-3">Role</th>
                                <th class="py-3 px-3">Status</th>
                                <th class="py-3 px-4">Last Activity</th>
                                <th class="py-3 px-5 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800">
                            <?php while ($u = $mfa_users->fetch_assoc()): ?>
                                <?php $is_online = (time() - strtotime($u['last_activity'])) < 300; ?>
                                <tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="py-4 px-5">
                                        <div class="flex items-center gap-2">
                                            <span class="font-bold text-white"><?= htmlspecialchars($u['username']) ?></span>
                                            <span class="text-[9px] text-gray-600 font-mono">#<?= $u['id'] ?></span>
                                        </div>
                                    </td>
                                    <td class="py-3 px-3">
                                        <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase <?= $u['role'] === 'admin' ? 'bg-purple-500/20 text-purple-400' : 'bg-blue-500/10 text-blue-400' ?>">
                                            <?= htmlspecialchars($u['role'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-3">
                                        <span class="text-[10px] font-bold uppercase <?= $u['is_active'] == 1 ? 'text-green-500' : 'text-yellow-500' ?>">
                                            <?= $u['is_active'] == 1 ? 'Active' : 'Pending' ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-gray-500 font-mono text-[10px]">
                                        <?= date('d/m/Y H:i', strtotime($u['last_activity'])) ?>
                                    </td>
                                    <td class="py-3 px-5 text-right">
                                        <?php if ($u['role'] !== 'admin'): ?>
                                            <button type="button" onclick="confirmResetMFA(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')"
                                                class="bg-red-600/10 text-red-500 border border-red-500/20 px-3 py-1.5 rounded-xl hover:bg-red-600 hover:text-white transition-all font-bold text-[10px] uppercase inline-flex items-center gap-1.5"
                                                title="Reset MFA untuk <?= htmlspecialchars($u['username']) ?>">
                                                <i data-lucide="shield-off" class="w-3 h-3"></i>
                                                Reset MFA
                                            </button>
                                        <?php else: ?>
                                            <span class="text-[9px] text-gray-600 italic">Protected</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="p-10 text-center">
                    <i data-lucide="shield-check" class="w-10 h-10 text-green-500/50 mx-auto mb-4"></i>
                    <p class="text-sm text-gray-500 font-bold">Tidak ada user dengan MFA aktif.</p>
                    <p class="text-[10px] text-gray-600 mt-1">Semua user aman tanpa perlu di-reset.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="text-center mt-8">                <a href="." class="text-xs text-gray-600 hover:text-blue-500 transition inline-flex items-center gap-1">
                <i data-lucide="arrow-left" class="w-3 h-3"></i> Kembali ke Dashboard Admin
            </a>
        </div>
    </div>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <script src="../assets/js/admin/mfa_reset.js?v=<?= filemtime('../assets/js/admin/mfa_reset.js') ?>"></script>
</body>

</html>
