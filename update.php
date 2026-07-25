<?php
require_once 'modules/core/helpers.php';
include 'auth/config.php';
require_once 'controllers/system/UpdateManager.php';

$um = new UpdateManager($conn);
$um->handle();

$flash        = $um->getFlash();
$sidebar_data = $um->getSidebarData();
$updates      = $um->getUpdates();

$is_logged_in = isset($_SESSION['user_id']);
$is_admin     = ($is_logged_in && isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="MEeL | Changelog">
    <meta property="og:description" content="Catatan perubahan sistem MEeL. Lihat update terbaru dan riwayat versi platform.">
    <meta property="og:image" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/assets/MEeL.png">
    <meta property="og:url" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <title>MEeL | Changelog</title>
    <link rel="icon" type="image/png" href="assets/MEeL.png">
    <link rel="manifest" href="assets/manifest.json">
    <link href="assets/css/tailwind.min.css" rel="stylesheet">
    <script src="assets/js/htmx.min.js"></script>
    <script src="assets/js/lucide.js"></script>
    <link rel="stylesheet" href="assets/css/up.css">
</head>

<body>
    <div class="wrap">

        <header class="masthead">
            <a href="index.php" class="masthead-logo" title="Beranda">
                <img src="assets/MEeL.png" alt="MEeL">
            </a>
            <div>
                <div class="masthead-title">CHANGELOG <span>&</span> UPDATES</div>
                <div class="masthead-sub">Catatan Perubahan Sistem MEeL</div>
            </div>
            <div class="masthead-meta">
                <div><?= date('d M Y') ?></div>
                <div>MEeL Platform</div>
                <div style="color:var(--orange)">RELEASE NOTES</div>
            </div>
        </header>

        <?php if ($is_admin): ?>
            <div class="admin-bar">
                <span class="admin-badge">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                    </svg>
                    Admin Mode
                </span>
                <button class="admin-btn" onclick="openModal('modal-add-update')">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2.5">
                        <line x1="12" y1="5" x2="12" y2="19" />
                        <line x1="5" y1="12" x2="19" y2="12" />
                    </svg>
                    Tambah Update
                </button>
                <button class="admin-btn" onclick="openModal('modal-edit-sidebar')">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2.5">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                    </svg>
                    Edit Sidebar
                </button>
            </div>
        <?php endif; ?>

        <div class="main-grid">

            <main>
                <?php if ($flash): ?>
                    <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-radius:12px;margin-bottom:18px;font-size:12px;font-weight:600;
                    <?= $flash['type'] === 'success'
                        ? 'background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.22);color:#22c55e;'
                        : 'background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.22);color:#ef4444;' ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <?php if ($flash['type'] === 'success'): ?>
                                <polyline points="20 6 9 17 4 12" />
                            <?php else: ?>
                                <circle cx="12" cy="12" r="10" />
                                <line x1="12" y1="8" x2="12" y2="12" />
                                <line x1="12" y1="16" x2="12.01" y2="16" />
                            <?php endif; ?>
                        </svg>
                        <?= htmlspecialchars($flash['msg']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($updates): ?>
                    <?php foreach ($updates as $row): ?>
                        <article class="entry">
                            <div class="entry-header">
                                <div style="display:flex;align-items:center;gap:.75rem">
                                    <span class="entry-version"><?= htmlspecialchars($row['version']) ?></span>
                                    <span class="entry-label">System Update</span>
                                </div>
                                <div style="display:flex;align-items:center;gap:1rem">
                                    <span class="entry-date"><?= date('d M Y', strtotime($row['created_at'])) ?></span>

                                    <?php if ($is_admin): ?>
                                        <div style="display:flex;align-items:center;gap:.5rem">
                                            <button onclick="openEditModal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>)" style="background:none;border:none;cursor:pointer;color:#60a5fa;" title="Edit Update">
                                                <i data-lucide="edit-2" style="width:14px;height:14px;"></i>
                                            </button>
                                            <form action="update.php" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus update versi <?= htmlspecialchars($row['version']) ?> ini?');" style="display:inline;">
                                                <input type="hidden" name="action" value="delete_update">
                                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <button type="submit" style="background:none;border:none;cursor:pointer;color:#ef4444;" title="Hapus Update">
                                                    <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="entry-body">
                                <?= nl2br(htmlspecialchars($row['content'])) ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty">Belum ada catatan update tersedia.</div>
                <?php endif; ?>
            </main>

            <aside class="sidebar">
                <div class="sidebar-card">
                    <div class="tab-nav">
                        <button class="tab-btn active-orange" id="btn-important" onclick="switchTab('important')">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10" />
                                <line x1="12" y1="8" x2="12" y2="12" />
                                <line x1="12" y1="16" x2="12.01" y2="16" />
                            </svg>
                            Penting
                        </button>
                        <button class="tab-btn" id="btn-announcement" onclick="switchTab('announcement')">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 8.5c0 2.485-4.477 4.5-10 4.5S2 10.985 2 8.5 6.477 4 12 4s10 2.015 10 4.5z" />
                                <path d="M2 8.5c0 4.694 3.582 8.757 8.5 9.5" />
                                <path d="m15 13-3 9" />
                            </svg>
                            Pengumuman
                        </button>
                    </div>

                    <div id="pane-important" class="tab-pane active">
                        <div class="tab-pane-label" style="color:var(--orange)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <circle cx="12" cy="12" r="10" />
                                <line x1="12" y1="8" x2="12" y2="12" />
                                <line x1="12" y1="16" x2="12.01" y2="16" />
                            </svg>
                            Informasi Penting
                        </div>
                        <?= $sidebar_data['important_content'] ?? '<span style="color:var(--muted);font-size:.75rem">Tidak ada konten penting.</span>' ?>
                    </div>

                    <div id="pane-announcement" class="tab-pane">
                        <div class="tab-pane-label" style="color:var(--blue)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="M22 8.5c0 2.485-4.477 4.5-10 4.5S2 10.985 2 8.5 6.477 4 12 4s10 2.015 10 4.5z" />
                                <path d="M2 8.5c0 4.694 3.582 8.757 8.5 9.5" />
                                <path d="m15 13-3 9" />
                            </svg>
                            Pengumuman
                        </div>
                        <?= $sidebar_data['announcement_content'] ?? '<span style="color:var(--muted);font-size:.75rem">Tidak ada pengumuman.</span>' ?>
                    </div>
                </div>

                <button class="check-btn" hx-get="api/check_update.php" hx-swap="outerHTML">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="23 4 23 10 17 10" />
                        <path d="M20.49 15a9 9 0 1 1-.08-4.49" />
                    </svg>
                    Cek Versi Terbaru
                </button>
            </aside>
        </div>
    </div>

    <?php if ($is_admin): ?>

        <div id="modal-add-update" class="modal-backdrop" onclick="handleBackdropClick(event, 'modal-add-update')">
            <div class="modal-box">
                <div class="modal-title">TAMBAH <span>UPDATE</span></div>
                <form action="update.php" method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
                        <div>
                            <label class="f-label">Versi</label>
                            <input type="text" name="version" class="f-input" placeholder="v1.4.0" required>
                        </div>
                        <div>
                            <label class="f-label">Tanggal</label>
                            <input type="text" class="f-input" value="<?= date('d M Y') ?>" disabled>
                            <input type="hidden" name="created_at" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div style="margin-bottom:1.5rem">
                        <label class="f-label">Catatan Perubahan</label>
                        <textarea name="content" class="f-textarea" rows="6" placeholder="- Fitur baru&#10;- Perbaikan bug&#10;- Peningkatan performa" required></textarea>
                    </div>
                    <div style="display:flex;gap:.75rem">
                        <button type="submit" class="btn-primary">PUBLIKASIKAN</button>
                        <button type="button" class="btn-ghost" onclick="closeModal('modal-add-update')">Batal</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="modal-edit-update" class="modal-backdrop" onclick="handleBackdropClick(event, 'modal-edit-update')">
            <div class="modal-box">
                <div class="modal-title">EDIT <span>UPDATE</span></div>
                <form action="update.php" method="POST">
                    <input type="hidden" name="action" value="edit_update">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="id" id="edit-id">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
                        <div>
                            <label class="f-label">Versi</label>
                            <input type="text" name="version" id="edit-version" class="f-input" required>
                        </div>
                        <div>
                            <label class="f-label">Tanggal</label>
                            <input type="date" name="created_at" id="edit-created-at" class="f-input" required>
                        </div>
                    </div>
                    <div style="margin-bottom:1.5rem">
                        <label class="f-label">Catatan Perubahan</label>
                        <textarea name="content" id="edit-content" class="f-textarea" rows="6" required></textarea>
                    </div>
                    <div style="display:flex;gap:.75rem">
                        <button type="submit" class="btn-primary">SIMPAN PERUBAHAN</button>
                        <button type="button" class="btn-ghost" onclick="closeModal('modal-edit-update')">Batal</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="modal-edit-sidebar" class="modal-backdrop" onclick="handleBackdropClick(event, 'modal-edit-sidebar')">
            <div class="modal-box">
                <div class="modal-title">EDIT <span>SIDEBAR</span></div>
                <form action="update.php" method="POST">
                    <input type="hidden" name="action" value="sidebar">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div style="margin-bottom:1rem">
                        <label class="f-label" style="color:var(--orange)">Konten Penting</label>
                        <textarea name="important" class="f-textarea" rows="4"><?= htmlspecialchars($sidebar_data['important_content'] ?? '') ?></textarea>
                    </div>
                    <div style="margin-bottom:1.5rem">
                        <label class="f-label" style="color:var(--blue)">Pengumuman</label>
                        <textarea name="announcement" class="f-textarea" rows="4"><?= htmlspecialchars($sidebar_data['announcement_content'] ?? '') ?></textarea>
                    </div>
                    <div style="display:flex;gap:.75rem">
                        <button type="submit" class="btn-primary">SIMPAN PERUBAHAN</button>
                        <button type="button" class="btn-ghost" onclick="closeModal('modal-edit-sidebar')">Batal</button>
                    </div>
                </form>
            </div>
        </div>

    <?php endif; ?>

    <script>
        lucide.createIcons();

        function openModal(id) {
            document.getElementById(id).classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('open');
            document.body.style.overflow = '';
        }

        function handleBackdropClick(e, id) {
            if (e.target === document.getElementById(id)) closeModal(id);
        }
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-backdrop.open').forEach(m => closeModal(m.id));
            }
        });

        function switchTab(t) {
            const panes = {
                important: document.getElementById('pane-important'),
                announcement: document.getElementById('pane-announcement')
            };
            const btns = {
                important: document.getElementById('btn-important'),
                announcement: document.getElementById('btn-announcement')
            };
            Object.entries(panes).forEach(([k, el]) => el.classList.toggle('active', k === t));
            btns.important.className = 'tab-btn' + (t === 'important' ? ' active-orange' : '');
            btns.announcement.className = 'tab-btn' + (t === 'announcement' ? ' active-blue' : '');
        }

        // Fungsi JavaScript Baru untuk Mengisi Data & Membuka Modal Edit Update
        function openEditModal(data) {
            // Helper untuk decode HTML entities (menghindari teks mentah &lt;script&gt; ter-double encode saat di-save ulang)
            const decodeHtml = (html) => {
                const txt = document.createElement("textarea");
                txt.innerHTML = html;
                return txt.value;
            };

            document.getElementById('edit-id').value = data.id;
            document.getElementById('edit-version').value = decodeHtml(data.version);
            document.getElementById('edit-content').value = decodeHtml(data.content);

            // Format tanggal agar sesuai dengan input type="date" (YYYY-MM-DD)
            if (data.created_at) {
                const dateOnly = data.created_at.split(' ')[0];
                document.getElementById('edit-created-at').value = dateOnly;
            }

            openModal('modal-edit-update');
        }
    </script>
    <?php include 'partials/footer.php'; ?>
</body>

</html>