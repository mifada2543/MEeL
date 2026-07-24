<?php
include '../auth/config.php';
include '../auth/auth.php';
include_once '../modules/core/helpers.php';
require_once '../modules/core/japanese.php';

// Proteksi: harus login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$curr_role = get_user_role($conn, (int)$user_id);
$is_admin   = ($curr_role === 'admin');

// Tolak guest
if ($curr_role === 'guest') {
    header("Location: ../index.php");
    exit();
}

// ── Back URL (smart referer) ──
$back_url = $is_admin ? 'cookies.php' : '../music/index.php';
if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref      = $_SERVER['HTTP_REFERER'];
    $host     = $_SERVER['HTTP_HOST'];
    if (parse_url($ref, PHP_URL_HOST) === $host) {
        $ref_path       = parse_url($ref, PHP_URL_PATH);
        $excluded_pages = ['edit-music.php', 'edit-video.php'];
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

// Validasi ID Musik
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt_music = $conn->prepare("SELECT m.*, u.username AS uploader, u.profile_picture AS uploader_pfp FROM music m JOIN users u ON m.user_id = u.id WHERE m.id = ? LIMIT 1");
$stmt_music->bind_param("i", $id);
$stmt_music->execute();
$music = $stmt_music->get_result()->fetch_assoc();

if (!$music) {
    die("<div style='color:orange; padding:20px; background:#0b0e14; min-height:100vh; font-family:sans-serif;'><h2>Error: Musik tidak ditemukan!</h2><a href='../music/index.php' style='color:#f97316;'>Kembali ke Musik</a></div>");
}

// Cek kepemilikan: admin bisa edit semua, uploader hanya miliknya
$is_owner = ((int)$music['user_id'] === (int)$user_id);
if (!$is_admin && !$is_owner) {
    header("Location: ../err/denied.php");
    exit();
}

$status = "";
$error_message = "";

if (isset($_POST['update'])) {
    if (!verify_csrf()) {
        $error_message = "CSRF Token tidak valid.";
    } else {
        $title = trim($_POST['title'] ?? '');
        $artist = trim($_POST['artist'] ?? 'Unknown Artist');
        $album = trim($_POST['album'] ?? 'Single');
        $description = trim($_POST['description'] ?? '');
        $thumbnail_url = $music['thumbnail'];
        // Handle cover thumbnail upload — konversi ke WebP
        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
            // Validasi ukuran file (maks 5MB)
            $max_size = 5 * 1024 * 1024;
            if ($_FILES['thumbnail']['size'] > $max_size) {
                $error_message = 'Ukuran file cover maksimal 5MB.';
            }

            // Validasi MIME type — finfo() cek magic bytes, lebih aman dari $_FILES['type']
            if (empty($error_message)) {
                $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $upload_mime = finfo_file($finfo, $_FILES['thumbnail']['tmp_name']);
                finfo_close($finfo);
                if (!in_array($upload_mime, $allowed_mime, true)) {
                    $error_message = 'File cover harus berupa gambar (JPEG, PNG, WebP, GIF, atau AVIF).';
                }
            }

            // Proses thumbnail hanya jika validasi lolos
            if (empty($error_message)) {
                $target_dir = __DIR__ . '/../music/upload/thumbnail/';
                if (!is_dir($target_dir)) {
                    @mkdir($target_dir, 0755, true);
                }
                // Nama file berdasarkan judul lagu
                $clean_title = getRomajiName($title);
                if (empty($clean_title)) $clean_title = 'music-cover';
                $new_name = $clean_title . '_cover.webp';
                $counter = 1;
                while (file_exists($target_dir . $new_name)) {
                    $new_name = $clean_title . '_cover_' . $counter . '.webp';
                    $counter++;
                }
                $upload_path = $target_dir . $new_name;
                $ffmpeg_bin = resolve_binary(['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg']);
                // Konversi ke WebP — 500×500 square, scale+pad agar tidak distorsi
                $cmd = escapeshellarg($ffmpeg_bin) . " -y -i " . escapeshellarg($_FILES['thumbnail']['tmp_name'])
                    . " -vf \"scale=500:500:force_original_aspect_ratio=decrease,pad=500:500:(ow-iw)/2:(oh-ih)/2\" -c:v libwebp -q:v 78 "
                    . escapeshellarg($upload_path) . " 2>&1";
                exec($cmd, $out, $ret);
                if ($ret === 0 && file_exists($upload_path) && filesize($upload_path) > 0) {
                    $thumbnail_url = $new_name;
                    // CATATAN: Thumbnail akan di-update bersama query utama di bawah, tidak perlu UPDATE terpisah
                } else {
                    // Fallback: simpan file asli jika ffmpeg gagal
                    if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $upload_path)) {
                        $thumbnail_url = $new_name;
                    } else {
                        $error_message = 'Gagal mengupload cover thumbnail.';
                    }
                }
            }
        }

        if ($title === '') {
            $error_message = "Judul lagu tidak boleh kosong.";
        } else {
            // Generate search_metadata baru
            $meta_string = trim("$title $artist $album");
            $romaji = getRomajiName($meta_string);
            $meta = mb_strtolower($meta_string . " " . $romaji, 'UTF-8');

            $stmt_update = $conn->prepare("UPDATE music SET title = ?, artist = ?, album = ?, description = ?, thumbnail = ?, search_metadata = ? WHERE id = ?");
            $stmt_update->bind_param("ssssssi", $title, $artist, $album, $description, $thumbnail_url, $meta, $id);
            if ($stmt_update->execute()) {
                $status = "success";
                // Refresh data musik terupdate
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

// Helper thumbnail URL
$thumb_src = !empty($music['thumbnail'])
    ? '../music/upload/thumbnail/' . htmlspecialchars($music['thumbnail'])
    : '../assets/img/music0.webp';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="Edit Musik | MEeL Admin">
    <meta property="og:description" content="Edit detail musik di MEeL. Ubah judul, artis, album, deskripsi, dan cover art.">
    <meta property="og:image" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/assets/MEeL.png">
    <meta property="og:url" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <title>Edit Musik | MEeL Admin</title>
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <link rel="stylesheet" href="../assets/css/em.css">
    <link href="../assets/css/tailwind.min.css" rel="stylesheet">
    <script src="../assets/js/lucide.js"></script>

</head>

<body class="theme-music">
    <div class="page-wrap">

        <!-- Top navigation -->
        <?php
        $page_title = 'Edit Musik';
        $media_type = 'music';
        include 'header-admin.php';
        ?>

        <!-- Main edit layout -->
        <div class="edit-layout">

            <!-- ── LEFT: Info sidebar ── -->
            <aside class="sidebar-panel">
                <!-- Cover — klik atau drag untuk ganti -->
                <div class="cover-wrap" id="cover-wrap">
                    <!-- File input dipindahkan ke dalam form (ID: cover-file-hidden) -->
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

                <!-- Uploader card -->
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
                    <a href="../music/watch.php?id=<?= $id ?>" class="btn-secondary" style="justify-content:center;">
                        <i data-lucide="arrow-left" style="width:13px;height:13px;"></i> Lihat Musik
                    </a>
                    <?php if ($is_admin): ?>
                        <a href="index.php" class="btn-secondary" style="justify-content:center;">
                            <i data-lucide="layout-dashboard" style="width:13px;height:13px;"></i> Dashboard Admin
                        </a>
                    <?php else: ?>
                        <a href="../profile/index.php" class="btn-secondary" style="justify-content:center;">
                            <i data-lucide="user" style="width:13px;height:13px;"></i> Profil Saya
                        </a>
                    <?php endif; ?>
                </div>
            </aside>

            <!-- ── RIGHT: Form panel ── -->
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

                    <!-- Judul -->
                    <div class="field-group">
                        <label class="field-label" for="f-title">Judul Lagu</label>
                        <input type="text" id="f-title" name="title" placeholder="Masukkan judul lagu..."
                            required class="field-input"
                            value="<?= htmlspecialchars($music['title']) ?>"
                            oninput="document.getElementById('sidebar-title').textContent = this.value || '—'">
                    </div>

                    <!-- Artis & Album -->
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

                    <!-- Deskripsi -->
                    <!-- Deskripsi — mengisi sisa ruang -->
                    <div class="field-group" style="flex:1;display:flex;flex-direction:column;">
                        <label class="field-label" for="f-desc">Deskripsi / Keterangan</label>
                        <textarea id="f-desc" name="description" placeholder="Masukkan deskripsi musik..."
                            class="field-input" style="flex:1;min-height:120px;resize:none;"><?= htmlspecialchars($music['description'] ?? '') ?></textarea>
                    </div>

                    <!-- Actions -->
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
    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <script src="../assets/js/script.min.js"></script>
    <script>
        lucide.createIcons();

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

        function handleSubmit() {
            const btn = document.getElementById('btn-save');
            btn.innerHTML = '<div style="width:16px;height:16px;border:2px solid rgba(0,0,0,.3);border-top-color:#000;border-radius:50%;animation:spin2 .7s linear infinite;"></div> Menyimpan...';
            btn.style.opacity = '.6';
            btn.style.pointerEvents = 'none';
        }

        // Hidden file input di dalam form — trigger via klik/drop di sidebar
        const coverHidden = document.getElementById('cover-file-hidden');

        function handleCoverChange(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('cover-preview').src = e.target.result;
                    document.getElementById('cover-changed-badge').style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Klik pada area cover → trigger hidden input
        document.getElementById('cover-wrap').addEventListener('click', function(e) {
            if (e.target === coverHidden) return;
            coverHidden.click();
        });

        // Hidden input change → preview
        coverHidden.addEventListener('change', function() {
            handleCoverChange(this);
        });

        // Drag-and-drop onto cover
        const coverWrap = document.getElementById('cover-wrap');

        coverWrap.addEventListener('dragover', function(e) {
            e.preventDefault();
            coverWrap.classList.add('drag-over');
        });
        coverWrap.addEventListener('dragleave', function() {
            coverWrap.classList.remove('drag-over');
        });
        coverWrap.addEventListener('drop', function(e) {
            e.preventDefault();
            coverWrap.classList.remove('drag-over');
            const files = e.dataTransfer.files;
            if (files && files[0] && files[0].type.startsWith('image/')) {
                const dt = new DataTransfer();
                dt.items.add(files[0]);
                coverHidden.files = dt.files;
                handleCoverChange(coverHidden);
            }
        });

        // Inject keyframe for spin
        const style = document.createElement('style');
        style.textContent = '@keyframes spin2 { to { transform: rotate(360deg); } }';
        document.head.appendChild(style);
    </script>
</body>

</html>