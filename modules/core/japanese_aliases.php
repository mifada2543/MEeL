<?php
/**
 * modules/core/japanese_aliases.php
 * Alias manual untuk brand/franchise/nama yang TIDAK ada di JMdict
 * (kamus umum tidak mencakup nama produk/karakter/game).
 *
 * Format: 'frasa Jepang persis' => 'terjemahan/nama Inggris'
 * Cocokkan berdasarkan SUBSTRING pada teks asli (case-sensitive untuk
 * karakter Jepang), bukan per-token MeCab — karena brand/franchise name
 * biasanya diparsing MeCab sebagai satu token utuh yang gagal match JMdict.
 *
 * Tambahkan entri baru di sini kapan saja tanpa perlu ubah kode lain.
 */
return [
    'プロジェクトセカイ'   => 'Project Sekai',
    'カラフルステージ'     => 'Colorful Stage',
    'ワンダーランズ×ショウタイム' => 'Wonderlands x Showtime',
    'ワンダーランズ'       => 'Wonderlands',
    // Tambahkan entri lain di sini sesuai kebutuhan katalog.
];
