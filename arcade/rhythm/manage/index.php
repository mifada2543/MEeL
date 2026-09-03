<?php
ini_set('display_errors', 0); ini_set('display_startup_errors', 0); error_reporting(E_ALL);


require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../api/config.php';

$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? null;
$is_logged_in = $user_id !== null;
$is_admin = $is_logged_in && is_admin($conn);

$user_songs = [];
if ($is_logged_in) {
    $stmt = $conn->prepare("SELECT id, title, artist, bpm, difficulty, difficulty_label, duration, note_count, audio_file, cover_file, play_count, created_at, updated_at FROM arcade_song WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $user_songs[] = $row;
    }
    $stmt->close();
}

$all_songs = [];
if ($is_admin) {
    $result = $conn->query("SELECT s.*, u.username FROM arcade_song s LEFT JOIN users u ON s.user_id = u.id ORDER BY s.created_at DESC LIMIT 500");
    while ($row = $result->fetch_assoc()) {
        $all_songs[] = $row;
    }
}

$total_plays = 0;
$total_notes = 0;
foreach ($user_songs as $s) {
    $total_plays += $s['play_count'];
    $total_notes += $s['note_count'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>MEeL!Mania — Manage Beatmaps</title>
  <link rel="icon" type="image/png" href="/MEeL/assets/MEeL.png">
  <link href="/MEeL/assets/css/font.css" rel="stylesheet">
  <link rel="stylesheet" href="/MEeL/arcade/rhythm/assets/css/editor.css">
  <style>
    .manage-layout {
      max-width: 1000px;
      margin: 0 auto;
      padding: 24px 20px;
    }
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 12px;
      margin-bottom: 28px;
    }
    .stat-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 16px;
      text-align: center;
    }
    .stat-card .stat-num {
      font-size: 28px;
      font-weight: 800;
      background: linear-gradient(135deg, var(--accent), var(--accent2));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .stat-card .stat-label {
      font-size: 11px;
      color: var(--text3);
      text-transform: uppercase;
      letter-spacing: 1px;
      margin-top: 4px;
    }
    .section-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
    }
    .section-header h2 {
      font-size: 18px;
      font-weight: 700;
    }
    .beatmap-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 10px;
    }
    .beatmap-card {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 14px 16px;
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 12px;
      transition: all 0.2s;
    }
    .beatmap-card:hover {
      border-color: var(--border-hover);
      background: var(--bg-hover);
    }
    .bm-cover {
      width: 52px;
      height: 52px;
      border-radius: 10px;
      object-fit: cover;
      flex-shrink: 0;
      background: var(--bg-surface);
    }
    .bm-info {
      flex: 1;
      min-width: 0;
    }
    .bm-title {
      font-size: 14px;
      font-weight: 700;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .bm-meta {
      font-size: 11px;
      color: var(--text3);
      font-family: var(--mono);
      margin-top: 2px;
    }
    .bm-tags {
      display: flex;
      gap: 6px;
      margin-top: 6px;
      flex-wrap: wrap;
    }
    .bm-tag {
      font-size: 10px;
      padding: 2px 8px;
      border-radius: 20px;
      font-weight: 600;
    }
    .bm-tag.diff {
      background: rgba(244,63,122,0.12);
      color: var(--accent);
    }
    .bm-tag.plays {
      background: rgba(99,102,241,0.12);
      color: var(--accent3);
    }
    .bm-tag.notes {
      background: rgba(168,85,247,0.12);
      color: var(--accent2);
    }
    .bm-actions {
      display: flex;
      gap: 6px;
      flex-shrink: 0;
    }
    .bm-actions .btn { font-size: 11px; padding: 6px 12px; }
    .btn-edit {
      background: linear-gradient(135deg, var(--accent3), var(--accent2));
      border: none;
      color: #fff;
    }
    .btn-edit:hover { box-shadow: 0 2px 10px rgba(99,102,241,0.3); }
    .btn-delete {
      background: transparent;
      border: 1px solid rgba(239,68,68,0.3);
      color: var(--danger);
    }
    .btn-delete:hover { background: rgba(239,68,68,0.1); }
    .empty-state {
      text-align: center;
      padding: 48px 20px;
      color: var(--text3);
    }
    .empty-state .empty-icon { font-size: 48px; margin-bottom: 12px; }
    .empty-state p { font-size: 14px; margin-bottom: 16px; }
    .detail-modal {
      position: fixed; inset: 0; z-index: 200;
      display: none; align-items: center; justify-content: center;
      background: rgba(6,6,14,0.85); backdrop-filter: blur(8px);
    }
    .detail-modal.active { display: flex; }
    .detail-card {
      background: var(--bg-card); border: 1px solid var(--border);
      border-radius: 16px; padding: 28px; max-width: 420px; width: 90%;
    }
    .detail-card h3 { font-size: 18px; font-weight: 700; margin-bottom: 12px; }
    .detail-row {
      display: flex; justify-content: space-between; padding: 8px 0;
      border-bottom: 1px solid var(--border); font-size: 13px;
    }
    .detail-row span:first-child { color: var(--text3); }
    .detail-row span:last-child { font-family: var(--mono); font-weight: 600; }
  </style>
</head>
<body>
  <?php if (!$is_logged_in): ?>
  <div class="auth-required">
    <div class="auth-card">
      <div class="auth-icon">🔒</div>
      <h2>Login Diperlukan</h2>
      <p>Anda harus login untuk mengelola beatmap.</p>
      <a href="../../auth/login.php" class="btn btn-primary">Login</a>
      <a href="../" class="btn btn-ghost">Kembali</a>
    </div>
  </div>
  <?php else: ?>

  <nav class="nav-bar">
    <a href="../" class="nav-back">← Kembali ke Lobby</a>
    <div class="nav-brand">
      <span class="brand-icon">♫</span>
      <span>Manage Beatmaps</span>
    </div>
    <div class="nav-actions">
      <a href="edit" class="btn btn-primary btn-sm">+ Buat Baru</a>
      <span class="user-info">👤 <?= htmlspecialchars($username) ?></span>
    </div>
  </nav>

  <div class="manage-layout">
    
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-num"><?= count($user_songs) ?></div>
        <div class="stat-label">Beatmaps</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= number_format($total_notes) ?></div>
        <div class="stat-label">Total Notes</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= number_format($total_plays) ?></div>
        <div class="stat-label">Total Plays</div>
      </div>
      <?php if ($is_admin): ?>
      <div class="stat-card">
        <div class="stat-num"><?= count($all_songs) ?></div>
        <div class="stat-label">Semua Song</div>
      </div>
      <?php endif; ?>
    </div>

    
    <div class="section-header">
      <h2>Beatmap Saya (<?= count($user_songs) ?>)</h2>
      <a href="edit" class="btn btn-primary btn-sm">+ Buat Baru</a>
    </div>

    <?php if (empty($user_songs)): ?>
    <div class="empty-state">
      <div class="empty-icon">🎵</div>
      <p>Belum ada beatmap. Mulai buat yang pertama!</p>
      <a href="edit" class="btn btn-primary">Buat Beatmap Pertama</a>
    </div>
    <?php else: ?>
    <div class="beatmap-grid" id="mySongs">
      <?php foreach ($user_songs as $s): ?>
      <div class="beatmap-card" data-id="<?= $s['id'] ?>">
        <div class="bm-cover" style="background:linear-gradient(135deg,<?= htmlspecialchars($s['color_primary'], ENT_QUOTES, 'UTF-8') ?>,<?= htmlspecialchars($s['color_secondary'], ENT_QUOTES, 'UTF-8') ?>);display:flex;align-items:center;justify-content:center;font-size:22px;">
          ♫
        </div>
        <div class="bm-info">
          <div class="bm-title"><?= htmlspecialchars($s['title']) ?></div>
          <div class="bm-meta"><?= htmlspecialchars($s['artist'] ?: 'Unknown') ?> · <?= (int)$s['bpm'] ?> BPM</div>
          <div class="bm-tags">
            <span class="bm-tag diff"><?= htmlspecialchars($s['difficulty_label'] ?: $s['difficulty'], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="bm-tag notes"><?= (int)$s['note_count'] ?> notes</span>
            <span class="bm-tag plays"><?= (int)$s['play_count'] ?> plays</span>
          </div>
        </div>
        <div class="bm-actions">
          <a href="edit?id=<?= $s['id'] ?>" class="btn btn-edit">✏️ Edit</a>
          <button class="btn" onclick="showDetail(<?= $s['id'] ?>)" title="Detail">ℹ️</button>
          <button class="btn btn-delete" onclick="deleteSong(<?= $s['id'] ?>)" title="Hapus">🗑</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($is_admin && !empty($all_songs)): ?>
    <div class="section-header" style="margin-top:32px;">
      <h2>🔧 Admin: Semua Song (<?= count($all_songs) ?>)</h2>
    </div>
    <div class="beatmap-grid">
      <?php foreach ($all_songs as $s): ?>
      <div class="beatmap-card" data-id="<?= $s['id'] ?>">
        <div class="bm-cover" style="background:linear-gradient(135deg,<?= htmlspecialchars($s['color_primary'], ENT_QUOTES, 'UTF-8') ?>,<?= htmlspecialchars($s['color_secondary'], ENT_QUOTES, 'UTF-8') ?>);display:flex;align-items:center;justify-content:center;font-size:22px;">
          ♫
        </div>
        <div class="bm-info">
          <div class="bm-title"><?= htmlspecialchars($s['title']) ?></div>
          <div class="bm-meta">by <?= htmlspecialchars($s['username'] ?? '?') ?> · <?= (int)$s['bpm'] ?> BPM · <?= (int)$s['note_count'] ?> notes</div>
          <div class="bm-tags">
            <span class="bm-tag diff"><?= htmlspecialchars($s['difficulty_label'] ?: $s['difficulty'], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="bm-tag plays"><?= (int)$s['play_count'] ?> plays</span>
          </div>
        </div>
        <div class="bm-actions">
          <a href="edit?id=<?= $s['id'] ?>" class="btn btn-edit">✏️ Edit</a>
          <button class="btn btn-delete" onclick="deleteSong(<?= $s['id'] ?>)" title="Hapus (Admin)">🗑</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  
  <div id="detailModal" class="detail-modal" onclick="if(event.target===this)this.classList.remove('active')">
    <div class="detail-card" id="detailContent"></div>
  </div>

  <script src="/MEeL/assets/js/compatibilitas/sweetalert2.all.min.js"></script>
  <script src="/MEeL/assets/js/compatibilitas/script.min.js"></script>
  <script>
    const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?? '' ?>';
    const SONGS_DATA = <?= json_encode(array_map(function($s) {
      return [
        'id' => $s['id'],
        'title' => $s['title'],
        'artist' => $s['artist'],
        'bpm' => $s['bpm'],
        'difficulty' => $s['difficulty'],
        'difficulty_label' => $s['difficulty_label'],
        'duration' => $s['duration'],
        'note_count' => $s['note_count'],
        'play_count' => $s['play_count'],
        'created_at' => $s['created_at'],
      ];
    }, $user_songs)) ?>;

    function showDetail(id) {
      var song = SONGS_DATA.find(function(s) { return s.id === id; });
      if (!song) return;
      var html = '<h3>' + song.title + '</h3>';
      html += '<div class="detail-row"><span>Artist</span><span>' + (song.artist || '-') + '</span></div>';
      html += '<div class="detail-row"><span>BPM</span><span>' + song.bpm + '</span></div>';
      html += '<div class="detail-row"><span>Difficulty</span><span>' + song.difficulty_label + '</span></div>';
      html += '<div class="detail-row"><span>Duration</span><span>' + song.duration + 's</span></div>';
      html += '<div class="detail-row"><span>Notes</span><span>' + song.note_count + '</span></div>';
      html += '<div class="detail-row"><span>Plays</span><span>' + song.play_count + '</span></div>';
      html += '<div class="detail-row"><span>Created</span><span>' + (song.created_at || '-') + '</span></div>';
      html += '<div style="margin-top:16px;display:flex;gap:8px;">';
      html += '<a href="edit?id=' + song.id + '" class="btn btn-edit" style="flex:1;">✏️ Edit</a>';
      html += '<button class="btn btn-delete" onclick="deleteSong(' + song.id + ')" style="flex:1;">🗑 Hapus</button>';
      html += '<button class="btn" onclick="document.getElementById(\'detailModal\').classList.remove(\'active\')" style="flex:1;">Tutup</button>';
      html += '</div>';
      document.getElementById('detailContent').innerHTML = html;
      document.getElementById('detailModal').classList.add('active');
    }

    function deleteSong(id) {
      Swal.fire({
        title: 'Hapus Beatmap?',
        text: 'Tindakan ini tidak dapat dibatalkan.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#475569',
        confirmButtonText: 'Hapus',
        background: '#0e1118',
        color: '#fff',
      }).then(function(r) {
        if (!r.isConfirmed) return;
        var fd = new FormData();
        fd.append('song_id', id);
        fd.append('csrf_token', CSRF_TOKEN);
        fetch('../api/delete', { method: 'POST', body: fd })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            if (res.success) {
              Swal.fire({ title: 'Terhapus!', text: res.message, icon: 'success', background: '#0e1118', color: '#fff' })
                .then(function() { window.location.reload(); });
            } else {
              Swal.fire({ title: 'Error', text: res.error, icon: 'error', background: '#0e1118', color: '#fff' });
            }
          })
          .catch(function() {
            Swal.fire({ title: 'Error', text: 'Gagal menghapus', icon: 'error', background: '#0e1118', color: '#fff' });
          });
      });
    }
  </script>
  <?php endif; ?>
</body>
</html>
