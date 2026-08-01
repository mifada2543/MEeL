<?php
/**
 * assets/css/music/manifest.php — Daftar modul CSS folder music/
 *
 * Endpoint tunggal untuk lihat/ubah urutan modul CSS music (dipakai
 * music/index.php, music/upload.php, music/watch.php, music/view_playlist.php).
 * Tambah/hapus/ubah urutan file CUKUP di sini — halaman otomatis meng-emit
 * <link> untuk tiap entri (paralel, bukan @import).
 *
 * Urutan tetap penting (cascade CSS): base → layout → cards → player →
 * mini-player → resume-modal → visualizer → playlist-modal → utility.
 */
return [
    'base.css',
    'layout.css',
    'cards.css',
    'player.css',
    'mini-player.css',
    'resume-modal.css',
    'visualizer.css',
    'playlist-modal.css',
    'utility.css',
];
