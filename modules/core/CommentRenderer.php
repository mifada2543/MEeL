<?php
/**
 * modules/core/CommentRenderer.php
 *
 * Comment rendering helpers untuk video & music.
 * render_video_comments() dan render_music_comments() digabung menjadi
 * render_comments() dengan parameter $theme untuk menentukan warna.
 *
 * @package MEeL
 */

if (!function_exists('render_comments')) {
/**
 * Render nested comments untuk video atau music.
 *
 * @param int    $parent_id        ID parent comment (0 untuk root)
 * @param array  $grouped          Comments yang sudah dikelompokkan per parent
 * @param int    $level            Level nesting (internal, untuk rekursi)
 * @param string $theme            'video' (merah) atau 'music' (oranye)
 * @param int    $playlist_context ID playlist untuk link navigasi (0 jika tidak ada)
 */
function render_comments(int $parent_id, array $grouped, int $level = 0, string $theme = 'video', int $playlist_context = 0): void
{
    global $id, $user_map;
    if (!isset($grouped[$parent_id])) return;

    // ── Theme color mapping ──────────────────────────────────────────────
    $is_video = ($theme === 'video');

    $c_avatar_from    = $is_video ? 'from-red-600' : 'from-orange-500';
    $c_avatar_to      = $is_video ? 'to-red-900' : 'to-red-600';
    $c_author          = $is_video ? 'text-red-400' : 'text-gray-300';
    $c_comment_text    = $is_video ? 'text-gray-400' : 'text-gray-300';
    $c_delete           = $is_video ? 'text-gray-300' : 'text-gray-500';
    $c_parent_text      = $is_video ? 'text-blue-400' : 'text-orange-400';
    $c_parent_bg        = $is_video ? 'bg-blue-500/10' : 'bg-orange-500/10';
    $c_reply_btn        = $is_video ? 'text-gray-500 hover:text-red-400' : 'text-orange-400';
    $c_reply_focus      = $is_video ? 'border-red-500/40' : 'border-orange-500/40';
    $c_reply_btn_bg     = $is_video ? 'bg-red-600 hover:bg-red-500' : 'bg-orange-500';
    $c_reply_btn_text   = $is_video ? 'text-white' : 'text-black';
    $reply_prefix       = $is_video ? 'vid-' : 'mus-';
    $author_time_color  = $is_video ? 'text-gray-300' : 'text-gray-500';
    $playlist_qs        = (!$is_video && $playlist_context > 0) ? '&amp;playlist_id=' . $playlist_context : '';

    foreach ($grouped[$parent_id] as $c):
        $author      = $c['username'] ?? 'Guest';
        $parent_user = ($c['parent_id'] > 0) ? ($user_map[$c['parent_id']] ?? 'Guest') : null;
        $indent      = min($level * 16, 48);
?>
        <div class="comment-row flex gap-3 p-3 rounded-xl" style="margin-left:<?= $indent ?>px">
            <div class="w-8 h-8 rounded-full bg-gradient-to-br <?= $c_avatar_from ?> <?= $c_avatar_to ?> flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                <?= strtoupper(substr($author, 0, 1)) ?>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between gap-2 mb-1">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="text-[11px] font-bold <?= $c_author ?> truncate">@<?= htmlspecialchars($author) ?></span>
                        <span class="text-[10px] <?= $author_time_color ?> flex-shrink-0"><?= time_ago($c['created_at']) ?></span>
                    </div>
                    <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $c['user_id']): ?>
                        <a href="../controllers/api/delete_comment.php?id=<?= $c['id'] ?>"
                            onclick="return meelConfirmLink(event, { title: 'Hapus Komentar', text: 'Hapus komentar ini?', confirmButtonText: 'HAPUS' })"
                            class="<?= $c_delete ?> hover:text-red-400 transition-colors no-underline flex-shrink-0"
                            title="Hapus komentar">
                            <i data-lucide="trash-2" class="w-3 h-3"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <p class="text-sm <?= $c_comment_text ?> leading-relaxed">
                    <?php if ($parent_user): ?>
                        <span class="<?= $c_parent_text ?> text-[10px] font-bold <?= $c_parent_bg ?> px-1.5 py-0.5 rounded mr-1">@<?= htmlspecialchars($parent_user) ?></span>
                    <?php endif; ?>
                    <?= nl2br(htmlspecialchars($c['comment'])) ?>
                </p>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <button onclick="toggleReply('<?= $reply_prefix . $c['id'] ?>')"
                        class="text-[10px] font-bold <?= $c_reply_btn ?> uppercase tracking-wider mt-2 bg-none border-none cursor-pointer p-0 transition-colors"
                        title="Balas komentar ini">
                        Balas
                    </button>
                    <div id="<?= $reply_prefix . $c['id'] ?>" class="hidden mt-3">
                        <form action="watch.php?id=<?= $id ?><?= $playlist_qs ?>" method="post" class="flex gap-2">
                            <input type="hidden" name="parent_id" value="<?= $c['id'] ?>">
                            <?php if (!$is_video): ?>
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                            <?php endif; ?>
                            <input type="text" name="comments"
                                class="flex-1 bg-black/30 border border-white/[.06] rounded-xl px-3 py-2 text-xs text-gray-300 focus:outline-none <?= $c_reply_focus ?> min-w-0"
                                placeholder="Balas @<?= htmlspecialchars($author) ?>..." required>
                            <button name="send"
                                class="<?= $c_reply_btn_bg ?> <?= $c_reply_btn_text ?> text-[10px] font-black uppercase px-3 sm:px-4 py-2 rounded-xl border-none cursor-pointer transition-all flex-shrink-0"
                                title="Kirim balasan">
                                Kirim
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
<?php
        render_comments((int)$c['id'], $grouped, $level + 1, $theme, $playlist_context);
    endforeach;
}
} // end function_exists('render_comments')

// ── Backward compatibility aliases ──────────────────────────────────────────

if (!function_exists('render_video_comments')) {
/** @deprecated Gunakan render_comments($parent_id, $grouped, $level, 'video') */
function render_video_comments(int $parent_id, array $grouped, int $level = 0): void
{
    render_comments($parent_id, $grouped, $level, 'video');
}
}

if (!function_exists('render_music_comments')) {
/** @deprecated Gunakan render_comments($parent_id, $grouped, $level, 'music', $playlist_context) */
function render_music_comments(int $parent_id, array $grouped, int $level = 0, int $playlist_context = 0): void
{
    render_comments($parent_id, $grouped, $level, 'music', $playlist_context);
}
}
