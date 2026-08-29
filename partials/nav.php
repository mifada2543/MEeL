<?php
// Ambil profile picture dari session (nav.php bisa di-include dari mana saja)
$_nav_pfp = null;
if (isset($_SESSION['user_id']) && isset($conn)) {
    $stmt_nav = $conn->prepare("SELECT profile_picture FROM users WHERE id = ? LIMIT 1");
    $stmt_nav->bind_param("i", $_SESSION['user_id']);
    $stmt_nav->execute();
    $_nav_user = $stmt_nav->get_result()->fetch_assoc();
    $_nav_pfp  = $_nav_user['profile_picture'] ?? null;
}

// Deteksi halaman aktif
$_nav_is_books  = str_contains($_SERVER['PHP_SELF'], '/books/');
$_nav_is_video  = str_contains($_SERVER['PHP_SELF'], '/video/');
$_nav_is_music  = str_contains($_SERVER['PHP_SELF'], '/music/');
$_nav_is_drive  = str_contains($_SERVER['PHP_SELF'], '/drive/');
$_nav_in_subdir = $_nav_is_books || $_nav_is_video || $_nav_is_music || $_nav_is_drive;

// Tentukan prefix path relatif (root vs subfolder)
$_nav_pfp_base = $_nav_in_subdir ? '../profile/upload/' : 'profile/upload/';
$_nav_root     = $_nav_in_subdir ? '../' : '';
?>
<style>    /* Mengunci paksa agar web tidak pernah bisa digeser ke samping */
    html,
    body {
        overflow-x: hidden !important;
    }
</style>

<!-- Cross-link Video ↔ Music (DESKTOP) -->
<?php if ($_nav_is_video): ?>
    <a href="<?= $_nav_root ?>music/beranda"
        class="hidden sm:flex items-center gap-1.5 bg-white/[.04] px-3 py-2 rounded-xl hover:bg-white/[.08] text-gray-300 hover:text-orange-500 transition-all"
        title="MEeL Music - Streaming Audio dengan kualitas terbaik">
        <i data-lucide="music" class="w-3.5 h-3.5"></i>
        <span class="hidden md:inline">Music</span>
    </a>
<?php elseif ($_nav_is_music): ?>
    <a href="<?= $_nav_root ?>video/beranda"
        class="hidden sm:flex items-center gap-1.5 bg-white/[.04] px-3 py-2 rounded-xl hover:bg-white/[.08] text-gray-600 hover:text-red-500 transition-all"
        title="MEeL Video">
        <i data-lucide="play" class="w-3.5 h-3.5"></i>
        <span class="hidden md:inline">Video</span>
    </a>
<?php elseif ($_nav_is_books): ?>
    <a href="<?= $_nav_root ?>video/beranda"
        class="hidden sm:flex items-center gap-1.5 bg-white/[.04] px-3 py-2 rounded-xl hover:bg-white/[.08] text-gray-600 hover:text-red-500 transition-all"
        title="MEeL Video">
        <i data-lucide="play" class="w-3.5 h-3.5"></i>
        <span class="hidden md:inline">Video</span>
    </a>
    <a href="<?= $_nav_root ?>music/beranda"
        class="hidden sm:flex items-center gap-1.5 bg-white/[.04] px-3 py-2 rounded-xl hover:bg-white/[.08] text-gray-600 hover:text-orange-500 transition-all"
        title="MEeL Music - Streaming Audio dengan kualitas terbaik">
        <i data-lucide="music" class="w-3.5 h-3.5"></i>
        <span class="hidden md:inline">Music</span>
    </a>
<?php endif; ?>
<?php if (isset($_SESSION['username'])): ?>
    <!-- AVATAR DROPDOWN (desktop) -->
    <div class="relative hidden sm:block" id="nav-dropdown-wrap">
        <button id="nav-avatar-btn"
            onclick="toggleNavDropdown()"
            class="flex items-center gap-2 p-1 rounded-xl transition-all group"
            style="color:var(--meel-text-secondary)"
            onmouseover="this.style.background='var(--meel-surface-hover)'"
            onmouseout="this.style.background='transparent'"
            title="Menu Akun">

            <!-- Avatar -->
            <div class="w-8 h-8 rounded-full overflow-hidden border border-white/10 flex-shrink-0 bg-gradient-to-br from-orange-500 to-red-600 flex items-center justify-center">
                <?php if (!empty($_nav_pfp)): ?>
                    <img src="<?= $_nav_pfp_base . htmlspecialchars($_nav_pfp) ?>"
                        class="w-full h-full object-cover"
                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <span class="hidden w-full h-full items-center justify-center text-white text-xs font-bold">
                        <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                    </span>
                <?php else: ?>
                    <span class="text-white text-xs font-bold">
                        <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                    </span>
                <?php endif; ?>
            </div>

            <i data-lucide="chevron-down" class="w-3 h-3 text-gray-600 transition-transform duration-200" id="nav-chevron"></i>
        </button>

        <!-- Dropdown panel -->
        <div id="nav-dropdown"
            class="hidden absolute right-0 top-full mt-2 w-52 rounded-2xl overflow-hidden z-[200]"
            style="background:var(--meel-surface-elevated); border:1px solid var(--meel-border-strong); box-shadow:var(--meel-shadow-xl)">

            <!-- User info header -->
            <div class="px-4 py-3 flex items-center gap-3" style="border-bottom:1px solid var(--meel-border)">
                <div class="w-9 h-9 rounded-full overflow-hidden border border-white/10 flex-shrink-0 bg-gradient-to-br from-orange-500 to-red-600 flex items-center justify-center">
                    <?php if (!empty($_nav_pfp)): ?>
                        <img src="<?= $_nav_pfp_base . htmlspecialchars($_nav_pfp) ?>"
                            class="w-full h-full object-cover"
                            onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <span class="hidden w-full h-full items-center justify-center text-white text-xs font-bold">
                            <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                        </span>
                    <?php else: ?>
                        <span class="text-white text-xs font-bold">
                            <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="min-w-0">
                    <div class="text-xs font-bold truncate" style="color:var(--meel-text-heading)" title="@<?= htmlspecialchars($_SESSION['username']) ?>">
                        @<?= htmlspecialchars($_SESSION['username']) ?>
                    </div>
                    <?php if (isset($_SESSION['role'])): ?>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <div class="flex items-center gap-1 mt-0.5" title="Anda adalah administrator platform">
                                <span class="relative flex h-1.5 w-1.5">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-red-500"></span>
                                </span>
                                <span class="text-[9px] text-red-500 font-black uppercase tracking-wider">Admin</span>
                            </div>
                        <?php elseif ($_SESSION['role'] === 'member'): ?>
                            <div class="flex items-center gap-1 mt-0.5" title="Anda adalah pengguna berlangganan MEeL">
                                <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>
                                <span class="text-[9px] text-green-500 font-medium uppercase tracking-tighter">Berlangganan</span>
                            </div>
                        <?php else: ?>
                            <div class="flex items-center gap-1 mt-0.5">
                                <span class="h-1.5 w-1.5 rounded-full bg-gray-600"></span>
                                <span class="text-[9px] text-gray-500 font-medium uppercase tracking-tighter" title="Anda adalah pengguna biasa">User</span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Menu items -->
            <div class="py-1.5">
                <a href="<?= $_nav_root ?>profile/?u=<?= urlencode($_SESSION['username']) ?>" title="Lihat profil Anda"
                    class="flex items-center gap-3 px-4 py-2.5 text-[11px] transition-all no-underline"
                    style="color:var(--meel-text-secondary)"
                    onmouseover="this.style.color='var(--meel-text-heading)'; this.style.background='var(--meel-surface-hover)'"
                    onmouseout="this.style.color='var(--meel-text-secondary)'; this.style.background='transparent'">
                    <i data-lucide="user" class="w-3.5 h-3.5 flex-shrink-0"></i>
                    <span>Profil</span>
                </a>

                <?php if (!$_nav_is_books): ?>
                <a href="<?= $_nav_root ?>books/beranda" title="Akses MEeL Books"
                    class="flex items-center gap-3 px-4 py-2.5 text-[11px] text-gray-400 hover:text-green-400 hover:bg-white/[.04] transition-all no-underline">
                    <i data-lucide="book-open" class="w-3.5 h-3.5 flex-shrink-0"></i>
                    <span>Books</span>
                </a>
                <?php endif; ?>
                <a href="<?= $_nav_root ?>introduction" title="Cara bernavigasi di MEeL"
                    class="flex items-center gap-3 px-4 py-2.5 text-[11px] text-gray-400 hover:text-white hover:bg-white/[.04] transition-all no-underline">
                    <i data-lucide="compass" class="w-3.5 h-3.5 flex-shrink-0"></i>
                    <span>Cara Navigasi</span>
                </a>

                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['member', 'admin'])): ?>
                    <a href="<?= $_nav_root ?>drive/beranda" title="Akses drive Anda untuk mengelola file dan dokumen"
                        class="flex items-center gap-3 px-4 py-2.5 text-[11px] text-gray-400 hover:text-blue-400 hover:bg-white/[.04] transition-all no-underline">
                        <i data-lucide="hard-drive" class="w-3.5 h-3.5 flex-shrink-0"></i>
                        <span>Drive</span>
                    </a>
                <?php endif; ?>
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    <a href="upload" title="Unggah media baru ke platform"
                        class="flex items-center gap-3 px-4 py-2.5 text-[11px] text-gray-400 hover:text-blue-400 hover:bg-white/[.04] transition-all no-underline">
                        <i data-lucide="upload-cloud" class="w-3.5 h-3.5 flex-shrink-0"></i>
                        <span>Upload Media</span>
                    </a>
                    <a href="<?= $_nav_root ?>admin/beranda"
                        title="Panel admin untuk mengelola pengguna dan konten"
                        class="flex items-center gap-3 px-4 py-2.5 text-[11px] text-gray-400 hover:text-red-400 hover:bg-white/[.04] transition-all no-underline">
                        <i data-lucide="settings" class="w-3.5 h-3.5 flex-shrink-0"></i>
                        <span>Admin Panel</span>
                    </a>
                <?php elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'member'): ?>
                    <!-- Upload tersedia juga untuk member -->
                    <a href="upload"
                        title="Unggah media baru ke platform"
                        class="flex items-center gap-3 px-4 py-2.5 text-[11px] text-gray-400 hover:text-blue-400 hover:bg-white/[.04] transition-all no-underline">
                        <i data-lucide="upload-cloud" class="w-3.5 h-3.5 flex-shrink-0"></i>
                        <span>Upload</span>
                    </a>
                <?php endif; ?>
            </div>

            <div class="border-t border-white/[.05] py-1.5">
                <a href="<?= $_nav_root ?>auth/logout"
                    data-meel-confirm-link
                    data-meel-confirm-size="sm"
                    data-meel-confirm-title="Logout"
                    data-meel-confirm-text="Yakin mau logout?"
                    data-meel-confirm-button="LOGOUT"
                    title="Keluar dari akun Anda"
                    class="flex items-center gap-3 px-4 py-2.5 text-[11px] text-gray-500 hover:text-red-400 hover:bg-red-500/[.06] transition-all no-underline">
                    <i data-lucide="log-out" class="w-3.5 h-3.5 flex-shrink-0"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </div>

    <!-- HAMBURGER (mobile only) -->        <button id="nav-hamburger"
        onclick="toggleNavDrawer()"
        class="sm:hidden flex items-center justify-center w-10 h-10 rounded-xl bg-white/[.04] border border-white/[.06] text-gray-500 hover:text-white transition-all"
        title="Buka menu navigasi">
        <i data-lucide="menu" class="w-6 h-6"></i>
    </button>

    <!-- MOBILE DRAWER -->
    <div id="nav-drawer-overlay"
        class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[300] hidden sm:hidden"
        onclick="toggleNavDrawer()"></div>

    <div id="nav-drawer"
        class="fixed top-0 right-0 h-[100dvh] w-72 sm:w-80 bg-[#0a0d14] border-l border-white/[.06] z-[310] transform translate-x-full transition-transform duration-300 ease-out hidden sm:hidden flex-col">
        <!-- Drawer header -->
        <div class="flex items-center justify-between px-5 py-4 border-b border-white/[.05]">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full overflow-hidden border border-white/10 flex-shrink-0 bg-gradient-to-br from-orange-500 to-red-600 flex items-center justify-center">
                    <?php if (!empty($_nav_pfp)): ?>
                        <img src="<?= $_nav_pfp_base . htmlspecialchars($_nav_pfp) ?>"
                            class="w-full h-full object-cover"
                            onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <span class="hidden w-full h-full items-center justify-center text-white text-sm font-bold">
                            <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                        </span>
                    <?php else: ?>
                        <span class="text-white text-sm font-bold">
                            <?= strtoupper(substr($_SESSION['username'], 0, 1)) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="text-sm font-bold text-white">@<?= htmlspecialchars($_SESSION['username']) ?></div>
                    <?php if (isset($_SESSION['role'])): ?>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <div class="flex items-center gap-1 mt-0.5">
                                <span class="relative flex h-1.5 w-1.5">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-red-500"></span>
                                </span>
                                <span class="text-[9px] text-red-500 font-black uppercase tracking-wider">Admin</span>
                            </div>
                        <?php elseif ($_SESSION['role'] === 'member'): ?>
                            <div class="flex items-center gap-1 mt-0.5">
                                <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>
                                <span class="text-[9px] text-green-500 font-medium uppercase">Berlangganan</span>
                            </div>
                        <?php else: ?>
                            <div class="flex items-center gap-1 mt-0.5">
                                <span class="h-1.5 w-1.5 rounded-full bg-gray-600"></span>
                                <span class="text-[9px] text-gray-500 uppercase">Member</span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <button onclick="toggleNavDrawer()" class="text-gray-600 hover:text-white p-1 transition-all" title="Tutup menu">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <!-- Drawer menu items -->
        <nav class="flex-1 overflow-y-auto py-4 space-y-1">
            <a href="<?= $_nav_root ?>profile/?u=<?= urlencode($_SESSION['username']) ?>"
                title="Lihat dan edit profil Anda"
                class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 hover:text-white hover:bg-white/[.04] transition-all no-underline">
                <i data-lucide="user" class="w-5 h-5 flex-shrink-0"></i>
                <span>Profil Saya</span>
            </a>
            <?php if (!$_nav_is_books): ?>
            <a href="<?= $_nav_root ?>books/beranda"
                title="Baca manga dan PDF digital"
                class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 hover:text-green-400 hover:bg-white/[.04] transition-all no-underline">
                <i data-lucide="book-open" class="w-5 h-5 flex-shrink-0"></i>
                <span>Books</span>
            </a>
            <?php endif; ?>
            <a href="<?= $_nav_root ?>introduction"
                title="Panduan penggunaan fitur MEeL"
                class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 hover:text-white hover:bg-white/[.04] transition-all no-underline">
                <i data-lucide="compass" class="w-5 h-5 flex-shrink-0"></i>
                <span>Cara Navigasi</span>
            </a>
            <?php if ($_nav_is_video): ?>
                <a href="<?= $_nav_root ?>music/beranda"
                    class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 hover:text-orange-400 hover:bg-orange-500/[.06] transition-all no-underline">
                    <i data-lucide="music" class="w-5 h-5 flex-shrink-0"></i>
                    <span>Music</span>
                </a>
            <?php elseif ($_nav_is_music): ?>
                <a href="<?= $_nav_root ?>video/beranda"
                    class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 hover:text-red-400 hover:bg-red-500/[.06] transition-all no-underline">
                    <i data-lucide="play" class="w-5 h-5 flex-shrink-0"></i>
                    <span>Video</span>
                </a>
            <?php elseif ($_nav_is_books): ?>
                <a href="<?= $_nav_root ?>video/beranda"
                    class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 hover:text-red-400 hover:bg-red-500/[.06] transition-all no-underline">
                    <i data-lucide="play" class="w-5 h-5 flex-shrink-0"></i>
                    <span>Video</span>
                </a>
                <a href="<?= $_nav_root ?>music/beranda"
                    class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 hover:text-orange-400 hover:bg-orange-500/[.06] transition-all no-underline">
                    <i data-lucide="music" class="w-5 h-5 flex-shrink-0"></i>
                    <span>Music</span>
                </a>
            <?php endif; ?>
            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['member', 'admin'])): ?>
                <a href="<?= $_nav_root ?>drive/beranda"
                    class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 hover:text-blue-400 hover:bg-white/[.04] transition-all no-underline">
                    <i data-lucide="hard-drive" class="w-5 h-5 flex-shrink-0"></i>
                    <span>Drive</span>
                </a>
            <?php endif; ?>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <a href="upload"
                    class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 hover:text-blue-400 hover:bg-white/[.04] transition-all no-underline">
                    <i data-lucide="upload-cloud" class="w-5 h-5 flex-shrink-0"></i>
                    <span>Upload Media</span>
                </a>
                <a href="<?= $_nav_root ?>admin/beranda"
                    class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 hover:text-red-400 hover:bg-white/[.04] transition-all no-underline">
                    <i data-lucide="settings" class="w-5 h-5 flex-shrink-0"></i>
                    <span>Admin Panel</span>
                </a>
            <?php elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'member'): ?>
                <a href="upload"
                    class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 hover:text-blue-400 hover:bg-white/[.04] transition-all no-underline">
                    <i data-lucide="upload-cloud" class="w-5 h-5 flex-shrink-0"></i>
                    <span>Upload</span>
                </a>
            <?php endif; ?>
            <div class="mx-6 my-3 h-px bg-white/[.05]"></div>

            <a href="<?= $_nav_root ?>update"
                class="flex items-center gap-4 px-6 py-4 text-base text-gray-500 hover:text-white hover:bg-white/[.04] transition-all no-underline">
                <i data-lucide="radio" class="w-5 h-5 flex-shrink-0"></i>
                <span>Changelog</span>
            </a>
        </nav>

        <div class="border-t border-white/[.05] p-5">
            <a href="<?= $_nav_root ?>auth/logout"
                data-meel-confirm-link
                data-meel-confirm-size="sm"
                data-meel-confirm-title="Logout"
                data-meel-confirm-text="Yakin mau logout?"
                data-meel-confirm-button="LOGOUT"
                class="flex items-center justify-center gap-3 w-full py-4 rounded-xl bg-red-600/10 border border-red-600/20 text-base text-red-400 hover:bg-red-600/20 transition-all no-underline font-bold">
                <i data-lucide="log-out" class="w-5 h-5"></i>
                Logout
            </a>
        </div>
    </div>
<?php else: ?>
    <!-- Belum login - DESKTOP -->
    <div class="hidden sm:flex items-center gap-2">
        <a href="<?= $_nav_root ?>auth/login"
            title="Login"
            class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-2 rounded-xl text-xs font-bold transition-all shadow-lg shadow-blue-900/40">
            LOGIN
        </a>
        <a href="<?= $_nav_root ?>auth/register"
            title="Daftar"
            class="bg-gray-600 hover:bg-gray-500 text-white px-4 py-2 rounded-xl text-xs font-bold transition-all shadow-lg shadow-gray-900/30">
            DAFTAR
        </a>
        <a href="<?= $_nav_root ?>introduction"
            class="text-gray-500 hover:text-white transition-all p-2 rounded-lg hover:bg-white/5"
            title="Cara Bernavigasi">
            <i data-lucide="compass" class="w-4 h-4"></i>
        </a>
    </div>

    <!-- Hamburger MOBILE (guest) -->
    <button id="nav-hamburger-guest"
        onclick="toggleNavDrawerGuest()"
        class="sm:hidden flex items-center justify-center w-10 h-10 rounded-xl bg-white/[.04] border border-white/[.06] text-gray-500 hover:text-white transition-all"
        title="Menu">
        <i data-lucide="menu" class="w-6 h-6"></i>
    </button>

    <!-- MOBILE DRAWER OVERLAY (guest) -->
    <div id="nav-drawer-guest-overlay"
        class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[300] hidden sm:hidden"
        onclick="toggleNavDrawerGuest()"></div>

    <!-- MOBILE DRAWER (guest) -->
    <div id="nav-drawer-guest"
        class="fixed top-0 right-0 h-[100dvh] w-72 sm:w-80 bg-[#0a0d14] border-l border-white/[.06] z-[310] transform translate-x-full transition-transform duration-300 ease-out hidden sm:hidden flex-col">

        <!-- Drawer header -->
        <div class="flex items-center justify-between px-5 py-4 border-b border-white/[.05]">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full overflow-hidden border border-white/10 flex-shrink-0 bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center">
                    <span class="text-white text-sm font-bold">?</span>
                </div>
                <div>
                    <div class="text-sm font-bold text-white">Guest</div>
                    <div class="text-[9px] text-gray-500 font-medium uppercase tracking-tighter">Belum Login</div>
                </div>
            </div>
            <button onclick="toggleNavDrawerGuest()" class="text-gray-600 hover:text-white p-1 transition-all">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <!-- Drawer menu items -->
        <nav class="flex-1 overflow-y-auto py-4 space-y-1">
            <a href="<?= $_nav_root ?>auth/login"
                class="flex items-center gap-4 px-6 py-4 text-base text-blue-400 hover:text-blue-300 hover:bg-blue-500/[.06] transition-all no-underline font-bold">
                <i data-lucide="log-in" class="w-5 h-5 flex-shrink-0"></i>
                <span>Login</span>
            </a>
            <a href="<?= $_nav_root ?>auth/register"
                class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 hover:text-white hover:bg-white/[.04] transition-all no-underline">
                <i data-lucide="user-plus" class="w-5 h-5 flex-shrink-0"></i>
                <span>Daftar</span>
            </a>

            <?php if ($_nav_is_video): ?>
                <a href="<?= $_nav_root ?>music/beranda"
                    class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 hover:text-orange-400 hover:bg-orange-500/[.06] transition-all no-underline">
                    <i data-lucide="music" class="w-5 h-5 flex-shrink-0"></i>
                    <span>Music</span>
                </a>
            <?php elseif ($_nav_is_music): ?>
                <a href="<?= $_nav_root ?>video/beranda"
                    class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 hover:text-red-400 hover:bg-red-500/[.06] transition-all no-underline">
                    <i data-lucide="play" class="w-5 h-5 flex-shrink-0"></i>
                    <span>Video</span>
                </a>
            <?php elseif ($_nav_is_books): ?>
                <a href="<?= $_nav_root ?>video/beranda"
                    class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 hover:text-red-400 hover:bg-red-500/[.06] transition-all no-underline">
                    <i data-lucide="play" class="w-5 h-5 flex-shrink-0"></i>
                    <span>Video</span>
                </a>
                <a href="<?= $_nav_root ?>music/beranda"
                    class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 hover:text-orange-400 hover:bg-orange-500/[.06] transition-all no-underline">
                    <i data-lucide="music" class="w-5 h-5 flex-shrink-0"></i>
                    <span>Music</span>
                </a>
            <?php endif; ?>
            <a href="<?= $_nav_root ?>introduction"
                class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 hover:text-white hover:bg-white/[.04] transition-all no-underline">
                <i data-lucide="compass" class="w-5 h-5 flex-shrink-0"></i>
                <span>Introduction</span>
            </a>
        </nav>

    </div>
<?php endif; ?>
<?php $scripts_root = $_nav_root; include __DIR__ . '/scripts.php'; ?>
<script>    // Dropdown desktop
    function toggleNavDropdown() {
        const dd = document.getElementById('nav-dropdown');
        const ch = document.getElementById('nav-chevron');
        if (!dd) return;
        dd.classList.toggle('hidden');
        if (ch) ch.style.transform = dd.classList.contains('hidden') ? '' : 'rotate(180deg)';
    }
    document.addEventListener('click', function(e) {
        const wrap = document.getElementById('nav-dropdown-wrap');
        if (wrap && !wrap.contains(e.target)) {
            const dd = document.getElementById('nav-dropdown');
            const ch = document.getElementById('nav-chevron');
            if (dd) dd.classList.add('hidden');
            if (ch) ch.style.transform = '';
        }
    });

    function toggleNavDrawer() {
        const drawer = document.getElementById('nav-drawer');
        const overlay = document.getElementById('nav-drawer-overlay');
        const mainContent = document.getElementById('app-content-grid') || document.querySelector('main');

        if (!drawer) return;
        const open = drawer.classList.contains('open');
        if (open) {
            drawer.style.transform = '';
            overlay.classList.add('hidden');
            drawer.classList.remove('open');

            document.body.style.overflow = '';
            document.documentElement.style.overflow = '';
            document.body.classList.remove('nav-drawer-open');

            if (mainContent) {
                mainContent.classList.remove('blur-md');
                mainContent.removeEventListener('click', closeDrawerOnMainClick);
            }

            setTimeout(() => {
                if (!drawer.classList.contains('open')) {
                    drawer.classList.add('hidden');
                    drawer.classList.remove('flex');
                }
            }, 300);

        } else {
            drawer.classList.remove('hidden');
            drawer.classList.add('flex');

            setTimeout(() => {
                drawer.style.transform = 'translateX(0)';
                drawer.classList.add('open');
            }, 10);

            overlay.classList.remove('hidden');

            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';
            document.body.classList.add('nav-drawer-open');

            if (mainContent) {
                mainContent.classList.add('blur-md', 'transition-all', 'duration-300');
                mainContent.addEventListener('click', closeDrawerOnMainClick);
            }
        }
    }

    function closeDrawerOnMainClick(e) {
        e.preventDefault();
        e.stopPropagation();
        toggleNavDrawer();
    }

    // Guest Drawer (mobile, belum login)
    function toggleNavDrawerGuest() {
        const drawer = document.getElementById('nav-drawer-guest');
        const overlay = document.getElementById('nav-drawer-guest-overlay');
        const mainContent = document.getElementById('app-content-grid') || document.querySelector('main');

        if (!drawer) return;
        const open = drawer.classList.contains('open');
        if (open) {
            drawer.style.transform = '';
            overlay.classList.add('hidden');
            drawer.classList.remove('open');

            document.body.style.overflow = '';
            document.documentElement.style.overflow = '';

            if (mainContent) {
                mainContent.classList.remove('blur-md');
                mainContent.removeEventListener('click', closeGuestDrawerOnMainClick);
            }

            setTimeout(() => {
                if (!drawer.classList.contains('open')) {
                    drawer.classList.add('hidden');
                    drawer.classList.remove('flex');
                }
            }, 300);
        } else {
            drawer.classList.remove('hidden');
            drawer.classList.add('flex');

            setTimeout(() => {
                drawer.style.transform = 'translateX(0)';
                drawer.classList.add('open');
            }, 10);

            overlay.classList.remove('hidden');

            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';

            if (mainContent) {
                mainContent.classList.add('blur-md', 'transition-all', 'duration-300');
                mainContent.addEventListener('click', closeGuestDrawerOnMainClick);
            }
        }
    }

    function closeGuestDrawerOnMainClick(e) {
        e.preventDefault();
        e.stopPropagation();
        toggleNavDrawerGuest();
    }

    // Theme init
    (function(){
        if (typeof MEELTheme !== 'undefined') {
            MEELTheme.init({
                isLoggedIn: <?= json_encode(isset($_SESSION['username'])) ?>,
                csrfToken: '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>'
            });
        }
    })();
</script>
