<?php
require_once __DIR__ . '/auth_helpers.php';
auth_boot_session();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../modules/core/helpers.php';
// ─── Rate limit ───
$max_mfa_attempts = 10;
$mfa_lockout_time = 300; // 5 menit
$is_loopback = auth_is_loopback(); // localhost bebas rate-limit saat pengembangan
$mfa_locked = false;
$mfa_remaining = 0;
if (!$is_loopback && isset($_SESSION['mfa_locked_until'])) {
    if (time() >= $_SESSION['mfa_locked_until']) {
        unset($_SESSION['mfa_locked_until'], $_SESSION['mfa_fail_count']);
    } else {
        $mfa_locked = true;
        $mfa_remaining = $_SESSION['mfa_locked_until'] - time();
    }
}
// ─── Cek pending MFA ───
if (!isset($_SESSION['mfa_temp_uid'])) {
    if (isset($_SESSION['user_id'])) {
        header("Location: ../");
        exit;
    }
    header("Location: login");
    exit;
}
$error = '';
$temp_username = $_SESSION['mfa_temp_username'] ?? 'User';
// ─── HANDLE FORM ───
if (isset($_POST['verify']) || isset($_POST['code'])) {
    if ($mfa_locked) {
        $error = 'Terlalu banyak percobaan. Silakan coba lagi dalam ' . $mfa_remaining . ' detik.';
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi keamanan kadaluarsa. Silakan refresh halaman.';
    } else {
        $user_code = trim($_POST['code'] ?? '');
        if (empty($user_code)) {
            $error = 'Masukkan kode verifikasi.';
        } else {
            $temp_id = (int)$_SESSION['mfa_temp_uid'];
            $stmt = $conn->prepare("SELECT mfa_secret, mfa_backup_codes FROM users WHERE id = ?");
            $stmt->bind_param("i", $temp_id);
            $stmt->execute();
            $u = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$u || empty($u['mfa_secret'])) {
                unset($_SESSION['mfa_temp_uid'], $_SESSION['mfa_temp_username'], $_SESSION['mfa_temp_role']);
                header("Location: login");
                exit;
            }
            $valid = false;
            if (verify_totp($u['mfa_secret'], $user_code)) {
                $valid = true;
            }
            if (!$valid && !empty($u['mfa_backup_codes'])) {
                $result = verify_backup_code($u['mfa_backup_codes'], $user_code);
                if ($result['valid']) {
                    $valid = true;
                    $new_codes = json_encode($result['remaining']);
                    $stmt = $conn->prepare("UPDATE users SET mfa_backup_codes = ? WHERE id = ?");
                    $stmt->bind_param("si", $new_codes, $temp_id);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            if ($valid) {
                // ─── LOGIN LENGKAP — set session ───
                session_regenerate_id(true);
                $_SESSION['user_id']  = $temp_id;
                $_SESSION['username'] = $_SESSION['mfa_temp_username'];
                $_SESSION['role']     = $_SESSION['mfa_temp_role'];
                $_SESSION['mfa_verified'] = true;
                unset($_SESSION['mfa_temp_uid'], $_SESSION['mfa_temp_username'], $_SESSION['mfa_temp_role']);
                log_activity($conn, $temp_id, 'login');
                $current_sid = session_id();
                $upd = $conn->prepare("UPDATE users SET last_session_id = ?, last_activity = NOW() WHERE id = ?");
                $upd->bind_param("si", $current_sid, $temp_id);
                $upd->execute();
                $upd->close();
                $stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, media_type, ip_address) VALUES (?, 'mfa_verify', 'totp', ?)");
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $stmt->bind_param("is", $temp_id, $ip);
                $stmt->execute();
                $stmt->close();
                header("Location: ../");
                exit;
            } else {
                $error = 'Kode tidak valid. Periksa aplikasi Authenticator atau gunakan kode cadangan.';
                $stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, media_type, ip_address) VALUES (?, 'mfa_verify_failed', 'totp', ?)");
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $stmt->bind_param("is", $temp_id, $ip);
                $stmt->execute();
                $stmt->close();
                if (!$is_loopback) {
                    $_SESSION['mfa_fail_count'] = ($_SESSION['mfa_fail_count'] ?? 0) + 1;
                    if ($_SESSION['mfa_fail_count'] >= $max_mfa_attempts) {
                        $_SESSION['mfa_locked_until'] = time() + $mfa_lockout_time;
                        $_SESSION['mfa_fail_count'] = 0;
                        $mfa_locked = true;
                        $mfa_remaining = $mfa_lockout_time;
                    }
                }
            }
        }
    }
}

// ─── HTML (shell bersama via partials) ───
$auth_title       = "Verifikasi MFA | MEeL";
$auth_description = "MEeL — Verifikasi autentikasi dua faktor.";
$auth_og_title    = "MEeL | Verifikasi MFA";
$auth_og_desc     = "Masukkan kode 6-digit dari aplikasi Authenticator untuk menyelesaikan login.";
$auth_extra_style = '
        .code-input {
            letter-spacing: 0.5em;
            font-size: 2rem;
            font-weight: 800;
            text-align: center;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .anim-fade { animation: fadeInUp 0.4s ease-out; }
        @keyframes pulse-dot {
            0%, 100% { opacity: 0.3; }
            50%      { opacity: 1; }
        }
        .pulse-dot { animation: pulse-dot 1.5s ease-in-out infinite; }
        .pulse-dot:nth-child(2) { animation-delay: 0.3s; }
        .pulse-dot:nth-child(3) { animation-delay: 0.6s; }
';
include __DIR__ . '/partials/auth_head.php';
?>
<main class="w-full max-w-sm" aria-labelledby="mfa-title">
    <!-- Header -->
    <div class="text-center mb-8 anim-fade">
        <div class="inline-flex p-4 bg-purple-600/10 rounded-3xl text-purple-500 mb-4">
            <i data-lucide="shield" class="w-10 h-10"></i>
        </div>
        <h2 id="mfa-title" class="text-3xl font-black text-white tracking-tighter">Verifikasi</h2>
        <p class="text-sm text-gray-400 mt-1">
            Masukkan kode dari aplikasi <span class="text-purple-500 font-bold">Authenticator</span>
        </p>
    </div>
    <?php if ($error): ?>
        <div class="mb-6 p-4 rounded-2xl text-sm flex items-center gap-3 <?= $mfa_locked ? 'bg-orange-500/10 text-orange-400 border border-orange-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20' ?> anim-fade" role="alert">
            <i data-lucide="<?= $mfa_locked ? 'timer' : 'alert-circle' ?>" class="w-5 h-5"></i>
            <?= $error ?>
            <?php if ($mfa_locked): ?>
                <span id="lockout-countdown" class="font-mono font-bold ml-1">(<?= $mfa_remaining ?>s)</span>
            <?php endif; ?>
        </div>
        <?php if ($mfa_locked): ?>
            <script>
                let lockSeconds = <?= max(1, $mfa_remaining) ?>;
                const lockDisplay = document.getElementById('lockout-countdown');
                if (lockDisplay) {
                    const lockTimer = setInterval(() => {
                        lockSeconds--;
                        lockDisplay.innerText = '(' + (lockSeconds > 0 ? lockSeconds : 0) + 's)';
                        if (lockSeconds <= 0) {
                            clearInterval(lockTimer);
                            location.reload();
                        }
                    }, 1000);
                }
            </script>
        <?php endif; ?>
    <?php endif; ?>
    <form method="post" class="glass-effect p-8 rounded-[2rem] shadow-2xl space-y-6 anim-fade" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
        <!-- Info user -->
        <div class="text-center">
            <div class="inline-flex items-center gap-2 px-4 py-2 bg-white/5 rounded-full text-sm">
                <i data-lucide="user" class="w-4 h-4 text-purple-400"></i>
                <span class="font-bold text-white"><?= htmlspecialchars($temp_username) ?></span>
            </div>
        </div>
        <!-- Waiting dots -->
        <div class="flex justify-center gap-1.5">
            <span class="pulse-dot w-2 h-2 bg-purple-500 rounded-full"></span>
            <span class="pulse-dot w-2 h-2 bg-purple-500 rounded-full"></span>
            <span class="pulse-dot w-2 h-2 bg-purple-500 rounded-full"></span>
        </div>
        <!-- Code input -->
        <div class="space-y-2">
            <label for="code" class="text-[10px] font-bold text-gray-400 uppercase tracking-widest block text-center">
                Kode 6 Digit
            </label>
            <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                autocomplete="one-time-code" placeholder="000000" required
                class="code-input w-full bg-[#0b0e14] border-2 border-gray-800 rounded-2xl py-5 px-4 focus:outline-none focus:border-purple-600 focus:ring-1 focus:ring-purple-600 text-white transition-all"
                title="Masukkan kode 6 digit dari aplikasi Authenticator">
        </div>
        <input type="hidden" name="verify" value="1">
        <button type="submit"
            class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-4 rounded-2xl transition-all flex items-center justify-center gap-2 group shadow-lg shadow-purple-900/20">
            Verifikasi
            <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform"></i>
        </button>
        <!-- Cancel / back -->
        <div class="text-center pt-2">
            <a href="login" class="text-xs text-gray-500 hover:text-gray-300 transition flex items-center justify-center gap-1">
                <i data-lucide="arrow-left" class="w-3 h-3"></i> Kembali ke Login
            </a>
        </div>
        <!-- Help -->
        <details class="text-center cursor-pointer group">
            <summary class="text-[10px] text-gray-600 hover:text-gray-400 transition uppercase tracking-wider font-bold">
                Tidak punya akses ke Authenticator?
            </summary>
            <div class="mt-3 p-4 bg-yellow-500/5 rounded-2xl border border-yellow-500/10 text-left">
                <p class="text-[11px] text-gray-400 leading-relaxed">
                    Gunakan <strong class="text-yellow-400">kode cadangan</strong> (backup code) yang Anda simpan saat setup MFA.
                    Setiap backup code hanya bisa digunakan <strong class="text-gray-300">sekali</strong>.
                </p>
                <p class="text-[10px] text-gray-600 mt-2">
                    Jika backup codes habis, hubungi admin untuk reset MFA.
                </p>
            </div>
        </details>
    </form>
    <?php include __DIR__ . '/partials/auth_mfa_footer.php'; ?>
