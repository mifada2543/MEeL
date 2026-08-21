<?php
require_once __DIR__ . '/../../modules/core/helpers.php';
require_once __DIR__ . '/../../auth/config.php';

header('Content-Type: application/json');

// Cek login
include __DIR__ . '/../../auth/auth.php';
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Sesi tidak valid. Silakan login ulang.']);
    exit;
}

// CSRF
if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'CSRF token tidak valid.']);
    exit;
}

// Validasi file upload
if (empty($_FILES['audio']['tmp_name']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Tidak ada file audio yang diterima.']);
    exit;
}

if (isset($_FILES['audio']['error']) && (int)$_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    $upload_errors = [
        UPLOAD_ERR_INI_SIZE   => 'File melebihi batas upload server (upload_max_filesize).',
        UPLOAD_ERR_FORM_SIZE  => 'File melebihi batas upload form.',
        UPLOAD_ERR_PARTIAL    => 'File hanya terupload sebagian.',
    ];
    echo json_encode(['status' => 'error', 'message' => $upload_errors[(int)$_FILES['audio']['error']] ?? 'Gagal mengupload file.']);
    exit;
}

$ext = strtolower(pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION));
$allowed = ['mp3', 'flac', 'ogg', 'm4a', 'wav', 'opus'];
if (!in_array($ext, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Format file audio tidak didukung.']);
    exit;
}

$user_role = get_user_role($conn, (int)$_SESSION['user_id']);
$max_size  = ($user_role === 'admin') ? 200 * 1024 * 1024 : 50 * 1024 * 1024;
if ((int)($_FILES['audio']['size'] ?? 0) > $max_size) {
    http_response_code(413);
    echo json_encode(['status' => 'error', 'message' => 'File terlalu besar untuk diproses metadata.']);
    exit;
}

// Resolve binary — pastikan benar-benar executable (bukan fallback path palsu)
$ffprobe = resolve_binary(['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', 'ffprobe']);
$ffmpeg  = resolve_binary(['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg']);

if (!is_executable($ffprobe)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server tidak memiliki ffprobe. Auto-metadata tidak tersedia.']);
    exit;
}

// Simpan ke temp
$temp_dir  = sys_get_temp_dir() . '/meel_auto_meta';
if (!is_dir($temp_dir)) @mkdir($temp_dir, 0755, true);

$temp_file = $temp_dir . '/' . uniqid('meta_', true) . '.' . $ext;
if (!move_uploaded_file($_FILES['audio']['tmp_name'], $temp_file)) {
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan file temp.']);
    exit;
}

$title       = '';
$artist      = '';
$album       = '';
$description = '';
$cover_b64   = '';

// Baca metadata via ffprobe (JSON output → robust)
$meta_cmd = 'export LD_LIBRARY_PATH=\'\'; '
    . escapeshellarg($ffprobe)
    . ' -v error -show_entries format_tags=title,artist,album,comment,description:stream_tags=title,artist,album,comment,description'
    . ' -of json '
    . escapeshellarg($temp_file) . ' 2>/dev/null';

$meta_json = shell_exec($meta_cmd);
if ($meta_json) {
    $parsed = json_decode($meta_json, true);

    // Vorbis comment (FLAC/OGG) sering memakai key HURUF BESAR
    // (TITLE/ARTIST/ALBUM), sedangkan ID3 (MP3) umumnya lowercase.
    // Normalisasi ke lowercase agar keduanya terbaca dengan benar.
    $tags = array_change_key_case($parsed['format']['tags'] ?? [], CASE_LOWER);
    $title  = trim($tags['title']  ?? '');
    $artist = trim($tags['artist'] ?? '');
    $album  = trim($tags['album']  ?? '');
    $description = trim($tags['comment'] ?? ($tags['description'] ?? ''));

    // menaruh sebagian tag di stream, sebagian di format).
    foreach (($parsed['streams'] ?? []) as $stream) {
        if (($stream['codec_type'] ?? '') !== 'audio') continue;
        $st = array_change_key_case($stream['tags'] ?? [], CASE_LOWER);
        if (!$st) continue;
        if ($title  === '') $title  = trim($st['title']  ?? '');
        if ($artist === '') $artist = trim($st['artist'] ?? '');
        if ($album  === '') $album  = trim($st['album']  ?? '');
        if ($description === '') $description = trim($st['comment'] ?? ($st['description'] ?? ''));
        if ($title !== '' && $artist !== '' && $album !== '' && $description !== '') break;
    }
}

// Ekstrak cover art via ffmpeg (opsional — dilewati jika ffmpeg tidak ada)
if (is_executable($ffmpeg)) {
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
}

// Cleanup
@unlink($temp_file);
if (!empty($cover_path) && file_exists($cover_path)) @unlink($cover_path);
@rmdir($temp_dir);

// Response
echo json_encode([
    'status'      => 'success',
    'title'       => $title,
    'artist'      => $artist,
    'album'       => $album,
    'description' => $description,
    'cover'       => $cover_b64,
]);
