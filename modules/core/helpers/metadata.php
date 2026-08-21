<?php
if (!function_exists('generate_search_metadata')) {
function generate_search_metadata(string $title, string $artist = '', string $album = ''): string
{
    static $japanese_loaded = false;
    if (!$japanese_loaded) {
        require_once __DIR__ . '/../japanese.php';
        $japanese_loaded = true;
    }

    $original = trim("$title $artist $album");

    // Title dianalisis terpisah agar alias frasa penuh bisa full-cover.
    $title_analysis = analyzeJapaneseText($title);

    // Romaji artist/album terpisah — hanya proses MeCab jika ada non-ASCII.
    $extra_romaji = '';
    if ($artist !== '' && preg_match('/[^\x20-\x7E]/u', $artist)) $extra_romaji .= ' ' . getRomajiName($artist);
    if ($album  !== '' && preg_match('/[^\x20-\x7E]/u', $album))  $extra_romaji .= ' ' . getRomajiName($album);

    $combined = trim($original . ' ' . $title_analysis['romaji'] . $extra_romaji . ' ' . $title_analysis['english']);
    return mb_strtolower($combined, 'UTF-8');
}
}
