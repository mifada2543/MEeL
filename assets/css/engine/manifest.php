<?php
/**
 * assets/css/engine/manifest.php — Daftar modul CSS folder engine/
 *
 * Endpoint tunggal untuk lihat/ubah urutan modul overlay download/
 * transcode. Tambah/hapus/ubah urutan file CUKUP di sini — partials/ui.php
 * otomatis meng-emit <link> untuk tiap entri (paralel, bukan @import).
 *
 * Urutan tetap penting (cascade CSS): base → download → progress →
 * transcode → buttons → animations.
 */
return [
    'base.css',
    'download.css',
    'progress.css',
    'transcode.css',
    'buttons.css',
    'animations.css',
];
