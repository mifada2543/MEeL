<?php


require_once __DIR__ . '/config.php';

$sort = $_GET['sort'] ?? 'default';
$filter_user = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
$search = trim($_GET['search'] ?? '');

$builtin_path = __DIR__ . '/../songs/_index.json';
$builtin_songs = [];
if (file_exists($builtin_path)) {
    $raw = file_get_contents($builtin_path);
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
                'user_id' => null,
                'username' => null,
                'play_count' => 0,
                'created_at' => null,
            ];
        }
    }
}

$sql = "SELECT s.id, s.title, s.artist, s.bpm, s.difficulty, s.difficulty_label,
               s.duration, s.note_count, s.audio_file, s.cover_file,
               s.color_primary, s.color_secondary, s.play_count, s.created_at,
               s.user_id, u.username
        FROM arcade_song s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.is_active = 1";

$params = [];
$types = '';

if ($filter_user) {
    $sql .= " AND s.user_id = ?";
    $params[] = $filter_user;
    $types .= 'i';
}

if ($search !== '') {
    $sql .= " AND (s.title LIKE ? OR s.artist LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

switch ($sort) {
    case 'bpm': $sql .= " ORDER BY s.bpm DESC"; break;
    case 'difficulty': $sql .= " ORDER BY s.difficulty DESC"; break;
    case 'newest': $sql .= " ORDER BY s.created_at DESC"; break;
    case 'plays': $sql .= " ORDER BY s.play_count DESC"; break;
    default: $sql .= " ORDER BY s.created_at DESC"; break;
}

$sql .= " LIMIT 100";

$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$custom_songs = [];

while ($row = $result->fetch_assoc()) {
    $cover_url = null;
    if ($row['cover_file']) {
        $cover_url = 'uploads/cover/' . $row['cover_file'];
    }

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
$stmt->close();

$all_songs = array_merge($builtin_songs, $custom_songs);

switch ($sort) {
    case 'bpm':
        usort($all_songs, fn($a, $b) => $b['bpm'] - $a['bpm']);
        break;
    case 'difficulty':
        usort($all_songs, fn($a, $b) => $b['difficulty'] - $a['difficulty']);
        break;
    case 'newest':
        usort($all_songs, function($a, $b) {
            $ta = $a['created_at'] ?? '2000-01-01';
            $tb = $b['created_at'] ?? '2000-01-01';
            return strcmp($tb, $ta);
        });
        break;
    case 'plays':
        usort($all_songs, fn($a, $b) => $b['play_count'] - $a['play_count']);
        break;
}

api_respond([
    'songs' => $all_songs,
    'total' => count($all_songs),
    'builtin_count' => count($builtin_songs),
    'custom_count' => count($custom_songs),
]);
