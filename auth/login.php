<?php
require_once __DIR__ . '/auth_helpers.php';

// Set session name & cookie params SEBELUM session_start()
auth_boot_session();
include 'config.php';

$back_url = auth_back_url(['login.php', 'register.php', 'revoked.php', 'banned.php']);

$error_msg = "";
$max_login_attempts = 5;
$lockout_time = 300; // 5 menit
$is_locked = false;
$remaining = 0;

// ─── LOOPBACK (localhost) — bebas rate-limit untuk debugging ──
$is_loopback = auth_is_loopback();

// ─── SESSION-BASED LOCKOUT (expired cleanup) — khusus login ────
if (isset($_SESSION['login_locked_until'])) {
    if (time() >= $_SESSION['login_locked_until']) {
        unset($_SESSION['login_locked_until']);
        $_SESSION['login_fail_count'] = 0;
    }
}

// ─── IP-BASED LOCKOUT CHECK (shared helper) ────────────────────
$ip_address  = auth_get_ip();
$ip_lock     = $is_loopback ? ['locked' => false, 'remaining' => 0] : auth_ip_lockout_status($conn, $ip_address);
$ip_locked   = $ip_lock['locked'];
$ip_remaining = $ip_lock['remaining'];

// Gabungan: locked jika session atau IP terkunci (di-skip untuk loopback)
if (!$is_loopback && ($ip_locked || (isset($_SESSION['login_locked_until']) && time() < $_SESSION['login_locked_until']))) {
    $is_locked = true;
    $remaining = max($ip_remaining, ($_SESSION['login_locked_until'] ?? 0) - time());
}

// ─── HELPER: catat percobaan gagal (session-based + IP via helper) ───
function record_failed_attempt($conn, $ip_address, $max_login_attempts, $lockout_time) {
    // Loopback (localhost) bebas rate-limit — jangan catat apa pun saat debug
    if (auth_is_loopback()) {
        return;
    }
    // Session-based counter (khusus login)
    $_SESSION['login_fail_count'] = ($_SESSION['login_fail_count'] ?? 0) + 1;
    if ($_SESSION['login_fail_count'] >= $max_login_attempts) {
        $_SESSION['login_locked_until'] = time() + $lockout_time;
        $_SESSION['login_fail_count'] = 0;
    }

    // IP-based counter (database) — shared helper
    auth_record_failed_attempt($conn, $ip_address, $max_login_attempts, $lockout_time);
}

// ─── FORM PROCESSING ───────────────────────────────────────────
if (isset($_POST['login']) && !$is_locked) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error_msg = "Sesi keamanan kadaluarsa. Silakan refresh halaman dan coba lagi.";
    } else {
        $user_input = trim($_POST['username'] ?? '');
        $pass_input = $_POST['password'] ?? '';

        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
        if ($stmt) {
            $stmt->bind_param("s", $user_input);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                $login_failed = false;

                if ($u = $result->fetch_assoc()) {
                    if (password_verify($pass_input, $u['PASSWORD'] ?? $u['password'])) {
                        if ($u['is_active'] == 0 || $u['is_active'] == 2) {
                            $error_msg = ($u['is_active'] == 2)
                                ? "Akun Anda sedang menunggu verifikasi admin."
                                : "Akses ditolak untuk akun Guest.";
                        } else {
                            // ─── LOGIN BERHASIL ───────────────────────────
                            // Reset session & IP fail count
                            unset($_SESSION['login_fail_count']);
                            unset($_SESSION['login_locked_until']);

                            $stmt_del = $conn->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
                            $stmt_del->bind_param("s", $ip_address);
                            $stmt_del->execute();
                            $stmt_del->close();

                            // ─── CEK MFA ──────────────────────────────────
                            if (!empty($u['mfa_secret']) && $u['mfa_enabled'] == 1) {
                                $_SESSION['mfa_temp_uid']      = (int)$u['id'];
                                $_SESSION['mfa_temp_username'] = $u['username'];
                                $_SESSION['mfa_temp_role']     = $u['role'];

                                log_activity($conn, $u['id'], 'login_password_ok');

                                $upd = $conn->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
                                $upd->bind_param("i", $u['id']);
                                $upd->execute();
                                $upd->close();

                                header("Location: mfa_verify.php");
                                exit;
                            }

                            // ─── LOGIN LENGKAP (tanpa MFA) ────────────────
                            session_regenerate_id(true);
                            $current_sid = session_id();
                            $_SESSION['user_id']  = $u['id'];
                            $_SESSION['username'] = $u['username'];
                            $_SESSION['role']     = $u['role'];

                            log_activity($conn, $u['id'], 'login');

                            $upd = $conn->prepare("UPDATE users SET last_session_id = ?, last_activity = NOW() WHERE id = ?");
                            if ($upd) {
                                $upd->bind_param("si", $current_sid, $u['id']);
                                $upd->execute();
                                header("Location: ../index.php");
                                exit;
                            }
                        }
                    } else {
                        $login_failed = true;
                    }
                } else {
                    $login_failed = true;
                }

                // ─── TANGANI LOGIN GAGAL (sekali, tanpa duplikasi) ────
                if ($login_failed) {
                    $error_msg = "Username atau password salah!";
                    record_failed_attempt($conn, $ip_address, $max_login_attempts, $lockout_time);
                }
            } else {
                $error_msg = "Terjadi kesalahan. Silakan coba lagi.";
            }
        } else {
            $error_msg = "Terjadi kesalahan. Silakan coba lagi.";
        }
    }
}

// Re-check lockout setelah POST processing (kalau baru kena lock)
if (!$is_loopback && !$is_locked && isset($_SESSION['login_locked_until']) && time() < $_SESSION['login_locked_until']) {
    $is_locked = true;
    $remaining = $_SESSION['login_locked_until'] - time();
}
// Cek IP lockout lagi setelah POST (database, karena $ip_locked sudah stale)
if (!$is_loopback && !$is_locked) {
    $recheck = auth_recheck_lockout($conn, $ip_address);
    if ($recheck['locked']) {
        $is_locked = true;
        $remaining = $recheck['remaining'];
    }
}

// ─── HTML (shell bersama via partials) ─────────────────────────
$auth_title       = "MEeL | Login";
$auth_description = "MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.";
$auth_og_title    = "MEeL | Login";
$auth_og_desc     = "Masuk ke akun MEeL untuk streaming video, musik, dan mengakses perpustakaan digital.";
include __DIR__ . '/partials/auth_head.php';
?>
    <main class="w-full max-w-sm" aria-labelledby="login-title">
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="inline-flex p-4 bg-blue-600/10 rounded-3xl text-blue-500 mb-4 shadow-lg shadow-blue-900/10"><i data-lucide="log-in" class="w-10 h-10"></i></div>
            <h2 id="login-title" class="text-3xl font-black text-white tracking-tighter">Login</h2>
            <p class="text-sm text-gray-300 mt-1">Masuk ke akun <span class="text-blue-500 font-bold">MEeL</span></p>
        </div>
        <?php if ($error_msg): ?>
            <div class="mb-6 p-4 rounded-2xl text-sm flex items-center gap-3 bg-red-500/10 text-red-400 border border-red-500/20 animate-shake"><i data-lucide="alert-circle" class="w-5 h-5"></i><?= $error_msg ?></div>
        <?php endif; ?>

        <!-- Login -->
        <form method="post" class="glass-effect p-8 rounded-[2rem] shadow-2xl space-y-6">
            <!-- Lockdown -->
            <?php if ($is_locked): ?>
                <?php
                $countdown_seconds = $remaining;
                $countdown_color   = 'text-blue-500';
                $countdown_extra   = '';
                include __DIR__ . '/partials/auth_countdown.php';
                ?>
            <?php else: ?>
                <!-- CSRF Token -->
                <?php if (isset($_SESSION['csrf_token'])): ?>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <?php endif; ?>

                <!-- Form login -->
                <div class="space-y-2">
                    <label for="username" class="text-[10px] font-bold text-gray-300 uppercase ml-1 tracking-widest">Username</label>
                    <div class="relative">
                        <i data-lucide="user" class="absolute left-4 top-3.5 w-5 h-5 text-gray-300"></i>
                        <input id="username" name="username" placeholder="Username" required title="Masukkan username Anda" class="w-full bg-[#0b0e14] border border-gray-800 rounded-2xl py-3.5 pl-12 pr-4 text-sm focus:outline-none focus:border-blue-600 focus:ring-1 focus:ring-blue-600 text-white transition-all">
                    </div>
                </div>
                <div class="space-y-2">
                    <label for="password" class="text-[10px] font-bold text-gray-300 uppercase ml-1 tracking-widest">Password</label>
                    <div class="relative">
                        <i data-lucide="lock" class="absolute left-4 top-3.5 w-5 h-5 text-gray-300"></i>
                        <input type="password" id="password" name="password" placeholder="••••••••" required title="Masukkan password Anda" class="w-full bg-[#0b0e14] border border-gray-800 rounded-2xl py-3.5 pl-12 pr-14 text-sm focus:outline-none focus:border-blue-600 focus:ring-1 focus:ring-blue-600 text-white transition-all">
                        <button type="button" id="togglePassword" class="absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 flex items-center justify-center rounded-full text-gray-300 hover:text-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-600 transition-colors" aria-label="Tampilkan atau sembunyikan password" aria-pressed="false">
                            <i data-lucide="eye" id="iconEye" class="w-5 h-5 hidden"></i>
                            <i data-lucide="eye-off" id="iconEyeOff" class="w-5 h-5"></i>
                        </button>
                    </div>
                </div>
                <button name="login" title="Masuk ke akun Anda" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-4 rounded-2xl transition-all flex items-center justify-center gap-2 group shadow-lg shadow-blue-900/20">
                    Masuk Sekarang
                    <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform"></i>
                </button>
            <?php endif; ?>

            <!-- Opsi lain -->
            <div class="flex items-center justify-between px-1">
                <a href="register.php" class="text-xs text-gray-300 hover:text-white transition" title="Daftar untuk mendapatkan akun">
                    Belum punya akun?
                </a>
                <a href="<?= htmlspecialchars($back_url) ?>" class="text-xs text-blue-500 font-bold hover:underline" title="Kembali">
                    Batal
                </a>
            </div>
        </form>
<?php include __DIR__ . '/partials/auth_footer.php'; ?>
