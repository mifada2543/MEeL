<?php
/**
 * MEeL — Admin MFA Reset Page
 *
 * Menampilkan daftar user yang memiliki MFA aktif.
 * Admin dapat mereset MFA user jika mereka kehilangan akses Authenticator.
 */

include '../auth/config.php';
include '../auth/auth.php';
include_once '../modules/core/helpers.php';
include_once '../modules/core/activity_logger.php';

if (!isset($_SESSION['user_id'])) {
    die(include '../err/denied.php');
}
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die(include '../err/denied.php');
}

include '../controllers/admin/admin_actions.php';

$msg      = $_GET['msg'] ?? '';
$msg_user = $_GET['user'] ?? '';

// Ambil user dengan MFA aktif
$mfa_users = $conn->query("
    SELECT id, username, role, is_active, last_activity, created_at
    FROM users
    WHERE mfa_enabled = 1
    ORDER BY last_activity DESC
");

// Ambil total user
$total_mfa = $conn->query("SELECT COUNT(*) AS c FROM users WHERE mfa_enabled = 1")->fetch_assoc()['c'] ?? 0;
$total_all = $conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'] ?? 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MFA Reset | Admin MEeL</title>
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <link href="../assets/css/tailwind.min.css" rel="stylesheet">
    <script src="../assets/js/lucide.js"></script>
    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <style>
        body { background-color: #0b0e14; }
        .glass {
            background: rgba(22, 27, 34, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .scroll-table {
            overflow: auto;
            scrollbar-width: thin;
            scrollbar-color: #374151 transparent;
        }
        .scroll-table::-webkit-scrollbar { width: 5px; }
        .scroll-table::-webkit-scrollbar-thumb { background: #374151; border-radius: 999px; }
    </style>
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

        <!-- Header -->
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

        <!-- Alert Messages -->
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

        <!-- Info Card -->
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

        <!-- Users Table -->
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
                                            <?= $u['role'] ?>
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

        <!-- Back link -->
        <div class="text-center mt-8">
            <a href="index.php" class="text-xs text-gray-600 hover:text-blue-500 transition inline-flex items-center gap-1">
                <i data-lucide="arrow-left" class="w-3 h-3"></i> Kembali ke Dashboard Admin
            </a>
        </div>
    </div>

    <script>
        lucide.createIcons();

        function confirmResetMFA(userId, username) {
            Swal.fire({
                title: 'Reset MFA ' + username + '?',
                html: '<div style="font-size:12px;color:#9ca3af">' +
                    'MFA untuk <strong style="color:#e5e7eb">@' + username + '</strong> akan dinonaktifkan.<br>' +
                    'User harus <strong style="color:#fbbf24">setup ulang MFA</strong> dari profil mereka.<br><br>' +
                    '<span style="color:#ef4444;font-size:11px">Tindakan ini tidak bisa dibatalkan.</span>' +
                    '</div>',
                icon: 'warning',
                iconColor: '#ef4444',
                showCancelButton: true,
                confirmButtonText: 'RESET MFA',
                cancelButtonText: 'BATAL',
                background: '#141820',
                color: '#fff',
                reverseButtons: true,
                customClass: {
                    popup: 'border border-red-600/25 rounded-2xl shadow-2xl',
                    title: 'text-sm font-black uppercase tracking-wider pt-4 text-red-500',
                    htmlContainer: 'mt-1 mb-4',
                    confirmButton: 'bg-red-600 hover:bg-red-500 text-white text-xs font-black uppercase tracking-wider py-2.5 px-6 rounded-xl transition-all border-none cursor-pointer ml-2',
                    cancelButton: 'bg-white/5 hover:bg-white/10 text-gray-400 text-xs font-black uppercase tracking-wider py-2.5 px-6 rounded-xl border border-white/10 cursor-pointer transition-all mr-2'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'mfa_reset.php?reset_mfa=1&user_id=' + userId + '&csrf_token=<?= $_SESSION['csrf_token'] ?>';
                }
            });
        }
    </script>
</body>
</html>
