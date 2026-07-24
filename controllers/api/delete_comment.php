<?php
/**
 * controllers/api/delete_comment.php
 * 
 * GET /api/delete_comment — Hapus komentar (dengan ownership check).
 *
 * Query params:
 *   - id (int, required) ID komentar yang akan dihapus
 *
 * Response:
 *   302 Redirect ke HTTP_REFERER (dengan validasi host)
 *   - Flash message disimpan di $_SESSION['success'] atau $_SESSION['error']
 *
 * Security:
 *   - Ownership check via MediaInteraction::deleteComment()
 *   - Open redirect protection via validasi HTTP_HOST
 *
 * Dependencies:
 *   - auth/config.php ($conn, $_SESSION)
 *   - modules/media/MediaInteraction.php
 */

define('ACCESS_GRANTED', true);
require_once '../../modules/helpers.php';
include '../../auth/config.php';
include '../../modules/RateLimiter.php';
include '../../modules/media/MediaInteraction.php';

// ⚡ RATE LIMIT: 10 comments per menit per user
$rateKey  = 'user_' . ($_SESSION['user_id'] ?? 0);
$rateRole = get_user_role($conn, (int)($_SESSION['user_id'] ?? 0));
$rateCheck = RateLimiter::check($rateKey, 'comment', $rateRole);
if (!$rateCheck['allowed']) {
    $_SESSION['error'] = 'Terlalu banyak request. Coba lagi dalam ' . $rateCheck['retry_after'] . ' detik.';
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
    exit;
}

// Get comment ID
$comment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

error_log("DELETE_COMMENT.PHP - ID: $comment_id");

// Gunakan MediaInteraction class
$interaction = new MediaInteraction($conn, $_SESSION['user_id'] ?? null);
$result = $interaction->deleteComment($comment_id);

// Log result
error_log("DELETE_COMMENT.PHP - Result: " . json_encode($result));

// Handle response
if (!$result['success']) {
    error_log("DELETE_COMMENT - ERROR: {$result['message']}");
    $_SESSION['error'] = $result['message'];
} else {
    $_SESSION['success'] = $result['message'];
}

// Redirect back — dengan validasi HTTP_REFERER cegah open redirect
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
