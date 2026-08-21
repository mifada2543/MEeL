<?php
/**
 * SwPrecache — Generator daftar precache untuk service worker (sw.js).
 *
 * sw.js TIDAK lagi ditulis manual: file tersebut dibangkitkan dinamis oleh
 * sw.js.php (rewrite via .htaccess) dan memakai daftar precache dari sini.
 *
 * Kenapa dinamis:
 * - Daftar CSS modul diambil otomatis dari setiap manifest.php di
 * subfolder assets/css — menambah folder modul baru TIDAK perlu
 * menyentuh sw.js/sw.js.php lagi.
 * - SW_VERSION dihitung dari hash isi semua aset precache
 * → setiap perubahan konten (aset, manifest, kode generator) otomatis
 * menaikkan versi SW, memicu update + purge cache lama di browser.
 *
 * @license GPL v3
 */
class SwPrecache
{

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /* @return string[] Path relatif ke root project. */
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

    /* @return string[] Path relatif ke root project. */
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

    /* @return string[] Path relatif ke root project. */
    public static function all(): array
    {
        return array_merge(self::baseAssets(), self::moduleAssets());
    }

    public static function version(): string
    {
        $parts = [];

        foreach (self::all() as $rel) {
            $abs = self::root() . '/' . $rel;
            $parts[] = is_file($abs) ? md5_file($abs) : 'MISSING:' . $rel;
        }

        // Isi semua manifest.php (menambah/ubah modul → versi berubah)
        foreach (glob(self::root() . '/assets/css/*/manifest.php') ?: [] as $manifest) {
            $parts[] = md5_file($manifest);
        }

        $parts[] = md5_file(__FILE__);

        // cache lama di-purge & update SW terdeteksi browser.
        $swFile = self::root() . '/sw.js.php';
        $parts[] = is_file($swFile) ? md5_file($swFile) : 'MISSING:sw.js.php';

        return 'v2-' . substr(md5(implode('|', $parts)), 0, 10);
    }
}
