<?php
require_once __DIR__ . '/auth_helpers.php';
auth_boot_session();
include 'config.php';
$back_url = auth_back_url(['login.php', 'register.php']);
$message = "";
$msg_type = "";
$max_reg_attempts = 3;
$reg_time_window = 3600;
$max_ip_attempts = 10;
$ip_lockout_time = 300;
$is_locked = false;
$remaining = 0;
$ip_address  = auth_get_ip();
$is_loopback = auth_is_loopback();
$ip_lock     = $is_loopback ? ['locked' => false, 'remaining' => 0] : auth_ip_lockout_status($conn, $ip_address);
$is_locked   = $ip_lock['locked'];
$remaining   = $ip_lock['remaining'];
if (!isset($_SESSION['reg_attempts'])) {
    $_SESSION['reg_attempts'] = [];
}
$_SESSION['reg_attempts'] = array_filter($_SESSION['reg_attempts'], function ($timestamp) use ($reg_time_window) {
    return $timestamp > (time() - $reg_time_window);
});
$session_blocked = !$is_loopback && count($_SESSION['reg_attempts']) >= $max_reg_attempts;
if (isset($_POST['register']) && !$is_locked && !$session_blocked) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $message = "Sesi keamanan kadaluarsa. Silakan refresh halaman.";
        $msg_type = "error";
        $validation_error = true;
    } else {
        $user = trim($_POST['username']);
        $pass_raw = $_POST['password'];
        $validation_error = false;
        $val_error = auth_validate_credentials($user, $pass_raw);
        if ($val_error !== null) {
            $message = $val_error;
            $msg_type = "warning";
            $validation_error = true;
        } else {
            $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $check->bind_param("s", $user);
            $check->execute();
            $result = $check->get_result();
            if ($result === false) {
                $message = "Terjadi kesalahan pada database. Silakan coba lagi nanti.";
                $msg_type = "error";
                $validation_error = true;
                error_log("[MEeL-Register] Database error: " . $conn->error);
            } else if ($result->num_rows > 0) {
                $message = "Username sudah terdaftar!";
                $msg_type = "warning";
                $validation_error = true;
            } else {
                $pass_hashed = password_hash($pass_raw, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, password, role, is_active) VALUES (?, ?, 'user', 2)");
                $stmt->bind_param("ss", $user, $pass_hashed);
                if ($stmt->execute()) {
                    $_SESSION['reg_attempts'][] = time();
                    $message = "Registrasi berhasil! Silakan tunggu verifikasi admin.";
                    $msg_type = "success";
                } else {
                    $message = "Terjadi kesalahan saat menyimpan data.";
                    $msg_type = "error";
                    $validation_error = true;
                }
            }
        }
        if ($validation_error) {
            $just_locked = auth_record_failed_attempt($conn, $ip_address, $max_ip_attempts, $ip_lockout_time);
            if ($just_locked) {
                $is_locked = true;
                $remaining = $ip_lockout_time;
            }
        }
    }
}
if (!$is_loopback && !$is_locked && !$session_blocked) {
    $recheck = auth_recheck_lockout($conn, $ip_address);
    if ($recheck['locked']) {
        $is_locked = true;
        $remaining = $recheck['remaining'];
    }
}
$auth_title       = "MEeL | Register";
$auth_description = "MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.";
$auth_og_title    = "MEeL | Register";
$auth_og_desc     = "Buat akun MEeL dan nikmati streaming video, musik, dan akses perpustakaan digital.";
include __DIR__ . '/partials/auth_head.php';
?>
<main class="w-full max-w-sm" aria-labelledby="register-title">
    <!-- Header -->
    <div class="text-center mb-8">
        <div class="inline-flex p-4 bg-red-600/10 rounded-3xl text-red-600 mb-4 shadow-lg shadow-red-900/10"><i data-lucide="user-plus" class="w-10 h-10"></i></div>
        <h2 id="register-title" class="text-3xl font-black text-white tracking-tighter">Register</h2>
        <p class="text-sm text-gray-400 mt-1">Buat akun <span class="text-red-500 font-bold">MEeL</span></p>
    </div>
    <!-- Message -->
    <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-2xl text-sm flex items-center gap-3 animate-pulse <?= $msg_type === 'success' ? 'bg-green-500/10 text-green-400 border border-green-500/20' : ($msg_type === 'warning' ? 'bg-orange-500/10 text-orange-400 border border-orange-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20') ?>" role="alert"><i data-lucide="<?= $msg_type === 'success' ? 'check-circle' : 'alert-circle' ?>" class="w-5 h-5"></i><?= $message ?></div>
    <?php endif; ?>
    <!-- Form -->
    <form method="post" class="glass-effect p-8 rounded-[2rem] shadow-2xl space-y-6">
        <?php if ($session_blocked && !$is_locked): ?>
            <!-- Session rate-limit tercapai -->
            <div class="text-center py-6 space-y-4">
                <i data-lucide="timer-reset" class="w-12 h-12 text-orange-500 mx-auto animate-pulse"></i>
                <h3 class="text-lg font-bold text-white">Batas Pendaftaran</h3>
                <p class="text-xs text-gray-300 leading-relaxed">Anda telah mencapai batas maksimal pendaftaran (<?= $max_reg_attempts ?> akun per jam). Silakan coba lagi nanti.</p>
                <div class="flex justify-center gap-3 pt-2">
                    <a href="login" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white text-sm font-bold rounded-xl transition-all" title="Login ke akun yang sudah ada">Login</a>
                    <a href="../" class="px-5 py-2.5 bg-gray-700 hover:bg-gray-600 text-white text-sm font-bold rounded-xl transition-all" title="Kembali ke beranda">Kembali</a>
                </div>
            </div>
        <?php elseif ($is_locked): ?>
            <!-- IP Lockout -->
            <?php
            $countdown_seconds = $remaining;
            $countdown_color   = 'text-red-500';
            $countdown_extra   = '<div class="pt-2">
                    <a href="login" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white text-sm font-bold rounded-xl transition-all" title="Pergi ke halaman login">Ke Halaman Login</a>
                </div>';
            include __DIR__ . '/partials/auth_countdown.php';
            ?>
        <?php else: ?>
            <!-- CSRF Token -->
            <?php if (isset($_SESSION['csrf_token'])): ?>
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
            <?php endif; ?>
            <div class="space-y-2">
                <label for="username" class="text-[10px] font-bold text-gray-300 uppercase ml-1 tracking-widest">Username</label>
                <div class="relative">
                    <i data-lucide="user" class="absolute left-4 top-3.5 w-5 h-5 text-gray-300"></i>
                    <input id="username" name="username" placeholder="Username" required class="w-full bg-[#0b0e14] border border-gray-800 rounded-2xl py-3.5 pl-12 pr-4 text-sm focus:outline-none focus:border-red-600 focus:ring-1 focus:ring-red-600 text-white transition-all" title="Masukkan username minimal 8 karakter, hanya huruf, angka, dan underscore">
                </div>
            </div>
            <div class="space-y-2">
                <label for="password" class="text-[10px] font-bold text-gray-300 uppercase ml-1 tracking-widest">Password</label>
                <div class="relative">
                    <i data-lucide="lock" class="absolute left-4 top-3.5 w-5 h-5 text-gray-300"></i>
                    <input type="password" id="password" name="password" placeholder="••••••••" required class="w-full bg-[#0b0e14] border border-gray-800 rounded-2xl py-3.5 pl-12 pr-14 text-sm focus:outline-none focus:border-red-600 focus:ring-1 focus:ring-red-600 text-white transition-all" title="Masukkan password minimal 8 karakter">
                    <button type="button" id="togglePassword" class="absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 flex items-center justify-center rounded-full text-gray-300 hover:text-red-500 focus:outline-none focus:ring-2 focus:ring-red-600 transition-colors" aria-label="Tampilkan atau sembunyikan password" aria-pressed="false">
                        <i data-lucide="eye" id="iconEye" class="w-5 h-5 hidden"></i>
                        <i data-lucide="eye-off" id="iconEyeOff" class="w-5 h-5"></i>
                    </button>
                </div>
            </div>
            <div class="pt-4 space-y-3">
                <button name="register" class="w-full bg-red-600 hover:bg-red-500 text-white font-bold py-4 rounded-2xl shadow-lg shadow-red-900/30 transition-all flex items-center justify-center gap-2 group" title="Daftar akun baru">
                    Daftar Sekarang
                    <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform"></i>
                </button>
                <div class="flex items-center justify-between px-1">
                    <a href="login" class="text-xs text-gray-300 hover:text-white transition" title="Login ke akun yang sudah ada">Sudah punya akun?</a>
                    <a href="../" class="text-xs text-red-500 font-bold hover:underline" title="Kembali ke beranda">Kembali</a>
                </div>
            </div>
        <?php endif; ?>
    </form>
    <?php include __DIR__ . '/partials/auth_footer.php'; ?>
