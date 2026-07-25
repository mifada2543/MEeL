<?php
/**
 * controllers/auto_metadata.php
 * 
 * AJAX endpoint untuk membaca metadata file audio via ffprobe.
 * Dipanggil dari music/upload.php saat user klik tombol "Auto".
 * Mengembalikan JSON: { status, title, artist, album, cover (base64) }
 */

require_once __DIR__ . '/../../modules/core/helpers.php';
require_once __DIR__ . '/../../auth/config.php';

header('Content-Type: application/json');

// ── Cek login ─────────────────────────────────────────────────────────────
include __DIR__ . '/../../auth/auth.php';
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Sesi tidak valid. Silakan login ulang.']);
    exit;
}

// ── Validasi file upload ──────────────────────────────────────────────────
if (empty($_FILES['audio']['tmp_name']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Tidak ada file audio yang diterima.']);
    exit;
}

$ext = strtolower(pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION));
$allowed = ['mp3', 'flac', 'ogg', 'm4a', 'wav', 'opus'];
if (!in_array($ext, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Format file audio tidak didukung.']);
    exit;
}

// ── Simpan ke temp ────────────────────────────────────────────────────────
$temp_dir  = sys_get_temp_dir() . '/meel_auto_meta';
if (!is_dir($temp_dir)) @mkdir($temp_dir, 0755, true);

$temp_file = $temp_dir . '/' . uniqid('meta_', true) . '.' . $ext;
if (!move_uploaded_file($_FILES['audio']['tmp_name'], $temp_file)) {
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan file temp.']);
    exit;
}

// ── Resolve binary ────────────────────────────────────────────────────────
$ffprobe = resolve_binary(['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', 'ffprobe']);
$ffmpeg  = resolve_binary(['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg']);

$title  = '';
$artist = '';
$album  = '';
$cover_b64 = '';

// ── Baca metadata via ffprobe (JSON output → robust) ──────────────────────
$meta_cmd = 'export LD_LIBRARY_PATH=\'\'; '
    . escapeshellarg($ffprobe)
    . ' -v error -show_entries format_tags=title,artist,album'
    . ' -of json '
    . escapeshellarg($temp_file) . ' 2>/dev/null';

$meta_json = shell_exec($meta_cmd);
if ($meta_json) {
    $parsed = json_decode($meta_json, true);
    $tags   = $parsed['format']['tags'] ?? [];
    $title  = trim($tags['title']  ?? '');
    $artist = trim($tags['artist'] ?? '');
    $album  = trim($tags['album']  ?? '');
}

// ── Ekstrak cover art via ffmpeg ──────────────────────────────────────────
$cover_path = $temp_dir . '/' . uniqid('cover_', true) . '.jpg';
$cover_cmd  = 'export LD_LIBRARY_PATH=\'\'; ' . escapeshellarg($ffmpeg)
    . ' -y -i ' . escapeshellarg($temp_file)
    . ' -an -vframes 1'
    . ' -vf "scale=500:500:force_original_aspect_ratio=decrease,pad=500:500:(ow-iw)/2:(oh-ih)/2"'
    . ' -c:v mjpeg -q:v 5 '
    . escapeshellarg($cover_path) . ' 2>/dev/null';

exec($cover_cmd, $cover_out, $cover_ret);
if ($cover_ret === 0 && file_exists($cover_path) && filesize($cover_path) > 0) {
    $cover_b64 = base64_encode(file_get_contents($cover_path));
}

// ── Cleanup ───────────────────────────────────────────────────────────────
@unlink($temp_file);
if (!empty($cover_path) && file_exists($cover_path)) @unlink($cover_path);
@rmdir($temp_dir);

// ── Response ──────────────────────────────────────────────────────────────
echo json_encode([
    'status' => 'success',
    'title'  => $title,
    'artist' => $artist,
    'album'  => $album,
    'cover'  => $cover_b64,
]);
