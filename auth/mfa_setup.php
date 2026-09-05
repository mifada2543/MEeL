<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../modules/core/helpers.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}
$user_id   = (int)$_SESSION['user_id'];
$username  = $_SESSION['username'] ?? '';
$stmt = $conn->prepare("SELECT mfa_enabled FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$mfa_enabled = (int)$stmt->get_result()->fetch_assoc()['mfa_enabled'] ?? 0;
$stmt->close();
$step = 'setup'; 
$error = '';
$secret = '';
$otpauth = '';
if (isset($_POST['disable_mfa']) && $mfa_enabled) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi keamanan kadaluarsa. Silakan refresh halaman.';
    } else {
        $stmt = $conn->prepare("UPDATE users SET mfa_enabled = 0, mfa_secret = NULL, mfa_backup_codes = NULL WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['mfa_verified'] = false;
        $mfa_enabled = 0;
        $step = 'setup';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_secret']) && !$mfa_enabled) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi keamanan kadaluarsa. Silakan refresh halaman.';
    } else {
        $secret = generate_mfa_secret();
        $otpauth = generate_otpauth_url($secret, $username);
        $_SESSION['mfa_pending_secret'] = $secret;
        $_SESSION['mfa_pending_otpauth'] = $otpauth;
        $step = 'verify';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_code']) && !$mfa_enabled) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesi keamanan kadaluarsa. Silakan refresh halaman.';
    } else {
        $secret = $_SESSION['mfa_pending_secret'] ?? '';
        $otpauth = $_SESSION['mfa_pending_otpauth'] ?? '';
        $user_code = trim($_POST['code'] ?? '');

        if (empty($secret)) {
            $error = 'Sesi setup MFA tidak ditemukan. Mulai ulang.';
            $step = 'setup';
        } elseif (!preg_match('/^[0-9]{6}$/', $user_code)) {
            $error = 'Kode harus 6 digit angka.';
            $step = 'verify';
        } elseif (verify_totp($secret, $user_code)) {
            $backup = generate_backup_codes();
            $backup_plain = $backup['plain'];
            $backup_hashed = $backup['hashed'];
            $stmt = $conn->prepare("UPDATE users SET mfa_secret = ?, mfa_backup_codes = ?, mfa_enabled = 1 WHERE id = ?");
            $hashed_json = json_encode($backup_hashed);
            $stmt->bind_param("ssi", $secret, $hashed_json, $user_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['mfa_backup_codes_show'] = $backup_plain;
            unset($_SESSION['mfa_pending_secret'], $_SESSION['mfa_pending_otpauth']);
            $_SESSION['mfa_verified'] = true;
            $mfa_enabled = 1;
            $step = 'backup';
        } else {
            $error = 'Kode tidak valid. Pastikan kode dari aplikasi Authenticator Anda.';
            $step = 'verify';
        }
    }
}
$backup_codes = $_SESSION['mfa_backup_codes_show'] ?? [];
if ($step === 'backup' && isset($_POST['backup_done'])) {
    unset($_SESSION['mfa_backup_codes_show']);
    $step = 'done';
}
if ($mfa_enabled && $step === 'setup') {
    $stmt = $conn->prepare("SELECT mfa_secret FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $existing_secret = $stmt->get_result()->fetch_assoc()['mfa_secret'] ?? '';
    $stmt->close();
    if (!empty($existing_secret)) {
        $otpauth = generate_otpauth_url($existing_secret, $username);
    }
}
$auth_title       = "Keamanan Akun | MEeL";
$auth_description = "MEeL - Kelola autentikasi dua faktor (MFA) akun Anda.";
$auth_og_title    = "Keamanan Akun | MEeL";
$auth_og_desc     = "Aktifkan, nonaktifkan, atau kelola autentikasi dua faktor akun MEeL Anda.";
$auth_extra_head  = '<script src="../assets/js/compatibilitas/qrcode.min.js"></script>';
$auth_extra_style = '
        .code-input {
            letter-spacing: 0.5em;
            font-size: 1.5rem;
            font-weight: 800;
            text-align: center;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .anim-fade { animation: fadeInUp 0.4s ease-out; }
        .backup-code {
            font-family: \'Courier New\', monospace;
            font-size: 13px;
            letter-spacing: 0.15em;
            background: rgba(0,0,0,0.3);
            padding: 8px 16px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.06);
            color: #d1d5db;
            user-select: all;
        }
';
include __DIR__ . '/partials/auth_head.php';
?>
<main class="w-full max-w-lg" aria-labelledby="mfa-title">
    <div class="text-center mb-8 anim-fade">
        <div class="inline-flex p-4 bg-purple-600/10 rounded-3xl text-purple-500 mb-4">
            <i data-lucide="shield" class="w-10 h-10"></i>
        </div>
        <h2 id="mfa-title" class="text-3xl font-black text-white tracking-tighter">
            Keamanan Akun
        </h2>
        <p class="text-sm text-gray-400 mt-1">
            <?= $mfa_enabled ? 'Autentikasi Dua Faktor <span class="text-green-400 font-bold">Aktif</span>' : 'Lindungi akun dengan <span class="text-purple-500 font-bold">MFA</span>' ?>
        </p>
    </div>
    <?php if ($error): ?>
        <div class="mb-6 p-4 rounded-2xl text-sm flex items-center gap-3 bg-red-500/10 text-red-400 border border-red-500/20 anim-fade">
            <i data-lucide="alert-circle" class="w-5 h-5"></i>
            <?= $error ?>
        </div>
    <?php endif; ?>
    <form method="post" class="glass-effect p-8 rounded-[2rem] shadow-2xl space-y-6 anim-fade">
        <?php if ($mfa_enabled && $step === 'setup'): ?>
            
            <div class="text-center space-y-4">
                <div class="inline-flex p-3 bg-green-500/10 rounded-full text-green-400">
                    <i data-lucide="check-circle" class="w-10 h-10"></i>
                </div>
                <h3 class="text-lg font-bold text-white">MFA Sudah Aktif</h3>
                <p class="text-xs text-gray-400 leading-relaxed">
                    Akun Anda dilindungi dengan autentikasi dua faktor.
                    Setiap login memerlukan kode 6-digit dari aplikasi Authenticator.
                </p>
                <?php if (!empty($otpauth)): ?>
                    <div class="pt-4 space-y-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-widest">Scan QR Code (jika perlu)</p>
                        <div id="mfa-qr-existing" class="inline-flex items-center justify-center w-44 h-44 rounded-2xl bg-white p-2"></div>
                    </div>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            var existingQr = document.getElementById('mfa-qr-existing');
                            if (existingQr && typeof QRCode !== 'undefined') {
                                new QRCode(existingQr, {
                                    text: <?= json_encode($otpauth) ?>,
                                    width: 160,
                                    height: 160,
                                    correctLevel: QRCode.CorrectLevel.M
                                });
                            }
                        });
                    </script>
                <?php endif; ?>
                
                <div class="pt-4 border-t border-white/5 space-y-4">
                    <p class="text-[10px] text-gray-600 uppercase tracking-widest">Ingin mengganti / menonaktifkan MFA?</p>
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <button type="button" onclick="confirmDisable()"
                        class="px-6 py-3 bg-red-600/10 hover:bg-red-600/20 text-red-400 border border-red-600/20 rounded-2xl text-sm font-bold transition-all flex items-center gap-2 mx-auto"
                        title="Nonaktifkan MFA">
                        <i data-lucide="shield-off" class="w-4 h-4"></i> Nonaktifkan MFA
                    </button>
                </div>
                <script>
                    function confirmDisable() {
                        Swal.fire({
                            title: 'Nonaktifkan MFA?',
                            html: '<div style="font-size:12px;color:var(--meel-text)">Akun Anda akan kembali hanya menggunakan <strong style="color:var(--meel-text-heading)">password</strong> untuk login. Ini mengurangi keamanan akun.</div>',
                            icon: 'warning',
                            iconColor: '#ef4444',
                            showCancelButton: true,
                            confirmButtonText: 'YA, NONAKTIFKAN',
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
                                const form = document.createElement('form');
                                form.method = 'POST';
                                form.innerHTML = '<input type="hidden" name="disable_mfa" value="1"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">';
                                document.body.appendChild(form);
                                form.submit();
                            }
                        });
                    }
                </script>
            </div>
        <?php elseif ($step === 'verify'): ?>
            
            <input type="hidden" name="verify_code" value="1">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="text-center space-y-4">
                <h3 class="text-lg font-bold text-white">1. Scan QR Code</h3>
                <p class="text-xs text-gray-400">
                    Buka aplikasi <strong class="text-white">Google Authenticator</strong> atau <strong class="text-white">Authy</strong>,
                    lalu scan QR Code di bawah ini.
                </p>
                
                <div class="flex justify-center">
                    <div id="mfa-qr-canvas" class="inline-flex items-center justify-center w-48 h-48 rounded-2xl bg-white p-2 shadow-lg"></div>
                </div>
                <button type="button" onclick="downloadQR()"
                    class="text-[11px] text-gray-400 hover:text-white bg-white/5 hover:bg-white/10 px-4 py-2 rounded-xl font-bold transition-all inline-flex items-center gap-2 mx-auto"
                    title="Download QR Code sebagai gambar PNG">
                    <i data-lucide="download" class="w-4 h-4"></i>
                    Download QR Code
                </button>
                
                <details class="text-left cursor-pointer group">
                    <summary class="text-[11px] text-gray-500 hover:text-gray-300 transition font-bold tracking-wider">
                        Tidak bisa scan? Masukkan manual
                    </summary>
                    <div class="mt-3 p-3 bg-black/30 rounded-xl text-[11px] text-gray-400 space-y-1 break-all font-mono">
                        <p><span class="text-gray-500">Secret:</span>
                            <span id="mfa-secret-text" class="text-white select-all font-mono"><?= htmlspecialchars($_SESSION['mfa_pending_secret'] ?? '') ?></span>
                            <button type="button" onclick="copySecret()"
                                class="inline-flex ml-1 p-1 rounded-md bg-white/10 hover:bg-white/20 text-gray-400 hover:text-white transition-all align-middle"
                                title="Salin secret key">
                                <i data-lucide="copy" class="w-3 h-3"></i>
                            </button>
                        </p>
                        <p><span class="text-gray-500">Tipe:</span> Time-based (TOTP)</p>
                        <p><span class="text-gray-500">Akun:</span> <span class="text-white"><?= htmlspecialchars($username) ?></span></p>
                    </div>
                </details>
            </div>
            <div class="border-t border-white/5 pt-6 space-y-4">
                <h3 class="text-lg font-bold text-white text-center">2. Verifikasi Kode</h3>
                <p class="text-[11px] text-gray-500 text-center">
                    Masukkan kode 6-digit yang muncul di aplikasi Authenticator Anda.
                </p>
                <div>
                    <label for="code" class="text-[10px] font-bold text-gray-400 uppercase ml-1 tracking-widest">Kode 6 Digit</label>
                    <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
                        placeholder="000000" required
                        class="code-input w-full bg-[#0b0e14] border border-gray-800 rounded-2xl py-4 px-4 focus:outline-none focus:border-purple-600 focus:ring-1 focus:ring-purple-600 text-white transition-all">
                </div>
                <button type="submit"
                    class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-4 rounded-2xl transition-all flex items-center justify-center gap-2 group shadow-lg shadow-purple-900/20">
                    Verifikasi & Aktifkan
                    <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform"></i>
                </button>
            </div>

        <?php elseif ($step === 'backup'): ?>
            
            <div class="text-center space-y-4">
                <div class="inline-flex p-3 bg-yellow-500/10 rounded-full text-yellow-400">
                    <i data-lucide="alert-triangle" class="w-10 h-10"></i>
                </div>
                <h3 class="text-lg font-bold text-white">Simpan Kode Cadangan!</h3>
                <p class="text-xs text-gray-400 leading-relaxed">
                    Kode-kode ini bisa digunakan <strong class="text-yellow-300">sekali pakai</strong> jika Anda kehilangan akses ke aplikasi Authenticator.
                    <span class="text-yellow-500 font-bold">Simpan di tempat aman!</span>
                </p>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <?php foreach ($backup_codes as $code): ?>
                    <div class="backup-code text-center"><?= htmlspecialchars($code) ?></div>
                <?php endforeach; ?>
            </div>
            <button type="button" onclick="downloadBackupCodes()"
                class="w-full bg-yellow-600/10 hover:bg-yellow-600/20 text-yellow-400 border border-yellow-600/20 hover:border-yellow-500/40 font-bold py-3 rounded-2xl transition-all flex items-center justify-center gap-2 text-sm">
                <i data-lucide="download" class="w-4 h-4"></i>
                Download Backup Codes (.txt)
            </button>
            <div class="text-[10px] text-gray-600 text-center">
                Halaman ini hanya ditampilkan <strong class="text-gray-400">sekali</strong>.
                Kode tidak bisa ditampilkan lagi setelah ini.
            </div>
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <button type="submit" name="backup_done" value="1"
                class="w-full bg-green-600 hover:bg-green-500 text-white font-bold py-4 rounded-2xl transition-all flex items-center justify-center gap-2 group shadow-lg shadow-green-900/20">
                Saya Sudah Menyimpannya
                <i data-lucide="check" class="w-4 h-4"></i>
            </button>
        <?php elseif ($step === 'done'): ?>
            
            <div class="text-center space-y-4">
                <div class="inline-flex p-3 bg-green-500/10 rounded-full text-green-400">
                    <i data-lucide="shield-check" class="w-10 h-10"></i>
                </div>
                <h3 class="text-lg font-bold text-white">MFA Berhasil Diaktifkan! 🎉</h3>
                <p class="text-xs text-gray-400 leading-relaxed">
                    Akun Anda sekarang lebih aman dengan autentikasi dua faktor.
                    Setiap login akan meminta kode 6-digit dari aplikasi Authenticator.
                </p>
                <a href="../"
                    class="inline-block mt-4 px-8 py-3 bg-blue-600 hover:bg-blue-500 text-white font-bold rounded-2xl transition-all">
                    Kembali ke Beranda
                </a>
            </div>
        <?php else: ?>
            
            <div class="text-center space-y-4">
                <div class="inline-flex p-3 bg-purple-500/10 rounded-full text-purple-400">
                    <i data-lucide="smartphone" class="w-10 h-10"></i>
                </div>
                <h3 class="text-lg font-bold text-white">Aktifkan MFA</h3>
                <p class="text-xs text-gray-400 leading-relaxed">
                    Autentikasi Dua Faktor (MFA) menambahkan lapisan keamanan ekstra.
                    Selain password, Anda juga perlu kode 6-digit dari aplikasi Authenticator
                    (<strong class="text-white">Google Authenticator</strong>, <strong class="text-white">Authy</strong>, atau <strong class="text-white">Bitwarden</strong>).
                </p>
            </div>
            <div class="bg-white/5 rounded-2xl p-5 space-y-3 text-sm">
                <div class="flex items-start gap-3">
                    <div class="w-7 h-7 bg-purple-600/20 rounded-lg flex items-center justify-center flex-shrink-0 text-purple-400 text-xs font-black">1</div>
                    <p class="text-gray-400">Klik tombol di bawah untuk <strong class="text-white">Generate Secret Key</strong></p>
                </div>
                <div class="flex items-start gap-3">
                    <div class="w-7 h-7 bg-purple-600/20 rounded-lg flex items-center justify-center flex-shrink-0 text-purple-400 text-xs font-black">2</div>
                    <p class="text-gray-400">Scan <strong class="text-white">QR Code</strong> dengan aplikasi Authenticator</p>
                </div>
                <div class="flex items-start gap-3">
                    <div class="w-7 h-7 bg-purple-600/20 rounded-lg flex items-center justify-center flex-shrink-0 text-purple-400 text-xs font-black">3</div>
                    <p class="text-gray-400">Masukkan kode 6-digit untuk <strong class="text-white">verifikasi</strong></p>
                </div>
                <div class="flex items-start gap-3">
                    <div class="w-7 h-7 bg-purple-600/20 rounded-lg flex items-center justify-center flex-shrink-0 text-purple-400 text-xs font-black">4</div>
                    <p class="text-gray-400">Simpan <strong class="text-yellow-400">backup codes</strong> untuk keadaan darurat</p>
                </div>
            </div>
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <button type="submit" name="generate_secret" value="1"
                class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-4 rounded-2xl transition-all flex items-center justify-center gap-2 group shadow-lg shadow-purple-900/20">
                Mulai Setup MFA
                <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform"></i>
            </button>
        <?php endif; ?>
        
        <div class="text-center pt-2">
            <a href="../index.php" class="text-xs text-gray-500 hover:text-gray-300 transition">
                <i data-lucide="arrow-left" class="w-3 h-3 inline-block mr-1"></i> Kembali ke Beranda
            </a>
        </div>
    </form>
    
    <script src="../assets/js/shared/download-backup-codes.js"></script>
    <script>
        var _backupCodes = <?= json_encode($backup_codes) ?>;
        document.addEventListener('DOMContentLoaded', function() {
            var qrContainer = document.getElementById('mfa-qr-canvas');
            if (qrContainer && typeof QRCode !== 'undefined') {
                new QRCode(qrContainer, {
                    text: <?= json_encode($otpauth) ?>,
                    width: 170,
                    height: 170,
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
        });
        function downloadQR() {
            var qrContainer = document.getElementById('mfa-qr-canvas');
            if (!qrContainer) return;
            var canvas = qrContainer.querySelector('canvas');
            if (!canvas) return;
            var link = document.createElement('a');
            link.download = 'MEeL-MFA-QR-<?= htmlspecialchars($username) ?>.png';
            link.href = canvas.toDataURL('image/png');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        window._meelBackupCodes = window._backupCodes || [];
        window._meelBackupUser = '<?= htmlspecialchars($username) ?>';
        function copySecret() {
            const text = document.getElementById('mfa-secret-text');
            if (text) {
                navigator.clipboard.writeText(text.textContent).then(() => {
                    const btn = document.querySelector('[onclick="copySecret()"]');
                    if (btn) {
                        const icon = btn.querySelector('i');
                        if (icon) {
                            icon.setAttribute('data-lucide', 'check');
                            lucide.createIcons();
                            setTimeout(() => {
                                icon.setAttribute('data-lucide', 'copy');
                                lucide.createIcons();
                            }, 2000);
                        }
                    }
                }).catch(() => {
                    text.select();
                    document.execCommand('copy');
                });
            }
        }
    </script>
    <?php include __DIR__ . '/partials/auth_mfa_footer.php'; ?>
