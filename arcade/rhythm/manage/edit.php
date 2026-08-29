<?php
ini_set('display_errors', 0);ini_set('display_startup_errors', 0);error_reporting(E_ALL);
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


if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$edit_song = null;
$edit_beatmap = null;
$edit_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($edit_id > 0 && $is_logged_in) {
    $stmt = $conn->prepare("SELECT * FROM arcade_song WHERE id = ? AND (user_id = ? OR ? = 'admin')");
    $role = $is_admin ? 'admin' : '';
    $stmt->bind_param("iis", $edit_id, $user_id, $role);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_song = $result->fetch_assoc();
    $stmt->close();
    if ($edit_song) {
        if (!empty($edit_song['beatmap_path']) && file_exists(__DIR__ . '/../' . $edit_song['beatmap_path'])) {
            $edit_beatmap = json_decode(file_get_contents(__DIR__ . '/../' . $edit_song['beatmap_path']), true);
        }
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
  <link rel="icon" type="image/png" href="/MEeL/assets/MEeL.png">
  <link href="/MEeL/assets/css/font.css" rel="stylesheet">
  <link rel="stylesheet" href="/MEeL/arcade/rhythm/assets/css/editor.css">
</head>
<body>

  <?php if (!$is_logged_in): ?>
  <div class="auth-required">
    <div class="auth-card">
      <div class="auth-icon">🔒</div>
      <h2>Login Diperlukan</h2>
      <p>Anda harus login untuk membuat beatmap.</p>
      <a href="/MEeL/auth/login" class="btn btn-primary">Login</a>
      <a href="../manage/" class="btn btn-ghost">Kembali</a>
    </div>
  </div>
  <?php else: ?>

  <!-- ─── Navigation ─── -->
  <nav class="nav-bar">
    <a href="./" class="nav-back">← Kembali</a>
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
          <label>Zoom <span id="zoomPercent" style="color:var(--accent-cyan);font-family:var(--font-mono)">100%</span></label>
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

  <script src="/MEeL/assets/js/compatibilitas/sweetalert2.all.min.js"></script>
  <script src="/MEeL/assets/js/compatibilitas/script.min.js"></script>
  <script>
    const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
    const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
    const EDIT_SONG = <?= $edit_song ? json_encode($edit_song) : 'null' ?>;
    const EDIT_BEATMAP = <?= $edit_beatmap ? json_encode($edit_beatmap) : 'null' ?>;

    // Global notes reference — module updates this, upload reads from it
    window.__editorNotes = [];
  </script>

  <!-- Upload/Delete as regular scripts (not module) — guaranteed to load -->
  <script>
    function uploadBeatmap() {
      var form = document.getElementById('beatmapForm');
      if (!form) return;
      var formData = new FormData(form);

      var title = formData.get('title');
      if (!title || title.trim() === '') {
        if (typeof Swal !== 'undefined') Swal.fire({title:'Error',text:'Judul wajib diisi!',icon:'error',confirmButtonColor:'#f43f7a',background:'#0e1118',color:'#fff'});
        else alert('Judul wajib diisi!');
        return;
      }
      if (!formData.get('audio') || formData.get('audio').size === 0) {
        if (typeof Swal !== 'undefined') Swal.fire({title:'Error',text:'File audio wajib diupload!',icon:'error',confirmButtonColor:'#f43f7a',background:'#0e1118',color:'#fff'});
        else alert('File audio wajib diupload!');
        return;
      }
      var currentNotes = window.__editorNotes || [];
      if (currentNotes.length < 10) {
        if (typeof Swal !== 'undefined') Swal.fire({title:'Error',text:'Minimal 10 notes dalam beatmap! (saat ini: ' + currentNotes.length + ')',icon:'error',confirmButtonColor:'#f43f7a',background:'#0e1118',color:'#fff'});
        else alert('Minimal 10 notes! (saat ini: ' + currentNotes.length + ')');
        return;
      }

      currentNotes.sort(function(a,b){return a.t-b.t;});
      formData.set('beatmap_json', JSON.stringify({notes: currentNotes}));
      if (typeof EDIT_SONG !== 'undefined' && EDIT_SONG) {
        formData.set('song_id', EDIT_SONG.id);
      }

      var overlay = document.getElementById('uploadOverlay');
      overlay.classList.remove('hidden');
      document.getElementById('uploadStatus').textContent = 'Mengirim ke server...';

      var xhr = new XMLHttpRequest();
      xhr.open('POST', '/MEeL/arcade/rhythm/api/upload', true);
      xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
          var pct = Math.round(e.loaded / e.total * 100);
          document.getElementById('uploadProgress').style.width = pct + '%';
          document.getElementById('uploadStatus').textContent = 'Mengirim... ' + pct + '%';
        }
      };
      xhr.onload = function() {
        overlay.classList.add('hidden');
        try {
          var res = JSON.parse(xhr.responseText);
          if (res.success) {
            if (typeof Swal !== 'undefined') Swal.fire({title:'Berhasil!',text:'Beatmap berhasil diupload! \ud83c\udf89',icon:'success',timer:3000,confirmButtonColor:'#f43f7a',background:'#0e1118',color:'#fff'});
            setTimeout(function(){ window.location.reload(); }, 1500);
          } else {
            if (typeof Swal !== 'undefined') Swal.fire({title:'Error',text:res.error||'Upload gagal',icon:'error',confirmButtonColor:'#f43f7a',background:'#0e1118',color:'#fff'});
            else alert(res.error || 'Upload gagal');
          }
        } catch(ex) {
          if (typeof Swal !== 'undefined') Swal.fire({title:'Error',text:'Response tidak valid dari server (HTTP ' + xhr.status + ')',icon:'error',confirmButtonColor:'#f43f7a',background:'#0e1118',color:'#fff'});
          else alert('Response tidak valid (HTTP ' + xhr.status + ')');
        }
      };
      xhr.onerror = function() {
        overlay.classList.add('hidden');
        if (typeof Swal !== 'undefined') Swal.fire({title:'Error',text:'Koneksi gagal!',icon:'error',confirmButtonColor:'#f43f7a',background:'#0e1118',color:'#fff'});
        else alert('Koneksi gagal!');
      };
      xhr.send(formData);
    }

    function deleteSong(id) {
      if (!confirm('Hapus beatmap ini?')) return;
      var fd = new FormData();
      fd.append('song_id', id);
      fd.append('csrf_token', CSRF_TOKEN);
      fetch('/MEeL/arcade/rhythm/api/delete', {method:'POST', body:fd})
        .then(function(r){return r.json();})
        .then(function(res){
          if (res.success) { alert('Beatmap terhapus!'); setTimeout(function(){location.reload();}, 1000); }
          else alert(res.error || 'Gagal menghapus');
        })
        .catch(function(){ alert('Gagal menghapus beatmap'); });
    }
  </script>

  <!-- Editor module (canvas, draw, input, etc.) -->
  <script type="module" src="/MEeL/arcade/rhythm/assets/js/editor/main.js"></script>
  <?php endif; ?>
</body>
</html>
