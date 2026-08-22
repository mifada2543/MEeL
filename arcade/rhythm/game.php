<?php
/**
 * MEeL!Mania — Gameplay
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once __DIR__ . '/api/config.php';

$song_id = $_GET['id'] ?? $_GET['song'] ?? 'starlight';
$song_type = $_GET['type'] ?? 'builtin';
$speed = (float) ($_GET['speed'] ?? 1.5);

$song_data = null;
$beatmap_data = null;

if ($song_type === 'custom' && is_numeric($song_id)) {
    // Custom song from DB
    $stmt = $conn->prepare(
        "SELECT s.*, u.username
         FROM arcade_song s
         LEFT JOIN users u ON s.user_id = u.id
         WHERE s.id = ? AND s.is_active = 1"
    );
    $stmt->bind_param("i", $song_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        // Increment play count
        $conn->query("UPDATE arcade_song SET play_count = play_count + 1 WHERE id = " . (int)$song_id);

        // Read beatmap from filesystem
        $beatmap_file = __DIR__ . '/' . $row['beatmap_path'];
        if (!empty($row['beatmap_path']) && file_exists($beatmap_file)) {
            $beatmap_data = json_decode(file_get_contents($beatmap_file), true);
        }

        $song_data = [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'artist' => $row['artist'],
            'bpm' => (int) $row['bpm'],
            'difficulty' => (int) $row['difficulty'],
            'difficulty_label' => $row['difficulty_label'],
            'color' => [$row['color_primary'], $row['color_secondary']],
            'emoji' => '🎵',
            'duration' => (int) $row['duration'],
            'note_count' => (int) $row['note_count'],
            'type' => 'custom',
            'audio_url' => 'uploads/audio/' . $row['audio_file'],
            'cover_url' => $row['cover_file'] ? ('uploads/cover/' . $row['cover_file']) : null,
        ];
    }
} else {
    // Built-in song from filesystem
    $index_path = __DIR__ . '/songs/_index.json';
    if (file_exists($index_path)) {
        $index = json_decode(file_get_contents($index_path), true);
        if (is_array($index)) {
            foreach ($index as $s) {
                if ($s['id'] === $song_id) {
                    $song_data = [
                        'id' => $s['id'],
                        'title' => $s['title'],
                        'artist' => $s['artist'],
                        'bpm' => $s['bpm'],
                        'difficulty' => $s['difficulty'],
                        'difficulty_label' => $s['difficultyLabel'] ?? 'Normal',
                        'color' => $s['color'] ?? ['#ec4899', '#a855f7'],
                        'emoji' => $s['emoji'] ?? '♪',
                        'duration' => $s['duration'] ?? 60,
                        'note_count' => $s['noteCount'] ?? 0,
                        'type' => 'builtin',
                        'audio_url' => null,
                        'cover_url' => 'songs/' . $s['id'] . '/cover.svg',
                    ];
                    break;
                }
            }
        }
    }

    // Load beatmap from filesystem
    $beatmap_file = __DIR__ . '/songs/' . $song_id . '/beatmap.json';
    if (file_exists($beatmap_file)) {
        $beatmap_data = json_decode(file_get_contents($beatmap_file), true);
    }
}

// Fallback
if (!$song_data) {
    $song_data = [
        'id' => $song_id, 'title' => $song_id, 'artist' => 'Unknown',
        'bpm' => 120, 'difficulty' => 2, 'difficulty_label' => 'Normal',
        'color' => ['#ec4899', '#a855f7'], 'emoji' => '♪',
        'duration' => 60, 'note_count' => 0, 'type' => 'builtin',
        'audio_url' => null, 'cover_url' => null,
    ];
}
if (!$beatmap_data) {
    $beatmap_data = ['notes' => [], 'duration' => 0];
}

$song_json = json_encode($song_data, JSON_UNESCAPED_UNICODE);
$beatmap_json = json_encode($beatmap_data, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no" />
  <meta name="robots" content="noindex, nofollow" />
  <title>MEeL!Mania — <?= htmlspecialchars($song_data['title']) ?></title>
  <link rel="icon" type="image/png" href="../assets/MEeL.png" />
  <link href="../assets/css/font.css" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/game.css?v=<?= filemtime(__DIR__ . '/assets/css/game.css') ?>" />
</head>
<body>

  <!-- Background image (blur bg option) -->
  <div id="bgImage" class="game-bg-image hidden"></div>
  <!-- Dim overlay -->
  <div id="dimOverlay" class="game-dim-overlay"></div>
  <!-- FPS counter -->
  <div id="fpsCounter" class="fps-counter hidden">0 FPS</div>

  <canvas id="gameCanvas"></canvas>
  <audio id="audioPlayer" preload="auto"></audio>

  <div id="hud" class="hud hidden">
    <div class="hud-top">
      <div class="hud-song-info">
        <div class="hud-song-title" id="hudTitle"></div>
        <div class="hud-song-artist" id="hudArtist"></div>
      </div>
      <div class="hud-score-area">
        <div class="hud-score-label">SCORE</div>
        <div class="hud-score" id="hudScore">000000</div>
      </div>
    </div>
    <div id="comboWrap" class="combo-wrap hidden">
      <div class="combo-number" id="comboNumber">0</div>
      <div class="combo-label">COMBO</div>
    </div>
    <div id="judgmentWrap" class="judgment-wrap hidden">
      <div class="judgment-text" id="judgmentText"></div>
    </div>
    <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
    <div class="hud-acc" id="hudAcc">100.0%</div>
  </div>

  <div id="touchLanes" class="touch-lanes hidden">
    <button data-lane="0" class="touch-btn tl-0"><span>A</span></button>
    <button data-lane="1" class="touch-btn tl-1"><span>S</span></button>
    <button data-lane="2" class="touch-btn tl-2"><span>K</span></button>
    <button data-lane="3" class="touch-btn tl-3"><span>L</span></button>
  </div>

  <div id="startOverlay" class="overlay">
    <div class="overlay-content">
      <div class="overlay-emoji" id="overlayEmoji">♪</div>
      <div class="overlay-title" id="overlayTitle">Loading...</div>
      <div class="overlay-sub" id="overlaySub">BPM: --</div>
      <div class="overlay-hint">Tekan SPASI atau TAP untuk mulai</div>
      <div class="overlay-controls">
        <div class="key-hint"><span class="key">A</span></div>
        <div class="key-hint"><span class="key">S</span></div>
        <div class="key-hint"><span class="key">K</span></div>
        <div class="key-hint"><span class="key">L</span></div>
      </div>
    </div>
  </div>

  <div id="pauseOverlay" class="overlay hidden">
    <div class="pause-card">
      <div class="pause-title">Pause Menu</div>
      <div class="pause-buttons">
        <button id="btnResume" class="pause-btn" onclick="resumeGame()">
          <span class="pause-btn-icon">▶</span>
          <span>Resume</span>
        </button>
        <button class="pause-btn" onclick="restartGame()">
          <span class="pause-btn-icon">↻</span>
          <span>Restart</span>
        </button>
        <button class="pause-btn" onclick="toggleAdvanced()">
          <span class="pause-btn-icon">⚙</span>
          <span>Advanced</span>
        </button>
        <button id="btnQuit" class="pause-btn pause-btn-danger" onclick="quitToLobby()">
          <span class="pause-btn-icon">🚪</span>
          <span>Exit Game</span>
        </button>
      </div>
    </div>
  </div>

  <!-- ─── Advanced Options Overlay ─── -->
  <div id="optionsOverlay" class="overlay hidden">
    <div class="options-card">
      <div class="options-title">Options</div>

      <div class="options-group">
        <label class="opt-label">Note Speed</label>
        <input type="range" class="opt-slider" id="optSpeed" min="1" max="20" value="10">
      </div>
      <div class="options-group">
        <label class="opt-label">Background Dim</label>
        <input type="range" class="opt-slider" id="optDim" min="0" max="100" value="70">
      </div>
      <div class="options-group">
        <label class="opt-label">Game Volume</label>
        <input type="range" class="opt-slider" id="optVolume" min="0" max="100" value="80">
      </div>

      <div class="options-checks">
        <label class="opt-check"><input type="checkbox" id="optBlurBg"><span class="check-box"></span>Blur Background</label>
        <label class="opt-check"><input type="checkbox" id="optFPS"><span class="check-box"></span>Show FPS</label>
        <label class="opt-check"><input type="checkbox" id="optLowGfx"><span class="check-box"></span>Low Graphics (Effects Off)</label>
      </div>

      <div class="options-footer">
        <button class="pause-btn" onclick="closeOptions()">
          <span class="pause-btn-icon">←</span>
          <span>Back</span>
        </button>
        <button class="pause-btn pause-btn-primary" onclick="saveOptions()">
          <span>Done</span>
        </button>
      </div>
    </div>
  </div>

  <!-- ─── Countdown Overlay ─── -->
  <div id="countdownOverlay" class="overlay hidden" style="background:transparent;backdrop-filter:none;">
    <div class="countdown-num" id="countdownNum">3</div>
  </div>

  <div id="resultsOverlay" class="overlay hidden">
    <div class="overlay-content results-content">
      <div class="results-rank" id="resultsRank">S</div>
      <div class="results-score-label">SKOR AKHIR</div>
      <div class="results-score" id="resultsScore">000000</div>
      <div id="newHighScoreBanner" class="new-hs hidden">★ NEW HIGH SCORE ★</div>
      <div class="results-grid">
        <div class="res-stat"><div class="res-label">PERFECT</div><div class="res-val gold" id="resPerfect">0</div></div>
        <div class="res-stat"><div class="res-label">GREAT</div><div class="res-val green" id="resGreat">0</div></div>
        <div class="res-stat"><div class="res-label">GOOD</div><div class="res-val blue" id="resGood">0</div></div>
        <div class="res-stat"><div class="res-label">BAD</div><div class="res-val red" id="resBad">0</div></div>
        <div class="res-stat"><div class="res-label">MISS</div><div class="res-val gray" id="resMiss">0</div></div>
        <div class="res-stat"><div class="res-label">MAX COMBO</div><div class="res-val purple" id="resMaxCombo">0</div></div>
      </div>
      <div class="results-acc">
        <span class="results-acc-label">Accuracy</span>
        <span class="results-acc-val" id="resultsAcc">0%</span>
      </div>
      <div class="results-actions">
        <button id="btnRetry" class="result-btn primary">Main Lagi</button>
        <button id="btnBackLobby" class="result-btn">Kembali ke Lobby</button>
      </div>
    </div>
  </div>

  <script>
    window.MANIA_SONG = <?= $song_json ?>;
    window.MANIA_BEATMAP = <?= $beatmap_json ?>;
    window.MANIA_SPEED = <?= json_encode($speed) ?>;
  </script>
  <script src="assets/js/game.js?v=<?= filemtime(__DIR__ . '/assets/js/game.js') ?>"></script>
</body>
</html>
