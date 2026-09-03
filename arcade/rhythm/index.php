<?php


header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once __DIR__ . '/api/config.php';


if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

$is_logged_in = isset($_SESSION['user_id']);
$username = $_SESSION['username'] ?? null;
$user_role = $is_logged_in ? get_user_role($conn, (int)$_SESSION['user_id']) : null;
$is_admin = ($user_role === 'admin');

$builtin_songs = [];
$index_path = __DIR__ . '/songs/_index.json';
if (file_exists($index_path)) {
    $raw = file_get_contents($index_path);
    $builtin_data = json_decode($raw, true);
    if (is_array($builtin_data)) {
        foreach ($builtin_data as $s) {
            $builtin_songs[] = [
                'id' => $s['id'],
                'title' => $s['title'],
                'artist' => $s['artist'],
                'bpm' => $s['bpm'],
                'difficulty' => $s['difficulty'],
                'difficulty_label' => $s['difficultyLabel'] ?? 'Normal',
                'duration' => $s['duration'] ?? 60,
                'note_count' => $s['noteCount'] ?? 0,
                'color' => $s['color'] ?? ['#ec4899', '#a855f7'],
                'emoji' => $s['emoji'] ?? '♪',
                'type' => 'builtin',
                'cover_url' => 'songs/' . $s['id'] . '/cover.svg',
                'audio_url' => null,
                'user_id' => null,
                'username' => null,
                'play_count' => 0,
                'created_at' => null,
            ];
        }
    }
}

$custom_songs = [];
if ($conn && $conn->connect_errno === 0) {
    $result = $conn->query(
        "SELECT s.id, s.title, s.artist, s.bpm, s.difficulty, s.difficulty_label,
                s.duration, s.note_count, s.audio_file, s.cover_file,
                s.color_primary, s.color_secondary, s.play_count, s.created_at,
                s.user_id, u.username
         FROM arcade_song s
         LEFT JOIN users u ON s.user_id = u.id
         WHERE s.is_active = 1
         ORDER BY s.created_at DESC
         LIMIT 100"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $cover_url = $row['cover_file'] ? ('uploads/cover/' . $row['cover_file']) : null;
            $custom_songs[] = [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'artist' => $row['artist'],
                'bpm' => (int) $row['bpm'],
                'difficulty' => (int) $row['difficulty'],
                'difficulty_label' => $row['difficulty_label'],
                'duration' => (int) $row['duration'],
                'note_count' => (int) $row['note_count'],
                'color' => [$row['color_primary'], $row['color_secondary']],
                'emoji' => '🎵',
                'type' => 'custom',
                'cover_url' => $cover_url,
                'audio_url' => 'uploads/audio/' . $row['audio_file'],
                'user_id' => (int) $row['user_id'],
                'username' => $row['username'] ?? 'Unknown',
                'play_count' => (int) $row['play_count'],
                'created_at' => $row['created_at'],
            ];
        }
    }
}

$all_songs = array_merge($builtin_songs, $custom_songs);
$songs_json = json_encode($all_songs, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no" />
  <meta name="description" content="MEeL!Mania — Rhythm game 4-lane. Pilih lagu, atur kecepatan, dan mainkan!" />
  <meta property="og:title" content="MEeL!Mania — Rhythm Arcade" />
  <meta property="og:description" content="Rhythm game 4-lane terinspirasi osu!mania. Tangkap note, raih skor tertinggi!" />
  <meta property="og:image" content="/MEeL/assets/MEeL.png" />
  <meta name="twitter:card" content="summary_large_image" />
  <link rel="manifest" href="../assets/manifest.json" />
  <title>MEeL!Mania</title>
  <link rel="icon" type="image/png" href="/MEeL/assets/MEeL.png" />
  <link href="../assets/css/font.css" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/lobby.css?v=<?= filemtime(__DIR__ . '/assets/css/lobby.css') ?>" />
</head>
<body>

  <canvas id="bgCanvas"></canvas>
  <div class="bg-overlay"></div>

  <nav class="nav-bar">
    <a href="../" class="nav-back" title="Kembali ke Arcade">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
    </a>
    <div class="nav-brand">
      <span class="brand-icon">♪</span>
      <span class="brand-text">MEeL!Mania</span>
    </div>
    <div class="nav-actions">
      <?php if ($is_logged_in): ?>
        <a href="manage/" class="nav-btn" title="Kelola Beatmap" style="text-decoration:none;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
        </a>
      <?php endif; ?>
      <button id="btnSettings" class="nav-btn" title="Pengaturan">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
      </button>
      <button id="btnStats" class="nav-btn" title="Statistik">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>
      </button>
    </div>
  </nav>

  <main class="main-content">
    <section class="hero">
      <div class="hero-glow"></div>
      <h1 class="hero-title">
        <span class="hero-note">♪</span>
        MEeL<span class="hero-accent">!Mania</span>
      </h1>
      <p class="hero-sub">Rhythm Game 4-Lane &middot; Tekan A S K L atau sentuh layar</p>
    </section>

    <section class="song-section">
      <div class="section-header">
        <h2 class="section-title">Pilih Lagu</h2>
        <div class="sort-group">
          <button class="sort-btn active" data-sort="default">Default</button>
          <button class="sort-btn" data-sort="bpm">BPM</button>
          <button class="sort-btn" data-sort="difficulty">Sulit</button>
        </div>
      </div>
      <div id="songGrid" class="song-grid"></div>
    </section>

    <section class="speed-section">
      <h3 class="speed-title">Speed Modifier</h3>
      <div class="speed-options">
        <button class="speed-btn" data-speed="1.0"><span class="speed-icon">○</span><span class="speed-label">Santai</span><span class="speed-val">1.0×</span></button>
        <button class="speed-btn selected" data-speed="1.5"><span class="speed-icon">◎</span><span class="speed-label">Normal</span><span class="speed-val">1.5×</span></button>
        <button class="speed-btn" data-speed="2.0"><span class="speed-icon">●</span><span class="speed-label">Cepat</span><span class="speed-val">2.0×</span></button>
        <button class="speed-btn" data-speed="2.5"><span class="speed-icon">◉</span><span class="speed-label">Gila</span><span class="speed-val">2.5×</span></button>
      </div>
    </section>

    <div class="play-area">
      <button id="btnPlay" class="play-btn" disabled>
        <span class="play-btn-glow"></span>
        <svg class="play-icon" width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        <span class="play-text">PILIH LAGU DULU</span>
      </button>
    </div>
  </main>

  <div id="settingsPanel" class="panel-overlay hidden">
    <div class="panel settings-panel">
      <div class="panel-header"><h2>Pengaturan</h2><button class="panel-close" id="closeSettings">✕</button></div>
      <div class="panel-body">
        <div class="setting-row"><label>Volume SFX</label><input type="range" id="sfxVolume" min="0" max="100" value="70" class="slider" /><span id="sfxVolumeVal" class="slider-val">70%</span></div>
        <div class="setting-row"><label>Volume BGM</label><input type="range" id="bgmVolume" min="0" max="100" value="50" class="slider" /><span id="bgmVolumeVal" class="slider-val">50%</span></div>
        <div class="setting-row"><label>Note Size</label><div class="radio-group"><button class="radio-btn" data-note-size="small">Kecil</button><button class="radio-btn active" data-note-size="normal">Normal</button><button class="radio-btn" data-note-size="large">Besar</button></div></div>
        <div class="setting-row"><label>Background Dim</label><input type="range" id="bgDim" min="0" max="100" value="80" class="slider" /><span id="bgDimVal" class="slider-val">80%</span></div>
        <div class="setting-row"><label>Reset Semua Data</label><button id="btnResetAll" class="btn-danger">Hapus Semua Score</button></div>
      </div>
    </div>
  </div>

  <div id="statsPanel" class="panel-overlay hidden">
    <div class="panel stats-panel">
      <div class="panel-header"><h2>Statistik Pemain</h2><button class="panel-close" id="closeStats">✕</button></div>
      <div class="panel-body" id="statsBody"></div>
    </div>
  </div>

  <div id="toast" class="toast hidden"></div>

  <script>
    window.MANIA_SONGS = <?= $songs_json ?>;
    window.MANIA_CSRF = <?= json_encode($csrf) ?>;
    window.MANIA_IS_LOGGED_IN = <?= $is_logged_in ? 'true' : 'false' ?>;
  </script>
  <script src="assets/js/lobby.js?v=<?= filemtime(__DIR__ . '/assets/js/lobby.js') ?>"></script>
</body>
</html>
