<?php
// Error logging diaktifkan, tapi display_errors dimatikan untuk keamanan production
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
        if (strpos($ref, 'login.php') === false && strpos($ref, 'register.php') === false) {
            $back_url = $ref;
        }
    }
}

$message = "";
$msg_type = "";
$max_reg_attempts = 3;
$reg_time_window = 3600; // 1 jam
$max_ip_attempts = 10;
$ip_lockout_time = 300; // 5 menit
$is_locked = false;
$remaining = 0;

// ─── IP-BASED LOCKOUT CHECK ────────────────────────────────────
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Cek & bersihkan lockout IP yang sudah expired
$stmt_ip = $conn->prepare("SELECT attempts, locked_until FROM login_attempts WHERE ip_address = ?");
if ($stmt_ip) {
    $stmt_ip->bind_param("s", $ip_address);
    $stmt_ip->execute();
    $ip_result = $stmt_ip->get_result();
    if ($ip_row = $ip_result->fetch_assoc()) {
        if ($ip_row['locked_until'] !== null) {
            $lock_ts = strtotime($ip_row['locked_until']);
            if (time() < $lock_ts) {
                $is_locked = true;
                $remaining = $lock_ts - time();
            } else {
                // Lockout expired — reset
                $stmt_del = $conn->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
                $stmt_del->bind_param("s", $ip_address);
                $stmt_del->execute();
                $stmt_del->close();
            }
        }
    }
    $stmt_ip->close();
}

// ─── SESSION-BASED RATE LIMIT (registrasi berhasil) ────────────
if (!isset($_SESSION['reg_attempts'])) {
    $_SESSION['reg_attempts'] = [];
}

// Bersihkan data percobaan yang sudah kadaluarsa
$_SESSION['reg_attempts'] = array_filter($_SESSION['reg_attempts'], function ($timestamp) use ($reg_time_window) {
    return $timestamp > (time() - $reg_time_window);
});

$session_blocked = count($_SESSION['reg_attempts']) >= $max_reg_attempts;

// ─── FORM PROCESSING ───────────────────────────────────────────
if (isset($_POST['register']) && !$is_locked && !$session_blocked) {
    if (!verify_csrf()) {
        $message = "Sesi keamanan kadaluarsa. Silakan refresh halaman.";
        $msg_type = "error";
        $validation_error = true;
    } else {
    $user = trim($_POST['username']);
    $pass_raw = $_POST['password'];

    $validation_error = false;

    // 1. Validasi Panjang Karakter
    if (strlen($user) < 8 || strlen($pass_raw) < 8) {
        $message = "Username min 8 karakter, Password min 8 karakter!";
        $msg_type = "warning";
        $validation_error = true;
    } else if (!preg_match('/^[a-zA-Z0-9_]+$/', $user)) {
        $message = "Username hanya boleh berisi huruf, angka, dan underscore (_)!";
        $msg_type = "warning";
        $validation_error = true;
    } else if (stripos($user, 'guest') !== false) {
        $message = "Username 'Guest' tidak dapat didaftarkan karena dicadangkan untuk sistem!";
        $msg_type = "warning";
        $validation_error = true;
    } else {
        // 3. Jika validasi lolos, baru cek ketersediaan username di database
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $user);
        $check->execute();
        $result = $check->get_result();

        // 4. Evaluasi hasil dari Database
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
            // Jika belum ada, simpan ke database
            $pass_hashed = password_hash($pass_raw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, password, role, is_active) VALUES (?, ?, 'user', 2)");
            $stmt->bind_param("ss", $user, $pass_hashed);

            if ($stmt->execute()) {
                // Tambahkan percobaan ke session HANYA ketika registrasi berhasil
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

    // ─── CATAT PERCOBAAN GAGAL KE IP ────────────────────────────
    if ($validation_error) {
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
                if ($chk_row['attempts'] >= $max_ip_attempts) {
                    $lock_ts = date('Y-m-d H:i:s', time() + $ip_lockout_time);
                    $stmt_lock = $conn->prepare("UPDATE login_attempts SET locked_until = ? WHERE ip_address = ?");
                    $stmt_lock->bind_param("ss", $lock_ts, $ip_address);
                    $stmt_lock->execute();
                    $stmt_lock->close();
                    $is_locked = true;
                    $remaining = $ip_lockout_time;
                }
            }
            $stmt_chk->close();
        }
    }
    } // end else (CSRF valid)
}

// Re-check lockout setelah POST
if (!$is_locked && !$session_blocked) {
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
    <meta property="og:title" content="MEeL | Register">
    <meta property="og:description" content="Buat akun MEeL dan nikmati streaming video, musik, dan akses perpustakaan digital.">
    <meta property="og:image" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/assets/MEeL.png">
    <meta property="og:url" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <title>MEeL | Register</title>
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
                        <a href="login.php" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white text-sm font-bold rounded-xl transition-all" title="Login ke akun yang sudah ada">Login</a>
                        <a href="../index.php" class="px-5 py-2.5 bg-gray-700 hover:bg-gray-600 text-white text-sm font-bold rounded-xl transition-all" title="Kembali ke beranda">Kembali</a>
                    </div>
                </div>
            <?php elseif ($is_locked): ?>
                <!-- IP Lockout -->
                <div class="text-center py-6 space-y-4">
                    <i data-lucide="shield-alert" class="w-12 h-12 text-red-500 mx-auto animate-pulse"></i>
                    <h3 class="text-lg font-bold text-white">Akses Ditangguhkan</h3>
                    <p class="text-xs text-gray-300 leading-relaxed">Terlalu banyak percobaan gagal. Silakan coba lagi dalam:</p>
                    <div id="countdown" class="text-4xl font-black text-red-500 tracking-widest"><?= $remaining ?></div>
                    <p class="text-[10px] text-gray-300 uppercase">Detik</p>
                    <div class="pt-2">
                        <a href="login.php" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white text-sm font-bold rounded-xl transition-all" title="Pergi ke halaman login">Ke Halaman Login</a>
                    </div>
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
                        <a href="login.php" class="text-xs text-gray-300 hover:text-white transition" title="Login ke akun yang sudah ada">Sudah punya akun?</a>
                        <a href="../index.php" class="text-xs text-red-500 font-bold hover:underline" title="Kembali ke beranda">Kembali</a>
                    </div>
                </div>
            <?php endif; ?>
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
