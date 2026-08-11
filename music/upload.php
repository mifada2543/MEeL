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

$user_role = get_user_role($conn, $user_id);
$is_admin  = ($user_role === 'admin');

// Upload 1 jam terakhir — window sama dengan System::checkRateLimit
$hour_count = get_hourly_upload_count($conn, $user_id, 'music');

// Total upload
$total_uploads = get_total_upload_count($conn, $user_id, 'music');

// Limit upload per jam — konsisten dengan enforcement (member 2x lipat)
$hourly_limit = $is_admin ? '∞' : get_upload_hourly_limit($user_role);

$uploader = new Uploader($conn, $user_id, $user);

if (isset($_POST['upload'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('CSRF token tidak valid.');
    }
    $result = $uploader->processMusic($_POST, $_FILES, __DIR__ . "/");

    if ($result['status'] === 'success') {
        $status = "success";
        $hour_count++;
        $total_uploads++;
        MediaLibrary::clearCountsCache();
        log_activity($conn, $user_id, 'upload_music', 'music', (int)($result['id'] ?? 0));
    } elseif (isset($result['alert']) && $result['alert'] == true) {
        $alert_message = $result['msg'];
    } else {
        die("<div style='color:red;'>Error: {$result['msg']}</div>");
    }
}

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
    <meta name="description" content="Upload musik ke MEeL Music Library. Format audio didukung: FLAC, MP3, WAV, OPUS, OGG, M4A.">
    <meta property="og:title" content="Upload | MEeL Music">
    <meta property="og:description" content="Upload musik ke MEeL Music Library. Format audio: FLAC, MP3, WAV, OPUS, OGG, M4A.">
    <title>Upload | MEeL Music</title>
    <?php include '../partials/link.php'; ?>
    <?php foreach (require __DIR__ . '/../assets/css/music/manifest.php' as $__f): ?>
    <link rel="stylesheet" href="../assets/css/music/<?= $__f ?><?= $__v('assets/css/music/' . $__f) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="../assets/css/font.css?v=<?= filemtime('../assets/css/font.css') ?>">
    <link rel="stylesheet" href="../assets/css/shared/design-tokens.css?v=<?= filemtime('../assets/css/shared/design-tokens.css') ?>">
    <link rel="stylesheet" href="../assets/css/shared/upload-form.css?v=<?= filemtime('../assets/css/shared/upload-form.css') ?>">
    <link rel="stylesheet" href="../assets/css/music/upload/main.css?v=<?= filemtime('../assets/css/music/upload/main.css') ?>">
</head>

<body>
    <div class="page-wrap">

        <!-- Nav -->
        <nav class="top-nav">
            <a href="../index.php" class="nav-brand">MEeL<span>Music</span></a>
            <div class="nav-sep"></div>
            <a href="index.php" class="nav-crumb">Library</a>
            <span class="nav-chevron">›</span>
            <span class="nav-crumb-current">Upload</span>
            <?php if ($is_admin): ?>
                <span class="admin-badge"><i data-lucide="shield" style="width:10px;height:10px;"></i> Admin</span>
            <?php endif; ?>
        </nav>

        <main>
            <div class="upload-layout">

            <!-- ── LEFT: Sidebar ── -->
            <aside class="sidebar-panel">

                <!-- Hero waveform -->
                <div class="hero-waveform">
                    <div class="waveform-bars">
                        <span></span><span></span><span></span><span></span>
                        <span></span><span></span><span></span><span></span>
                        <span></span><span></span><span></span><span></span>
                    </div>
                    <div style="position:relative;z-index:1;text-align:center;">
                        <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:#e2e6ef;text-transform:uppercase;letter-spacing:.1em;">Upload Musik</div>
                        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.16em;color:#cbd5e1;margin-top:3px;">FLAC · MP3 · WAV · OPUS</div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-strip">
                    <div class="stat-chip">
                        <div class="stat-number"><?= $hour_count ?></div>
                        <div class="stat-label">Jam Ini</div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-number"><?= $total_uploads ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-number" style="font-size:15px;"><?= $hourly_limit ?></div>
                        <div class="stat-label">Limit/Jam</div>
                    </div>
                </div>

                <!-- Guide -->
                <div class="guide-list">
                    <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.16em;color:#455060;padding-left:2px;">Panduan Upload</div>
                    <div class="guide-item">
                        <div class="guide-icon"><i data-lucide="file-audio" style="width:13px;height:13px;color:var(--accent);"></i></div>
                        <div>
                            <div class="guide-title">Format Audio</div>
                            <div class="guide-desc">FLAC, MP3, WAV, OPUS, OGG, atau M4A. Auto-transcode ke Opus untuk efisiensi.</div>
                        </div>
                    </div>
                    <div class="guide-item">
                        <div class="guide-icon"><i data-lucide="image" style="width:13px;height:13px;color:var(--accent);"></i></div>
                        <div>
                            <div class="guide-title">Cover Art</div>
                            <div class="guide-desc">Opsional. Jika tidak diupload, cover diambil dari metadata file audio (ID3/FLAC).</div>
                        </div>
                    </div>
                    <div class="guide-item">
                        <div class="guide-icon"><i data-lucide="clock" style="width:13px;height:13px;color:var(--accent);"></i></div>
                        <div>
                            <div class="guide-title">Durasi Maks.</div>
                            <div class="guide-desc"><?= $is_admin ? 'Admin: tidak terbatas durasi.' : 'User: maksimal 5 menit (300 detik).' ?></div>
                        </div>
                    </div>
                    <?php if ($is_admin): ?>
                        <div class="guide-item" style="border-color:var(--accent-border);background:var(--accent-dim);">
                            <div class="guide-icon"><i data-lucide="shield" style="width:13px;height:13px;color:var(--accent);"></i></div>
                            <div>
                                <div class="guide-title" style="color:var(--accent);">Mode Admin</div>
                                <div class="guide-desc">Akses Anti Transcode tersedia. Simpan file audio asli tanpa kompresi Opus.</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </aside>

            <!-- ── RIGHT: Form panel ── -->
            <section class="form-panel">
                <div class="form-header">
                    <div>
                        <h1 class="form-title">Add New <span>Track</span></h1>
                        <p class="form-subtitle">Tambahkan lagu ke music library</p>
                    </div>
                    <i data-lucide="music-2" style="width:36px;height:36px;color:var(--accent);opacity:.3;flex-shrink:0;margin-top:4px;"></i>
                </div>

                <?php if ($status === "success"): ?>
                    <div class="alert alert-success">
                        <i data-lucide="check-circle" style="width:15px;height:15px;flex-shrink:0;"></i>
                        Berhasil ditambahkan ke Music Library!
                    </div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data" onsubmit="handleSubmit()" style="display:flex;flex-direction:column;gap:20px;flex:1;">
                    <?php if (isset($_SESSION['csrf_token'])): ?>
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                    <?php endif; ?>
                    <!-- Judul + Auto-fill -->
                    <div class="field-group">
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <label class="field-label" for="f-title">Judul Lagu</label>
                            <button type="button" id="btn-auto-meta" class="btn-auto"
                                onclick="autoFillMetadata()"
                                title="Isi otomatis dari metadata file audio (ID3/FLAC)">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 4V2"/><path d="M15 16V8"/><path d="M9 10V2"/><path d="M9 22V16"/><path d="M12 10h.01"/><path d="M12 16h.01"/></svg>
                                Auto
                            </button>
                        </div>
                        <input type="text" id="f-title" name="title" required
                            placeholder="Song Title..."
                            class="field-input">
                    </div>

                    <!-- Artis & Album -->
                    <div class="two-col">
                        <div class="field-group">
                            <label class="field-label" for="f-artist">Artis</label>
                            <input type="text" id="f-artist" name="artist" required
                                placeholder="Artist..." class="field-input">
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="f-album">Album</label>
                            <input type="text" id="f-album" name="album"
                                placeholder="Album (Opsional)..." class="field-input">
                        </div>
                    </div>

                    <!-- Deskripsi -->
                    <div class="field-group" style="flex:1;display:flex;flex-direction:column;">
                        <label class="field-label" for="f-desc">Deskripsi / Keterangan</label>
                        <textarea id="f-desc" name="description"
                            placeholder="Masukkan deskripsi lagu... (opsional)"
                            class="field-input" style="flex:1;min-height:100px;resize:none;"></textarea>
                    </div>

                    <div class="divider" style="margin:0;"></div>

                    <!-- Drop zones -->
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <label class="field-label">File Audio & Cover Art</label>
                        <div class="drop-grid">
                            <!-- Audio file -->
                            <div class="drop-zone" id="audio-zone">
                                <input type="file" name="media" accept="audio/*" required
                                    id="audio-input" onchange="handleAudioFile(this)" aria-label="Pilih atau drop file audio untuk upload lagu">
                                <div class="drop-zone-icon">
                                    <i data-lucide="file-audio" style="width:18px;height:18px;color:var(--accent);"></i>
                                </div>
                                <div class="drop-zone-label" id="audio-label">Drag &amp; Drop Audio</div>
                                <div class="drop-zone-sub">FLAC · MP3 · WAV · OPUS</div>
                            </div>

                            <!-- Cover art -->
                            <div class="drop-zone" id="cover-zone">
                                <input type="file" name="thumbnail" accept="image/*"
                                    id="cover-input" onchange="handleCoverFile(this)" aria-label="Pilih atau drop cover art untuk lagu">
                                <img id="cover-preview" class="thumb-mini" alt="preview">
                                <div class="drop-zone-icon" id="cover-icon-wrap">
                                    <i data-lucide="image" style="width:18px;height:18px;color:#4a5568;"></i>
                                </div>
                                <div class="drop-zone-label" id="cover-label">Cover Art</div>
                                <div class="drop-zone-sub" id="cover-sub">Opsional · Auto dari ID3</div>
                            </div>
                        </div>
                    </div>

                    <?php if ($is_admin): ?>
                        <!-- Anti-transcode toggle (admin only) -->
                        <div class="toggle-card">
                            <div>
                                <div style="font-size:11px;font-weight:700;color:var(--accent);text-transform:uppercase;letter-spacing:.1em;">Anti Transcode</div>
                                <div style="font-size:10px;color:#cbd5e1;margin-top:2px;">Simpan file asli tanpa konversi ke Opus</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="skip_transcode">
                                <div class="toggle-track"></div>
                            </label>
                        </div>
                    <?php endif; ?>
                    <!-- Upload button -->
                    <div style="margin-top:auto;">
                        <button type="submit" name="upload" id="btn-upload" class="btn-primary">
                            <i data-lucide="upload" style="width:15px;height:15px;"></i>
                            Save to MEeL Music
                        </button>
                    </div>

                    <!-- Footer links -->
                    <div class="footer-links">
                        <a href="index.php" class="footer-link">Library</a>
                        <a href="../index.php" class="footer-link">Portal</a>
                        <a href="../video/upload.php" class="footer-link accent">Go to Video</a>
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
            <div class="upload-wave">
                <span></span><span></span><span></span><span></span><span></span>
                <span></span><span></span><span></span><span></span><span></span>
            </div>
            <div style="width:100%;text-align:center;display:flex;flex-direction:column;gap:8px;">
                <div class="overlay-title">Mengupload Musik...</div>
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
                File FLAC besar memerlukan waktu lebih lama.
            </div>
        </div>
    </div>
    <script src="../assets/js/compatibilitas/sweetalert2.all.min.js"></script>
    <script src="../assets/js/compatibilitas/script.min.js"></script>
    <script src="../assets/js/shared/htmx-lucide.js<?= $__v('assets/js/shared/htmx-lucide.js') ?>"></script>
    <script>
        <?php if ($alert_message !== ""): ?>
            meelAlertRedirect({
                title: 'Upload Music',
                text: <?= json_encode($alert_message) ?>,
                icon: 'warning',
                redirectUrl: 'upload.php'
            });
        <?php endif; ?>
        <?php if ($status === "success"): ?>
            Swal.fire({
                title: 'Berhasil!',
                text: 'Lagu berhasil ditambahkan ke Music Library.',
                icon: 'success',
                confirmButtonColor: '#f97316',
                background: '#0e1118',
                color: '#fff'
            });
        <?php endif; ?>
    </script>
    <script src="../assets/js/shared/upload-progress.js<?= $__v('assets/js/shared/upload-progress.js') ?>"></script>
    <script src="../assets/js/music/upload/upload.js<?= $__v('assets/js/music/upload/upload.js') ?>"></script>
</body>

</html>
