<?php
define('ACCESS_GRANTED', true);
require_once '../../modules/core/helpers.php';

meel_boot_session();

include '../../auth/config.php';
// RateLimiter sudah dimuat oleh modules/core/helpers.php → modules/auth/
// loader.php (require_once). include ulang di sini = fatal
// "Cannot declare class RateLimiter" (regresi pemindahan ke modules/auth).
include '../../modules/media/MediaInteraction.php';

$is_ajax = !empty($_SERVER['HTTP_HX_REQUEST']);

// Redirect balik yang aman: referer DITERIMA hanya jika host sama (cegah
// open redirect). Fallback ke halaman asal module. Dipakai di semua jalur
// non-AJAX agar konsisten (CSRF gagal, rate limit, error, sukses).
if (!function_exists('safe_comment_back_url')) {
    function safe_comment_back_url(): string
    {
        $ref_url = $_SERVER['HTTP_REFERER'] ?? '';
        if ($ref_url !== '') {
            $allowed_host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), PHP_URL_HOST);
            $ref_host     = parse_url($ref_url, PHP_URL_HOST);
            if ($ref_host !== $allowed_host) {
                $ref_url = '';
            }
        }
        return $ref_url !== '' ? $ref_url : 'index.php';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        if ($is_ajax) {
            http_response_code(403);
            header('HX-Retarget: #comment-alert');
            header('HX-Reswap: innerHTML');
            echo '<div class="p-3 rounded-xl text-[10px] font-bold uppercase tracking-wider border border-red-500/30 bg-red-500/10 text-red-400">CSRF Token tidak valid. Muat ulang halaman.</div>';
        } else {
            $_SESSION['error'] = 'CSRF Token tidak valid.';
            header('Location: ' . safe_comment_back_url());
        }
        exit;
    }
}

// RATE LIMIT: 10 comments per menit per user
$rateKey  = 'user_' . ($_SESSION['user_id'] ?? 0);
$rateRole = get_user_role($conn, (int)($_SESSION['user_id'] ?? 0));
$rateCheck = RateLimiter::check($rateKey, 'comment', $rateRole);
if (!$rateCheck['allowed']) {
    if ($is_ajax) {
        http_response_code(429);
        header('HX-Retarget: #comment-alert');
        header('HX-Reswap: innerHTML');
        echo '<div class="p-3 rounded-xl text-[10px] font-bold uppercase tracking-wider border border-yellow-500/30 bg-yellow-500/10 text-yellow-500">⏱️ Terlalu banyak request. Coba lagi dalam ' . (int)$rateCheck['retry_after'] . ' detik.</div>';
    } else {
        $_SESSION['error'] = 'Terlalu banyak request. Coba lagi dalam ' . $rateCheck['retry_after'] . ' detik.';
        header('Location: ' . safe_comment_back_url());
    }
    exit;
}

$comment_id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));

if (defined('APP_DEBUG') && APP_DEBUG) { error_log("DELETE_COMMENT.PHP - ID: $comment_id"); }

$interaction = new MediaInteraction($conn, $_SESSION['user_id'] ?? null);
$result = $interaction->deleteComment($comment_id);

if (defined('APP_DEBUG') && APP_DEBUG) { error_log("DELETE_COMMENT.PHP - Result: " . json_encode($result)); }

if (!$result['success']) {
    if (defined('APP_DEBUG') && APP_DEBUG) { error_log("DELETE_COMMENT - ERROR: {$result['message']}"); }
    if ($is_ajax) {
        http_response_code((int)($result['http_code'] ?? 400));
        header('HX-Retarget: #comment-alert');
        header('HX-Reswap: innerHTML');
        echo '<div class="p-3 rounded-xl text-[10px] font-bold uppercase tracking-wider border border-red-500/30 bg-red-500/10 text-red-400">' . htmlspecialchars($result['message'], ENT_QUOTES) . '</div>';
    } else {
        $_SESSION['error'] = $result['message'];
        header('Location: ' . safe_comment_back_url());
    }
    exit;
}

// ─── Mode AJAX: render ulang daftar komentar ───
if ($is_ajax) {
    require_once __DIR__ . '/../../modules/media/MediaViewer.php';
    require_once __DIR__ . '/../../modules/core/CommentRenderer.php';

    $media_type = (($_POST['media_type'] ?? 'video') === 'music') ? 'music' : 'video';
    $media_id   = (int)($_POST['media_id'] ?? 0);
    if ($media_id <= 0) {
        http_response_code(400);
        header('HX-Retarget: #comment-alert');
        header('HX-Reswap: innerHTML');
        echo '<div class="p-3 rounded-xl text-[10px] font-bold uppercase tracking-wider border border-red-500/30 bg-red-500/10 text-red-400">Media tidak valid.</div>';
        exit;
    }

    $playlist_context = (int)($_POST['playlist_id'] ?? 0);

    $viewer = new MediaViewer($conn, (int)($_SESSION['user_id'] ?? 0), $media_type, $media_id);
    $comments_data = $viewer->getComments();
    $grouped       = $comments_data['grouped'];
    $user_map      = $comments_data['user_map'];

    $media_row = $viewer->getMediaData();
    $GLOBALS['uploader_id'] = (int)($media_row['user_id'] ?? 0);

    $GLOBALS['id']       = $media_id;
    $GLOBALS['user_map'] = $user_map;

    if (empty($grouped)) {
        render_comment_empty_state($media_type);
    } else {
        render_comments(0, $grouped, 0, $media_type, $playlist_context);
    }
    exit;
}

// ─── Fallback non-JS: flash message + redirect balik (dengan validasi host) ───
$_SESSION['success'] = $result['message'];
header('Location: ' . safe_comment_back_url());
exit;
