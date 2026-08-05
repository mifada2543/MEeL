<?php
/**
 * SwPrecache — Generator daftar precache untuk service worker (sw.js).
 *
 * sw.js TIDAK lagi ditulis manual: file tersebut dibangkitkan dinamis oleh
 * sw.js.php (rewrite via .htaccess) dan memakai daftar precache dari sini.
 *
 * Kenapa dinamis:
 *  - Daftar CSS modul diambil otomatis dari setiap manifest.php di
 *    subfolder assets/css — menambah folder modul baru TIDAK perlu
 *    menyentuh sw.js/sw.js.php lagi.
 *  - SW_VERSION dihitung dari hash isi semua aset precache
 *    → setiap perubahan konten (aset, manifest, kode generator) otomatis
 *      menaikkan versi SW, memicu update + purge cache lama di browser.
 *
 * @license GPL v3
 */
class SwPrecache
{
    /**
     * Root project — dihitung dari lokasi file ini (modules/core/).
     * Tidak bergantung pada konstanta MEEL_ROOT yang hanya ada di lingkungan
     * test, sehingga aman dipakai langsung dari sw.js.php (produksi).
     */
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Aset tetap (non-modul) yang selalu di-pre-cache.
     * Termasuk CSS "shared" yang tidak punya manifest.php sendiri
     * (list paralel <link>, bukan @import).
     *
     * @return string[] Path relatif ke root project.
     */
    public static function baseAssets(): array
    {
        return [
            // CSS inti
            'assets/css/index(hub).css',
            'assets/css/tailwind.min.css',
            'assets/css/font.css',
            'assets/css/plyr.css',
            'assets/css/up.css',
            'assets/css/introduction.css',
            // CSS shared (tanpa manifest.php)
            'assets/css/shared/design-tokens.css',
            'assets/css/shared/upload-form.css',
            // JS
            'assets/js/compatibilitas/lucide.js',
            'assets/js/compatibilitas/hls.js',
            // Font
            'assets/css/font/latin.woff2',
            // Assets
            'assets/MEeL.png',
            'assets/MEeL-192.png',
            'assets/MEeL-512.png',
            'assets/MEeL-180.png',
            // Manifest
            'assets/manifest.json',
            // Offline page
            'err/offline.php',
        ];
    }

    /**
     * CSS modul — otomatis dari SEMUA manifest.php di subfolder assets/css.
     * Folder modul baru (yang punya manifest.php) langsung ikut ter-precache
     * tanpa perlu mengubah kode apa pun.
     *
     * @return string[] Path relatif ke root project.
     */
    public static function moduleAssets(): array
    {
        $out = [];
        foreach (glob(self::root() . '/assets/css/*/manifest.php') ?: [] as $manifest) {
            $folder = basename(dirname($manifest));
            foreach (require $manifest as $mod) {
                $out[] = "assets/css/{$folder}/{$mod}";
            }
        }
        return $out;
    }

    /**
     * Daftar precache lengkap (aset tetap + semua modul CSS).
     *
     * @return string[] Path relatif ke root project.
     */
    public static function all(): array
    {
        return array_merge(self::baseAssets(), self::moduleAssets());
    }

    /**
     * Versi SW otomatis — hash isi semua input precache + kode generator.
     * Format: v2-<hash 10 char>. Deterministik: output identik selama tidak
     * ada konten yang berubah, sehingga browser tidak melakukan update SW
     * yang tidak perlu pada setiap kunjungan.
     */
    public static function version(): string
    {
        $parts = [];

        // Isi semua aset precache (file hilang → penanda 'MISSING:' beda hash)
        foreach (self::all() as $rel) {
            $abs = self::root() . '/' . $rel;
            $parts[] = is_file($abs) ? md5_file($abs) : 'MISSING:' . $rel;
        }

        // Isi semua manifest.php (menambah/ubah modul → versi berubah)
        foreach (glob(self::root() . '/assets/css/*/manifest.php') ?: [] as $manifest) {
            $parts[] = md5_file($manifest);
        }

        // Kode generator itu sendiri (perubahan logika → versi berubah)
        $parts[] = md5_file(__FILE__);

        return 'v2-' . substr(md5(implode('|', $parts)), 0, 10);
    }
}
