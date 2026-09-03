<?php
require_once '../../modules/core/helpers.php';
require_once '../../auth/auth.php';
require_once '../../auth/config.php';
require_once '../../modules/core/Transcoder.php';
require_once '../../modules/core/GarbageCollector.php';
GarbageCollector::run();

/**
 * Post-encode musik dari unduhan URL (yt-dlp → encodeMusic).
 *
 * Keamanan:
 * - Hanya menerima POST + CSRF token (state-changing endpoint).
 * - `temp_file` diperlakukan sebagai OPAQUE TOKEN (bukan filesystem path):
 *     hanya nama file polos (basename, tanpa separator/..), dan WAJIB
 *     memiliki entri padanan di $_SESSION['meel_pending_music'] milik user
 *   yang sama. Metadata tidak pernah diambil dari request — hanya dari sesi.
 * - File fisik tetap di-resolve server-side di dalam direktori temp milik
 *   server (lihat Transcoder::resolveMusicInputPath()).
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    die("<h1>Error: Endpoint ini hanya menerima metode POST.</h1>");
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die("<h1>Error: CSRF token tidak valid. Muat ulang halaman lalu coba lagi.</h1>");
}

$temp_file = (string) ($_POST['temp_file'] ?? '');

if ($temp_file === '') {
    http_response_code(400);
    die("<h1>Error: Parameter temp_file tidak ditemukan.</h1>");
}

// Token format: nama file polos — tolak separator path, traversal, null byte.
if (!preg_match('/^[A-Za-z0-9._-]+$/', $temp_file) || str_contains($temp_file, '..')) {
    http_response_code(400);
    die("<h1>Error: Token temp_file tidak valid.</h1>");
}

$meta_key = pathinfo($temp_file, PATHINFO_FILENAME);

// Ownership + eksistensi: hanya job yang dibuat oleh sesi user ini.
$pending = is_array($_SESSION['meel_pending_music'] ?? null)
    ? ($_SESSION['meel_pending_music'][$meta_key] ?? null)
    : null;

if (!is_array($pending)) {
    http_response_code(403);
    die("<h1>Error: Job encoding tidak ditemukan atau bukan milik Anda.</h1>");
}

// Expiration: job lebih dari 1 jam dianggap basi.
if ((int)($pending['ts'] ?? 0) < time() - 3600) {
    unset($_SESSION['meel_pending_music'][$meta_key]);
    http_response_code(410);
    die("<h1>Error: Job encoding sudah kedaluwarsa. Silakan unduh ulang.</h1>");
}

$transcoder = new Transcoder($conn, (int)$_SESSION['user_id']);

// Server-side resolve: pastikan file berada di direktori temp milik server
// dan bukan path arbitrer. Metadata HANYA dari sesi — bukan dari request.
$input_path = $transcoder->resolveMusicInputPath($temp_file);
if ($input_path === null) {
    http_response_code(410);
    die("<h1>Error: File sumber tidak ditemukan atau sudah kedaluwarsa.</h1>");
}

$result = $transcoder->encodeMusic(
    $temp_file,
    (string) ($pending['title']       ?? 'Unknown'),
    (string) ($pending['artist']      ?? 'Unknown Artist'),
    (string) ($pending['album']       ?? 'Single'),
    (int)    ($pending['duration']    ?? 0),
    (string) ($pending['description'] ?? 'Upload by MEeL Engine')
);

if ($result['status'] === 'success') {
    unset($_SESSION['meel_pending_music'][$meta_key]);
    $base = defined('MEEL_BASE_URL') ? rtrim(MEEL_BASE_URL, '/') : '';
    header("Location: {$base}/upload?success=1&file=" . urlencode($result['filename']));
    exit;
}

http_response_code(500);
echo "<h1>FFmpeg Gagal Menghasilkan Ogg!</h1>";
echo "<pre>" . htmlspecialchars((string) ($result['msg'] ?? ''), ENT_QUOTES, 'UTF-8') . "</pre>";
