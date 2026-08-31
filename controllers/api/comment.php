<?php
require_once '../../modules/core/helpers.php';

meel_boot_session();

include '../../auth/config.php';
require_once __DIR__ . '/../../modules/media/MediaViewer.php';
require_once __DIR__ . '/../../modules/core/CommentRenderer.php';

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

$viewer = new MediaViewer($conn, $user_id, $media_type, $media_id);
if (!$viewer->addComment($_POST)) {
    http_response_code(400);
    header('HX-Retarget: #comment-alert');
    header('HX-Reswap: innerHTML');
    echo '<div class="p-3 rounded-xl text-[10px] font-bold uppercase tracking-wider border border-red-500/30 bg-red-500/10 text-red-400">Gagal mengirim komentar.</div>';
    exit;
}

$comments_data = $viewer->getComments();
$grouped       = $comments_data['grouped'];
$user_map      = $comments_data['user_map'];

$playlist_context = (int)($_POST['playlist_id'] ?? 0);

$media_row = $viewer->getMediaData();
$GLOBALS['uploader_id'] = (int)($media_row['user_id'] ?? 0);

$GLOBALS['id']        = $media_id;
$GLOBALS['user_map']  = $user_map;

if (empty($grouped)) {
    render_comment_empty_state($media_type);
} else {
    render_comments(0, $grouped, 0, $media_type, $playlist_context);
}
