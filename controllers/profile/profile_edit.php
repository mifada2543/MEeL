<?php
require_once '../../auth/auth.php';
require_once '../../auth/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login");
    exit();
}

$user_id = $_SESSION['user_id'];
$msg = "";

if (isset($_POST['update_profile'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $msg = 'CSRF Token tidak valid.';
    } else {
    $bio = trim($_POST['bio'] ?? '');

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE users SET bio = ? WHERE id = ?");
        $stmt->bind_param("si", $bio, $user_id);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Gagal memperbarui bio: ' . $stmt->error);
        }

        if (!empty($_FILES['avatar']['name'])) {
            $file_tmp  = $_FILES['avatar']['tmp_name'];

            if (isset($_FILES['avatar']['error']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                $upload_errors = [
                    UPLOAD_ERR_INI_SIZE   => 'File melebihi batas upload (upload_max_filesize).',
                    UPLOAD_ERR_FORM_SIZE  => 'File melebihi batas ukuran form.',
                    UPLOAD_ERR_PARTIAL    => 'File hanya ter-upload sebagian.',
                    UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang di-upload.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Folder tmp server tidak ditemukan.',
                    UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk.',
                    UPLOAD_ERR_EXTENSION  => 'Upload dihentikan oleh ekstensi PHP.',
                ];
                throw new \RuntimeException($upload_errors[$_FILES['avatar']['error']] ?? 'Gagal mengupload file.');
            }

            $img_info = @getimagesize($file_tmp);
            if ($img_info === false || empty($img_info[0]) || empty($img_info[1]) || empty($img_info['mime'])) {
                throw new \RuntimeException('Gambar tidak valid. Gunakan JPG, PNG, atau WebP.');
            }

            $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
            if (!in_array($img_info['mime'], $allowed, true)) {
                throw new \RuntimeException('Format file tidak didukung! Gunakan JPG, PNG, atau WebP.');
            }
            $real_mime = $img_info['mime'];

            $img_w = (int)$img_info[0];
            $img_h = (int)$img_info[1];

            $crop_x = isset($_POST['crop_x']) ? (int)$_POST['crop_x'] : -1;
            $crop_y = isset($_POST['crop_y']) ? (int)$_POST['crop_y'] : -1;
            $crop_size = min($img_w, $img_h);
            if ($crop_x < 0 || $crop_x > $img_w - $crop_size) {
                $crop_x = (int)(($img_w - $crop_size) / 2);
            }
            if ($crop_y < 0 || $crop_y > $img_h - $crop_size) {
                $crop_y = (int)(($img_h - $crop_size) / 2);
            }

            $stmt_old = $conn->prepare("SELECT profile_picture FROM users WHERE id = ?");
            $stmt_old->bind_param("i", $user_id);
            $stmt_old->execute();
            $old_pic = $stmt_old->get_result()->fetch_assoc()['profile_picture'] ?? null;
            $stmt_old->close();

            $new_name = "user_" . $user_id . ".webp";
            $upload_dir = __DIR__ . '/../../profile/upload/';

            if (!is_dir($upload_dir)) {
                if (!@mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
                    throw new \RuntimeException('Direktori upload tidak ditemukan.');
                }
            }
            if (!is_writable($upload_dir)) {
                throw new \RuntimeException('Direktori upload tidak writable.');
            }

            $upload_path = $upload_dir . $new_name;

            $ffmpeg_bin = defined('MEEL_FFMPEG_PATH') && MEEL_FFMPEG_PATH !== ''
                ? MEEL_FFMPEG_PATH
                : resolve_binary(['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg']);
            $ffmpeg_ok = $ffmpeg_bin !== '' && is_executable($ffmpeg_bin);

            if ($real_mime === 'image/webp') {
                if (!$ffmpeg_ok) {
                    throw new \RuntimeException('Format WebP membutuhkan ffmpeg untuk diproses.');
                }

                $tmp_out = $upload_dir . 'user_' . $user_id . '_tmp_' . bin2hex(random_bytes(4)) . '.webp';
                $cmd = "export LD_LIBRARY_PATH=''; " . escapeshellarg($ffmpeg_bin)
                    . " -y -i " . escapeshellarg($file_tmp)
                    . ' -vf "crop=' . $crop_size . ':' . $crop_size . ':' . $crop_x . ':' . $crop_y . ',scale=400:400"'
                    . " -c:v libwebp -q:v 80 "
                    . escapeshellarg($tmp_out) . " 2>&1";
                $cmd_out = [];
                exec($cmd, $cmd_out, $ret);

                if ($ret !== 0 || !is_file($tmp_out) || filesize($tmp_out) <= 0) {
                    @unlink($tmp_out);
                    throw new \RuntimeException('Gagal memproses foto profil.');
                }
                if (!@rename($tmp_out, $upload_path)) {
                    @unlink($tmp_out);
                    throw new \RuntimeException('Gagal menyimpan foto profil.');
                }
            } else {
                $source = null;
                $tmp_img = null;
                $tmp_png = null;
                try {
                    $source = ($real_mime === 'image/png') ? imagecreatefrompng($file_tmp) : imagecreatefromjpeg($file_tmp);
                    if (!$source) {
                        throw new \RuntimeException('Gagal membaca file gambar.');
                    }

                    $target = 400;
                    $crop   = $crop_size;
                    $src_x  = $crop_x;
                    $src_y  = $crop_y;

                    $tmp_img = imagecreatetruecolor($target, $target);
                    if (!$tmp_img) {
                        throw new \RuntimeException('Gagal membuat kanvas gambar.');
                    }

                    if (!imagecopyresampled($tmp_img, $source, 0, 0, $src_x, $src_y, $target, $target, $crop, $crop)) {
                        throw new \RuntimeException('Gagal memproses gambar.');
                    }

                    $webp_ok = false;
                    if ($ffmpeg_ok) {
                        $tmp_png = sys_get_temp_dir() . '/meel_avatar_' . $user_id . '_' . bin2hex(random_bytes(4)) . '.png';
                        if (imagepng($tmp_img, $tmp_png)) {
                            $tmp_out = $upload_dir . 'user_' . $user_id . '_tmp_' . bin2hex(random_bytes(4)) . '.webp';
                            $cmd = "export LD_LIBRARY_PATH=''; " . escapeshellarg($ffmpeg_bin)
                                . " -y -i " . escapeshellarg($tmp_png)
                                . " -c:v libwebp -q:v 80 "
                                . escapeshellarg($tmp_out) . " 2>&1";
                            $cmd_out = [];
                            exec($cmd, $cmd_out, $ret);
                            if (($ret === 0) && is_file($tmp_out) && filesize($tmp_out) > 0) {
                                $webp_ok = @rename($tmp_out, $upload_path);
                            }
                            @unlink($tmp_out);
                        }
                    }

                    if (!$webp_ok) {

                        $new_name = "user_" . $user_id . ".jpg";
                        $jpg_path = $upload_dir . $new_name;
                        $tmp_jpg = $upload_dir . 'user_' . $user_id . '_tmp_' . bin2hex(random_bytes(4)) . '.jpg';
                        if (!imagejpeg($tmp_img, $tmp_jpg, 80) || !@rename($tmp_jpg, $jpg_path)) {
                            @unlink($tmp_jpg);
                            throw new \RuntimeException('Gagal menyimpan foto profil.');
                        }
                        $upload_path = $jpg_path;
                    }
                } finally {
                    if ($tmp_png && is_file($tmp_png)) {
                        @unlink($tmp_png);
                    }
                    if ($tmp_img) {
                        imagedestroy($tmp_img);
                    }
                    if ($source) {
                        imagedestroy($source);
                    }
                }
            }

            $stmt_pic = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
            $stmt_pic->bind_param("si", $new_name, $user_id);
            if (!$stmt_pic->execute()) {
                throw new \RuntimeException('Gagal menyimpan path foto: ' . $stmt_pic->error);
            }

            $allowed_old = ["user_" . $user_id . ".jpg", "user_" . $user_id . ".webp"];
            if ($old_pic && in_array($old_pic, $allowed_old, true) && $old_pic !== $new_name) {
                $old_path = $upload_dir . $old_pic;
                if (is_file($old_path)) {
                    @unlink($old_path);
                }
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

$stmt_data = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt_data->bind_param("i", $user_id);
$stmt_data->execute();
$data = $stmt_data->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="id">

<head>
<?php
$_META_TITLE = 'Edit Profile | MEeL';
$_META_DESC  = 'Edit profil Anda di MEeL. Ubah bio dan foto profil.';
include __DIR__ . '/../../partials/link.php';
$scripts_root = '../';
include __DIR__ . '/../../partials/scripts.php';
?>
    <style>        body {
            background-color: #0b0e14;
        }

        .glass {
            background: rgba(22, 27, 34, 0.7);
            backdrop-filter: blur(10px);
        }

        .crop-guide {
            background-color: rgba(255, 255, 255, 0.35);
        }
</style>
    <link rel="stylesheet" href="<?= meel_base_url_path() ?>/assets/css/shared/light-theme.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/shared/light-theme.css') ?>">
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
                    <img id="avatarPreview" src="../profile/upload/<?= htmlspecialchars($data['profile_picture'] ?: 'default_avatar.png', ENT_QUOTES, 'UTF-8') ?>" class="w-24 h-24 rounded-3xl object-cover border-2 border-blue-500/30" alt="Foto profil">
                    <label class="cursor-pointer bg-white/5 hover:bg-white/10 px-4 py-2 rounded-xl text-[10px] font-bold tracking-widest uppercase transition">
                        Ganti Foto
                        <input id="avatarInput" type="file" name="avatar" class="hidden" accept="image/jpeg,image/png,image/jpg,image/webp">
                    </label>
                    <p id="avatarStatus" class="hidden text-[10px] text-red-400 text-center"></p>
                </div>

                <!-- Modal Preview Foto Profil -->
                <div id="avatarModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" onclick="batalPreview()"></div>
                    <div class="relative glass rounded-[2rem] border border-white/10 shadow-2xl w-full max-w-xs p-8 text-center">
                        <img src="../profile/upload/<?= htmlspecialchars($data['profile_picture'] ?: 'default_avatar.png', ENT_QUOTES, 'UTF-8') ?>" alt="Foto profil saat ini" class="w-16 h-16 mx-auto mb-2 rounded-2xl object-cover border-2 border-white/10 shadow-lg" title="Foto profil Anda saat ini">
                        <p class="text-xs text-gray-500 uppercase tracking-widest mb-4">Foto Saat Ini</p>
                        <h3 class="text-sm font-black text-white uppercase tracking-widest mb-4">Pratinjau Foto</h3>
                        <p class="text-[10px] text-gray-500 mb-4">Geser foto untuk memilih bagian yang dipakai</p>
                        <div id="cropFrame" class="relative h-56 mx-auto mb-4 rounded-2xl overflow-hidden border-2 border-blue-500/30 select-none" style="width:224px;touch-action:none;cursor:grab;background-color:#0b0e14">
                            <img id="modalAvatarPreview" class="absolute select-none" style="max-width:none;top:0;left:0" alt="Preview" draggable="false">
                            <div class="pointer-events-none absolute top-0 bottom-0 left-1/2 w-px crop-guide" style="transform:translateX(-50%)"></div>
                            <div class="pointer-events-none absolute left-0 right-0 top-1/2 h-px crop-guide" style="transform:translateY(-50%)"></div>
                        </div>
                        <input type="hidden" name="crop_x" id="cropX" value="">
                        <input type="hidden" name="crop_y" id="cropY" value="">
                        <div class="flex gap-3">
                            <button type="button" id="avatarUseBtn" class="flex-1 bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 rounded-2xl transition-all text-[10px] uppercase tracking-widest shadow-lg shadow-blue-600/20">Gunakan</button>
                            <button type="button" id="avatarCancelBtn" class="flex-1 bg-white/5 hover:bg-white/10 text-gray-400 font-bold py-3 rounded-2xl transition-all text-[10px] uppercase tracking-widest border border-white/10">Batal</button>
                        </div>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest ml-1">Bio Anda</label>
                    <textarea name="bio" rows="4" class="w-full bg-[#0b0e14] border border-white/5 rounded-2xl p-4 text-sm focus:outline-none focus:border-blue-600 transition"><?= htmlspecialchars($data['bio']) ?></textarea>
                </div>

                <button name="update_profile" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-4 rounded-2xl transition-all shadow-lg shadow-blue-600/20">
                    Simpan Perubahan
                </button>
            </form>

            <a href="../profile/?u=<?= $_SESSION['username'] ?>" class="block text-center mt-6 text-xs text-gray-600 hover:text-gray-400">Batal dan Kembali</a>
        </div>
    </div>
    <script>        lucide.createIcons();

        var avatarInput        = document.getElementById('avatarInput');
        var avatarPreview      = document.getElementById('avatarPreview');
        var avatarModal        = document.getElementById('avatarModal');
        var modalAvatarPreview = document.getElementById('modalAvatarPreview');
        var cropFrame          = document.getElementById('cropFrame');
        var avatarUseBtn       = document.getElementById('avatarUseBtn');
        var avatarCancelBtn    = document.getElementById('avatarCancelBtn');
        var avatarStatus       = document.getElementById('avatarStatus');
        var cropXInput         = document.getElementById('cropX');
        var cropYInput         = document.getElementById('cropY');
        var pendingAvatarUrl   = null;

        var cropState = { W: 0, H: 0, D: 0, scale: 1, ox: 0, oy: 0 };

        function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

        function initCrop(img) {
            var W = img.naturalWidth, H = img.naturalHeight;
            if (!W || !H) {
                avatarModal.classList.add('hidden');
                return;
            }
            var D = Math.min(W, H);
            var frameSize = cropFrame.clientWidth;
            var scale = frameSize / D;
            cropState = { W: W, H: H, D: D, scale: scale, ox: 0, oy: 0 };
            modalAvatarPreview.style.width  = Math.round(W * scale) + 'px';
            modalAvatarPreview.style.height = Math.round(H * scale) + 'px';
            modalAvatarPreview.src = pendingAvatarUrl;
            setCropOffset((frameSize - W * scale) / 2, (frameSize - H * scale) / 2);
        }

        function setCropOffset(ox, oy) {
            var s = cropState.scale;
            var frameSize = cropFrame.clientWidth;
            var dispW = cropState.W * s, dispH = cropState.H * s;
            ox = clamp(ox, frameSize - dispW, 0);
            oy = clamp(oy, frameSize - dispH, 0);
            cropState.ox = ox; cropState.oy = oy;
            modalAvatarPreview.style.transform = 'translate3d(' + ox + 'px,' + oy + 'px,0)';
            var cx = clamp(Math.round(-ox / s), 0, cropState.W - cropState.D);
            var cy = clamp(Math.round(-oy / s), 0, cropState.H - cropState.D);
            if (cropXInput) cropXInput.value = cx;
            if (cropYInput) cropYInput.value = cy;
        }

        function batalPreview() {
            avatarModal.classList.add('hidden');

            if (avatarInput) avatarInput.value = '';
            if (cropXInput) cropXInput.value = '';
            if (cropYInput) cropYInput.value = '';
        }

        if (avatarInput && avatarModal) {
            avatarInput.addEventListener('change', function () {
                var file = this.files && this.files[0];
                if (!file) return;

                if (!/^image\/(jpeg|jpg|png|webp)$/i.test(file.type)) {
                    if (avatarStatus) {
                        avatarStatus.textContent = 'Format tidak didukung! Gunakan JPG, PNG, atau WebP.';
                        avatarStatus.classList.remove('hidden');
                    }
                    this.value = '';
                    return;
                }
                if (avatarStatus) {
                    avatarStatus.textContent = '';
                    avatarStatus.classList.add('hidden');
                }

                if (cropXInput) cropXInput.value = '';
                if (cropYInput) cropYInput.value = '';

                var reader = new FileReader();
                reader.onload = function (e) {
                    pendingAvatarUrl = e.target.result;
                    var probe = new Image();
                    probe.onload = function () {

                        avatarModal.classList.remove('hidden');
                        initCrop(probe);
                    };
                    probe.src = pendingAvatarUrl;
                };
                reader.readAsDataURL(file);
            });

            var dragging = null;
            if (cropFrame) {
                cropFrame.addEventListener('pointerdown', function (e) {
                    dragging = { sx: e.clientX, sy: e.clientY, ox: cropState.ox, oy: cropState.oy };
                    cropFrame.setPointerCapture(e.pointerId);
                    cropFrame.style.cursor = 'grabbing';
                });
                cropFrame.addEventListener('pointermove', function (e) {
                    if (!dragging) return;
                    setCropOffset(dragging.ox + (e.clientX - dragging.sx), dragging.oy + (e.clientY - dragging.sy));
                });
                function endDrag() {
                    dragging = null;
                    cropFrame.style.cursor = 'grab';
                }
                cropFrame.addEventListener('pointerup', endDrag);
                cropFrame.addEventListener('pointercancel', endDrag);
            }

            if (avatarUseBtn) {
                avatarUseBtn.addEventListener('click', function () {
                    if (pendingAvatarUrl && modalAvatarPreview.src) {
                        var c = document.createElement('canvas');
                        c.width = 400; c.height = 400;
                        var ctx = c.getContext('2d');
                        var cx = clamp(Math.round(-cropState.ox / cropState.scale), 0, cropState.W - cropState.D);
                        var cy = clamp(Math.round(-cropState.oy / cropState.scale), 0, cropState.H - cropState.D);
                        try {
                            ctx.drawImage(modalAvatarPreview, cx, cy, cropState.D, cropState.D, 0, 0, 400, 400);
                            avatarPreview.src = c.toDataURL('image/webp', 0.85);
                        } catch (e) {
                            avatarPreview.src = pendingAvatarUrl;
                        }
                    }
                    avatarModal.classList.add('hidden');
                });
            }

            if (avatarCancelBtn) {
                avatarCancelBtn.addEventListener('click', batalPreview);
            }
        }
</script>
</body>

</html>
