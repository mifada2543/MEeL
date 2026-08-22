<?php
ini_set('display_errors', 1);ini_set('display_startup_errors', 1);error_reporting(E_ALL);
/**
 * MEeL!Mania — Beatmap Editor
 * Visual editor for creating beatmaps
 */
require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../api/config.php';

$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? null;
$is_logged_in = $user_id !== null;
$is_admin = $is_logged_in && is_admin($conn);

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get user's existing songs for management
$user_songs = [];
if ($is_logged_in) {
    $stmt = $conn->prepare("SELECT id, title, artist, bpm, difficulty, note_count, play_count, created_at FROM arcade_song WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $user_songs[] = $row;
    }
    $stmt->close();
}

// Admin: get all songs for management
$all_songs = [];
if ($is_admin) {
    $result = $conn->query("SELECT s.id, s.title, s.artist, s.bpm, s.difficulty, s.note_count, s.play_count, s.created_at, u.username FROM arcade_song s LEFT JOIN users u ON s.user_id = u.id ORDER BY s.created_at DESC LIMIT 200");
    while ($row = $result->fetch_assoc()) {
        $all_songs[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
  <meta name="robots" content="noindex, nofollow">
  <title>MEeL!Mania — Beatmap Editor</title>
  <link rel="icon" type="image/png" href="../../assets/MEeL.png">
  <link href="../../assets/css/font.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/editor.css">
</head>
<body>

  <?php if (!$is_logged_in): ?>
  <div class="auth-required">
    <div class="auth-card">
      <div class="auth-icon">🔒</div>
      <h2>Login Diperlukan</h2>
      <p>Anda harus login untuk membuat beatmap.</p>
      <a href="../../auth/login.php" class="btn btn-primary">Login</a>
      <a href="../" class="btn btn-ghost">Kembali</a>
    </div>
  </div>
  <?php else: ?>

  <!-- ─── Navigation ─── -->
  <nav class="nav-bar">
    <a href="../" class="nav-back">← Kembali</a>
    <div class="nav-brand">
      <span class="brand-icon">♪</span>
      <span>Beatmap Editor</span>
      <?php if ($is_admin): ?>
        <span class="admin-badge">Admin</span>
      <?php endif; ?>
    </div>
    <div class="nav-actions">
      <span class="user-info">👤 <?= htmlspecialchars($username) ?></span>
    </div>
  </nav>

  <main class="editor-layout">

    <!-- ─── Left: Form ─── -->
    <aside class="editor-sidebar">
      <div class="sidebar-section">
        <h3>Metadata</h3>
        <form id="beatmapForm" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

          <div class="form-group">
            <label>Judul Lagu *</label>
            <input type="text" name="title" id="f-title" required maxlength="120" placeholder="Song Title...">
          </div>

          <div class="form-group">
            <label>Artis</label>
            <input type="text" name="artist" id="f-artist" placeholder="Artist Name...">
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>BPM *</label>
              <input type="number" name="bpm" id="f-bpm" required min="60" max="300" value="120">
            </div>
            <div class="form-group">
              <label>Difficulty *</label>
              <select name="difficulty" id="f-difficulty">
                <option value="1">1 - Easy</option>
                <option value="2" selected>2 - Normal</option>
                <option value="3">3 - Hard</option>
                <option value="4">4 - Expert</option>
                <option value="5">5 - Insane</option>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Warna 1</label>
              <input type="color" name="color_primary" id="f-color1" value="#ec4899">
            </div>
            <div class="form-group">
              <label>Warna 2</label>
              <input type="color" name="color_secondary" id="f-color2" value="#a855f7">
            </div>
          </div>

          <div class="form-group">
            <label>Audio File * <small>(MP3, OGG, OPUS, FLAC, WAV — max 5 menit)</small></label>
            <div class="file-drop" id="audio-drop">
              <input type="file" name="audio" id="f-audio" accept="audio/*" required>
              <div class="file-drop-label" id="audio-label">🎵 Pilih atau drag audio</div>
              <div class="file-drop-info" id="audio-info"></div>
            </div>
          </div>

          <div class="form-group">
            <label>Cover Art <small>(opsional)</small></label>
            <div class="file-drop" id="cover-drop">
              <input type="file" name="cover" id="f-cover" accept="image/*">
              <div class="file-drop-label" id="cover-label">🖼️ Pilih cover image</div>
              <img id="cover-preview" class="cover-preview" alt="preview">
            </div>
          </div>
        </form>
      </div>

      <div class="sidebar-section">
        <h3>Editor Controls</h3>
        <div class="editor-controls">
          <button id="btnPlayPause" class="btn btn-sm" onclick="togglePlayback()">▶ Play</button>
          <button id="btnStop" class="btn btn-sm" onclick="stopPlayback()">⏹ Stop</button>
          <button class="btn btn-sm" onclick="clearNotes()">🗑 Clear</button>
        </div>
        <div class="editor-stats">
          <div class="stat-item">
            <span>Notes:</span>
            <span id="noteCount">0</span>
          </div>
          <div class="stat-item">
            <span>Duration:</span>
            <span id="durationDisplay">0:00</span>
          </div>
          <div class="stat-item">
            <span>Position:</span>
            <span id="positionDisplay">0:00</span>
          </div>
        </div>
        <div class="form-group">
          <label>Zoom</label>
          <input type="range" id="zoomSlider" min="1" max="10" value="3" oninput="setZoom(this.value)">
        </div>
        <div class="form-group">
          <label>Snap to Beat</label>
          <select id="snapSelect" onchange="setSnap(this.value)">
            <option value="0">Off</option>
            <option value="4">1/4 Beat</option>
            <option value="8" selected>1/8 Beat</option>
            <option value="16">1/16 Beat</option>
          </select>
        </div>
      </div>

      <div class="sidebar-section">
        <h3>Note Info</h3>
        <div id="noteInfoPanel">
          <p class="empty-text">Klik note untuk melihat info</p>
        </div>
      </div>

      <div class="sidebar-section">
        <h3>Shortcuts</h3>
        <div class="editor-stats">
          <div class="stat-item"><span>Click</span><span>Tap note</span></div>
          <div class="stat-item"><span>Click + Drag</span><span>Hold note</span></div>
          <div class="stat-item"><span style="color:#fbbf24">G (pilih note)</span><span style="color:#fbbf24">Toggle gold ⭐</span></div>
          <div class="stat-item"><span>Delete</span><span>Hapus note</span></div>
          <div class="stat-item"><span>Right click</span><span>Hapus note</span></div>
          <div class="stat-item"><span>Ctrl+Z</span><span>Undo</span></div>
          <div class="stat-item"><span>Space</span><span>Play/Pause</span></div>
          <div class="stat-item"><span>↑ / ↓</span><span>Seek ±1s</span></div>
        </div>
        <div class="gold-hint">⭐ Klik note → tekan G → Gold note (3x skor!)</div>
      </div>

      <div class="sidebar-section">
        <button id="btnUpload" class="btn btn-primary btn-full" onclick="uploadBeatmap()" style="padding:12px;font-size:13px;">
          📤 Upload Beatmap
        </button>
      </div>
    </aside>

    <!-- ─── Center: Canvas Editor ─── -->
    <section class="editor-main">
      <div class="editor-canvas-wrap" id="canvasWrap">
        <canvas id="editorCanvas"></canvas>
        <!-- Audio element for playback -->
        <audio id="audioPlayer" preload="auto"></audio>
        <!-- Prompt overlay: upload audio first -->
        <div id="audioPromptOverlay" class="audio-prompt-overlay">
          <div class="audio-prompt-card">
            <div class="audio-prompt-icon">🎵</div>
            <h3>Pilih File Audio Dulu</h3>
            <p>Upload file audio (MP3, OGG, OPUS, FLAC, WAV) di sidebar kiri untuk mulai mengedit beatmap.</p>
            <div class="audio-prompt-formats">MP3 · OGG · OPUS · FLAC · WAV</div>
            <div class="audio-prompt-arrow">Upload music dulu</div>
          </div>
        </div>
      </div>
      <div class="editor-timeline">
        <div class="timeline-bar" id="timelineBar">
          <div class="timeline-progress" id="timelineProgress"></div>
          <div class="timeline-cursor" id="timelineCursor"></div>
        </div>
      </div>
    </section>

    <!-- ─── Right: My Songs / Management ─── -->
    <aside class="editor-sidebar right">
      <div class="sidebar-section">
        <h3>Lagu Saya (<?= count($user_songs) ?>)</h3>
        <div class="song-list" id="mySongs">
          <?php if (empty($user_songs)): ?>
            <p class="empty-text">Belum ada beatmap.</p>
          <?php else: ?>
            <?php foreach ($user_songs as $s): ?>
              <div class="song-item" data-id="<?= $s['id'] ?>">
                <div class="song-item-info">
                  <div class="song-item-title"><?= htmlspecialchars($s['title']) ?></div>
                  <div class="song-item-meta"><?= $s['bpm'] ?> BPM · <?= $s['note_count'] ?> notes · <?= $s['play_count'] ?> plays</div>
                </div>
                <button class="btn-icon btn-delete" onclick="deleteSong(<?= $s['id'] ?>)" title="Hapus">🗑</button>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($is_admin && !empty($all_songs)): ?>
      <div class="sidebar-section">
        <h3>Admin: Semua Song (<?= count($all_songs) ?>)</h3>
        <div class="song-list" id="allSongs">
          <?php foreach ($all_songs as $s): ?>
            <div class="song-item" data-id="<?= $s['id'] ?>">
              <div class="song-item-info">
                <div class="song-item-title"><?= htmlspecialchars($s['title']) ?></div>
                <div class="song-item-meta">by <?= htmlspecialchars($s['username'] ?? '?') ?> · <?= $s['bpm'] ?> BPM</div>
              </div>
              <button class="btn-icon btn-delete" onclick="deleteSong(<?= $s['id'] ?>)" title="Hapus (Admin)">🗑</button>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </aside>

  </main>

  <!-- Upload Progress Overlay -->
  <div id="uploadOverlay" class="overlay hidden">
    <div class="overlay-card">
      <div class="spinner"></div>
      <div class="overlay-title">Mengupload Beatmap...</div>
      <div class="overlay-status" id="uploadStatus">Mengirim ke server</div>
      <div class="progress-track">
        <div class="progress-bar" id="uploadProgress"></div>
      </div>
    </div>
  </div>

  <script src="../../assets/js/compatibilitas/sweetalert2.all.min.js"></script>
  <script src="../../assets/js/compatibilitas/script.min.js"></script>
  <script>
    const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
    const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
  </script>
  <script src="../assets/js/editor.js"></script>
  <?php endif; ?>
</body>
</html>
