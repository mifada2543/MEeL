<?php
/**
 * modules/core/base_url.php — Perhitungan Base URL Terpusat
 *
 * ═══════════════════════════════════════════════════════════════
 * Satu-satunya sumber kebenaran untuk menghitung path base URL proyek
 * (relatif terhadap DOCUMENT_ROOT). Dipakai oleh:
 *   - modules/core/bootstrap.php      (fallback MEEL_BASE_URL)
 *   - auth/config.php                 (definisi MEEL_BASE_URL)
 *   - auth/config.example.php         (template config)
 *   - modules/core/helpers/url.php    (fallback base_url())
 *
 * Dihitung dari lokasi FILE INI (modules/core/ → dirname(__DIR__, 2) =
 * root proyek), sehingga hasilnya konsisten di semua kedalaman include —
 * tidak bergantung pada direktori halaman aktif (SCRIPT_NAME).
 * ═══════════════════════════════════════════════════════════════
 */

if (!function_exists('meel_base_url_path')) {
    /**
     * Hitung path base URL proyek relatif terhadap DOCUMENT_ROOT.
     *
     * Contoh: proyek di /opt/lampp/htdocs/MEeL dengan DOCUMENT_ROOT
     * /opt/lampp/htdocs → "/MEeL". Jika proyek = DOCUMENT_ROOT → "".
     *
     * @return string Path base URL tanpa trailing slash (bisa kosong).
     */
    function meel_base_url_path(): string
    {
        $project_root = str_replace('\\', '/', dirname(__DIR__, 2));
        $doc_root     = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '/');
        $relative     = substr($project_root, strlen(rtrim($doc_root, '/')));
        return rtrim($relative, '/');
    }
}
