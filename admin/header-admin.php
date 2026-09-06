<?php
if (!isset($is_admin)) {
    $is_admin = (isset($_SESSION['user_id']) && isset($conn)
        && function_exists('is_admin') && is_admin($conn));
}

if (!isset($back_url)) {
    if ($is_admin) {
        $back_url = 'index.php';
    } else {
        $back_url = '../index.php';
    }
}

$nav_page_title = $page_title ?? 'Edit';
$nav_media_type = $media_type ?? 'music';
$nav_id         = $id ?? 0;

$nav_current_page = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
$nav_current_page = str_replace('-', '_', $nav_current_page);
if ($nav_current_page === 'index') $nav_current_page = 'dashboard';

$nav_page_labels = [
    'dashboard'       => 'Dashboard',
    'cookies'         => 'Media Analytics',
    'activity_log'    => 'Activity Log',
    'user_management' => 'User Management',
    'meelcoin'        => 'MEeLCoin Settings',
    'mfa_reset'       => 'MFA Management',
    'catur'           => 'Chess Room',
];
$nav_current_label = $nav_page_labels[$nav_current_page] ?? $nav_page_title;
?>
<nav class="sticky top-0 z-50 bg-[#080b11]/90 backdrop-blur-md border-b border-white/5 px-6 h-14 flex items-center gap-3">
    <a href="../" class="font-sans text-sm font-extrabold text-white no-underline tracking-wider">
        MEeL<?php if ($is_admin): ?><span class="text-blue-600">Admin</span><?php endif; ?>
    </a>
    <div class="w-px h-5 bg-white/10"></div>

    <?php if ($is_admin): ?>
        <a href="." class="text-[11px] font-semibold text-gray-500 no-underline hover:text-gray-300 transition-colors">Dashboard</a>
        <?php if ($nav_current_page !== 'dashboard'): ?>
            <span class="text-gray-600">›</span>
            <span class="text-[11px] font-semibold text-gray-200"><?= htmlspecialchars($nav_current_label) ?></span>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($nav_media_type === 'dashboard'): ?>
            <span class="text-[11px] font-semibold text-gray-200">Dashboard</span>
        <?php else: ?>
            <a href="../profile/<?= urlencode($_SESSION['username'] ?? '') ?>" class="text-[11px] font-semibold text-gray-500 no-underline hover:text-gray-300 transition-colors">Dashboard</a>
            <span class="text-gray-600">›</span>
            <?php if ($nav_media_type === 'video'): ?>
                <a href="../video/beranda" class="text-[11px] font-semibold text-gray-500 no-underline hover:text-gray-300 transition-colors">Video</a>
            <?php else: ?>
                <a href="../music/beranda" class="text-[11px] font-semibold text-gray-500 no-underline hover:text-gray-300 transition-colors">Musik</a>
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
        <?php if ($is_admin): ?>
            <div class="admin-menu-wrapper" id="admin-menu-wrapper">
                <button class="admin-menu-toggle <?= $nav_current_page !== 'dashboard' ? 'admin-menu-toggle--active' : '' ?>" id="admin-menu-toggle" type="button" title="Menu navigasi admin">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-400"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                </button>
                <div class="admin-menu-dropdown" id="admin-menu-dropdown">
                    <a href="." class="admin-menu-item <?= $nav_current_page === 'dashboard' ? 'active' : '' ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                        Dashboard
                    </a>
                    <a href="analys" class="admin-menu-item <?= $nav_current_page === 'cookies' ? 'active' : '' ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                        Media Analytics
                    </a>
                    <a href="activity-log" class="admin-menu-item <?= $nav_current_page === 'activity_log' ? 'active' : '' ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                        Activity Log
                    </a>

                    <div class="admin-menu-divider"></div>

                    <a href="user-management" class="admin-menu-item <?= $nav_current_page === 'user_management' ? 'active' : '' ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        User Management
                    </a>
                    <a href="meelcoin" class="admin-menu-item <?= $nav_current_page === 'meelcoin' ? 'active' : '' ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"></path><path d="M12 18V6"></path></svg>
                        MEeLCoin Settings
                    </a>

                    <div class="admin-menu-divider"></div>

                    <a href="mfa-reset" class="admin-menu-item <?= $nav_current_page === 'mfa_reset' ? 'active' : '' ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        MFA Management
                    </a>
                    <a href="catur" class="admin-menu-item <?= $nav_current_page === 'catur' ? 'active' : '' ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8V6a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v2"></path><path d="M4 8h16v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8z"></path><path d="M10 20v-4h4v4"></path></svg>
                        Chess Room
                    </a>
                </div>
            </div>
        <?php else: ?>
            <a href="<?= htmlspecialchars($back_url) ?>" class="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest text-gray-500 py-1.5 px-3.5 rounded-lg border border-white/10 bg-white/5 no-underline transition-all duration-200 hover:text-gray-200 hover:bg-white/10">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                Kembali
            </a>
        <?php endif; ?>
    </div>
</nav>

<script>
(function(){
    var btn = document.getElementById('admin-menu-toggle');
    var dd  = document.getElementById('admin-menu-dropdown');
    if (!btn || !dd) return;
    btn.addEventListener('click', function(e){
        e.stopPropagation();
        dd.classList.toggle('open');
    });
    document.addEventListener('click', function(e){
        if (!dd.contains(e.target) && e.target !== btn) {
            dd.classList.remove('open');
        }
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') dd.classList.remove('open');
    });
})();
</script>
