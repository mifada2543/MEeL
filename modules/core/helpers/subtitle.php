<?php
// helpers/subtitle.php — Subtitle & WebVTT Helpers
if (!function_exists('convert_srt_to_vtt')) {
/* @param string $srt Konten file .srt; @return string Konten .vtt yang valid */
function convert_srt_to_vtt(string $srt): string
{
    $srt = strip_utf8_bom($srt);
    $srt = str_replace(["\r\n", "\r"], "\n", $srt);
    $lines = explode("\n", $srt);
    $out   = ['WEBVTT', ''];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Skip baris nomor indeks SRT (angka murni di baris sendiri)
        if ($trimmed !== '' && ctype_digit($trimmed)) {
            continue;
        }

        // Konversi timestamp SRT -> VTT (koma jadi titik).
        // Di-anchor ke pola timestamp (MM:SS / HH:MM:SS) agar teks cue
        // yang kebetulan mengandung ",ddd" tidak ikut terubah.
        if (strpos($line, '-->') !== false) {
            $line = preg_replace('/(\d{1,2}:\d{2}(?::\d{2})?),(\d{3})/', '$1.$2', $line);
        }

        $out[] = $line;
    }

    return implode("\n", $out) . "\n";
}
} // end function_exists('convert_srt_to_vtt')

if (!function_exists('strip_utf8_bom')) {
/* @param string $content Konten teks mentah; @return string Konten tanpa BOM */
function strip_utf8_bom(string $content): string
{
    return preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
}
} // end function_exists('strip_utf8_bom')

if (!function_exists('sanitize_subtitle_lang')) {
/**
 * @param string|null $lang Kode bahasa mentah dari form/nama file
 * @param string $default Nilai fallback jika tidak valid (default 'id')
 * @return string Kode bahasa yang aman untuk nama file
 */
function sanitize_subtitle_lang(?string $lang, string $default = 'id'): string
{
    $lang = strtolower(trim((string)$lang));
    if (preg_match('/^[a-z]{2,3}(?:-[a-z]{2,8})?$/', $lang)) {
        return $lang;
    }
    return $default;
}
} // end function_exists('sanitize_subtitle_lang')

if (!function_exists('lang_map')) {
/* @return array<string,string> Map kode bahasa => label tampilan */
function lang_map(): array
{
    return [
        'id' => 'Indonesia',
        'en' => 'English',
        'ja' => '日本語',
        'zh' => '中文',
        'ko' => '한국어',
        'ms' => 'Melayu',
        'ar' => 'العربية',
        'de' => 'Deutsch',
        'es' => 'Español',
        'fr' => 'Français',
        'it' => 'Italiano',
        'pt' => 'Português',
        'ru' => 'Русский',
        'th' => 'ไทย',
        'vi' => 'Tiếng Việt',
    ];
}
} // end function_exists('lang_map')

if (!function_exists('subtitle_lang_map')) {
/* @return array<string,string> Map kode bahasa => label tampilan */
function subtitle_lang_map(): array
{
    return lang_map();
}
} // end function_exists('subtitle_lang_map')

if (!function_exists('lang_label')) {
/* @param string $lang Kode bahasa (id, en, ja, ...); @return string Label tampilan (Indonesia, English, 日本語, ...) */
function lang_label(string $lang): string
{
    $lang = strtolower(trim($lang));
    $labels = lang_map();
    return $labels[$lang] ?? strtoupper($lang);
}
} // end function_exists('lang_label')

if (!function_exists('subtitle_lang_label')) {
/* @param string $lang Kode bahasa (id, en, ja, ...); @return string Label tampilan (Indonesia, English, 日本語, ...) */
function subtitle_lang_label(string $lang): string
{
    return lang_label($lang);
}
} // end function_exists('subtitle_lang_label')

if (!function_exists('validate_subtitle_file')) {
/* @param string $tmp_path Path file upload di temp; @return bool True jika aman untuk diproses */
function validate_subtitle_file(string $tmp_path): bool
{
    if (!is_file($tmp_path) || filesize($tmp_path) > 2 * 1024 * 1024) {
        return false;
    }
    $content = @file_get_contents($tmp_path);
    if ($content === false) return false;

    // Tolak binary (null byte) dan skrip PHP
    if (strpos($content, "\x00") !== false) return false;
    if (preg_match('/<\?php|<\?=/i', $content)) return false;

    return true;
}
} // end function_exists('validate_subtitle_file')
