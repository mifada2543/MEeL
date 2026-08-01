<?php
/**
 * assets/css/video/manifest.php — Daftar modul CSS folder video/
 *
 * Endpoint tunggal untuk lihat/ubah urutan modul CSS video (dipakai
 * video/index.php, video/upload.php, video/watch.php, profile/manage.php).
 * Tambah/hapus/ubah urutan file CUKUP di sini — halaman otomatis meng-emit
 * <link> untuk tiap entri (paralel, bukan @import).
 *
 * Urutan tetap penting (cascade CSS): base → layout → navbar → player →
 * fullscreen → cards → mini-player → resume-modal → autonext → glow →
 * seek → toast → utility.
 */
return [
    'base.css',
    'layout.css',
    'navbar.css',
    'player.css',
    'fullscreen.css',
    'cards.css',
    'mini-player.css',
    'resume-modal.css',
    'autonext.css',
    'glow.css',
    'seek.css',
    'toast.css',
    'utility.css',
];
