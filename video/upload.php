<?php
require_once '../modules/core/helpers.php';
include '../auth/auth.php';
include '../modules/core/Uploader.php';
require_once '../modules/core/GarbageCollector.php';
require_once '../modules/media/MediaLibrary.php';
GarbageCollector::run();

set_time_limit(0);
$status        = "";
$user          = $_SESSION['username'];
$user_id       = $_SESSION['user_id'];
$alert_message = "";

// Ambil role user — via shared helper (cache otomatis per request)
$user_role = get_user_role($conn, $user_id);
$is_admin  = ($user_role === 'admin');

// Ambil jumlah upload user hari ini
$stmt_count = $conn->prepare("SELECT COUNT(*) AS c FROM video WHERE user_id = ? AND DATE(upload_date) = CURDATE()");
$stmt_count->bind_param("i", $user_id);
$stmt_count->execute();
$today_count = (int)$stmt_count->get_result()->fetch_assoc()['c'];

// Total semua upload user
$stmt_total = $conn->prepare("SELECT COUNT(*) AS c FROM video WHERE user_id = ?");
$stmt_total->bind_param("i", $user_id);
$stmt_total->execute();
$total_uploads = (int)$stmt_total->get_result()->fetch_assoc()['c'];

// Limit per hari
$daily_limit = $is_admin ? '∞' : '3';

$uploader = new Uploader($conn, $user_id, $user);

if (isset($_POST['upload'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('CSRF token tidak valid.');
    }
    $result = $uploader->processVideo($_POST, $_FILES, __DIR__ . "/");

    if ($result['status'] === 'success') {
        $status = "success";
        $today_count++;
        $total_uploads++;
        MediaLibrary::clearCountsCache();
        log_activity($conn, $user_id, 'upload_video', 'video', (int)($result['id'] ?? 0));
    } elseif (isset($result['alert']) && $result['alert'] == true) {
        $alert_message = $result['msg'];
    } else {
        die("<div style='color:red; padding:20px; background:#000;'><h2>$user, Error!</h2><p>{$result['msg']}</p></div>");
    }
}

// Cache-busting: pakai filemtime agar browser & SW selalu dapet versi terbaru.
// filemtime di-cache per request (static) agar tidak 1 stat syscall per aset.
$__v = function($f) {
    static $mtimeCache = [];
    $path = __DIR__ . '/../' . $f;
    if (!isset($mtimeCache[$path])) {
        $mtimeCache[$path] = @filemtime($path);
    }
    return '?v=' . $mtimeCache[$path];
};
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Upload video ke MEeL Video Library. Format video didukung: MP4, WEBM, MKV. Transcoding otomatis ke HLS.">
    <meta property="og:title" content="MEeL Video | Upload">
    <meta property="og:description" content="Upload video ke MEeL Video Library. Format: MP4, WEBM, MKV. Transcoding otomatis ke HLS.">
    <title>MEeL Video | Upload</title>
    <?php include '../partials/link.php'; ?>
    <link rel="stylesheet" href="../assets/css/video/main.css?v=<?= filemtime('../assets/css/video/main.css') ?>">
    <link rel="stylesheet" href="../assets/css/font.css?v=<?= filemtime('../assets/css/font.css') ?>">
    <link rel="stylesheet" href="../assets/css/shared/design-tokens.css?v=<?= filemtime('../assets/css/shared/design-tokens.css') ?>">
    <link rel="stylesheet" href="../assets/css/shared/upload-form.css?v=<?= filemtime('../assets/css/shared/upload-form.css') ?>">
    <link rel="stylesheet" href="../assets/css/video/upload/main.css?v=<?= filemtime('../assets/css/video/upload/main.css') ?>">
</head>

<body>
    <div class="page-wrap">

        <!-- Nav -->
        <nav class="top-nav">
            <a href="../index.php" class="nav-brand">MEeL<span>Video</span></a>
            <div class="nav-sep"></div>
            <a href="index.php" class="nav-crumb">Library</a>
            <span class="nav-chevron">›</span>
            <span class="nav-crumb-current">Upload</span>
            <?php if ($is_admin): ?>
                <span class="admin-badge"><i data-lucide="shield" style="width:10px;height:10px;"></i> Admin</span>
            <?php endif; ?>
        </nav>

        <div class="upload-layout">

            <!-- ── LEFT: Sidebar ── -->
            <aside class="sidebar-panel">

                <!-- Hero visual -->
                <div class="hero-icon">
                    <div class="hero-icon-ring">
                        <i data-lucide="clapperboard" style="width:28px;height:28px;color:var(--accent);"></i>
                    </div>
                    <div style="position:relative;z-index:1;text-align:center;">
                        <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:#e2e6ef;text-transform:uppercase;letter-spacing:.1em;">Upload Video</div>
                        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.16em;color:#455060;margin-top:3px;">MP4 · WEBM · MKV</div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-strip">
                    <div class="stat-chip">
                        <div class="stat-number"><?= $today_count ?></div>
                        <div class="stat-label">Hari Ini</div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-number"><?= $total_uploads ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-number" style="font-size:15px;"><?= $daily_limit ?></div>
                        <div class="stat-label">Limit/Hari</div>
                    </div>
                </div>

                <!-- Guide -->
                <div class="guide-list">
                    <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.16em;color:#455060;padding-left:2px;">Panduan Upload</div>
                    <div class="guide-item">
                        <div class="guide-icon"><i data-lucide="file-video" style="width:13px;height:13px;color:var(--accent);"></i></div>
                        <div>
                            <div class="guide-title">Format Video</div>
                            <div class="guide-desc">MP4, WEBM, atau MKV. Akan di-transcode otomatis ke HLS.</div>
                        </div>
                    </div>
                    <div class="guide-item">
                        <div class="guide-icon"><i data-lucide="image" style="width:13px;height:13px;color:var(--accent);"></i></div>
                        <div>
                            <div class="guide-title">Thumbnail</div>
                            <div class="guide-desc">Opsional. Jika tidak diupload, thumbnail digenerate otomatis dari frame video.</div>
                        </div>
                    </div>
                    <div class="guide-item">
                        <div class="guide-icon"><i data-lucide="clock" style="width:13px;height:13px;color:var(--accent);"></i></div>
                        <div>
                            <div class="guide-title">Proses Upload</div>
                            <div class="guide-desc">Video besar memerlukan waktu lebih lama. Jangan tutup tab saat proses berlangsung.</div>
                        </div>
                    </div>
                    <?php if ($is_admin): ?>
                        <div class="guide-item" style="border-color:var(--accent-border);background:var(--accent-dim);">
                            <div class="guide-icon"><i data-lucide="shield" style="width:13px;height:13px;color:var(--accent);"></i></div>
                            <div>
                                <div class="guide-title" style="color:var(--accent);">Mode Admin</div>
                                <div class="guide-desc">Tidak ada limit upload harian. Ukuran & durasi maksimum ditingkatkan.</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </aside>

            <!-- ── RIGHT: Form panel ── -->
            <section class="form-panel">
                <div class="form-header">
                    <div>
                        <h1 class="form-title">Halo, <span><?= htmlspecialchars($user) ?></span></h1>
                        <p class="form-subtitle">Tambahkan koleksi video ke library</p>
                    </div>
                    <i data-lucide="upload-cloud" style="width:36px;height:36px;color:var(--accent);opacity:.3;flex-shrink:0;margin-top:4px;"></i>
                </div>

                <?php if ($status === "success"): ?>
                    <div class="alert alert-success">
                        <i data-lucide="check-circle" style="width:15px;height:15px;flex-shrink:0;"></i>
                        Video berhasil diupload dan sedang diproses!
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" onsubmit="handleSubmit()" style="display:flex;flex-direction:column;gap:20px;flex:1;">
                    <?php if (isset($_SESSION['csrf_token'])): ?>
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                    <?php endif; ?>

                    <!-- Judul -->
                    <div class="field-group">
                        <label class="field-label" for="f-title">Judul Video</label>
                        <input type="text" id="f-title" name="title" required
                            placeholder="Masukkan judul video..."
                            class="field-input">
                    </div>

                    <!-- Deskripsi -->
                    <div class="field-group" style="flex:1;display:flex;flex-direction:column;">
                        <label class="field-label" for="f-desc">Deskripsi / Keterangan</label>
                        <textarea id="f-desc" name="description"
                            placeholder="Masukkan deskripsi video... (opsional)"
                            class="field-input" style="flex:1;min-height:100px;resize:none;"></textarea>
                    </div>

                    <div class="divider" style="margin:0;"></div>

                    <!-- Drop zones -->
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <label class="field-label">File & Thumbnail</label>
                        <div class="drop-grid">
                            <!-- Video file -->
                            <div class="drop-zone" id="video-zone">
                                <input type="file" name="video" accept=".mp4,.webm,.mkv" required
                                    id="video-input" onchange="handleVideoFile(this)" aria-label="Pilih atau drop file video (format: MP4, WEBM, MKV)">
                                <div class="drop-zone-icon">
                                    <i data-lucide="file-video" style="width:18px;height:18px;color:var(--accent);"></i>
                                </div>
                                <div class="drop-zone-label" id="video-label">Pilih / Drop Video</div>
                                <div class="drop-zone-sub">MP4 · WEBM · MKV</div>
                            </div>

                            <!-- Thumbnail -->
                            <div class="drop-zone" id="thumb-zone">
                                <input type="file" name="thumbnail" accept="image/*"
                                    id="thumb-input" onchange="handleThumbFile(this)" aria-label="Pilih atau drop file thumbnail (opsional)">
                                <img id="thumb-preview" class="thumb-mini" alt="preview">
                                <div class="drop-zone-icon" id="thumb-icon-wrap">
                                    <i data-lucide="image" style="width:18px;height:18px;color:#4a5568;"></i>
                                </div>
                                <div class="drop-zone-label" id="thumb-label">Thumbnail</div>
                                <div class="drop-zone-sub" id="thumb-sub">Opsional · Auto-generate</div>
                            </div>
                        </div>
                    </div>

                    <!-- Subtitle (opsional) -->
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <label class="field-label">Subtitle (Opsional)</label>
                        <!-- Subtitle file — drop zone memanjang satu baris penuh -->
                        <div class="drop-zone drop-zone-subtitle" id="subtitle-zone">
                            <input type="file" name="subtitle" accept=".vtt,.srt"
                                id="subtitle-input" onchange="handleSubtitleFile(this)" aria-label="Pilih atau drop file subtitle (format: VTT, SRT)">
                            <div class="drop-zone-icon">
                                <i data-lucide="captions" style="width:18px;height:18px;color:var(--accent);"></i>
                            </div>
                            <div class="drop-zone-text">
                                <div class="drop-zone-label" id="subtitle-label">Subtitle</div>
                                <div class="drop-zone-sub" id="subtitle-sub">Opsional · VTT / SRT</div>
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
                    </div>

                    <!-- Upload button -->
                    <div style="margin-top:auto;">
                        <button type="submit" name="upload" id="btn-upload" class="btn-primary">
                            <i data-lucide="upload" style="width:15px;height:15px;"></i>
                            Mulai Upload
                        </button>
                    </div>

                    <!-- Footer links -->
                    <div class="footer-links">
                        <a href="index.php" class="footer-link">Library</a>
                        <a href="../index.php" class="footer-link">Portal</a>
                        <a href="../music/upload.php" class="footer-link accent">Go to Music</a>
                        <a href="../upload_advanced.php" class="footer-link"
                            onclick="return meelAlertRedirect({ title:'Upload Lanjutan', text:'Anda dan Server memerlukan koneksi internet', icon:'info', redirectUrl:'../upload_advanced.php' })">
                            Upload Lanjutan
                        </a>
                    </div>
                </form>
            </section>

        </div>
        </main>

    </div>

    <?php include '../partials/footer.php'; ?>

    <!-- ── Upload Overlay ── -->
    <div id="upload-overlay">
        <div class="overlay-card">
            <div class="upload-ring">
                <div class="upload-ring-inner"></div>
            </div>
            <div style="width:100%;text-align:center;display:flex;flex-direction:column;gap:8px;">
                <div class="overlay-title">Mengupload Video...</div>
                <div class="overlay-filename" id="overlay-filename">Mempersiapkan file</div>
            </div>
            <div style="width:100%;display:flex;flex-direction:column;gap:8px;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div class="overlay-status" id="overlay-status">Mengirim ke server</div>
                    <div id="overlay-pct" style="font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:#e2e6ef;">0%</div>
                </div>
                <div class="progress-track">
                    <div class="progress-bar" id="progress-bar"></div>
                </div>
            </div>
            <div class="overlay-note">
                Jangan tutup atau refresh halaman ini.<br>
                Video besar memerlukan waktu lebih lama.
            </div>
        </div>
    </div>
    <script src="../assets/js/compatibilitas/sweetalert2.all.min.js"></script>
    <script src="../assets/js/compatibilitas/script.min.js"></script>
    <script src="../assets/js/shared/lang-dropdown.js<?= $__v('assets/js/shared/lang-dropdown.js') ?>"></script>
    <script src="../assets/js/shared/htmx-lucide.js<?= $__v('assets/js/shared/htmx-lucide.js') ?>"></script>
    <script>
        <?php if ($alert_message !== ""): ?>
            meelAlertRedirect({
                title: 'Upload Video',
                text: <?= json_encode($alert_message) ?>,
                icon: 'warning',
                redirectUrl: 'upload.php'
            });
        <?php endif; ?>

        <?php if ($status === "success"): ?>
            Swal.fire({
                title: 'Berhasil!',
                text: 'Video telah diupload dan sedang diproses.',
                icon: 'success',
                confirmButtonColor: '#ef4444',
                background: '#0e1118',
                color: '#fff'
            });
        <?php endif; ?>
    </script>
    <script src="../assets/js/shared/upload-progress.js<?= $__v('assets/js/shared/upload-progress.js') ?>"></script>
    <script src="../assets/js/video/upload/upload.js<?= $__v('assets/js/video/upload/upload.js') ?>"></script>
</body>

</html>