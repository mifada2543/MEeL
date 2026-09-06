<?php
include '../auth/config.php';
include '../auth/auth.php';
include_once '../modules/core/helpers.php';
require_once '../modules/core/japanese.php';

$_EDIT_CONTEXT = $_EDIT_CONTEXT ?? 'admin';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login");
    exit();
}

$user_id = $_SESSION['user_id'];
$curr_role = get_user_role($conn, (int)$user_id);
$is_admin   = is_admin($conn);

if ($curr_role === 'guest') {
    header("Location: ../");
    exit();
}

// Routing berbasis role: /admin/edit-* khusus admin, /profile/edit-* khusus pemilik (non-admin).
$edit_id = (int)($_GET['id'] ?? 0);
if ($_EDIT_CONTEXT === 'admin') {
    if (!$is_admin) {
        header('Location: ' . base_url('/profile/edit-music?id=' . $edit_id));
        exit;
    }
} elseif ($is_admin) {
    header('Location: ' . base_url('/admin/edit-music?id=' . $edit_id));
    exit;
}

$back_url = $is_admin ? 'stats.php' : '../music/beranda';
if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref      = $_SERVER['HTTP_REFERER'];
    $host     = $_SERVER['HTTP_HOST'];
    if (parse_url($ref, PHP_URL_HOST) === $host) {
        $ref_path       = parse_url($ref, PHP_URL_PATH);
        $excluded_pages = ['edit-music.php', 'edit-music', 'edit-video.php', 'edit-video'];
        $should_exclude = false;
        foreach ($excluded_pages as $page) {
            if (strpos($ref_path, $page) !== false) {
                $should_exclude = true;
                break;
            }
        }
        if (!$should_exclude) $back_url = $ref;
    }
}
require_once __DIR__ . '/../modules/media/MediaAdminRepository.php';
$adminMedia = new MediaAdminRepository($conn);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$music = $adminMedia->getMedia('music', $id);
if (!$music) {
    header("Location: ../err/?code=not_found");
    exit;
}

$is_owner = ((int)$music['user_id'] === (int)$user_id);
if (!$is_admin && !$is_owner) {
    header("Location: ../err/?code=denied");
    exit();
}
$status = "";
$error_message = "";
if (isset($_POST['update'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error_message = "CSRF Token tidak valid.";
    } else {
        $title = trim($_POST['title'] ?? '');
        $artist = trim($_POST['artist'] ?? 'Unknown Artist');
        $album = trim($_POST['album'] ?? 'Single');
        $description = trim($_POST['description'] ?? '');
        $thumbnail_url = $music['thumbnail'];
        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
            $max_size = 5 * 1024 * 1024;
            if ($_FILES['thumbnail']['size'] > $max_size) {
                $error_message = 'Ukuran file cover maksimal 5MB.';
            }
            if (empty($error_message)) {
                $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $upload_mime = finfo_file($finfo, $_FILES['thumbnail']['tmp_name']);
                finfo_close($finfo);
                if (!in_array($upload_mime, $allowed_mime, true)) {
                    $error_message = 'File cover harus berupa gambar (JPEG, PNG, WebP, GIF, atau AVIF).';
                }
            }
            if (empty($error_message)) {
                $target_dir = meel_media_base_path('music') . '/thumbnail/';
                if (!is_dir($target_dir)) {
                    @mkdir($target_dir, 0755, true);
                }
                $clean_title = getRomajiName($title);
                if (empty($clean_title)) $clean_title = 'music-cover';
                
                // Reservasi nama atomik via helper bersama (fopen x) — dua
                // request bersamaan tidak boleh memilih nama yang sama.
                $new_name    = meel_reserve_unique_filename($target_dir, $clean_title . '_cover', 'webp', 200, '_');
                $upload_path = $new_name !== null ? $target_dir . $new_name : null;
                $ffmpeg_bin = resolve_binary(['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg']);

                $cmd = escapeshellarg($ffmpeg_bin) . " -y -i " . escapeshellarg($_FILES['thumbnail']['tmp_name'])
                    . " -vf \"scale=500:500:force_original_aspect_ratio=decrease,pad=500:500:(ow-iw)/2:(oh-ih)/2\" -c:v libwebp -q:v 78 "
                    . escapeshellarg($upload_path) . " 2>&1";
                exec($cmd, $out, $ret);
                if ($ret === 0 && file_exists($upload_path) && filesize($upload_path) > 0) {
                    $thumbnail_url = $new_name;
                } else {
                    if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $upload_path)) {
                        $thumbnail_url = $new_name;
                    } else {
                        @unlink($upload_path);
                        $error_message = 'Gagal mengupload cover thumbnail.';
                    }
                }
            }
        }
        if ($title === '') {
            $error_message = "Judul lagu tidak boleh kosong.";
        } else {

            $meta = generate_search_metadata($title, $artist, $album);
            if ($adminMedia->updateMusic($id, $title, $artist, $album, $description, $thumbnail_url, $meta)) {
                $status = "success";
                $music['title'] = $title;
                $music['artist'] = $artist;
                $music['album'] = $album;
                $music['description'] = $description;
                if ($thumbnail_url !== $music['thumbnail']) {
                    $music['thumbnail'] = $thumbnail_url;
                }
            } else {
                $error_message = "Gagal menyimpan perubahan ke database.";
            }
        }
    }
}

$thumb_src = !empty($music['thumbnail'])
    ? '../music/upload/thumbnail/' . htmlspecialchars($music['thumbnail'])
    : '../assets/img/music0.webp';
?>
<!DOCTYPE html>
<html lang="id">

<head>
<?php
$_META_TITLE = 'Edit Musik | MEeL Admin';
$_META_DESC  = 'Edit detail musik di MEeL. Ubah judul, artis, album, deskripsi, dan cover art.';
include __DIR__ . '/../partials/link.php';
?>
    <link rel="stylesheet" href="../assets/css/shared/design-tokens.css?v=<?= filemtime('../assets/css/shared/design-tokens.css') ?>">
    <link rel="stylesheet" href="../assets/css/shared/upload-form.css?v=<?= filemtime('../assets/css/shared/upload-form.css') ?>">
    <link rel="stylesheet" href="../assets/css/admin/edit/shared/main.css?v=<?= filemtime('../assets/css/admin/edit/shared/main.css') ?>">
    <link rel="stylesheet" href="../assets/css/admin/edit/music/main.css?v=<?= filemtime('../assets/css/admin/edit/music/main.css') ?>">
</head>

<body class="theme-music">
    <div class="page-wrap">

        
        <?php
        $page_title = 'Edit Musik';
        $media_type = 'music';
        include __DIR__ . '/header-admin.php';
        ?>
        
        <div class="edit-layout">

            
            <aside class="sidebar-panel">
                
                <div class="cover-wrap" id="cover-wrap">
                    
                    <img src="<?= $thumb_src ?>"
                        alt="Cover <?= htmlspecialchars($music['title']) ?>"
                        class="cover-img"
                        id="cover-preview">
                    <div class="cover-overlay">
                        <div class="cover-overlay-icon">
                            <i data-lucide="image" style="width:20px;height:20px;color:#fff;"></i>
                        </div>
                        <div class="cover-overlay-text">Klik atau drop<br>untuk ganti cover</div>
                    </div>
                    <span class="cover-badge" id="cover-badge">Cover Art</span>
                    <span class="cover-changed-badge" id="cover-changed-badge">✓ Baru</span>
                </div>

                
                <div class="uploader-card">
                    <?php if (!empty($music['uploader_pfp'])): ?>
                        <img src="../profile/upload/<?= htmlspecialchars($music['uploader_pfp']) ?>"
                            alt="<?= htmlspecialchars($music['uploader'] ?? '') ?>"
                            class="uploader-avatar">
                    <?php else: ?>
                        <div class="uploader-avatar-fallback">
                            <?= strtoupper(substr($music['uploader'] ?? 'U', 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div class="uploader-info">
                        <div class="uploader-label">Diunggah oleh</div>
                        <div class="uploader-name">@<?= htmlspecialchars($music['uploader'] ?? '—') ?></div>
                    </div>
                    <div class="uploader-role-badge"><?= $is_admin && !$is_owner ? 'Admin Edit' : 'Uploader' ?></div>
                </div>

                <div class="meta-info">
                    <div class="meta-row">
                        <div class="meta-row-icon">
                            <i data-lucide="music" style="width:13px;height:13px;color:var(--accent)"></i>
                        </div>
                        <div>
                            <div class="meta-label">Judul Lagu</div>
                            <div class="meta-value" id="sidebar-title"><?= htmlspecialchars($music['title']) ?></div>
                        </div>
                    </div>
                    <div class="meta-row">
                        <div class="meta-row-icon">
                            <i data-lucide="mic-2" style="width:13px;height:13px;color:var(--accent)"></i>
                        </div>
                        <div>
                            <div class="meta-label">Artis</div>
                            <div class="meta-value" id="sidebar-artist"><?= htmlspecialchars($music['artist'] ?? '—') ?></div>
                        </div>
                    </div>
                    <div class="meta-row">
                        <div class="meta-row-icon">
                            <i data-lucide="disc" style="width:13px;height:13px;color:var(--accent)"></i>
                        </div>
                        <div>
                            <div class="meta-label">Album</div>
                            <div class="meta-value" id="sidebar-album"><?= htmlspecialchars($music['album'] ?? '—') ?></div>
                        </div>
                    </div>
                    <div class="meta-row">
                        <div class="meta-row-icon">
                            <i data-lucide="calendar" style="width:13px;height:13px;color:var(--accent)"></i>
                        </div>
                        <div>
                            <div class="meta-label">Tanggal Upload</div>
                            <div class="meta-value"><?= !empty($music['upload_date']) ? date('d M Y', strtotime($music['upload_date'])) : '—' ?></div>
                        </div>
                    </div>
                </div>

                <div class="stats-strip">
                    <div class="stat-chip">
                        <div class="stat-number"><?= number_format($music['views'] ?? 0) ?></div>
                        <div class="stat-label">Views</div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-number"><?= number_format($music['likes'] ?? 0) ?></div>
                        <div class="stat-label">Likes</div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-number" style="color:#f87171;"><?= number_format($music['dislikes'] ?? 0) ?></div>
                        <div class="stat-label">Dislikes</div>
                    </div>
                </div>

                <div style="display:flex;flex-direction:column;gap:8px;margin-top:auto">
                    <a href="<?= base_url('/music/watch?id=' . (int)$id) ?>" class="btn-secondary" style="justify-content:center;">
                        <i data-lucide="arrow-left" style="width:13px;height:13px;"></i> Lihat Musik
                    </a>
                    <?php if ($is_admin): ?>
                        <a href="." class="btn-secondary" style="justify-content:center;">
                            <i data-lucide="layout-dashboard" style="width:13px;height:13px;"></i> Dashboard Admin
                        </a>
                    <?php else: ?>
                        <a href="../profile/" class="btn-secondary" style="justify-content:center;">
                            <i data-lucide="user" style="width:13px;height:13px;"></i> Profil Saya
                        </a>
                    <?php endif; ?>
                </div>
            </aside>

            
            <section class="form-panel">
                <div class="form-header">
                    <div>
                        <h1 class="form-title">Edit <span>Musik</span></h1>
                        <p class="form-subtitle"><?= $is_admin && !$is_owner ? 'Edit sebagai Admin · Milik @' . htmlspecialchars($music['uploader']) : 'Ubah keterangan &amp; detail lagu' ?></p>
                    </div>
                    <i data-lucide="music-2" style="width:36px;height:36px;color:var(--accent);opacity:.3;flex-shrink:0;margin-top:4px;"></i>
                </div>

                <?php if ($status === "success"): ?>
                    <div class="alert alert-success" style="margin-bottom:20px;">
                        <i data-lucide="check-circle" style="width:15px;height:15px;flex-shrink:0;"></i>
                        Detail musik berhasil diperbarui!
                    </div>
                <?php endif; ?>
                <?php if ($error_message !== ""): ?>
                    <div class="alert alert-error" style="margin-bottom:20px;">
                        <i data-lucide="alert-triangle" style="width:15px;height:15px;flex-shrink:0;"></i>
                        <?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>
                <form id="edit-form" method="POST" enctype="multipart/form-data" onsubmit="handleSubmit()" style="display:flex;flex-direction:column;gap:20px;flex:1;">
                    <?php if (isset($_SESSION['csrf_token'])): ?>
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                        <input type="file" name="thumbnail" accept="image/*" id="cover-file-hidden" style="display:none">
                    <?php endif; ?>
                    
                    <div class="field-group">
                        <label class="field-label" for="f-title">Judul Lagu</label>
                        <input type="text" id="f-title" name="title" placeholder="Masukkan judul lagu..."
                            required class="field-input"
                            value="<?= htmlspecialchars($music['title']) ?>"
                            oninput="document.getElementById('sidebar-title').textContent = this.value || '—'">
                    </div>

                    
                    <div class="two-col">
                        <div class="field-group">
                            <label class="field-label" for="f-artist">Artis</label>
                            <input type="text" id="f-artist" name="artist" placeholder="Artis..."
                                required class="field-input"
                                value="<?= htmlspecialchars($music['artist'] ?? '') ?>"
                                oninput="document.getElementById('sidebar-artist').textContent = this.value || '—'">
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="f-album">Album</label>
                            <input type="text" id="f-album" name="album" placeholder="Album..."
                                class="field-input"
                                value="<?= htmlspecialchars($music['album'] ?? '') ?>"
                                oninput="document.getElementById('sidebar-album').textContent = this.value || '—'">
                        </div>
                    </div>

                    
                    <div class="field-group" style="flex:1;display:flex;flex-direction:column;">
                        <label class="field-label" for="f-desc">Deskripsi / Keterangan</label>
                        <textarea id="f-desc" name="description" placeholder="Masukkan deskripsi musik..."
                            class="field-input" style="flex:1;min-height:120px;resize:none;"><?= htmlspecialchars($music['description'] ?? '') ?></textarea>
                    </div>

                    
                    <div class="form-actions">
                        <button type="submit" name="update" id="btn-save" class="btn-primary">
                            <i data-lucide="save" style="width:15px;height:15px;"></i>
                            Simpan Perubahan
                        </button>
                    </div>
                </form>
            </section>

        </div>
    </div>

    <?php include '../partials/footer.php'; ?>
    <?php $scripts_root = '../'; include __DIR__ . '/../partials/scripts.php'; ?>
    <script src="../assets/js/admin/edit/shared/form.js?v=<?= filemtime('../assets/js/admin/edit/shared/form.js') ?>"></script>
    <script src="../assets/js/admin/edit/shared/thumbnail.js?v=<?= filemtime('../assets/js/admin/edit/shared/thumbnail.js') ?>"></script>
    <script src="../assets/js/admin/edit/shared/dragdrop.js?v=<?= filemtime('../assets/js/admin/edit/shared/dragdrop.js') ?>"></script>
    <script src="../assets/js/admin/edit/music.js?v=<?= filemtime('../assets/js/admin/edit/music.js') ?>"></script>
    <script>
        <?php if ($status === "success"): ?>
            Swal.fire({
                title: 'Berhasil!',
                text: 'Detail musik telah diperbarui.',
                icon: 'success',
                confirmButtonColor: '#f97316',
                background: '#0e1118',
                color: '#fff'
            });
        <?php endif; ?>
    </script>
</body>

</html>
