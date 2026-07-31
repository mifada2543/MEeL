<?php
/**
 * controllers/api/comment.php
 *
 * POST /api/comment — Kirim komentar video/music via AJAX (HTMX).
 *
 * Request (form-urlencoded, di-serialisasi oleh HTMX):
 *   - comments    (string, required) teks komentar
 *   - parent_id   (int, optional) ID komentar yang dibalas (0/null = root)
 *   - csrf_token  (string, required) token CSRF dari session
 *   - media_type  (string) 'video' | 'music' (default 'video')
 *   - id          (int, required) ID media
 *
 * Response (HTML partial):
 *   - Sukses : innerHTML untuk #comment-list (seluruh daftar komentar
 *     dirender ulang, termasuk komentar baru di bagian bawah)
 *   - Error  : HTTP 4xx + snippet HTML untuk #comment-alert (HX-Retarget)
 *
 * Dependencies:
 *   - helpers.php (verify_csrf_token, get_user_role)
 *   - auth/config.php ($conn, $_SESSION)
 *   - modules/core/RateLimiter.php
 *   - modules/media/MediaViewer.php
 *   - modules/core/CommentRenderer.php
 */

require_once '../../modules/core/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('meel');
    session_start();
}

include '../../auth/config.php';
include '../../modules/core/RateLimiter.php';
require_once __DIR__ . '/../../modules/media/MediaViewer.php';
require_once __DIR__ . '/../../modules/core/CommentRenderer.php';

// CSRF: verifikasi token untuk AJAX POST
if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    http_response_code(403);
    header('HX-Retarget: #comment-alert');
    header('HX-Reswap: innerHTML');
    echo '<div class="p-3 rounded-xl text-[10px] font-bold uppercase tracking-wider border border-red-500/30 bg-red-500/10 text-red-400">CSRF Token tidak valid. Muat ulang halaman.</div>';
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    http_response_code(401);
    exit;
}

$media_type = (($_POST['media_type'] ?? 'video') === 'music') ? 'music' : 'video';
$media_id   = (int)($_POST['id'] ?? 0);
if ($media_id <= 0) {
    http_response_code(400);
    header('HX-Retarget: #comment-alert');
    header('HX-Reswap: innerHTML');
    echo '<div class="p-3 rounded-xl text-[10px] font-bold uppercase tracking-wider border border-red-500/30 bg-red-500/10 text-red-400">Media tidak valid.</div>';
    exit;
}

// RATE LIMIT: 10 komentar per menit per user
$rateKey   = 'user_' . $user_id;
$rateRole  = get_user_role($conn, $user_id);
$rateCheck = RateLimiter::check($rateKey, 'comment', $rateRole);
if (!$rateCheck['allowed']) {
    http_response_code(429);
    header('HX-Retarget: #comment-alert');
    header('HX-Reswap: innerHTML');
    echo '<div class="p-3 rounded-xl text-[10px] font-bold uppercase tracking-wider border border-yellow-500/30 bg-yellow-500/10 text-yellow-500">⏱️ Terlalu banyak komentar. Coba lagi dalam ' . (int)$rateCheck['retry_after'] . ' detik.</div>';
    exit;
}

// Simpan komentar
$viewer = new MediaViewer($conn, $user_id, $media_type, $media_id);
if (!$viewer->addComment($_POST)) {
    http_response_code(400);
    header('HX-Retarget: #comment-alert');
    header('HX-Reswap: innerHTML');
    echo '<div class="p-3 rounded-xl text-[10px] font-bold uppercase tracking-wider border border-red-500/30 bg-red-500/10 text-red-400">Gagal mengirim komentar.</div>';
    exit;
}

// Render ulang seluruh daftar komentar (komentar baru otomatis di bagian bawah,
// sesuai urutan ASC created_at pada getComments())
$comments_data = $viewer->getComments();
$grouped       = $comments_data['grouped'];
$user_map      = $comments_data['user_map'];

// Konteks playlist (khusus music) agar link navigasi pada render ulang tetap utuh
$playlist_context = (int)($_POST['playlist_id'] ?? 0);

// render_comments() membaca global $id dan $user_map
$GLOBALS['id']        = $media_id;
$GLOBALS['user_map']  = $user_map;

if (empty($grouped)) {
    render_comment_empty_state($media_type);
} else {
    render_comments(0, $grouped, 0, $media_type, $playlist_context);
}
