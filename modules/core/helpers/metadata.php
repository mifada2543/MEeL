<?php
// helpers/metadata.php — Search Metadata Helper
// Bagian dari pecahan modules/core/helpers.php.
// Dimuat oleh helpers/main.php.
// Semua fungsi dibungkus function_exists() guard sebagai
// defense-in-depth terhadap double-include.
if (!function_exists('generate_search_metadata')) {
/**
 * @param string $title Judul video / judul lagu
 * @param string $artist Artis (khusus music, opsional)
 * @param string $album Album (khusus music, opsional)
 * @return string search_metadata lowercase
 */
function generate_search_metadata(string $title, string $artist = '', string $album = ''): string
{
    // akibat urutan require yang berbeda antar pemanggil.
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
