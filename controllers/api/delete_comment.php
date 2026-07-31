<?php
/**
 * controllers/api/delete_comment.php
 *
 * Hapus komentar (dengan ownership check).
 *
 * Dua mode:
 *   1. AJAX (HTMX, hx-post): hapus lalu render ulang daftar komentar
 *      (#comment-list innerHTML) — tanpa reload halaman.
 *   2. Fallback non-JS (GET): hapus lalu redirect balik ke referer.
 *
 * Request:
 *   - id          (int, required) ID komentar yang akan dihapus
 *   - media_type  (string) 'video' | 'music' (untuk render ulang AJAX)
 *   - media_id    (int) ID media (untuk render ulang AJAX)
 *   - playlist_id (int, optional) konteks playlist music
 *   - csrf_token  (string, required untuk mode AJAX/POST)
 *
 * Security:
 *   - Ownership check via MediaInteraction::deleteComment()
 *   - CSRF verification untuk request POST (HTMX)
 *   - Open redirect protection via validasi HTTP_HOST pada fallback
 *
 * Dependencies:
 *   - auth/config.php ($conn, $_SESSION)
 *   - modules/media/MediaInteraction.php
 *   - modules/media/MediaViewer.php (render ulang AJAX)
 *   - modules/core/CommentRenderer.php (render ulang AJAX)
 */

define('ACCESS_GRANTED', true);
require_once '../../modules/core/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('meel');
    session_start();
}

include '../../auth/config.php';
include '../../modules/core/RateLimiter.php';
include '../../modules/media/MediaInteraction.php';

$is_ajax = !empty($_SERVER['HTTP_HX_REQUEST']);

// 🔒 CSRF: wajib untuk jalur POST (AJAX). GET fallback tetap apa adanya (historis).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        if ($is_ajax) {
            http_response_code(403);
            header('HX-Retarget: #comment-alert');
            header('HX-Reswap: innerHTML');
            echo '<div class="p-3 rounded-xl text-[10px] font-bold uppercase tracking-wider border border-red-500/30 bg-red-500/10 text-red-400">CSRF Token tidak valid. Muat ulang halaman.</div>';
        } else {
            $_SESSION['error'] = 'CSRF Token tidak valid.';
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
        }
        exit;
    }
}

// ⚡ RATE LIMIT: 10 comments per menit per user
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
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
    }
    exit;
}

// Get comment ID (POST untuk AJAX, GET untuk fallback)
$comment_id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));

error_log("DELETE_COMMENT.PHP - ID: $comment_id");

// Gunakan MediaInteraction class
$interaction = new MediaInteraction($conn, $_SESSION['user_id'] ?? null);
$result = $interaction->deleteComment($comment_id);

// Log result
error_log("DELETE_COMMENT.PHP - Result: " . json_encode($result));

// Handle response
if (!$result['success']) {
    error_log("DELETE_COMMENT - ERROR: {$result['message']}");
    if ($is_ajax) {
        http_response_code((int)($result['http_code'] ?? 400));
        header('HX-Retarget: #comment-alert');
        header('HX-Reswap: innerHTML');
        echo '<div class="p-3 rounded-xl text-[10px] font-bold uppercase tracking-wider border border-red-500/30 bg-red-500/10 text-red-400">' . htmlspecialchars($result['message'], ENT_QUOTES) . '</div>';
    } else {
        $_SESSION['error'] = $result['message'];
        $ref_url = $_SERVER['HTTP_REFERER'] ?? '';
        if ($ref_url !== '') {
            $allowed_host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), PHP_URL_HOST);
            $ref_host = parse_url($ref_url, PHP_URL_HOST);
            if ($ref_host !== $allowed_host) {
                $ref_url = 'index.php';
            }
        }
        if ($ref_url === '') {
            $ref_url = 'index.php';
        }
        header("Location: " . $ref_url);
    }
    exit;
}

// ── Mode AJAX: render ulang daftar komentar ────────────────────────────────
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

    // render_comments() membaca global $id dan $user_map
    $GLOBALS['id']       = $media_id;
    $GLOBALS['user_map'] = $user_map;

    if (empty($grouped)) {
        render_comment_empty_state($media_type);
    } else {
        render_comments(0, $grouped, 0, $media_type, $playlist_context);
    }
    exit;
}

// ── Fallback non-JS: flash message + redirect balik (dengan validasi host) ──
$_SESSION['success'] = $result['message'];

$ref_url = $_SERVER['HTTP_REFERER'] ?? '';
if ($ref_url !== '') {
    $allowed_host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), PHP_URL_HOST);
    $ref_host = parse_url($ref_url, PHP_URL_HOST);
    if ($ref_host !== $allowed_host) {
        $ref_url = 'index.php';
    }
}
if ($ref_url === '') {
    $ref_url = 'index.php';
}
header("Location: " . $ref_url);
exit;
