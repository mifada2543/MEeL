<?php
require_once '../auth/auth.php';
require_once '../auth/config.php';
// activity_logger loaded via auth/config.php 
$back_url = '../index.php'; 

// ── Validasi Referer (Back URL) menggunakan MEEL_HOST constant ──
// MEEL_HOST didefinisikan di auth/config.php (bisa di-hardcode untuk keamanan lebih)
$allowed_hosts = [
    defined('MEEL_HOST') && !empty(MEEL_HOST) ? MEEL_HOST : ($_SERVER['HTTP_HOST'] ?? ''),
    'localhost',
    '127.0.0.1',
    '::1',
];

if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
    $ref_host = parse_url($ref, PHP_URL_HOST);

    // Validasi host referer terhadap whitelist
    $host_valid = false;
    foreach ($allowed_hosts as $allowed) {
        if ($allowed !== '' && $ref_host === $allowed) {
            $host_valid = true;
            break;
        }
    }

    if ($host_valid) {
        
        // 2. Ambil hanya bagian path-nya saja (misal: /profile/edit.php)
        $ref_path = parse_url($ref, PHP_URL_PATH);
        $excluded_pages = ['profile_edit.php', 'index.php', 'manage.php'];
        
        $should_exclude = false;
        foreach ($excluded_pages as $page) {
            if (strpos($ref_path, $page) !== false) {
                $should_exclude = true;
                break;
            }
        }

        if (!$should_exclude) {
            $back_url = $ref;
        }
    }
}
// 1. Ambil username dari URL
$target_user = $_GET['u'] ?? '';

if (empty($target_user)) {
    header("Location: ../index.php");
    exit();
}

// 2. Query Data User (TAMBAHKAN 'id' di sini!)
$stmt = $conn->prepare("SELECT id, username, bio, role, profile_picture, last_activity FROM users WHERE username = ?");
$stmt->bind_param("s", $target_user);
$stmt->execute();
$res = $stmt->get_result();
$u = $res->fetch_assoc();

if (!$u) {
    die("<div class='min-h-screen bg-[#0b0e14] flex items-center justify-center text-white font-mono'>User tidak ditemukan!</div>");
}

// Sekarang $u['id'] sudah ada isinya
$profile_id = $u['id'];

// Hitung total Video (prepared statement untuk defense-in-depth)
$stmt_vid = $conn->prepare("SELECT COUNT(*) as total FROM video WHERE user_id = ?");
$stmt_vid->bind_param("i", $profile_id);
$stmt_vid->execute();
$total_video = (int)$stmt_vid->get_result()->fetch_assoc()['total'];
$stmt_vid->close();

// Hitung total Musik (prepared statement)
$stmt_mus = $conn->prepare("SELECT COUNT(*) as total FROM music WHERE user_id = ?");
$stmt_mus->bind_param("i", $profile_id);
$stmt_mus->execute();
$total_music = (int)$stmt_mus->get_result()->fetch_assoc()['total'];
$stmt_mus->close();

$total_uploads = $total_video + $total_music;
$is_online = (strtotime($u['last_activity']) > strtotime("-5 minutes"));
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="<?= htmlspecialchars($u['username']) ?> — MEeL Profile">
    <meta property="og:description" content="Profil <?= htmlspecialchars($u['username']) ?> di MEeL - Platform Media Hub Pribadi.">
    <title>MEeL Profile | <?= htmlspecialchars($u['username']) ?></title>
    <?php include '../partials/link.php'; ?>
    <style>
        body {
            background-color: #0b0e14;
        }

        .glass {
            background: rgba(22, 27, 34, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        /* ════════════════════════════════════
           MFA TOGGLE SWITCH
           ════════════════════════════════════ */
        .mfa-switch {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            padding: 0;
            background: none;
            border: none;
            text-decoration: none;
            transition: opacity 0.2s;
        }
        .mfa-switch:hover { opacity: 0.85; }
        .mfa-switch:active { transform: scale(0.97); }
        .mfa-switch:focus-visible {
            outline: 2px solid #a855f7;
            outline-offset: 4px;
            border-radius: 6px;
        }

        .mfa-track {
            position: relative;
            width: 44px;
            height: 24px;
            border-radius: 99px;
            transition: background 0.3s ease;
            flex-shrink: 0;
        }
        .mfa-track--on  { background: #22c55e; box-shadow: 0 0 10px rgba(34,197,94,0.25); }
        .mfa-track--off { background: #374151; }

        .mfa-knob {
            position: absolute;
            top: 2px;
            left: 2px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 1px 4px rgba(0,0,0,0.3);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .mfa-track--on .mfa-knob {
            transform: translateX(20px);
        }

        .mfa-label {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
            white-space: nowrap;
        }
        .mfa-label--on  { color: #22c55e; }
        .mfa-label--off { color: #6b7280; }

        .mfa-label-sub {
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #6b7280;
            display: block;
            margin-top: -1px;
        }
    </style>
</head>

<body class="text-gray-300">

    <div class="max-w-2xl mx-auto mt-10 p-4">
        <div class="glass rounded-[2.5rem] overflow-hidden shadow-2xl">
            <div class="h-32 bg-gradient-to-r from-blue-600 to-indigo-800"></div>

            <div class="px-8 pb-8">
                <div class="relative flex justify-between items-end -mt-12">
                    <div class="relative">
                        <img src="upload/<?= $u['profile_picture'] ?: 'default.png' ?>"
                            class="w-32 h-32 rounded-3xl border-4 border-[#0b0e14] object-cover bg-gray-800 shadow-xl" title="Foto profil <?= htmlspecialchars($u['username']) ?>">
                        <?php if ($is_online): ?>
                            <div class="absolute bottom-2 right-2 w-5 h-5 bg-green-500 border-4 border-[#0b0e14] rounded-full"></div>
                        <?php endif; ?>
                    </div>

                    <?php if ($_SESSION['username'] === $u['username']):
                        // Cek status MFA
                        $stmt_mfa_p = $conn->prepare("SELECT mfa_enabled FROM users WHERE id = ?");
                        $stmt_mfa_p->bind_param("i", $profile_id);
                        $stmt_mfa_p->execute();
                        $_mfa_on = (int)$stmt_mfa_p->get_result()->fetch_assoc()['mfa_enabled'] ?? 0;
                        $stmt_mfa_p->close();
                    ?>
                        <div class="grid grid-cols-2 gap-3 mb-2">
                            <!-- Baris 1: Edit Profile + Kelola Konten -->
                            <a href="../controllers/profile/profile_edit.php"
                               class="bg-white/10 hover:bg-white/20 text-white px-4 py-3 rounded-2xl text-sm font-bold transition-all flex items-center justify-center gap-2"
                               title="Edit profil dan bio Anda">
                                <i data-lucide="edit-3" class="w-4 h-4"></i> Edit Profile
                            </a>
                            <a href="manage.php"
                               class="bg-blue-600/20 hover:bg-blue-600/30 text-blue-400 border border-blue-600/30 hover:border-blue-500/50 px-4 py-3 rounded-2xl text-sm font-bold transition-all flex items-center justify-center gap-2"
                               title="Kelola konten video dan musik Anda">
                                <i data-lucide="layout-dashboard" class="w-4 h-4"></i> Kelola Konten
                            </a>

                            <!-- Baris 2: MFA + Backup Codes (jika aktif) -->
                            <a href="../auth/mfa_setup.php"
                               class="mfa-switch justify-center"
                               role="link"
                               aria-label="MFA: saat ini <?= $_mfa_on ? 'aktif' : 'nonaktif' ?>. Klik untuk kelola."
                               title="Atur autentikasi dua faktor (MFA)">
                                <span class="mfa-track <?= $_mfa_on ? 'mfa-track--on' : 'mfa-track--off' ?>">
                                    <span class="mfa-knob"></span>
                                </span>
                                <span class="mfa-label <?= $_mfa_on ? 'mfa-label--on' : 'mfa-label--off' ?>">
                                    MFA
                                    <span class="mfa-label-sub"><?= $_mfa_on ? 'Aktif' : 'Nonaktif' ?></span>
                                </span>
                            </a>

                            <?php if ($_mfa_on): ?>
                            <button type="button" onclick="showBackupModal()"
                                    class="bg-yellow-600/10 hover:bg-yellow-600/20 text-yellow-400 border border-yellow-600/20 hover:border-yellow-500/40 px-4 py-3 rounded-2xl text-sm font-bold transition-all flex items-center justify-center gap-2"
                                    title="Lihat atau download kode cadangan MFA">
                                <i data-lucide="key-round" class="w-4 h-4"></i>
                                Backup Codes
                            </button>
                            <?php endif; ?>

                            <?php if (!$_mfa_on): ?>
                            <div></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mt-6">
                    <h1 class="text-3xl font-black text-white tracking-tight italic">
                        <?= htmlspecialchars($u['username']) ?>
                        <?php if ($u['role'] === 'admin'): ?>
                            <span class="ml-2 text-[10px] bg-blue-500/20 text-blue-400 px-2 py-1 rounded-lg uppercase tracking-widest border border-blue-500/30">Staff</span>
                        <?php endif; ?>
                        <?php if ($u['role'] === 'member'): ?>
                            <span class="ml-2 text-[10px] bg-green-500/20 text-green-400 px-2 py-1 rounded-lg uppercase tracking-widest border border-green-500/30" title="Jadilah member untuk mendapatkan benefit berupa akses Drive">Berlangganan</span>
                        <?php endif; ?>
                    </h1>
                    <p class="text-gray-500 text-sm mt-1">@<?= strtolower($u['username']) ?> • Profile</p>

                    <div class="mt-6 p-4 bg-white/5 rounded-2xl border border-white/5">
                        <p class="text-gray-400 text-sm italic leading-relaxed">
                            <?= $u['bio'] ?: "Pengguna ini belum menulis bio." ?>
                        </p>
                    </div>

                    <div class="flex gap-4 mt-8">
                        <div class="flex-1 glass p-4 rounded-2xl text-center group hover:border-blue-500/50 transition-all">
                            <span class="block text-xl font-bold text-white"><?= $total_uploads ?></span>
                            <span class="text-[10px] text-gray-500 uppercase tracking-widest group-hover:text-blue-400 transition">Total Uploads</span>
                        </div>
                        <div class="flex-1 glass p-4 rounded-2xl text-center group hover:border-green-500/50 transition-all">
                            <span class="block text-xl font-bold text-white"><?= $total_video ?></span>
                            <span class="text-[10px] text-gray-500 uppercase tracking-widest group-hover:text-green-400 transition">Videos</span>
                        </div>
                        <div class="flex-1 glass p-4 rounded-2xl text-center group hover:border-purple-500/50 transition-all">
                            <span class="block text-xl font-bold text-white"><?= $total_music ?></span>
                            <span class="text-[10px] text-gray-500 uppercase tracking-widest group-hover:text-purple-400 transition">Music</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center mt-8">
            <a href="<?= htmlspecialchars($back_url); ?>" class="text-gray-600 hover:text-blue-500 transition text-xs flex items-center justify-center gap-2" title="Kembali ke halaman sebelumnya">
                <i data-lucide="arrow-left" class="w-4 h-4"></i> Kembali ke Dashboard
            </a>
        </div>
    </div>
 <?php include '../partials/footer.php'; ?>
    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <script>
        lucide.createIcons();

        // ── Modal Backup Codes (dengan verifikasi password) ──
        function showBackupModal() {
            Swal.fire({
                title: 'Kode Cadangan MFA',
                html: '<div style="font-size:12px;color:#9ca3af;margin-bottom:12px">Masukkan <strong style="color:#e5e7eb">password</strong> untuk verifikasi. Kode cadangan LAMA akan <strong style="color:#fbbf24">dinonaktifkan</strong> dan diganti dengan yang baru.</div>' +
                      '<div style="position:relative">' +
                      '  <i data-lucide="lock" style="position:absolute;left:14px;top:13px;width:16px;height:16px;color:#6b7280"></i>' +
                      '  <input id="backup-pwd-input" type="password" placeholder="Password Anda" style="width:100%;background:#0b0e14;border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:12px 12px 12px 42px;color:#fff;font-size:14px;outline:none">' +
                      '</div>',
                focusConfirm: false,
                showCancelButton: true,
                confirmButtonText: 'VERIFIKASI',
                cancelButtonText: 'BATAL',
                background: '#141820',
                color: '#fff',
                reverseButtons: true,
                didOpen: function() {
                    var input = document.getElementById('backup-pwd-input');
                    if (input) input.focus();
                    lucide.createIcons();
                },
                customClass: {
                    popup: 'border border-yellow-600/25 rounded-2xl shadow-2xl',
                    title: 'text-sm font-black uppercase tracking-wider pt-4 text-yellow-400',
                    htmlContainer: 'mt-1 mb-4 text-left',
                    confirmButton: 'bg-yellow-600 hover:bg-yellow-500 text-black text-xs font-black uppercase tracking-wider py-2.5 px-6 rounded-xl transition-all border-none cursor-pointer ml-2',
                    cancelButton: 'bg-white/5 hover:bg-white/10 text-gray-400 text-xs font-black uppercase tracking-wider py-2.5 px-6 rounded-xl border border-white/10 cursor-pointer transition-all mr-2'
                },
                preConfirm: function() {
                    var pwd = document.getElementById('backup-pwd-input');
                    if (!pwd || !pwd.value) {
                        Swal.showValidationMessage('Masukkan password Anda');
                        return false;
                    }
                    return pwd.value;
                }
            }).then(function(result) {
                if (!result.isConfirmed || !result.value) return;

                // Kirim request ke server
                fetch('../controllers/system/mfa.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=generate_backup&password=' + encodeURIComponent(result.value) + '&csrf_token=<?= $_SESSION['csrf_token'] ?>'
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.status === 'success' && data.codes) {
                        var codesHtml = data.codes.map(function(c) {
                            return '<div style="font-family:monospace;background:rgba(0,0,0,0.3);padding:8px 16px;border-radius:10px;border:1px solid rgba(255,255,255,0.06);color:#d1d5db;text-align:center;font-size:13px;letter-spacing:0.15em;user-select:all">' + c + '</div>';
                        }).join('');

                        Swal.fire({
                            title: 'Kode Cadangan Baru',
                            html: '<div style="font-size:11px;color:#fbbf24;margin-bottom:12px;font-weight:700">⚠️ Simpan di tempat aman. Kode TIDAK bisa ditampilkan lagi!</div>' +
                                  '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px">' + codesHtml + '</div>' +
                                  '<button onclick="downloadBackupCodes()" style="background:rgba(251,191,36,0.1);color:#fbbf24;border:1px solid rgba(251,191,36,0.2);padding:10px 20px;border-radius:12px;font-size:12px;font-weight:700;cursor:pointer;transition:all 0.2s" onmouseover="this.style.background=\'rgba(251,191,36,0.2)\';" onmouseout="this.style.background=\'rgba(251,191,36,0.1)\'">' +
                                  '  <i data-lucide="download" style="width:14px;height:14px;display:inline-block;vertical-align:middle;margin-right:6px"></i> Download (.txt)' +
                                  '</button>',
                            showConfirmButton: true,
                            confirmButtonText: 'SIMPAN',
                            background: '#141820',
                            color: '#fff',
                            didOpen: function() { lucide.createIcons(); },
                            customClass: {
                                popup: 'border border-yellow-600/25 rounded-2xl shadow-2xl',
                                title: 'text-sm font-black uppercase tracking-wider pt-4 text-yellow-400',
                                htmlContainer: 'mt-1 mb-4',
                                confirmButton: 'bg-yellow-600 hover:bg-yellow-500 text-black text-xs font-black uppercase tracking-wider py-2.5 px-6 rounded-xl transition-all border-none cursor-pointer'
                            }
                        });

                        // Simpan codes untuk download
                        window._lastBackupCodes = data.codes;
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: data.message || 'Terjadi kesalahan.',
                            background: '#141820',
                            color: '#fff',
                            customClass: { popup: 'border border-red-600/25 rounded-2xl shadow-2xl' }
                        });
                    }
                })
                .catch(function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Gagal terhubung ke server.',
                        background: '#141820',
                        color: '#fff',
                        customClass: { popup: 'border border-red-600/25 rounded-2xl shadow-2xl' }
                    });
                });
            });
        }

        // ── Download Backup Codes sebagai TXT ──
        function downloadBackupCodes() {
            var codes = window._lastBackupCodes;
            if (!codes || !codes.length) return;

            var username = '<?= htmlspecialchars($_SESSION['username'] ?? 'user') ?>';
            var dateStr = new Date().toISOString().replace(/T/, ' ').slice(0, 19);
            var lines = [
                'MEeL — MFA Backup Codes',
                'User: ' + username,
                'Generated: ' + dateStr,
                '',
                'Setiap kode hanya bisa digunakan SEKALI.',
                'Simpan di tempat yang aman!',
                '',
            ];
            codes.forEach(function(c) { lines.push('  ' + c); });

            var blob = new Blob([lines.join('\n') + '\n'], { type: 'text/plain;charset=utf-8' });
            var link = document.createElement('a');
            link.download = 'MEeL-backup-codes-' + username + '.txt';
            link.href = URL.createObjectURL(blob);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(link.href);
        }
    </script>
</body>

</html>
