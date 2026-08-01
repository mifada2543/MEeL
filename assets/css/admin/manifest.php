<?php
/**
 * assets/css/admin/manifest.php — Daftar modul CSS folder admin/
 *
 * Endpoint tunggal untuk lihat/ubah urutan modul CSS admin (dipakai
 * admin/index.php, admin/cookies.php, admin/activity_log.php,
 * admin/mfa_reset.php). Tambah/hapus/ubah urutan file CUKUP di sini —
 * halaman otomatis meng-emit <link> untuk tiap entri (paralel, bukan
 * @import).
 *
 * Urutan tetap penting (cascade CSS): base → layout → cards → table →
 * form → modal → utility (semua dari subfolder shared/).
 */
return [
    'shared/base.css',
    'shared/layout.css',
    'shared/cards.css',
    'shared/table.css',
    'shared/form.css',
    'shared/modal.css',
    'shared/utility.css',
];
