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
$is_admin  = is_admin($conn);
// Tolak guest
if ($curr_role === 'guest') {
    header("Location: ../index.php");
    exit();
}
// ─── Back URL (smart referer) ───
$back_url = $is_admin ? 'cookies.php' : '../video/index.php';
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
// Validasi ID Video
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt_video = $conn->prepare("SELECT v.*, u.username AS uploader, u.profile_picture AS uploader_pfp FROM video v JOIN users u ON v.user_id = u.id WHERE v.id = ? LIMIT 1");
$stmt_video->bind_param("i", $id);
$stmt_video->execute();
$video = $stmt_video->get_result()->fetch_assoc();
if (!$video) {
    die("<div style='color:red; padding:20px; background:#0b0e14; min-height:100vh; font-family:sans-serif;'><h2>Error: Video tidak ditemukan!</h2><a href='../video/index.php' style='color:#ef4444;'>Kembali ke Video</a></div>");
}

$is_owner = ((int)$video['user_id'] === (int)$user_id);
if (!$is_admin && !$is_owner) {
    header("Location: ../err/denied.php");
    exit();
}
$status = "";
$error_message = "";
if (isset($_POST['update'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error_message = "CSRF Token tidak valid.";
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $thumbnail_url = $video['thumbnail'];
        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
            // Validasi ukuran file (maks 5MB)
            $max_size = 5 * 1024 * 1024;
            if ($_FILES['thumbnail']['size'] > $max_size) {
                $error_message = 'Ukuran file thumbnail maksimal 5MB.';
            }
            // Validasi MIME type — finfo() cek magic bytes
            if (empty($error_message)) {
                $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $upload_mime = finfo_file($finfo, $_FILES['thumbnail']['tmp_name']);
                finfo_close($finfo);
                if (!in_array($upload_mime, $allowed_mime, true)) {
                    $error_message = 'File thumbnail harus berupa gambar (JPEG, PNG, WebP, GIF, atau AVIF).';
                }
            }
            // Proses thumbnail lolos
            if (empty($error_message)) {
                $target_dir = __DIR__ . '/../video/upload/thumbnail/';
                if (!is_dir($target_dir)) {
                    @mkdir($target_dir, 0755, true);
                }
                // Nama file berdasarkan judul video
                $clean_title = getRomajiName($title);
                if (empty($clean_title)) $clean_title = 'video-thumb';
                $new_name = $clean_title . '_thumb.webp';
                $counter = 1;
                while (file_exists($target_dir . $new_name)) {
                    $new_name = $clean_title . '_thumb_' . $counter . '.webp';
                    $counter++;
                }
                $upload_path = $target_dir . $new_name;
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
                        $error_message = 'Gagal mengupload thumbnail ke server.';
                    }
                }
            }
        }
        // ─── SUBTITLE (OPSIONAL): Upload / timpa file subtitle ── Konvensi nama... ───
        if (empty($error_message) && isset($_FILES['subtitle']) && $_FILES['subtitle']['error'] === UPLOAD_ERR_OK) {
            $sub_ext     = strtolower(pathinfo($_FILES['subtitle']['name'], PATHINFO_EXTENSION));
            $sub_lang    = sanitize_subtitle_lang($_POST['subtitle_lang'] ?? 'id');
            $sub_allowed = ['vtt', 'srt'];

            if (in_array($sub_ext, $sub_allowed, true) && validate_subtitle_file($_FILES['subtitle']['tmp_name'])) {
                $sub_content = (string)@file_get_contents($_FILES['subtitle']['tmp_name']);
                if ($sub_content !== '') {
                    if ($sub_ext === 'srt') {
                        $sub_content = convert_srt_to_vtt($sub_content);
                    }
                    $sub_content = strip_utf8_bom($sub_content); // WEBVTT harus jadi byte pertama

                    // Lokasi folder HLS video di storage
                    $hls_folder = basename(dirname($video['filename']));
                    $sub_dir    = __DIR__ . '/../video/upload/video/' . $hls_folder . '/';
                    if (is_dir($sub_dir)) {
                        $sub_target = $sub_dir . $hls_folder . '.' . $sub_lang . '.vtt';
                        if (@file_put_contents($sub_target, $sub_content, LOCK_EX) !== false) {
                            $status = "success";
                        } else {
                            $error_message = "Gagal menulis file subtitle ke storage.";
                        }
                    } else {
                        $error_message = "Folder HLS video tidak ditemukan di storage.";
                    }
                } else {
                    $error_message = "File subtitle kosong atau tidak dapat dibaca.";
                }
            } else {
                $error_message = "File subtitle tidak valid. Gunakan format VTT atau SRT (maks 2MB).";
            }
        }
        if ($title === '') {
            $error_message = "Judul video tidak boleh kosong.";
        } elseif ($error_message === '') {

            $meta = generate_search_metadata($title);
            $stmt_update = $conn->prepare("UPDATE video SET title = ?, description = ?, thumbnail = ?, search_metadata = ? WHERE id = ?");
            $stmt_update->bind_param("ssssi", $title, $description, $thumbnail_url, $meta, $id);
            if ($stmt_update->execute()) {
                $status = "success";
                $video['title'] = $title;
                $video['description'] = $description;
                $video['thumbnail'] = $thumbnail_url;
            } else {
                $error_message = "Gagal menyimpan perubahan ke database.";
            }
        }
        // ─── ROLLBACK THUMBNAIL ───
        if ($error_message !== '' && $thumbnail_url !== $video['thumbnail']) {
            $orphan_thumb = __DIR__ . '/../video/upload/thumbnail/' . basename($thumbnail_url);
            if (is_file($orphan_thumb)) {
                @unlink($orphan_thumb);
            }
        }
    }
}
// ─── SUBTITLE: Hapus file subtitle berdasarkan bahasa (handler terpisah) ───
if (isset($_POST['delete_subtitle_lang']) && $_POST['delete_subtitle_lang'] !== '') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error_message = "CSRF Token tidak valid.";
    } else {
        $del_lang   = sanitize_subtitle_lang($_POST['delete_subtitle_lang'], 'und');
        $hls_folder = basename(dirname($video['filename']));
        $del_path   = __DIR__ . '/../video/upload/video/' . $hls_folder . '/' . $hls_folder . '.' . $del_lang . '.vtt';
        if (file_exists($del_path)) {
            if (@unlink($del_path)) {
                $status = "success";
            } else {
                $error_message = "Gagal menghapus file subtitle.";
            }
        } else {
            $error_message = "File subtitle bahasa tersebut tidak ditemukan.";
        }
    }
}

// ─── DAFTAR SUBTITLE EXISTING format {folder}.{lang}.vtt. ───
$existing_subtitles = [];
$hls_folder_dir    = basename(dirname($video['filename']));
$sub_scan_dir      = __DIR__ . '/../video/upload/video/' . $hls_folder_dir . '/';
if (is_dir($sub_scan_dir)) {
    foreach (glob($sub_scan_dir . '*.vtt') ?: [] as $sf) {
        $sbase = basename($sf);
        if ($sbase === 'thumbnails.vtt') continue; // preview thumbnail, bukan subtitle
        if (preg_match('/\.([a-z]{2,3}(?:-[a-z]{2,8})?)\.vtt$/i', $sbase, $m)) {
            $existing_subtitles[] = ['lang' => strtolower($m[1]), 'file' => $sbase];
        }
    }
}
usort($existing_subtitles, fn($a, $b) => strcmp($a['lang'], $b['lang']));

$thumb_src = !empty($video['thumbnail'])
    ? '../video/upload/thumbnail/' . htmlspecialchars($video['thumbnail'])
    : '../assets/img/video0.webp';
?>
<!DOCTYPE html>
<html lang="id">

<head>
<?php
$_META_TITLE = 'Edit Video | MEeL Admin';
$_META_DESC  = 'Edit detail video di MEeL. Ubah judul, deskripsi, dan thumbnail video.';
include __DIR__ . '/../partials/link.php';
?>
    <link rel="stylesheet" href="../assets/css/shared/design-tokens.css?v=<?= filemtime('../assets/css/shared/design-tokens.css') ?>">
    <link rel="stylesheet" href="../assets/css/shared/upload-form.css?v=<?= filemtime('../assets/css/shared/upload-form.css') ?>">
    <link rel="stylesheet" href="../assets/css/admin/edit/shared/main.css?v=<?= filemtime('../assets/css/admin/edit/shared/main.css') ?>">
    <link rel="stylesheet" href="../assets/css/admin/edit/video/main.css?v=<?= filemtime('../assets/css/admin/edit/video/main.css') ?>">
</head>

<body class="theme-video">
    <div class="page-wrap">

        <!-- Top nav -->
        <?php
        $page_title = 'Edit Video';
        $media_type = 'video';
        include 'header-admin.php';
        ?>
        <div class="edit-layout">

            <!-- ── LEFT: Sidebar ── -->
            <aside class="sidebar-panel">
                <!-- Thumbnail — klik atau drag untuk ganti -->
                <div class="thumb-wrap" id="thumb-wrap">
                    <!-- File input (ID: thumb-file-hidden) -->
                    <img src="<?= $thumb_src ?>"
                        alt="Thumbnail <?= htmlspecialchars($video['title']) ?>"
                        class="thumb-img"
                        id="thumb-preview">
                    <div class="thumb-overlay">
                        <div class="thumb-overlay-icon">
                            <i data-lucide="image" style="width:22px;height:22px;color:#fff;"></i>
                        </div>
                        <div class="thumb-overlay-text">Klik atau drop<br>untuk ganti thumbnail</div>
                    </div>
                    <span class="thumb-label" id="thumb-label">Thumbnail saat ini</span>
                    <span class="thumb-changed-badge" id="thumb-changed-badge">✓ Baru</span>
                </div>

                <!-- Uploader card -->
                <div class="uploader-card">
                    <?php if (!empty($video['uploader_pfp'])): ?>
                        <img src="../profile/upload/<?= htmlspecialchars($video['uploader_pfp']) ?>"
                            alt="<?= htmlspecialchars($video['uploader'] ?? '') ?>"
                            class="uploader-avatar">
                    <?php else: ?>
                        <div class="uploader-avatar-fallback">
                            <?= strtoupper(substr($video['uploader'] ?? 'U', 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div class="uploader-info">
                        <div class="uploader-label">Diunggah oleh</div>
                        <div class="uploader-name">@<?= htmlspecialchars($video['uploader'] ?? '—') ?></div>
                    </div>
                    <div class="uploader-role-badge"><?= $is_admin && !$is_owner ? 'Admin Edit' : 'Uploader' ?></div>
                </div>

                <!-- Meta rows -->
                <div class="meta-info">
                    <div class="meta-row">
                        <div class="meta-row-icon">
                            <i data-lucide="film" style="width:13px;height:13px;color:var(--accent)"></i>
                        </div>
                        <div>
                            <div class="meta-label">Judul Video</div>
                            <div class="meta-value" id="sidebar-title"><?= htmlspecialchars($video['title']) ?></div>
                        </div>
                    </div>
                    <div class="meta-row">
                        <div class="meta-row-icon">
                            <i data-lucide="calendar" style="width:13px;height:13px;color:var(--accent)"></i>
                        </div>
                        <div>
                            <div class="meta-label">Tanggal Upload</div>
                            <div class="meta-value"><?= !empty($video['upload_date']) ? date('d M Y', strtotime($video['upload_date'])) : '—' ?></div>
                        </div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-strip">
                    <div class="stat-chip">
                        <div class="stat-number"><?= number_format($video['views'] ?? 0) ?></div>
                        <div class="stat-label">Views</div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-number"><?= number_format($video['likes'] ?? 0) ?></div>
                        <div class="stat-label">Likes</div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-number" style="color:#f87171;"><?= number_format($video['dislikes'] ?? 0) ?></div>
                        <div class="stat-label">Dislikes</div>
                    </div>
                </div>

                <!-- Nav buttons -->
                <div style="display:flex;flex-direction:column;gap:8px;margin-top:auto;">
                    <a href="../video/watch.php?id=<?= $id ?>" class="btn-secondary" style="justify-content:center;">
                        <i data-lucide="arrow-left" style="width:13px;height:13px;"></i> Lihat Video
                    </a>
                    <?php if ($is_admin): ?>
                        <a href="index.php" class="btn-secondary" style="justify-content:center;">
                            <i data-lucide="layout-dashboard" style="width:13px;height:13px;"></i> Dashboard Admin
                        </a>
                    <?php else: ?>
                        <a href="../profile/?u=<?= $_SESSION['username'] ?>" class="btn-secondary" style="justify-content:center;">
                            <i data-lucide="user" style="width:13px;height:13px;"></i> Profil Saya
                        </a>
                    <?php endif; ?>
                </div>
            </aside>

            <!-- ── RIGHT: Form panel ── -->
            <section class="form-panel">
                <div class="form-header">
                    <div>
                        <h1 class="form-title">Edit <span>Video</span></h1>
                        <p class="form-subtitle"><?= $is_admin && !$is_owner ? 'Edit sebagai Admin · Milik @' . htmlspecialchars($video['uploader']) : 'Ubah keterangan &amp; detail video' ?></p>
                    </div>
                    <i data-lucide="video" style="width:36px;height:36px;color:var(--accent);opacity:.3;flex-shrink:0;margin-top:4px;"></i>
                </div>

                <?php if ($status === "success"): ?>
                    <div class="alert alert-success" style="margin-bottom:20px;">
                        <i data-lucide="check-circle" style="width:15px;height:15px;flex-shrink:0;"></i>
                        Detail video berhasil diperbarui!
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
                        <input type="file" name="thumbnail" accept="image/*" id="thumb-file-hidden" style="display:none">
                    <?php endif; ?>
                    <!-- Judul -->
                    <div class="field-group">
                        <label class="field-label" for="f-title">Judul Video</label>
                        <input type="text" id="f-title" name="title" placeholder="Masukkan judul video..."
                            required class="field-input"
                            value="<?= htmlspecialchars($video['title']) ?>"
                            oninput="document.getElementById('sidebar-title').textContent = this.value || '—'">
                    </div>

                    <!-- Deskripsi — mengisi sisa ruang -->
                    <div class="field-group" style="flex:1;display:flex;flex-direction:column;">
                        <label class="field-label" for="f-desc">Deskripsi / Keterangan</label>
                        <textarea id="f-desc" name="description"
                            placeholder="Masukkan deskripsi video..."
                            class="field-input" style="flex:1;min-height:120px;resize:none;"><?= htmlspecialchars($video['description'] ?? '') ?></textarea>
                    </div>

                    <!-- Subtitle existing — DI LUAR form utama agar tidak nested form -->
                    <div class="field-group" style="gap:10px;">
                        <label class="field-label">Subtitle</label>
                        <?php if (!empty($existing_subtitles)): ?>
                            <div style="display:flex;flex-direction:column;gap:6px;">
                                <?php foreach ($existing_subtitles as $_sub): ?>
                                    <div style="display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:6px 10px;">
                                        <i data-lucide="captions" style="width:13px;height:13px;color:var(--accent);flex-shrink:0;"></i>
                                        <span style="flex:1;font-size:11px;font-weight:700;color:#e2e6ef;text-transform:uppercase;letter-spacing:.06em;"><?= htmlspecialchars(subtitle_lang_label($_sub['lang'])) ?></span>
                                        <span style="font-size:9px;color:#455060;text-transform:uppercase;letter-spacing:.05em;"><?= htmlspecialchars($_sub['file']) ?></span>
                                        <form method="POST" style="display:inline;margin:0;"
                                            onsubmit="return meelConfirmForm(event, { title:'Hapus Subtitle', text:'Hapus subtitle bahasa <?= htmlspecialchars(subtitle_lang_label($_sub['lang'])) ?>?', confirmButtonText:'HAPUS' })">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="delete_subtitle_lang" value="<?= htmlspecialchars($_sub['lang']) ?>">
                                            <button type="submit" title="Hapus subtitle"
                                                style="background:none;border:none;cursor:pointer;color:#f87171;padding:4px;display:flex;"
                                                aria-label="Hapus subtitle <?= htmlspecialchars($_sub['lang']) ?>">
                                                <i data-lucide="trash-2" style="width:13px;height:13px;"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="font-size:10px;color:#455060;">Belum ada subtitle untuk video ini.</div>
                        <?php endif; ?>
                    </div>
                    <!-- Subtitle upload (di dalam form utama) — paling bawah setelah deskripsi -->
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <label class="field-label">Upload / Ganti Subtitle</label>
                        <!-- Subtitle file — drop zone memanjang satu baris penuh -->
                        <div class="drop-zone-subtitle" id="subtitle-zone">
                            <input type="file" name="subtitle" accept=".vtt,.srt"
                                id="f-subtitle" onchange="handleSubtitleFile(this)" aria-label="Pilih file subtitle (VTT atau SRT)">
                            <div class="drop-zone-icon">
                                <i data-lucide="captions" style="width:18px;height:18px;color:var(--accent);"></i>
                            </div>
                            <div class="drop-zone-text">
                                <div class="drop-zone-label" id="subtitle-label">Subtitle</div>
                                <div class="drop-zone-sub" id="subtitle-sub">Opsional · VTT / SRT (maks 2MB)</div>
                            </div>
                        </div>

                        <!-- Bahasa subtitle — custom dropdown ala books/read.php -->
                        <div class="field-group">
                            <label class="field-label" for="f-subtitle-lang-trigger">Bahasa Subtitle</label>
                            <div class="lang-dropdown" id="f-subtitle-lang-dropdown" data-name="subtitle_lang">
                                <button type="button" class="lang-trigger" id="f-subtitle-lang-trigger"
                                    aria-haspopup="listbox" aria-expanded="false">
                                    <span class="lang-trigger-label" id="f-subtitle-lang-label"><?= htmlspecialchars(lang_map()['id'] ?? 'Indonesia') ?></span>
                                    <i data-lucide="chevron-down" class="lang-trigger-chevron"></i>
                                </button>
                                <div class="lang-options hidden" role="listbox" aria-label="Pilih bahasa subtitle">
                                    <?php foreach (lang_map() as $_lang_code => $_lang_label): ?>
                                        <button type="button" class="lang-option<?= $_lang_code === 'id' ? ' active' : '' ?>"
                                            data-lang="<?= htmlspecialchars($_lang_code) ?>" role="option"
                                            aria-selected="<?= $_lang_code === 'id' ? 'true' : 'false' ?>"><?= htmlspecialchars($_lang_label) ?></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <input type="hidden" name="subtitle_lang" id="f-subtitle-lang" value="id">
                        </div>
                        <div style="font-size:9px;color:#455060;">SRT dikonversi otomatis ke VTT</div>
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
    <?php $scripts_root = '../'; include __DIR__ . '/../partials/scripts.php'; ?>
    <script src="../assets/js/admin/edit/shared/form.js?v=<?= filemtime('../assets/js/admin/edit/shared/form.js') ?>"></script>
    <script src="../assets/js/admin/edit/shared/thumbnail.js?v=<?= filemtime('../assets/js/admin/edit/shared/thumbnail.js') ?>"></script>
    <script src="../assets/js/admin/edit/shared/dragdrop.js?v=<?= filemtime('../assets/js/admin/edit/shared/dragdrop.js') ?>"></script>
    <script src="../assets/js/admin/edit/video.js?v=<?= filemtime('../assets/js/admin/edit/video.js') ?>"></script>
    <script src="../assets/js/shared/lang-dropdown.js?v=<?= filemtime('../assets/js/shared/lang-dropdown.js') ?>"></script>
    <script>
        <?php if ($status === "success"): ?>
            Swal.fire({
                title: 'Berhasil!',
                text: 'Detail video telah diperbarui.',
                icon: 'success',
                confirmButtonColor: '#ef4444',
                background: '#0e1118',
                color: '#fff'
            });
        <?php endif; ?>
    </script>
</body>

</html>
