<?php
if (!isset($is_logged_in)) {
    $is_logged_in = isset($_SESSION['user_id']);
}
?>
<nav class="absolute top-0 left-0 w-full px-6 py-5 flex justify-end items-center gap-3 z-50">
    <?php if ($is_logged_in): ?>
        <div class="flex items-center gap-2 bg-white/[.04] px-4 py-2 rounded-xl border border-white/[.06] backdrop-blur-sm">
            <span class="w-1.5 h-1.5 bg-green-500 rounded-full animate-pulse"></span>
            <a href="profile/?u=<?= urlencode($_SESSION['username']) ?>"
               class="text-[11px] font-bold text-gray-400 hover:text-white transition-colors">
                <?= htmlspecialchars($_SESSION['username']) ?>
            </a>
            <span class="w-px h-3 bg-white/10 mx-1"></span>
            <a href="auth/logout"
               data-meel-confirm-link
               data-meel-confirm-size="sm"
               data-meel-confirm-title="Logout"
               data-meel-confirm-text="Yakin mau logout?"
               data-meel-confirm-button="LOGOUT"
               class="text-[10px] font-bold text-gray-600 hover:text-red-400 uppercase tracking-widest transition-colors">
                Out
            </a>
        </div>
    <?php else: ?>
        <a href="auth/login"
           class="text-[11px] font-bold text-gray-400 hover:text-white transition-colors px-3 py-2">
            Login
        </a>
        <a href="auth/register"
           class="bg-blue-600 hover:bg-blue-500 text-white text-[11px] font-bold px-4 py-2 rounded-lg transition btn-glow uppercase tracking-wider">
            Daftar
        </a>
    <?php endif; ?>
    <a href="introduction"
       class="text-gray-600 hover:text-white transition-all p-2 rounded-lg hover:bg-white/5"
       title="Panduan">
        <i data-lucide="compass" class="w-4 h-4"></i>
    </a>
</nav>
