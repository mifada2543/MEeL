<?php


class SwPrecache
{

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    
    public static function baseAssets(): array
    {
        return [
            
            'assets/css/index(hub).css',
            'assets/css/tailwind.min.css',
            'assets/css/font.css',
            'assets/css/plyr.css',
            'assets/css/up.css',
            'assets/css/introduction.css',
            
            'assets/css/shared/design-tokens.css',
            'assets/css/shared/upload-form.css',
            'assets/css/shared/theme-tokens.css',
            'assets/css/shared/light-theme.css',
            
            'assets/js/compatibilitas/lucide.js',
            'assets/js/compatibilitas/hls.js',
            'assets/js/shared/theme.js',
            
            'assets/css/font/latin.woff2',
            
            'assets/MEeL.png',
            'assets/MEeL-192.png',
            'assets/MEeL-512.png',
            'assets/MEeL-180.png',
            
            'assets/manifest.json',
            
            'err/offline.php',
        ];
    }

    
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

        
        foreach (glob(self::root() . '/assets/css/*/manifest.php') ?: [] as $manifest) {
            $parts[] = md5_file($manifest);
        }

        $parts[] = md5_file(__FILE__);

        
        $swFile = self::root() . '/sw.js.php';
        $parts[] = is_file($swFile) ? md5_file($swFile) : 'MISSING:sw.js.php';

        return 'v2-' . substr(md5(implode('|', $parts)), 0, 10);
    }
}
