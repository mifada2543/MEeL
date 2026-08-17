<?php
// helpers/metadata.php — Search Metadata Helper
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

    // Analisis JUDUL terpisah agar alias frasa penuh judul bisa full-cover
    // (jika digabung dgn artist/album, alias tidak pernah menutupi seluruh input).
    $title_analysis = analyzeJapaneseText($title);

    // Romaji artist/album dilampirkan terpisah supaya tetap bisa dicari via romaji.
    // Hanya perlu diproses MeCab jika mengandung non-ASCII — teks ASCII romajinya
    // sama dengan input (menghindari proc_open yang tidak perlu).
    $extra_romaji = '';
    if ($artist !== '' && preg_match('/[^\x20-\x7E]/u', $artist)) $extra_romaji .= ' ' . getRomajiName($artist);
    if ($album  !== '' && preg_match('/[^\x20-\x7E]/u', $album))  $extra_romaji .= ' ' . getRomajiName($album);

    $combined = trim($original . ' ' . $title_analysis['romaji'] . $extra_romaji . ' ' . $title_analysis['english']);
    return mb_strtolower($combined, 'UTF-8');
}
}
