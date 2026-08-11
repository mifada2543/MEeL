<?php
$countdown_seconds = max(1, (int)($countdown_seconds ?? 1));
$countdown_color   = $countdown_color ?? 'text-red-500';
?>
<div class="text-center py-6 space-y-4">
    <i data-lucide="shield-alert" class="w-12 h-12 text-red-500 mx-auto animate-pulse"></i>
    <h3 class="text-lg font-bold text-white">Akses Ditangguhkan</h3>
    <p class="text-xs text-gray-300 leading-relaxed">Terlalu banyak percobaan gagal. Silakan coba lagi dalam:</p>
    <div id="countdown" class="text-4xl font-black <?= $countdown_color ?> tracking-widest"><?= $countdown_seconds ?></div>
    <p class="text-[10px] text-gray-300 uppercase">Detik</p>
    <?= $countdown_extra ?? '' ?>
</div>
<script>
    let seconds = <?= $countdown_seconds ?>;
    const display = document.getElementById('countdown');
    const timer = setInterval(() => {
        seconds--;
        display.innerText = seconds > 0 ? seconds : 0;
        if (seconds <= 0) {
            clearInterval(timer);
            location.reload();
        }
    }, 1000);
</script>
