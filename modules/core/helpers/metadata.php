<?php

// ════════════════════════════════════════════════════════════════
// helpers/metadata.php — Search Metadata Helper
//
// Bagian dari pecahan modules/core/helpers.php.
// Dimuat oleh helpers/main.php.
//
// Semua fungsi dibungkus function_exists() guard sebagai
// defense-in-depth terhadap double-include.
// ════════════════════════════════════════════════════════════════

if (!function_exists('generate_search_metadata')) {
/**
 * Generate search_metadata (original + romaji + english, lowercase).
 *
 * Sumber tunggal logika ini — dipakai oleh Uploader, database/backfill,
 * admin/edit-video.php, dan admin/edit-music.php agar hasil selalu
 * konsisten (termasuk english translation + brand alias dari
 * japanese_aliases.php). Format identik di semua pemanggil sehingga
 * backfill tetap idempotent (tidak menghasilkan diff saat dijalankan ulang).
 *
 * @param string $title  Judul video / judul lagu
 * @param string $artist Artis (khusus music, opsional)
 * @param string $album  Album (khusus music, opsional)
 * @return string search_metadata lowercase
 */
function generate_search_metadata(string $title, string $artist = '', string $album = ''): string
{
    // Lazy-load japanese.php kalau belum dimuat — jangan sampai fatal error
    // akibat urutan require yang berbeda antar pemanggil.
    // File ini berada di modules/core/helpers/, japanese.php di modules/core/.
    static $japanese_loaded = false;
    if (!$japanese_loaded) {
        require_once __DIR__ . '/../japanese.php';
        $japanese_loaded = true;
    }

    $original = trim("$title $artist $album");
    $analysis = analyzeJapaneseText($original); // 1x MeCab: romaji + english sekaligus

    $combined = trim($original . ' ' . $analysis['romaji'] . ' ' . $analysis['english']);
    return mb_strtolower($combined, 'UTF-8');
}
} // end function_exists('generate_search_metadata')
