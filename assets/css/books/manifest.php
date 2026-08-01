<?php
/**
 * assets/css/books/manifest.php — Daftar modul CSS folder books/
 *
 * Endpoint tunggal untuk lihat/ubah urutan modul CSS books (dipakai
 * books/index.php, books/upload.php, books/read.php, books/read_pdf.php).
 * Tambah/hapus/ubah urutan file CUKUP di sini — halaman otomatis meng-emit
 * <link> untuk tiap entri (paralel, bukan @import).
 *
 * Urutan tetap penting (cascade CSS): base → cards → manga → reader →
 * pdf → utility.
 */
return [
    'base.css',
    'cards.css',
    'manga.css',
    'reader.css',
    'pdf.css',
    'utility.css',
];
