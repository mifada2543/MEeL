<?php
/* @package MEeL */

if (!function_exists('render_comments')) {
/**
 * @param int $parent_id ID parent comment (0 untuk root)
 * @param array $grouped Comments yang sudah dikelompokkan per parent
 * @param int $level Level nesting (internal, untuk rekursi)
 * @param string $theme 'video' (merah) atau 'music' (oranye)
 * @param int $playlist_context ID playlist untuk link navigasi (0 jika tidak ada)
 */
function render_comments(int $parent_id, array $grouped, int $level = 0, string $theme = 'video', int $playlist_context = 0): void
{
    global $id, $user_map, $uploader_id;
    $uploader_id = (int)($uploader_id ?? 0);
    if (!isset($grouped[$parent_id])) return;

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
    $form_action_url   = base_url(($is_video ? '/video/watch' : '/music/watch') . '?id=' . (int)$id . (!$is_video && $playlist_context > 0 ? '&playlist_id=' . (int)$playlist_context : ''));

    foreach ($grouped[$parent_id] as $c):
        $author      = $c['username'] ?? 'Guest';
        $parent_user = ($c['parent_id'] > 0) ? ($user_map[$c['parent_id']] ?? 'Guest') : null;
        $indent      = min($level * 16, 48);
?>
        <div class="comment-row flex gap-3 p-3 rounded-xl" data-id="<?= (int)$c['id'] ?>" style="margin-left:<?= $indent ?>px">
            <div class="w-8 h-8 rounded-full bg-gradient-to-br <?= $c_avatar_from ?> <?= $c_avatar_to ?> flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                <?= strtoupper(substr($author, 0, 1)) ?>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between gap-2 mb-1">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="text-[11px] font-bold <?= $c_author ?> truncate">@<?= htmlspecialchars($author) ?></span>
                        <?php
                        $_c_role = $c['role'] ?? '';
                        if ($_c_role === 'admin' || $_c_role === 'member'):
                            $_badge_color = ($_c_role === 'admin')
                                ? 'bg-red-500/15 text-red-400 border-red-500/30'
                                : 'bg-blue-500/15 text-blue-400 border-blue-500/30';
                        ?>
                            <span class="text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded-md border flex-shrink-0 <?= $_badge_color ?>"
                                title="Role: <?= htmlspecialchars($_c_role) ?>">
                                <?= htmlspecialchars($_c_role) ?>
                            </span>
                        <?php endif; ?>
                        <span class="text-[10px] <?= $author_time_color ?> flex-shrink-0"><?= time_ago($c['created_at']) ?></span>
                    </div>
                    <?php
                        $is_owner   = (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$c['user_id']);
                        $is_uploader = ($uploader_id > 0 && isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $uploader_id);
                        $is_admin    = (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin');
                        $can_delete = $is_owner || $is_uploader || $is_admin;

                        if ($can_delete):
                        $_c_snippet = trim(preg_replace('/\s+/', ' ', (string)($c['comment'] ?? '')));
                        if (function_exists('mb_strlen') && mb_strlen($_c_snippet) > 60) {
                            $_c_snippet = mb_substr($_c_snippet, 0, 60) . '…';
                        } elseif (strlen($_c_snippet) > 60) {
                            $_c_snippet = substr($_c_snippet, 0, 60) . '…';
                        }

                        if ($_c_snippet === '') {
                            $delete_text = $is_owner
                                ? 'Yakin ingin menghapus komentar ini?'
                                : 'Yakin ingin menghapus komentar dari @' . $author . ' ini?';
                        } else {
                            $delete_text = $is_owner
                                ? 'Yakin ingin menghapus komentar \'' . $_c_snippet . '\'?'
                                : 'Yakin ingin menghapus komentar dari @' . $author . ': \'' . $_c_snippet . '\'?';
                        }

                        $delete_json = htmlspecialchars(json_encode([
                            'title'             => 'Hapus Komentar',
                            'text'              => $delete_text,
                            'confirmButtonText' => 'HAPUS',
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                    ?>
                        <a href="<?= base_url('/api/delete-comment') ?>?id=<?= (int)$c['id'] ?>"
                            hx-post="<?= base_url('/api/delete-comment') ?>"
                            hx-vals='{"id":"<?= (int)$c['id'] ?>","media_type":"<?= $is_video ? 'video' : 'music' ?>","media_id":"<?= (int)$id ?>"<?= (!$is_video && $playlist_context > 0) ? ',"playlist_id":"' . (int)$playlist_context . '"' : '' ?>,"csrf_token":"<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"}'
                            hx-target="#comment-list"
                            hx-swap="innerHTML"
                            hx-confirm="<?= htmlspecialchars($delete_text, ENT_QUOTES, 'UTF-8') ?>"
                            data-meel-confirm='<?= $delete_json ?>'
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
                        <form action="<?= $form_action_url ?>" method="post" class="flex gap-2"
                            hx-post="<?= base_url('/api/comment') ?>"
                            hx-target="#comment-list"
                            hx-swap="innerHTML"
                            hx-vals='{"id":"<?= $id ?>","media_type":"<?= $is_video ? 'video' : 'music' ?>"<?= (!$is_video && $playlist_context > 0) ? ',"playlist_id":"' . (int)$playlist_context . '"' : '' ?>}'
                            hx-on::after-request="if (event.detail.successful) { var box = this.parentElement; if (box) box.classList.add('hidden'); }">
                            <input type="hidden" name="parent_id" value="<?= $c['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
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
}

if (!function_exists('comment_preview')) {
/**
 * @param array $grouped Comments yang sudah dikelompokkan per parent
 * @param int $limit Berapa komentar terbaru yang dikembalikan (default 4)
 * @return array{text: string, latest_comment: ?array, items: array}
 */
function comment_preview(array $grouped, int $limit = 4): array
{
    $preview_txt    = 'Jadilah komentar pertama';
    $latest_comment = null;
    $items          = [];

    $all = [];
    foreach ($grouped as $_grp) {
        foreach ($_grp as $_c) {
            $all[] = $_c;
        }
    }
    usort($all, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);

    $items = array_slice($all, 0, max(1, $limit));
    $latest_comment = $items[0] ?? null;

    if ($latest_comment) {
        $preview_author = $latest_comment['username'] ?? 'Guest';
        $preview_body   = preg_replace('/\s+/', ' ', (string)($latest_comment['comment'] ?? ''));
        $preview_txt    = '@' . $preview_author . ': ' . $preview_body;
    }

    return ['text' => $preview_txt, 'latest_comment' => $latest_comment, 'items' => $items];
}
}

if (!function_exists('render_comment_empty_state')) {
/* @param string $theme 'video' (merah) atau 'music' (oranye) */
function render_comment_empty_state(string $theme = 'video'): void
{
    $color = ($theme === 'music') ? 'text-gray-700' : 'text-gray-300';
    echo "<div class='py-10 text-center text-[10px] $color uppercase tracking-widest'>Jadilah komentar pertama.</div>";
}
}
