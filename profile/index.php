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

                    <?php if ($_SESSION['username'] === $u['username']): ?>
                        <div class="flex flex-col sm:flex-row gap-2 mb-2">
                            <a href="../controllers/profile/profile_edit.php" class="bg-white/10 hover:bg-white/20 text-white px-5 py-2.5 rounded-2xl text-sm font-bold transition-all flex items-center gap-2" title="Edit profil dan bio Anda">
                                <i data-lucide="edit-3" class="w-4 h-4"></i> Edit Profile
                            </a>
                            <a href="manage.php" class="bg-blue-600/20 hover:bg-blue-600/30 text-blue-400 border border-blue-600/30 hover:border-blue-500/50 px-5 py-2.5 rounded-2xl text-sm font-bold transition-all flex items-center gap-2" title="Kelola konten video dan musik Anda">
                                <i data-lucide="layout-dashboard" class="w-4 h-4"></i> Kelola Konten
                            </a>
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
    <script>
        lucide.createIcons();
    </script>
</body>

</html>
