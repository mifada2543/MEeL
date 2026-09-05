<?php
/**
 * Fragment AJAX (htmx) untuk profil/channel: memuat batch berikutnya dari
 * feed konten user dan merender kartu + tombol load-more lanjutan (atau
 * penanda akhir). Dipanggil oleh profile/index.php via hx-get="channel-more?".
 *
 * - tab=all   → feed campuran video+musik (UNION, urut upload_date DESC)
 * - tab=video → hanya video
 * - tab=music → hanya musik
 *
 * Params: u (username), tab, offset.
 */
require_once '../modules/auth/helpers/session.php';
meel_boot_session();
require_once '../auth/config.php';
require_once '../modules/media/ProfileRepository.php';

$target_user = $_GET['u'] ?? '';
$active_tab  = $_GET['tab'] ?? 'all';
if (!in_array($active_tab, ['all', 'video', 'music'], true)) {
    $active_tab = 'all';
}

$offset = max(0, (int) ($_GET['offset'] ?? 0));
$limit  = 12;

if ($target_user === '' || $target_user === 'guest') {
    header('Location: ' . base_url('/err/?code=not_found'), true, 302);
    exit;
}

$profileRepo = new ProfileRepository($conn);
$user = $profileRepo->findByUsername($target_user);
if (!$user) {
    header('Location: ' . base_url('/err/?code=not_found'), true, 302);
    exit;
}

$user_id = (int) $user['id'];

if ($active_tab === 'video') {
    $items = $profileRepo->getVideosPaginated($user_id, $limit, $offset);
    $total = $profileRepo->countVideo($user_id);
} elseif ($active_tab === 'music') {
    $items = $profileRepo->getMusicPaginated($user_id, $limit, $offset);
    $total = $profileRepo->countMusic($user_id);
} else {
    $items = $profileRepo->getFeedPaginated($user_id, $limit, $offset);
    $total = $profileRepo->countVideo($user_id) + $profileRepo->countMusic($user_id);
}

$next     = $offset + count($items);
$has_more = $next < $total;
$more_url = 'channel-more?u=' . rawurlencode($target_user)
          . '&tab=' . $active_tab
          . '&offset=' . $next;
$more_label = $active_tab === 'all' ? 'Konten' : ($active_tab === 'video' ? 'Video' : 'Musik');
?>

<?php foreach ($items as $item):
    $is_music = ($item['type'] ?? $active_tab) === 'music';
    $thumb = !empty($item['thumbnail'])
        ? ($is_music ? '../music/upload/thumbnail/' : '../video/upload/thumbnail/') . htmlspecialchars($item['thumbnail'])
        : ($is_music ? '../assets/img/music0.webp' : '../assets/img/video0.webp');
    $watch = base_url(($is_music ? '/music' : '/video') . '/watch?id=' . (int)$item['id']);
?>
    <div class="content-card">
        <a href="<?= $watch ?>" class="block card-thumb relative" title="<?= htmlspecialchars($item['title']) ?>">
            <span class="type-badge <?= $is_music ? 'music' : 'video' ?>"><?= $is_music ? 'Music' : 'Video' ?></span>
            <img src="<?= $thumb ?>" alt="<?= htmlspecialchars($item['title']) ?>" loading="lazy" width="640" height="360">
        </a>
        <div class="card-body">
            <a href="<?= $watch ?>" class="card-title no-underline hover:text-<?= $is_music ? 'orange' : 'red' ?>-400 transition-colors" title="<?= htmlspecialchars($item['title']) ?>">
                <?= htmlspecialchars($item['title']) ?>
            </a>
            <div class="card-meta">
                <?php if ($is_music): ?>
                    <span><?= htmlspecialchars($item['artist'] ?? 'Unknown') ?></span>
                    <span>•</span>
                <?php endif; ?>
                <span><?= number_format($item['views'] ?? 0) ?> views</span>
                <span>•</span>
                <span><?= date('d M Y', strtotime($item['upload_date'])) ?></span>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($has_more): ?>
    <button type="button" id="channel-more-area"
        class="col-span-full h-16 flex items-center justify-center gap-2 bg-white/[.02] border border-dashed border-white/[.06] rounded-2xl cursor-pointer hover:border-white/10 hover:bg-white/[.03] transition-all group"
        hx-get="<?= htmlspecialchars($more_url) ?>"
        hx-target="#channel-more-area"
        hx-swap="outerHTML"
        aria-label="Muat lebih banyak <?= strtolower($more_label) ?>">
        <span class="text-[10px] font-bold uppercase tracking-[.2em] text-gray-300 group-hover:text-white transition-colors">
            <i data-lucide="chevrons-down" class="w-3.5 h-3.5 inline-block -mr-1 mr-1.5"></i>
            Muat Lebih Banyak <?= $more_label ?>
        </span>
    </button>
<?php else: ?>
    <div class="col-span-full h-16 flex items-center justify-center gap-2 border border-dashed border-white/[.04] rounded-2xl">
        <span class="text-[9px] text-gray-600 uppercase tracking-widest flex items-center gap-2">
            <i data-lucide="check-check" class="w-3.5 h-3.5"></i>
            Semua <?= $more_label ?> Dimuat
        </span>
    </div>
<?php endif; ?>
<script>lucide.createIcons();</script>