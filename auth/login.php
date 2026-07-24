<?php
// Set session name & cookie params SEBELUM session_start()
if (session_status() === PHP_SESSION_NONE) {
    $timeout = 43200; // 12 jam
    session_set_cookie_params($timeout, "/");
    session_name('meel');
    session_start();
}
if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}
include 'config.php';

$back_url = '../index.php';

if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
    $host = $_SERVER['HTTP_HOST'];
    if (strpos($ref, $host) !== false) {
        if (strpos($ref, 'login.php') === false && strpos($ref, 'register.php') === false && strpos($ref, 'revoked.php') === false && strpos($ref, 'banned.php') === false) {
            $back_url = $ref;
        }
    }
}

$error_msg = "";
$max_login_attempts = 5;
$lockout_time = 300; // 5 menit
$is_locked = false;
$remaining = 0;

// ─── IP-BASED LOCKOUT CHECK ────────────────────────────────────
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Bersihkan lockout yang sudah expired (session-based)
if (isset($_SESSION['login_locked_until'])) {
    if (time() >= $_SESSION['login_locked_until']) {
        unset($_SESSION['login_locked_until']);
        $_SESSION['login_fail_count'] = 0;
    }
}

// Cek & bersihkan lockout yang sudah expired (IP-based)
$ip_locked = false;
$ip_remaining = 0;
$stmt_ip = $conn->prepare("SELECT attempts, locked_until FROM login_attempts WHERE ip_address = ?");
if ($stmt_ip) {
    $stmt_ip->bind_param("s", $ip_address);
    $stmt_ip->execute();
    $ip_result = $stmt_ip->get_result();
    if ($ip_row = $ip_result->fetch_assoc()) {
        if ($ip_row['locked_until'] !== null) {
            $lock_ts = strtotime($ip_row['locked_until']);
            if (time() < $lock_ts) {
                $ip_locked = true;
                $ip_remaining = $lock_ts - time();
            } else {
                // Lockout expired — reset
                $stmt_del = $conn->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
                $stmt_del->bind_param("s", $ip_address);
                $stmt_del->execute();
            }
        }
    }
    $stmt_ip->close();
}

// Gabungan: locked jika session atau IP terkunci
if ($ip_locked || (isset($_SESSION['login_locked_until']) && time() < $_SESSION['login_locked_until'])) {
    $is_locked = true;
    $remaining = max($ip_remaining, ($_SESSION['login_locked_until'] ?? 0) - time());
}

// ─── HELPER: catat percobaan gagal ─────────────────────────────
function record_failed_attempt($conn, $ip_address, $max_login_attempts, $lockout_time) {
    // Session-based counter
    $_SESSION['login_fail_count'] = ($_SESSION['login_fail_count'] ?? 0) + 1;
    if ($_SESSION['login_fail_count'] >= $max_login_attempts) {
        $_SESSION['login_locked_until'] = time() + $lockout_time;
        $_SESSION['login_fail_count'] = 0;
    }

    // IP-based counter (database)
    $stmt_ups = $conn->prepare(
        "INSERT INTO login_attempts (ip_address, attempts, last_attempt_at, locked_until)
         VALUES (?, 1, NOW(), NULL)
         ON DUPLICATE KEY UPDATE
             attempts = attempts + 1,
             last_attempt_at = NOW()"
    );
    if ($stmt_ups) {
        $stmt_ups->bind_param("s", $ip_address);
        $stmt_ups->execute();
        $stmt_ups->close();
    }

    // Cek apakah IP sudah melebihi batas
    $stmt_chk = $conn->prepare("SELECT attempts FROM login_attempts WHERE ip_address = ?");
    if ($stmt_chk) {
        $stmt_chk->bind_param("s", $ip_address);
        $stmt_chk->execute();
        $chk_res = $stmt_chk->get_result();
        if ($chk_row = $chk_res->fetch_assoc()) {
            if ($chk_row['attempts'] >= $max_login_attempts) {
                $lock_ts = date('Y-m-d H:i:s', time() + $lockout_time);
                $stmt_lock = $conn->prepare("UPDATE login_attempts SET locked_until = ? WHERE ip_address = ?");
                $stmt_lock->bind_param("ss", $lock_ts, $ip_address);
                $stmt_lock->execute();
                $stmt_lock->close();
            }
        }
        $stmt_chk->close();
    }
}

// ─── FORM PROCESSING ───────────────────────────────────────────
if (isset($_POST['login']) && !$is_locked) {
    if (!verify_csrf()) {
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

                            $_SESSION['user_id']  = $u['id'];
                            $_SESSION['username'] = $u['username'];
                            $_SESSION['role']     = $u['role'];
                            $current_sid = session_id();

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
if (!$is_locked && isset($_SESSION['login_locked_until']) && time() < $_SESSION['login_locked_until']) {
    $is_locked = true;
    $remaining = $_SESSION['login_locked_until'] - time();
}
// Cek IP lockout lagi setelah POST (database, karena $ip_locked sudah stale)
if (!$is_locked) {
    $stmt_ip2 = $conn->prepare("SELECT locked_until FROM login_attempts WHERE ip_address = ? AND locked_until IS NOT NULL");
    if ($stmt_ip2) {
        $stmt_ip2->bind_param("s", $ip_address);
        $stmt_ip2->execute();
        $ip2_res = $stmt_ip2->get_result();
        if ($ip2_row = $ip2_res->fetch_assoc()) {
            $lock_ts = strtotime($ip2_row['locked_until']);
            if (time() < $lock_ts) {
                $is_locked = true;
                $remaining = $lock_ts - time();
            }
        }
        $stmt_ip2->close();
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="MEeL | Login">
    <meta property="og:description" content="Masuk ke akun MEeL untuk streaming video, musik, dan mengakses perpustakaan digital.">
    <meta property="og:image" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/assets/MEeL.png">
    <meta property="og:url" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <title>MEeL | Login</title>
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <link href="../assets/css/tailwind.min.css" rel="stylesheet">
    <script src="../assets/js/lucide.js"></script>
    <style>
        body {
            background-color: #0b0e14;
        }

        .glass-effect {
            background: rgba(22, 27, 34, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
    </style>
</head>

<body class="text-gray-200 min-h-screen flex items-center justify-center p-4">
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
                <div class="text-center py-6 space-y-4">
                    <i data-lucide="shield-alert" class="w-12 h-12 text-red-500 mx-auto animate-pulse"></i>
                    <h3 class="text-lg font-bold text-white">Akses Ditangguhkan</h3>
                    <p class="text-xs text-gray-300 leading-relaxed">Terlalu banyak percobaan gagal. Silakan coba lagi dalam:</p>
                    <div id="countdown" class="text-4xl font-black text-blue-500 tracking-widest"><?= $remaining ?></div>
                    <p class="text-[10px] text-gray-300 uppercase">Detik</p>
                </div>
                <script>
                    let seconds = <?= max(1, $remaining) ?>;
                    const display = document.getElementById('countdown');
                    const timer = setInterval(() => {
                        seconds--;
                        display.innerText = seconds > 0 ? seconds : 0;
                        if (seconds <= 0) {
                            clearInterval(timer);
                            location.reload();
                        }
                    }, 1000);
                </script>
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

        <!-- Copyright -->
        <p class="text-center text-[10px] text-gray-300 mt-8 uppercase tracking-[0.3em]">©MEeL - 2025</p>
    </main>

    <script>
        lucide.createIcons();

        // Fitur Toggle Password
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const iconEye = document.getElementById('iconEye');
        const iconEyeOff = document.getElementById('iconEyeOff');

        if (togglePassword) {
            togglePassword.addEventListener('click', function() {
                const isHidden = passwordInput.type === 'password';
                passwordInput.type = isHidden ? 'text' : 'password';
                togglePassword.setAttribute('aria-pressed', String(isHidden));
                togglePassword.setAttribute('aria-label', isHidden ? 'Sembunyikan password' : 'Tampilkan password');
                iconEye.classList.toggle('hidden', !isHidden);
                iconEyeOff.classList.toggle('hidden', isHidden);
            });
        }
    </script>
</body>

</html>