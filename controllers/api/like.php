<?php
require_once '../../modules/core/helpers.php';
// Session terpusat dengan cookie flags aman (HttpOnly, SameSite=Lax)
meel_boot_session();

include '../../auth/config.php';
include '../../modules/auth/RateLimiter.php';
include '../../modules/media/MediaInteraction.php';

// Verifikasi token untuk AJAX POST
if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    http_response_code(403);
    exit;
}

// RATE LIMIT: 30 likes per menit per user
$rateKey = 'user_' . ($_SESSION['user_id'] ?? 0);
$rateRole = get_user_role($conn, (int)($_SESSION['user_id'] ?? 0));
$rateCheck = RateLimiter::check($rateKey, 'like', $rateRole);
if (!$rateCheck['allowed']) {
    http_response_code(429);
    header('HX-Retarget: #like-dislike-container');
    header('HX-Reswap: innerHTML');
    $media_type = isset($_POST['media_type']) ? trim($_POST['media_type']) : 'video';
    $inactive_class = 'bg-gray-900/40 border-gray-800 text-gray-400 hover:bg-gray-800/60 hover:text-gray-300';
    ?>
    <div id="like-dislike-container" class="flex items-center gap-2 mt-4 sm:mt-0">
        <div class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider border border-yellow-500/30 bg-yellow-500/10 text-yellow-500">
            ⏱️ Wait <?= $rateCheck['retry_after'] ?>s
        </div>
        <button class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer <?= $inactive_class ?>">
            Like
        </button>
        <button class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer <?= $inactive_class ?>">
            Dislike
        </button>
    </div>
    <?php
    exit;
}

$id         = isset($_POST['id'])         ? intval($_POST['id'])       : 0;
$media_type = isset($_POST['media_type']) ? trim($_POST['media_type']) : '';
$type       = isset($_POST['type'])       ? trim($_POST['type'])       : '';

if (defined('APP_DEBUG') && APP_DEBUG) { error_log("LIKE.PHP - POST: " . json_encode(['id' => $id, 'media_type' => $media_type, 'type' => $type])); }

$user_id = $_SESSION['user_id'] ?? null;

// Validasi is_active == 1 dan role bukan 'guest'
if ($user_id) {
    $stmt_user = $conn->prepare("SELECT is_active, role FROM users WHERE id = ? LIMIT 1");
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $user = $stmt_user->get_result()->fetch_assoc();

    if (!$user || $user['is_active'] != 1 || $user['role'] === 'guest') {
        if (defined('APP_DEBUG') && APP_DEBUG) { error_log("LIKE.PHP - BLOCKED: User ID $user_id is inactive or guest."); }
        http_response_code(403); // HTTP 403 Forbidden
        exit;
    }
} else {
    // Jika tidak ada user_id di session (belum login)
    http_response_code(401); // HTTP 401 Unauthorized
    exit;
}

$interaction = new MediaInteraction($conn, $user_id);
$result = $interaction->toggleLike($id, $media_type, $type);

if (!$result['success']) {
    if (defined('APP_DEBUG') && APP_DEBUG) { error_log("LIKE.PHP - ERROR: {$result['message']} (Code: {$result['http_code']})"); }
    http_response_code($result['http_code']);
    exit;
}

$user_interaction = $result['data']['user_interaction'];
$likes            = $result['data']['likes'];
$dislikes         = $result['data']['dislikes'];
$table = ($media_type === 'music') ? 'music' : 'video';

$like_active_class = ($media_type === 'music')
    ? 'bg-orange-500/15 border-orange-500/30 text-orange-400'
    : 'bg-red-500/15 border-red-500/30 text-red-400';

$dislike_active_class = ($media_type === 'music')
    ? 'bg-orange-500/15 border-orange-500/30 text-orange-400'
    : 'bg-red-500/15 border-red-500/30 text-red-400';

$inactive_class = 'bg-gray-900/40 border-gray-800 text-gray-400 hover:bg-gray-800/60 hover:text-gray-300';
?>
<div id="like-dislike-container" class="flex items-center gap-2 mt-4 sm:mt-0" hx-get-trigger="load">
    <button
        hx-post="../api/like" hx-target="#like-dislike-container" hx-swap="outerHTML"
        hx-vals='{"id":"<?= $id ?>","media_type":"<?= $media_type ?>","type":"like","csrf_token":"<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"}'
        class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer <?= $user_interaction === 'like' ? $like_active_class : $inactive_class ?>">
        <i data-lucide="thumbs-up" class="w-3.5 h-3.5 <?= $user_interaction === 'like' ? 'fill-current' : '' ?>"></i>
        Like<?= $likes > 0 ? " <span class='tabular-nums ml-0.5'>{$likes}</span>" : '' ?>
    </button>

    <button
        hx-post="../api/like" hx-target="#like-dislike-container" hx-swap="outerHTML"
        hx-vals='{"id":"<?= $id ?>","media_type":"<?= $media_type ?>","type":"dislike","csrf_token":"<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"}'
        class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer <?= $user_interaction === 'dislike' ? $dislike_active_class : $inactive_class ?>">
        <i data-lucide="thumbs-down" class="w-3.5 h-3.5 <?= $user_interaction === 'dislike' ? 'fill-current' : '' ?>"></i>
        <?= $dislikes > 0 ? "<span class='tabular-nums'>{$dislikes}</span>" : '' ?>
    </button>

    <?php if ($media_type === 'music'): ?>
        <button onclick="document.getElementById('playlist-modal').classList.remove('hidden')"
            class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer bg-gray-900/40 border-gray-800 text-gray-400 hover:bg-gray-800/60 hover:text-gray-300">
            <i data-lucide="plus" class="w-3.5 h-3.5"></i>
            Playlist
        </button>
    <?php endif; ?>
</div>
