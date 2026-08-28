<?php
/**
 * MEeL!Mania — Upload Beatmap API
 * POST /arcade/rhythm/api/upload.php
 *
 * Fields:
 *   - title (required)
 *   - artist (optional)
 *   - bpm (required, 60-300)
 *   - difficulty (required, 1-5)
 *   - audio (file, required)
 *   - cover (file, optional)
 *   - beatmap_json (required, JSON string)
 *   - color_primary (optional, hex)
 *   - color_secondary (optional, hex)
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed', 405);
}

$user_id = require_auth();
$user_role = get_user_role($conn, $user_id);
$is_admin = ($user_role === 'admin');

// ─── CSRF Check ──────────────────────────────────────
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])
    || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    api_error('CSRF token tidak valid.');
}

// ─── Rate Limit (simple: max 10 per hour for users) ──
if (!$is_admin) {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM arcade_song WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $cnt = $stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
    $stmt->close();
    if ($cnt >= 10) {
        api_error('Batas upload tercapai (10/jam). Coba lagi nanti.');
    }
}

// ─── Validate Fields ─────────────────────────────────
$title = trim($_POST['title'] ?? '');
$artist = trim($_POST['artist'] ?? 'Unknown Artist');
$bpm = (int) ($_POST['bpm'] ?? 0);
$difficulty = (int) ($_POST['difficulty'] ?? 2);
$beatmap_json = $_POST['beatmap_json'] ?? '';
$color_primary = $_POST['color_primary'] ?? '#ec4899';
$color_secondary = $_POST['color_secondary'] ?? '#a855f7';

if (empty($title)) api_error('Judul wajib diisi.');
if (strlen($title) > 120) api_error('Judul maksimal 120 karakter.');
if ($bpm < 60 || $bpm > 300) api_error('BPM harus antara 60-300.');
if ($difficulty < 1 || $difficulty > 5) api_error('Difficulty harus antara 1-5.');

// Validate beatmap JSON
$beatmap = json_decode($beatmap_json, true);
if (!$beatmap || !isset($beatmap['notes']) || !is_array($beatmap['notes'])) {
    api_error('Beatmap JSON tidak valid.');
}
if (count($beatmap['notes']) < 10) {
    api_error('Minimal 10 notes dalam beatmap.');
}
if (count($beatmap['notes']) > 5000) {
    api_error('Maksimal 5000 notes dalam beatmap.');
}

// Validate each note (tap: {t, l} or hold: {t, e, l}, optional gold: {g: true})
foreach ($beatmap['notes'] as $i => $note) {
    if (!isset($note['t']) || !isset($note['l'])) {
        api_error("Note ke-" . ($i + 1) . " format tidak valid (butuh 't' dan 'l').");
    }
    $t = (int) $note['t'];
    $l = (int) $note['l'];
    if ($t < 0) api_error("Note ke-" . ($i + 1) . " waktu negatif.");
    if ($l < 0 || $l > 3) api_error("Note ke-" . ($i + 1) . " lane harus 0-3.");
    // Hold note validation
    if (isset($note['e'])) {
        $e = (int) $note['e'];
        if ($e <= $t) api_error("Note ke-" . ($i + 1) . " hold end time harus lebih besar dari start time.");
        if ($e > $MAX_DURATION * 1000) api_error("Note ke-" . ($i + 1) . " hold end time melebihi durasi maksimal.");
    }
}

// Sort notes by time
usort($beatmap['notes'], fn($a, $b) => $a['t'] - $b['t']);
$note_count = count($beatmap['notes']);

// Calculate duration from last note (consider hold end times) + buffer
$last_time = 0;
foreach ($beatmap['notes'] as $n) {
    $end = isset($n['e']) ? $n['e'] : $n['t'];
    if ($end > $last_time) $last_time = $end;
}
$beatmap_duration = ceil($last_time / 1000) + 2; // +2s buffer

// Validate color hex
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color_primary)) $color_primary = '#ec4899';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color_secondary)) $color_secondary = '#a855f7';

// ─── Validate Audio File ─────────────────────────────
if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    $err_code = $_FILES['audio']['error'] ?? -1;
    api_error('File audio tidak diterima. Error code: ' . $err_code);
}

$audio_tmp = $_FILES['audio']['tmp_name'];
$audio_name = $_FILES['audio']['name'];
$audio_size = $_FILES['audio']['size'];
$audio_ext = strtolower(pathinfo($audio_name, PATHINFO_EXTENSION));

// Check extension
if (!in_array($audio_ext, $ALLOWED_AUDIO_EXT, true)) {
    api_error('Format audio tidak didukung. Gunakan: MP3, OGG, OPUS, FLAC, WAV.');
}

// Check size
if ($audio_size > $MAX_AUDIO_SIZE) {
    api_error('File audio terlalu besar (maksimal 20MB).');
}

// Validate MIME type
$mime = validate_audio_mime($audio_tmp);
if (!$mime) {
    api_error('Tipe MIME audio tidak valid. File mungkin bukan audio.');
}

// Validate magic bytes (basic check)
$handle = fopen($audio_tmp, 'rb');
$header = fread($handle, 16);
fclose($handle);
if ($header === false || strlen($header) < 4) {
    api_error('File audio tidak dapat dibaca.');
}

// ─── Probe Duration ──────────────────────────────────
$duration = probe_duration($audio_tmp);
if ($duration <= 0) {
    api_error('Gagal membaca durasi audio. File mungkin korup.');
}
if ($duration > $MAX_DURATION) {
    api_error('Durasi audio maksimal 5 menit (' . round($duration, 1) . ' detik).');
}

// ─── Probe Audio Info ────────────────────────────────
$audio_info = probe_audio_info($audio_tmp);
$audio_bitrate = 0;
if (isset($audio_info['format']['bit_rate'])) {
    $audio_bitrate = (int) ($audio_info['format']['bit_rate'] / 1000); // kbps
}

// ─── Process Audio File ──────────────────────────────
$clean_name = sanitize_filename($title);
$final_ext = $audio_ext;
$final_mime = $mime;

// Transcode FLAC to Opus
if ($audio_ext === 'flac') {
    $opus_path = $AUDIO_DIR . $clean_name . '.ogg';
    $result = transcode_flac_to_opus($audio_tmp);
    if ($result && file_exists($result)) {
        // File already transcoded and saved by transcode function
        $final_ext = 'ogg';
        $final_mime = 'audio/ogg';
        $audio_bitrate = 128; // Opus default
    } else {
        // Fallback: keep FLAC if transcode fails
        $final_ext = 'flac';
        $final_mime = 'audio/flac';
        move_uploaded_file($audio_tmp, $AUDIO_DIR . $clean_name . '.flac');
    }
} else {
    $filename = unique_filename($clean_name, $final_ext, $AUDIO_DIR);
    if (!move_uploaded_file($audio_tmp, $AUDIO_DIR . $filename)) {
        api_error('Gagal menyimpan file audio.');
    }
    $clean_name = pathinfo($filename, PATHINFO_FILENAME);
}

$audio_filename = $clean_name . '.' . $final_ext;

// ─── Process Cover (optional) ────────────────────────
$cover_filename = null;
if (isset($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
    $cover_tmp = $_FILES['cover']['tmp_name'];
    $cover_size = $_FILES['cover']['size'];

    // Validate cover is image
    $cover_finfo = new finfo(FILEINFO_MIME_TYPE);
    $cover_mime = $cover_finfo->file($cover_tmp);
    $allowed_cover = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($cover_mime, $allowed_cover, true)) {
        api_error('Cover harus berupa gambar (JPG, PNG, GIF, WebP).');
    }
    if ($cover_size > 5 * 1024 * 1024) {
        api_error('Cover terlalu besar (maksimal 5MB).');
    }

    // Convert to webp using ffmpeg
    $cover_webp = $clean_name . '_cover.webp';
    $cover_out = $COVER_DIR . $cover_webp;
    $cmd = "export LD_LIBRARY_PATH=''; "
        . escapeshellarg($FFMPEG_BIN) . " -y -i "
        . escapeshellarg($cover_tmp)
        . ' -vf "scale=\'min(512,iw)\':-1" -c:v libwebp -q:v 80 '
        . escapeshellarg($cover_out) . " 2>&1";
    exec($cmd, $out, $ret);
    if ($ret === 0 && file_exists($cover_out) && filesize($cover_out) > 0) {
        $cover_filename = $cover_webp;
    } else {
        // Fallback: save original
        $cover_ext = strtolower(pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION)) ?: 'jpg';
        $cover_filename = $clean_name . '_cover.' . $cover_ext;
        move_uploaded_file($cover_tmp, $COVER_DIR . $cover_filename);
    }
}

// ─── Difficulty Label ────────────────────────────────
$diff_labels = [
    1 => 'Easy', 2 => 'Normal', 3 => 'Hard', 4 => 'Expert', 5 => 'Insane',
];
$diff_label = $diff_labels[$difficulty] ?? 'Normal';

// ─── Save Beatmap JSON to Filesystem ────────────────
$beatmap_dir = __DIR__ . '/../uploads/beatmap/';
if (!is_dir($beatmap_dir)) mkdir($beatmap_dir, 0755, true);

$is_edit = !empty($_POST['song_id']);
$edit_song_id = $is_edit ? (int) $_POST['song_id'] : 0;
$existing = null;

// Verify ownership if editing
if ($is_edit) {
    $chk = $conn->prepare("SELECT id, user_id, audio_file, cover_file, beatmap_path FROM arcade_song WHERE id = ?");
    $chk->bind_param("i", $edit_song_id);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$existing) api_error('Beatmap tidak ditemukan.');
    if ($existing['user_id'] != $user_id && !$is_admin) api_error('Tidak punya akses.');
}

$conn->begin_transaction();
try {
    if ($is_edit) {
        // UPDATE existing beatmap
        $stmt = $conn->prepare(
            "UPDATE arcade_song SET
             title = ?, artist = ?, bpm = ?, difficulty = ?, difficulty_label = ?,
             duration = ?, note_count = ?, audio_file = ?, audio_mime = ?,
             audio_bitrate = ?, cover_file = ?, color_primary = ?, color_secondary = ?,
             updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmt->bind_param(
            "ssiiiiisssissi",
            $title, $artist, $bpm, $difficulty, $diff_label,
            $beatmap_duration, $note_count, $audio_filename, $final_mime,
            $audio_bitrate, $cover_filename, $color_primary, $color_secondary,
            $edit_song_id
        );
        if (!$stmt->execute()) throw new RuntimeException('Execute failed: ' . $stmt->error);
        $song_id = $edit_song_id;
        $stmt->close();
        // Delete old audio/cover if replaced
        if ($existing['audio_file'] && $existing['audio_file'] !== $audio_filename) @unlink($AUDIO_DIR . $existing['audio_file']);
        if ($existing['cover_file'] && $existing['cover_file'] !== $cover_filename) @unlink($COVER_DIR . $existing['cover_file']);
    } else {
        // INSERT new beatmap
        $stmt = $conn->prepare(
            "INSERT INTO arcade_song
             (user_id, title, artist, bpm, difficulty, difficulty_label, duration,
              note_count, audio_file, audio_mime, audio_bitrate, cover_file,
              beatmap_path, color_primary, color_secondary)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)"
        );
        if (!$stmt) throw new RuntimeException('Prepare failed: ' . $conn->error);
        $stmt->bind_param(
            "issiiiiisssiss",
            $user_id, $title, $artist, $bpm, $difficulty, $diff_label,
            $beatmap_duration, $note_count, $audio_filename, $final_mime,
            $audio_bitrate, $cover_filename, $color_primary, $color_secondary
        );
        if (!$stmt->execute()) throw new RuntimeException('Execute failed: ' . $stmt->error);
        $song_id = $conn->insert_id;
        $stmt->close();
    }

    // Save beatmap.json to filesystem
    $beatmap_filename = 'beatmap_' . $song_id . '.json';
    $beatmap_filepath = $beatmap_dir . $beatmap_filename;
    $beatmap_data = json_encode($beatmap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($beatmap_filepath, $beatmap_data) === false) {
        throw new RuntimeException('Gagal menyimpan beatmap.json ke filesystem.');
    }

    // Update beatmap_path in database
    $beatmap_db_path = 'uploads/beatmap/' . $beatmap_filename;
    $stmt2 = $conn->prepare("UPDATE arcade_song SET beatmap_path = ? WHERE id = ?");
    $stmt2->bind_param("si", $beatmap_db_path, $song_id);
    if (!$stmt2->execute()) throw new RuntimeException('Gagal update beatmap_path.');
    $stmt2->close();

    $conn->commit();

    // Log activity
    if (function_exists('log_activity')) {
        log_activity($conn, $user_id, 'upload_arcade_beatmap', 'arcade', $song_id);
    }

    api_respond([
        'success' => true,
        'song_id' => $song_id,
        'message' => 'Beatmap berhasil diupload!',
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    // Cleanup files
    @unlink($AUDIO_DIR . $audio_filename);
    if ($cover_filename) @unlink($COVER_DIR . $cover_filename);
    if (isset($beatmap_filepath)) @unlink($beatmap_filepath);
    api_error('Database error: ' . $e->getMessage(), 500);
}
