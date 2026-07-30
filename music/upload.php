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

// Upload hari ini
$stmt_count = $conn->prepare("SELECT COUNT(*) AS c FROM music WHERE user_id = ? AND DATE(upload_date) = CURDATE()");
$stmt_count->bind_param("i", $user_id);
$stmt_count->execute();
$today_count = (int)$stmt_count->get_result()->fetch_assoc()['c'];

// Total upload
$stmt_total = $conn->prepare("SELECT COUNT(*) AS c FROM music WHERE user_id = ?");
$stmt_total->bind_param("i", $user_id);
$stmt_total->execute();
$total_uploads = (int)$stmt_total->get_result()->fetch_assoc()['c'];

$daily_limit = $is_admin ? '∞' : '5';

$uploader = new Uploader($conn, $user_id, $user);

if (isset($_POST['upload'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('CSRF token tidak valid.');
    }
    $result = $uploader->processMusic($_POST, $_FILES, __DIR__ . "/");

    if ($result['status'] === 'success') {
        $status = "success";
        $today_count++;
        $total_uploads++;
        MediaLibrary::clearCountsCache();
        log_activity($conn, $user_id, 'upload_music', 'music', (int)($result['id'] ?? 0));
    } elseif (isset($result['alert']) && $result['alert'] == true) {
        $alert_message = $result['msg'];
    } else {
        die("<div style='color:red;'>Error: {$result['msg']}</div>");
    }
}
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
    <link rel="stylesheet" href="../assets/css/music/main.css">
    <link rel="stylesheet" href="../assets/css/music/upload/main.css">
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
    <script>
        lucide.createIcons();

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

        function handleAudioFile(input) {
            if (!input.files || !input.files[0]) return;
            const zone = document.getElementById('audio-zone');
            const label = document.getElementById('audio-label');
            label.textContent = input.files[0].name;
            zone.classList.add('has-file');
        }

        function handleCoverFile(input) {
            if (!input.files || !input.files[0]) return;
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('cover-preview');
                const iconWrap = document.getElementById('cover-icon-wrap');
                const label = document.getElementById('cover-label');
                const sub = document.getElementById('cover-sub');
                const zone = document.getElementById('cover-zone');
                preview.src = e.target.result;
                preview.style.display = 'block';
                iconWrap.style.display = 'none';
                label.textContent = input.files[0].name;
                sub.textContent = '';
                zone.classList.add('has-file');
            };
            reader.readAsDataURL(input.files[0]);
        }

        function handleSubmit() {
            const audioInput = document.getElementById('audio-input');
            const titleInput = document.getElementById('f-title');
            const overlay = document.getElementById('upload-overlay');
            const fname = document.getElementById('overlay-filename');
            const status = document.getElementById('overlay-status');
            const bar = document.getElementById('progress-bar');
            const pct = document.getElementById('overlay-pct');
            const btn = document.getElementById('btn-upload');

            // Tampilkan nama file
            if (audioInput.files[0]) {
                fname.textContent = audioInput.files[0].name;
            } else if (titleInput.value) {
                fname.textContent = titleInput.value;
            }

            btn.style.opacity = '.5';
            btn.style.pointerEvents = 'none';
            overlay.classList.add('active');

            // Fase animasi — estimasi berdasarkan ukuran file
            const fileSizeMB = audioInput.files[0] ? audioInput.files[0].size / 1024 / 1024 : 20;
            const baseDelay = Math.max(2000, Math.min(fileSizeMB * 200, 18000)); // 2s–18s
            const phases = [{
                    msg: 'Mengirim file ke server…',
                    pctVal: 8
                },
                {
                    msg: 'Memproses audio…',
                    pctVal: 35
                },
                {
                    msg: 'Transcode ke Opus…',
                    pctVal: 65
                },
                {
                    msg: 'Menyimpan ke library…',
                    pctVal: 88
                },
            ];
            const phaseDelay = baseDelay / phases.length;
            let phaseIdx = 0;

            function advancePhase() {
                if (phaseIdx >= phases.length) return;
                const p = phases[phaseIdx];
                status.textContent = p.msg;
                bar.style.width = p.pctVal + '%';
                pct.textContent = p.pctVal + '%';
                phaseIdx++;
                if (phaseIdx < phases.length) setTimeout(advancePhase, phaseDelay);
            }

            advancePhase();
            // Form submit biasa — PHP proses & redirect sendiri
        }

        document.querySelector('form').addEventListener('submit', function() {
            handleSubmit();
        });

        // Drag-and-drop audio
        const audioZone = document.getElementById('audio-zone');
        const audioInput = document.getElementById('audio-input');
        audioZone.addEventListener('dragover', e => {
            e.preventDefault();
            audioZone.classList.add('drag-over');
        });
        audioZone.addEventListener('dragleave', () => audioZone.classList.remove('drag-over'));
        audioZone.addEventListener('drop', e => {
            e.preventDefault();
            audioZone.classList.remove('drag-over');
            const files = e.dataTransfer.files;
            if (files[0]) {
                const dt = new DataTransfer();
                dt.items.add(files[0]);
                audioInput.files = dt.files;
                handleAudioFile(audioInput);
            }
        });

        // Drag-and-drop cover
        const coverZone = document.getElementById('cover-zone');
        const coverInput = document.getElementById('cover-input');
        coverZone.addEventListener('dragover', e => {
            e.preventDefault();
            coverZone.classList.add('drag-over');
        });
        coverZone.addEventListener('dragleave', () => coverZone.classList.remove('drag-over'));
        coverZone.addEventListener('drop', e => {
            e.preventDefault();
            coverZone.classList.remove('drag-over');
            const files = e.dataTransfer.files;
            if (files[0] && files[0].type.startsWith('image/')) {
                const dt = new DataTransfer();
                dt.items.add(files[0]);
                coverInput.files = dt.files;
                handleCoverFile(coverInput);
            }
        });

        /**
         * Auto-fill metadata dari file audio via ffprobe di server.
         * Upload file ke auto_metadata.php → parse response → isi form + cover.
         */
        function autoFillMetadata() {
            const audioInput = document.getElementById('audio-input');
            if (!audioInput.files || !audioInput.files[0]) {
                Swal.fire({
                    title: 'Pilih file dulu!',
                    text: 'Silakan pilih file audio terlebih dahulu sebelum menggunakan Auto-fill.',
                    icon: 'warning',
                    confirmButtonColor: '#f97316',
                    background: '#0e1118',
                    color: '#fff'
                });
                return;
            }

            const btn = document.getElementById('btn-auto-meta');
            btn.disabled = true;
            btn.innerHTML = '<div class="auto-spinner"></div> Memproses...';

            const formData = new FormData();
            formData.append('audio', audioInput.files[0]);

            fetch('../controllers/api/auto_metadata.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    // Isi field text
                    const hasTitle  = data.title  && data.title.trim() !== '';
                    const hasArtist = data.artist && data.artist.trim() !== '';
                    const hasAlbum  = data.album  && data.album.trim() !== '';

                    if (hasTitle)  document.getElementById('f-title').value   = data.title.trim();
                    if (hasArtist) document.getElementById('f-artist').value  = data.artist.trim();
                    if (hasAlbum)  document.getElementById('f-album').value   = data.album.trim();

                    // Isi cover art dari metadata
                    if (data.cover && data.cover.length > 0) {
                        const preview = document.getElementById('cover-preview');
                        const iconWrap = document.getElementById('cover-icon-wrap');
                        const label = document.getElementById('cover-label');
                        const sub = document.getElementById('cover-sub');
                        const zone = document.getElementById('cover-zone');
                        preview.src = 'data:image/jpeg;base64,' + data.cover;
                        preview.style.display = 'block';
                        iconWrap.style.display = 'none';
                        label.textContent = 'Cover dari metadata';
                        sub.textContent = '';
                        zone.classList.add('has-file');
                    }

                    if (!hasTitle && !hasArtist && !hasAlbum && !data.cover) {
                        Swal.fire({
                            title: 'Metadata tidak ditemukan',
                            text: 'File ini tidak memiliki metadata ID3/FLAC yang bisa dibaca.',
                            icon: 'info',
                            confirmButtonColor: '#f97316',
                            background: '#0e1118',
                            color: '#fff'
                        });
                    } else {
                        Swal.fire({
                            title: 'Metadata ditemukan!',
                            text: 'Formulir telah diisi otomatis dari metadata file audio.',
                            icon: 'success',
                            confirmButtonColor: '#f97316',
                            background: '#0e1118',
                            color: '#fff',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    }
                } else {
                    Swal.fire({
                        title: 'Gagal',
                        text: data.message || 'Tidak dapat membaca metadata dari file ini.',
                        icon: 'error',
                        confirmButtonColor: '#f97316',
                        background: '#0e1118',
                        color: '#fff'
                    });
                }
            })
            .catch(err => {
                console.error('Auto-metadata error:', err);
                Swal.fire({
                    title: 'Error',
                    text: 'Terjadi kesalahan koneksi saat memproses metadata.',
                    icon: 'error',
                    confirmButtonColor: '#f97316',
                    background: '#0e1118',
                    color: '#fff'
                });
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 4V2"/><path d="M15 16V8"/><path d="M9 10V2"/><path d="M9 22V16"/><path d="M12 10h.01"/><path d="M12 16h.01"/></svg> Auto';
            });
        }

        // Keyframe @keyframes spin sekarang ada di upload/main.css
    </script>
</body>

</html>