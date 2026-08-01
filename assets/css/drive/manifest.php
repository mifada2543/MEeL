<?php
/**
 * assets/css/drive/manifest.php — Daftar modul CSS folder drive/
 *
 * Endpoint tunggal untuk lihat/ubah urutan modul CSS drive (dipakai
 * drive/index.php). Tambah/hapus/ubah urutan file CUKUP di sini — halaman
 * otomatis meng-emit <link> untuk tiap entri (paralel, bukan @import).
 *
 * Urutan tetap penting (cascade CSS): base → layout → navbar → cards →
 * upload → utility → index/main.css.
 *
 * Catatan: index/main.css (page-specific dashboard) sengaja menjadi modul
 * terakhir di sini — dulunya di-import paling akhir oleh drive/main.css.
 */
return [
    'base.css',
    'layout.css',
    'navbar.css',
    'cards.css',
    'upload.css',
    'utility.css',
    'index/main.css',
];
