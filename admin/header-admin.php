<?php
// Tentukan role admin jika belum di-set di file utama
if (!isset($is_admin)) {
    $is_admin = false;
    if (isset($_SESSION['user_id']) && isset($conn)) {
        $query_user = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $query_user->bind_param("i", $_SESSION['user_id']);
        $query_user->execute();
        $user_data = $query_user->get_result()->fetch_assoc();
        $is_admin = ($user_data && $user_data['role'] === 'admin');
    }
}

// Default back URL if not set
if (!isset($back_url)) {
    if ($is_admin) {
        $back_url = 'index.php'; // Dashboard admin
    } else {
        $back_url = '../index.php';
    }
}

// Default variables
$nav_page_title = $page_title ?? 'Edit';
$nav_media_type = $media_type ?? 'music';
$nav_id         = $id ?? 0;
?>
<nav class="sticky top-0 z-50 bg-[#080b11]/90 backdrop-blur-md border-b border-white/5 px-6 h-14 flex items-center gap-3">
    <a href="../index.php" class="font-sans text-sm font-extrabold text-white no-underline tracking-wider">
        MEeL<?php if ($is_admin): ?><span class="text-blue-600">Admin</span><?php endif; ?>
    </a>
    <div class="w-px h-5 bg-white/10"></div>

    <?php if ($is_admin): ?>
        <?php if ($nav_media_type === 'dashboard'): ?>
            <span class="text-[11px] font-semibold text-gray-200">Dashboard</span>
        <?php elseif ($nav_media_type === 'analytics'): ?>
            <a href="index.php" class="text-[11px] font-semibold text-gray-500 no-underline hover:text-gray-300 transition-colors">Dashboard</a>
            <span class="text-gray-600">›</span>
            <span class="text-[11px] font-semibold text-gray-200"><?= htmlspecialchars($nav_page_title) ?></span>
        <?php else: ?>
            <a href="index.php" class="text-[11px] font-semibold text-gray-500 no-underline hover:text-gray-300 transition-colors">Dashboard</a>
            <span class="text-gray-600">›</span>
            <a href="cookies.php" class="text-[11px] font-semibold text-gray-500 no-underline hover:text-gray-300 transition-colors">Media Analytics</a>
            <span class="text-gray-600">›</span>
            <span class="text-[11px] font-semibold text-gray-200"><?= htmlspecialchars($nav_page_title) ?></span>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($nav_media_type === 'dashboard'): ?>
            <span class="text-[11px] font-semibold text-gray-200">Dashboard</span>
        <?php else: ?>
            <a href="../profile/index.php?u=<?= urlencode($_SESSION['username'] ?? '') ?>" class="text-[11px] font-semibold text-gray-500 no-underline hover:text-gray-300 transition-colors">Dashboard</a>
            <span class="text-gray-600">›</span>
            <?php if ($nav_media_type === 'video'): ?>
                <a href="../video/index.php" class="text-[11px] font-semibold text-gray-500 no-underline hover:text-gray-300 transition-colors">Video</a>
            <?php else: ?>
                <a href="../music/index.php" class="text-[11px] font-semibold text-gray-500 no-underline hover:text-gray-300 transition-colors">Musik</a>
            <?php endif; ?>
            <span class="text-gray-600">›</span>
            <span class="text-[11px] font-semibold text-gray-200"><?= htmlspecialchars($nav_page_title) ?></span>
        <?php endif; ?>
    <?php endif; ?>

    <div class="ml-auto flex items-center gap-2">
        <?php if ($nav_id > 0): ?>
            <?php
            $chip_color = ($nav_media_type === 'video') ? 'text-red-500 border-red-500/10 bg-red-500/5' : 'text-orange-500 border-orange-500/10 bg-orange-500/5';
            ?>
            <span class="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest <?= $chip_color ?> py-1.5 px-3 rounded-lg border cursor-default">
                #<?= $nav_id ?>
            </span>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($back_url) ?>" class="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest text-gray-500 py-1.5 px-3.5 rounded-lg border border-white/10 bg-white/5 no-underline transition-all duration-200 hover:text-gray-200 hover:bg-white/10">
            <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Kembali
        </a>
        <?php if ($is_admin): ?>
            <a href="activity_log.php" class="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest text-gray-500 py-1.5 px-3.5 rounded-lg border border-white/10 bg-white/5 no-underline transition-all duration-200 hover:text-gray-200 hover:bg-white/10">
                <i data-lucide="activity" class="w-3.5 h-3.5"></i> Log
            </a>
            <a href="catur.php" class="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest text-gray-500 py-1.5 px-3.5 rounded-lg border border-white/10 bg-white/5 no-underline transition-all duration-200 hover:text-gray-200 hover:bg-white/10">
                <i data-lucide="chess-king" class="w-3.5 h-3.5"></i> Catur
            </a>
        <?php endif; ?>
    </div>
</nav>