<?php
require_once '../../auth/auth.php';
require_once '../../auth/config.php';

// Pastikan hanya user yang login bisa akses
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$msg = "";

if (isset($_POST['update_profile'])) {
    // Verifikasi token
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $msg = 'CSRF Token tidak valid.';
    } else {
    $bio = trim($_POST['bio'] ?? '');

    // TRANSACTION: Atomic profile update — bio + avatar adalah satu kesatuan
    $conn->begin_transaction();
    try {
        // 1. UPDATE BIO
        $stmt = $conn->prepare("UPDATE users SET bio = ? WHERE id = ?");
        $stmt->bind_param("si", $bio, $user_id);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Gagal memperbarui bio: ' . $stmt->error);
        }

        // 2. LOGIKA UPLOAD FOTO
        if (!empty($_FILES['avatar']['name'])) {
            $file_name = $_FILES['avatar']['name'];
            $file_tmp  = $_FILES['avatar']['tmp_name'];
            $file_type = $_FILES['avatar']['type'];

            $allowed = ['image/jpeg', 'image/png', 'image/jpg'];
            if (in_array($file_type, $allowed)) {
                $new_name = "user_" . $user_id . ".jpg";
                $upload_path = "../profile/upload/" . $new_name;

                $source = ($file_type == 'image/png') ? imagecreatefrompng($file_tmp) : imagecreatefromjpeg($file_tmp);
                list($width, $height) = getimagesize($file_tmp);
                $new_width = 400;
                $new_height = ($height / $width) * $new_width;

                $tmp_img = imagecreatetruecolor($new_width, $new_height);
                imagecopyresampled($tmp_img, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

                if (!imagejpeg($tmp_img, $upload_path, 80)) {
                    throw new \RuntimeException('Gagal menyimpan foto profil.');
                }

                imagedestroy($source);
                imagedestroy($tmp_img);

                // Update nama file di database
                $stmt_pic = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                $stmt_pic->bind_param("si", $new_name, $user_id);
                if (!$stmt_pic->execute()) {
                    throw new \RuntimeException('Gagal menyimpan path foto: ' . $stmt_pic->error);
                }
            } else {
                throw new \RuntimeException('Format file tidak didukung! Gunakan JPG atau PNG.');
            }
        }

        $msg = "Profil berhasil diperbarui!";
        $conn->commit();
    } catch (\Throwable $e) {
        $conn->rollback();
        $msg = 'Error: ' . $e->getMessage();
    }
    } // tutup else verify_csrf
}

// Ambil data terbaru untuk ditampilkan di form — prepared statement
$stmt_data = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt_data->bind_param("i", $user_id);
$stmt_data->execute();
$data = $stmt_data->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="Edit Profile | MEeL">
    <meta property="og:description" content="Edit profil Anda di MEeL. Ubah bio dan foto profil.">
    <meta property="og:image" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/assets/MEeL.png">
    <meta property="og:url" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <title>Edit Profile | MEeL</title>
    <link rel="icon" type="image/png" href="../../assets/MEeL.png">
    <link href="../../assets/css/tailwind.min.css" rel="stylesheet">
    <script src="../../assets/js/compatibilitas/lucide.js"></script>
    <style>
        body {
            background-color: #0b0e14;
        }

        .glass {
            background: rgba(22, 27, 34, 0.7);
            backdrop-filter: blur(10px);
        }

    </style>
</head>

<body class="text-gray-300 p-6">
    <div class="max-w-md mx-auto mt-10">
        <div class="glass p-8 rounded-[2.5rem] border border-white/5 shadow-2xl">
            <h2 class="text-2xl font-black text-white mb-6 italic">Pengaturan Profil</h2>

            <?php if ($msg): ?>
                <div class="bg-blue-500/10 border border-blue-500/50 text-blue-400 p-3 rounded-xl text-xs mb-4">
                    <?= $msg ?>
                </div>
            <?php endif; ?>

            <form action="" method="POST" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="flex flex-col items-center gap-4">
                    <img src="../../profile/upload/<?= htmlspecialchars($data['profile_picture'] ?: 'default.png', ENT_QUOTES, 'UTF-8') ?>" class="w-24 h-24 rounded-3xl object-cover border-2 border-blue-500/30">
                    <label class="cursor-pointer bg-white/5 hover:bg-white/10 px-4 py-2 rounded-xl text-[10px] font-bold tracking-widest uppercase transition">
                        Ganti Foto
                        <input type="file" name="avatar" class="hidden" accept="image/*">
                    </label>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest ml-1">Bio Anda</label>
                    <textarea name="bio" rows="4" class="w-full bg-[#0b0e14] border border-white/5 rounded-2xl p-4 text-sm focus:outline-none focus:border-blue-600 transition"><?= htmlspecialchars($data['bio']) ?></textarea>
                </div>

                <button name="update_profile" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-4 rounded-2xl transition-all shadow-lg shadow-blue-600/20">
                    Simpan Perubahan
                </button>
            </form>

            <a href="../../profile/?u=<?= $_SESSION['username'] ?>" class="block text-center mt-6 text-xs text-gray-600 hover:text-gray-400">Batal dan Kembali</a>
        </div>
    </div>
    <script>
        lucide.createIcons();
    </script>
</body>

</html>
