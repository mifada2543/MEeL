<?php


require_once __DIR__ . '/../../../modules/auth/helpers/session.php';
meel_boot_session();

require_once __DIR__ . '/../../../auth/settings.php';
$conn = new mysqli($server, $username, $password, $db);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}
$conn->set_charset('utf8mb4');

require_once __DIR__ . '/../../../modules/auth/helpers/user.php';
require_once __DIR__ . '/../../../modules/auth/helpers/authz.php';
require_once __DIR__ . '/../../../modules/auth/helpers/csrf.php';

// Helper upload bersama (meel_reserve_unique_filename) — satu sumber
// kebenaran untuk alokasi nama file atomik.
require_once __DIR__ . '/../../../modules/core/helpers/upload.php';


$FFMPEG_BIN  = defined('MEEL_FFMPEG_PATH') && MEEL_FFMPEG_PATH !== '' ? MEEL_FFMPEG_PATH : 'ffmpeg';
$FFPROBE_BIN = defined('MEEL_FFPROBE_PATH') && MEEL_FFPROBE_PATH !== '' ? MEEL_FFPROBE_PATH : 'ffprobe';

$UPLOAD_DIR   = __DIR__ . '/../uploads/';
$AUDIO_DIR    = $UPLOAD_DIR . 'audio/';
$COVER_DIR    = $UPLOAD_DIR . 'cover/';
$MAX_DURATION = 300;
$MAX_AUDIO_SIZE = 20 * 1024 * 1024;
$ALLOWED_AUDIO_MIME = [
    'audio/mpeg', 'audio/mp3',
    'audio/ogg', 'audio/opus', 'audio/vorbis',
    'audio/flac', 'audio/x-flac',
    'audio/wav', 'audio/x-wav',
];
$ALLOWED_AUDIO_EXT = ['mp3', 'ogg', 'opus', 'flac', 'wav'];

function api_respond($data, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(string $msg, int $code = 400) {
    api_respond(['error' => $msg], $code);
}

function require_auth() {
    if (!isset($_SESSION['user_id'])) {
        api_error('Silakan login terlebih dahulu.', 401);
    }
    return (int) $_SESSION['user_id'];
}

function get_auth_user() {
    global $conn;
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'role' => isset($_SESSION['user_id']) ? get_user_role($conn, (int)$_SESSION['user_id']) : null,
    ];
}



function probe_duration(string $filepath): float {
    global $FFPROBE_BIN;
    $cmd = escapeshellarg($FFPROBE_BIN)
        . ' -v quiet -show_entries format=duration -of csv=p=0 '
        . escapeshellarg($filepath) . ' 2>&1';
    $output = trim(shell_exec($cmd));
    $duration = (float) $output;
    return ($duration > 0) ? $duration : 0;
}



function probe_audio_info(string $filepath): array {
    global $FFPROBE_BIN;
    $cmd = escapeshellarg($FFPROBE_BIN)
        . ' -v quiet -print_format json -show_format -show_streams '
        . escapeshellarg($filepath) . ' 2>&1';
    $json = shell_exec($cmd);
    $data = json_decode($json, true);
    return $data ?? [];
}



function transcode_flac_to_opus(string $input, string $output): ?string {
    global $FFMPEG_BIN;
    $cmd = "export LD_LIBRARY_PATH=''; "
        . escapeshellarg($FFMPEG_BIN) . " -y -i "
        . escapeshellarg($input)
        . " -c:a libopus -vbr on -compression_level 10 "
        . escapeshellarg($output) . " 2>&1";
    exec($cmd, $out, $ret);
    if ($ret === 0 && file_exists($output) && filesize($output) > 0) {
        @unlink($input);
        return $output;
    }
    return false;
}



function validate_audio_mime(string $filepath): ?string {
    global $ALLOWED_AUDIO_MIME;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($filepath);
    return in_array($mime, $ALLOWED_AUDIO_MIME, true) ? $mime : null;
}



function unique_filename(string $base, string $ext, string $dir): string {
    // Delegasi ke helper bersama (meel_reserve_unique_filename, fopen O_EXCL)
    // — dua request bersamaan tidak bisa memilih nama yang sama. Placeholder
    // kosong dibuat lalu ditimpa oleh move_uploaded_file/transcoder.
    $reserved = meel_reserve_unique_filename($dir, $base, $ext, 200);
    if ($reserved !== null) {
        return $reserved;
    }
    return $base . '.' . $ext;
}



function sanitize_filename(string $name): string {
    $name = preg_replace('/[^\w\-]/u', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    $name = trim($name, '_-');
    return substr($name, 0, 60) ?: 'beatmap-' . time();
}
