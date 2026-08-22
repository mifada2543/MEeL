<?php
/**
 * MEeL!Mania — Get Beatmap API
 * GET /arcade/rhythm/api/beatmap.php?id={song_id}
 *
 * Returns beatmap JSON, audio URL, and metadata for a specific song
 */
require_once __DIR__ . '/config.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    api_error('Song ID tidak valid.');
}

// Check if it's a built-in song (string ID like "starlight")
$builtin_id = $_GET['id'] ?? '';
$builtin_path = __DIR__ . '/../songs/' . $builtin_id . '/beatmap.json';

if (file_exists($builtin_path) && !is_numeric($builtin_id)) {
    // Built-in song
    $beatmap = json_decode(file_get_contents($builtin_path), true);
    $index_path = __DIR__ . '/../songs/_index.json';
    $index = json_decode(file_get_contents($index_path), true);
    $meta = null;
    foreach ($index as $s) {
        if ($s['id'] === $builtin_id) {
            $meta = $s;
            break;
        }
    }

    api_respond([
        'id' => $builtin_id,
        'type' => 'builtin',
        'title' => $meta['title'] ?? $builtin_id,
        'artist' => $meta['artist'] ?? 'Unknown',
        'bpm' => $meta['bpm'] ?? 120,
        'difficulty' => $meta['difficulty'] ?? 2,
        'difficulty_label' => $meta['difficultyLabel'] ?? 'Normal',
        'duration' => $beatmap['duration'] ?? 60,
        'note_count' => count($beatmap['notes'] ?? []),
        'beatmap' => $beatmap,
        'audio_url' => null,
        'cover_url' => 'songs/' . $builtin_id . '/cover.svg',
        'color' => $meta['color'] ?? ['#ec4899', '#a855f7'],
        'emoji' => $meta['emoji'] ?? '♪',
        'user_id' => null,
        'username' => null,
    ]);
    exit;
}

// Custom song from DB
$stmt = $conn->prepare(
    "SELECT s.*, u.username
     FROM arcade_song s
     LEFT JOIN users u ON s.user_id = u.id
     WHERE s.id = ? AND s.is_active = 1"
);
$stmt->bind_param("i", $id);
$stmt->execute();
$song = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$song) {
    api_error('Beatmap tidak ditemukan.', 404);
}

// Increment play count
$conn->query("UPDATE arcade_song SET play_count = play_count + 1 WHERE id = $id");

// Read beatmap.json from filesystem
$beatmap = null;
$beatmap_file = __DIR__ . '/../' . $song['beatmap_path'];
if (!empty($song['beatmap_path']) && file_exists($beatmap_file)) {
    $beatmap = json_decode(file_get_contents($beatmap_file), true);
}
if (!$beatmap) {
    api_error('File beatmap.json tidak ditemukan.', 404);
}

api_respond([
    'id' => (int) $song['id'],
    'type' => 'custom',
    'title' => $song['title'],
    'artist' => $song['artist'],
    'bpm' => (int) $song['bpm'],
    'difficulty' => (int) $song['difficulty'],
    'difficulty_label' => $song['difficulty_label'],
    'duration' => (int) $song['duration'],
    'note_count' => (int) $song['note_count'],
    'beatmap' => $beatmap,
    'audio_url' => 'uploads/audio/' . $song['audio_file'],
    'cover_url' => $song['cover_file'] ? ('uploads/cover/' . $song['cover_file']) : null,
    'color' => [$song['color_primary'], $song['color_secondary']],
    'emoji' => '🎵',
    'user_id' => (int) $song['user_id'],
    'username' => $song['username'] ?? 'Unknown',
]);
