<?php
// helpers/audio.php — Audio MIME & Format Helpers
if (!function_exists('get_audio_mime_type')) {
/* @param string $ext Ekstensi file (mp3, ogg, flac, dll); @return string MIME type yang sesuai */
function get_audio_mime_type(string $ext): string
{
    return match (strtolower($ext)) {
        'mp3'        => 'audio/mpeg',
        'm4a'        => 'audio/mp4',
        'ogg', 'opus' => 'audio/ogg',
        'flac'       => 'audio/flac',
        'wav'        => 'audio/wav',
        default      => 'audio/ogg',
    };
}
} // end function_exists('get_audio_mime_type')

if (!function_exists('get_audio_format_label')) {
/* @param string $ext Ekstensi file (mp3, ogg, flac, dll); @return string Label format (MP3, OPUS, FLAC, dll) */
function get_audio_format_label(string $ext): string
{
    $lower = strtolower($ext);
    return strtoupper($lower === 'ogg' ? 'OPUS' : $lower);
}
} // end function_exists('get_audio_format_label')

if (!function_exists('get_audio_format_description')) {
/* @param string $ext Ekstensi file; @return string Deskripsi format */
function get_audio_format_description(string $ext): string
{
    return match (strtolower($ext)) {
        'ogg', 'opus' => 'Opus adalah codec audio modern untuk web',
        'm4a'         => 'M4a adalah codec audio terbaik dalam hal kompatibilitas',
        'mp3'         => 'Ini adalah codec audio universal yang sangat populer',
        'flac'        => 'Ini adalah codec audio yang memiliki kualitas audio terbaik',
        default       => 'Format audio tidak dikenal',
    };
}
} // end function_exists('get_audio_format_description')
