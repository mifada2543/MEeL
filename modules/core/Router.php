<?php
/**
 * MEeL — Front Controller Router
 *
 * Satu-satunya titik masuk untuk semua halaman web & API (kecuali aset statis,
 * media storage upload/, dan sub-app arcade/chess yang tetap dilayani langsung
 * oleh Apache sebagai file nyata).
 *
 * URL bersih query-style:
 * /video/watch?id=5            → video/watch.php
 * /music/watch?id=5&playlist_id=3 → music/watch.php
 * /admin/edit-video?id=5       → admin/edit-video.php
 * /api/like                    → controllers/api/like.php
 *
 * Mengapa query-style (bukan /video/watch/5):
 * Kedalaman URL tidak berubah dari file aslinya, sehingga semua link relatif,
 * form POST, dan JS tetap bekerja TANPA <base>.
 *
 * Mekanisme dispatch:
 * 1. router.php (root) memanggil MeelRouter::resolvePath() → path bersih.
 * 2. MeelRouter::dispatch() mencocokkan path ke routing table.
 * 3. Handler (file .php lama) di-include — require relatif di dalamnya
 * tetap di-resolve PHP terhadap direktori file sumber, jadi tidak ada
 * perubahan pada handler.
 * 4. $_SERVER['SCRIPT_NAME'] & PHP_SELF disimulasikan ke handler asli
 * sehingga deteksi halaman (partials/nav.php, activity_logger.php)
 * tetap bekerja tanpa perubahan.
 *
 * @license GPL v3
 */
final class MeelRouter
{
    /** @var array<string, array{handler: string, script: string}> */
    private const ROUTES = [
        // Root pages
        ''                => ['handler' => 'index.php',           'script' => '/index.php'],
        'introduction'    => ['handler' => 'introduction.php',    'script' => '/introduction.php'],
        'update'          => ['handler' => 'update.php',          'script' => '/update.php'],
        'upload'         => ['handler' => 'upload_advanced.php', 'script' => '/upload_advanced.php'],
        'transcode'       => ['handler' => 'transcode.php',       'script' => '/transcode.php'],
        'err'             => ['handler' => 'err/index.php',       'script' => '/err/index.php'],
        'err/offline'     => ['handler' => 'err/offline.php',     'script' => '/err/offline.php'],

        // Video
        'video'           => ['handler' => 'video/index.php',       'script' => '/video/index.php'],
        'video/beranda'   => ['handler' => 'video/index.php',       'script' => '/video/index.php'],
        'video/watch'     => ['handler' => 'video/watch.php',       'script' => '/video/watch.php'],
        'video/search'    => ['handler' => 'video/search_video.php','script' => '/video/search_video.php'],
        'video/load-more' => ['handler' => 'video/load_more.php',   'script' => '/video/load_more.php'],
        'video/upload'    => ['handler' => 'video/upload.php',      'script' => '/video/upload.php'],
        'video/stream'    => ['handler' => 'video/stream.php',      'script' => '/video/stream.php'],

        // Music
        'music'                 => ['handler' => 'music/index.php',          'script' => '/music/index.php'],
        'music/beranda'         => ['handler' => 'music/index.php',          'script' => '/music/index.php'],
        'music/watch'           => ['handler' => 'music/watch.php',          'script' => '/music/watch.php'],
        'music/search'          => ['handler' => 'music/search_music.php',   'script' => '/music/search_music.php'],
        'music/load-more'       => ['handler' => 'music/load_more_music.php','script' => '/music/load_more_music.php'],
        'music/upload'          => ['handler' => 'music/upload.php',         'script' => '/music/upload.php'],
        'music/playlist'        => ['handler' => 'music/view_playlist.php',  'script' => '/music/view_playlist.php'],
        'music/playlist-action' => ['handler' => 'music/playlist_action.php','script' => '/music/playlist_action.php'],
        'music/stream'          => ['handler' => 'music/stream.php',          'script' => '/music/stream.php'],
        'music/file'            => ['handler' => 'music/file.php',            'script' => '/music/file.php'],

        // Books
        'books'        => ['handler' => 'books/index.php',     'script' => '/books/index.php'],
        'books/beranda'=> ['handler' => 'books/index.php',     'script' => '/books/index.php'],
        'books/read'   => ['handler' => 'books/read.php',      'script' => '/books/read.php'],
        'books/read-pdf' => ['handler' => 'books/read_pdf.php','script' => '/books/read_pdf.php'],
        'books/search' => ['handler' => 'books/search_books.php','script' => '/books/search_books.php'],
        'books/upload' => ['handler' => 'books/upload.php',    'script' => '/books/upload.php'],
        'books/file'   => ['handler' => 'books/file.php',      'script' => '/books/file.php'],

        // Drive
        'drive'          => ['handler' => 'drive/index.php',   'script' => '/drive/index.php'],
        'drive/beranda'  => ['handler' => 'drive/index.php',   'script' => '/drive/index.php'],
        'drive/upload'   => ['handler' => 'drive/upload.php',  'script' => '/drive/upload.php'],
        'drive/delete'   => ['handler' => 'drive/delete.php',  'script' => '/drive/delete.php'],
        'drive/download' => ['handler' => 'drive/download.php','script' => '/drive/download.php'],
        'drive/stream'   => ['handler' => 'drive/stream.php',  'script' => '/drive/stream.php'],

        // Profile
        'profile'            => ['handler' => 'profile/index.php',              'script' => '/profile/index.php'],
        'profile/manage'     => ['handler' => 'profile/manage.php',             'script' => '/profile/manage.php'],
        'profile/edit'       => ['handler' => 'controllers/profile/profile_edit.php', 'script' => '/controllers/profile/profile_edit.php'],
        'profile/manage-action' => ['handler' => 'controllers/profile/fun-manage.php', 'script' => '/controllers/profile/fun-manage.php'],

        // Admin
        'admin'             => ['handler' => 'admin/index.php',               'script' => '/admin/index.php'],
        'admin/beranda'     => ['handler' => 'admin/index.php',               'script' => '/admin/index.php'],
        'admin/edit-video'  => ['handler' => 'admin/edit-video.php',          'script' => '/admin/edit-video.php'],
        'admin/edit-music'  => ['handler' => 'admin/edit-music.php',          'script' => '/admin/edit-music.php'],
        'admin/analys'      => ['handler' => 'admin/cookies.php',             'script' => '/admin/cookies.php'],
        'admin/activity-log'=> ['handler' => 'admin/activity_log.php',        'script' => '/admin/activity_log.php'],
        'admin/catur'       => ['handler' => 'admin/catur.php',               'script' => '/admin/catur.php'],
        'admin/mfa-reset'   => ['handler' => 'admin/mfa_reset.php',           'script' => '/admin/mfa_reset.php'],
        'admin/actions'     => ['handler' => 'controllers/admin/admin_actions.php', 'script' => '/controllers/admin/admin_actions.php'],
        'admin/data'        => ['handler' => 'controllers/admin/admin_data.php',    'script' => '/controllers/admin/admin_data.php'],

        // Auth
        'auth/login'    => ['handler' => 'auth/login.php',    'script' => '/auth/login.php'],
        'auth/register' => ['handler' => 'auth/register.php', 'script' => '/auth/register.php'],
        'auth/logout'   => ['handler' => 'auth/logout.php',   'script' => '/auth/logout.php'],
        'auth/mfa-setup'=> ['handler' => 'auth/mfa_setup.php','script' => '/auth/mfa_setup.php'],
        'auth/mfa-verify' => ['handler' => 'auth/mfa_verify.php','script' => '/auth/mfa_verify.php'],

        // Arcade (halaman; sub-app chess + aset tetap file nyata)
        'arcade'           => ['handler' => 'arcade/index.php',          'script' => '/arcade/index.php'],
        'arcade/beranda'   => ['handler' => 'arcade/index.php',          'script' => '/arcade/index.php'],
        'arcade/chess'     => ['handler' => 'arcade/chess/index.php',    'script' => '/arcade/chess/index.php'],
        'arcade/rhythm'    => ['handler' => 'arcade/rhythm/index.php',   'script' => '/arcade/rhythm/index.php'],
        'arcade/rhythm/game'=> ['handler' => 'arcade/rhythm/game.php',   'script' => '/arcade/rhythm/game.php'],
        'arcade/rhythm/editor' => ['handler' => 'arcade/rhythm/editor/index.php', 'script' => '/arcade/rhythm/editor/index.php'],
        'arcade/rhythm/manage' => ['handler' => 'arcade/rhythm/manage/index.php', 'script' => '/arcade/rhythm/manage/index.php'],
        'arcade/rhythm/edit'   => ['handler' => 'arcade/rhythm/manage/edit.php',  'script' => '/arcade/rhythm/manage/edit.php'],
        'arcade/rhythm/manage/edit' => ['handler' => 'arcade/rhythm/manage/edit.php', 'script' => '/arcade/rhythm/manage/edit.php'],
        'arcade/rhythm/api/upload'   => ['handler' => 'arcade/rhythm/api/upload.php',   'script' => '/arcade/rhythm/api/upload.php'],
        'arcade/rhythm/api/delete'   => ['handler' => 'arcade/rhythm/api/delete.php',   'script' => '/arcade/rhythm/api/delete.php'],
        'arcade/rhythm/api/songs'    => ['handler' => 'arcade/rhythm/api/songs.php',    'script' => '/arcade/rhythm/api/songs.php'],
        'arcade/rhythm/api/beatmap'  => ['handler' => 'arcade/rhythm/api/beatmap.php',  'script' => '/arcade/rhythm/api/beatmap.php'],

        // API (controllers/)
        'api/like'               => ['handler' => 'controllers/api/like.php',              'script' => '/controllers/api/like.php'],
        'api/comment'            => ['handler' => 'controllers/api/comment.php',           'script' => '/controllers/api/comment.php'],
        'api/delete-comment'     => ['handler' => 'controllers/api/delete_comment.php',    'script' => '/controllers/api/delete_comment.php'],
        'api/auto-metadata'      => ['handler' => 'controllers/api/auto_metadata.php',     'script' => '/controllers/api/auto_metadata.php'],
        'api/pdf'                => ['handler' => 'controllers/api/pdf.php',               'script' => '/controllers/api/pdf.php'],
        'api/download-transcode' => ['handler' => 'controllers/api/download_transcode.php','script' => '/controllers/api/download_transcode.php'],
        'api/post-encode'        => ['handler' => 'controllers/api/post_encode.php',       'script' => '/controllers/api/post_encode.php'],
        'api/ajax-refresh'       => ['handler' => 'controllers/api/ajax_refresh.php',      'script' => '/controllers/api/ajax_refresh.php'],
        'api/server-stats'       => ['handler' => 'controllers/api/server_stats.php',      'script' => '/controllers/api/server_stats.php'],
        'api/server-stats-sse'   => ['handler' => 'controllers/api/server_stats_sse.php',  'script' => '/controllers/api/server_stats_sse.php'],
        'system/mfa'             => ['handler' => 'controllers/system/mfa.php',            'script' => '/controllers/system/mfa.php'],
    ];

    /** @var string|null Base path proyek (mis. "/MEeL") — di-cache. */
    private static ?string $base = null;

    /**
     * Path base URL proyek relatif terhadap DOCUMENT_ROOT (tanpa trailing slash).
     */
    public static function basePath(): string
    {
        if (self::$base === null) {
            if (defined('MEEL_BASE_URL')) {
                self::$base = rtrim((string) MEEL_BASE_URL, '/');
            } else {
                require_once __DIR__ . '/base_url.php';
                self::$base = meel_base_url_path();
            }
        }
        return self::$base;
    }

    /**
     * Resolve path rute bersih dari REQUEST_URI.
     * Contoh: "/MEeL/video/watch?id=5" → "video/watch"
     */
    public static function resolvePath(): string
    {
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        $path   = (string) parse_url($uri, PHP_URL_PATH);
        $base   = self::basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = ltrim($path, '/');
        $path = rtrim($path, '/');
        // Decode persen-encoding (mis. %20) tapi jaga slash.
        $path = rawurldecode($path);
        return $path;
    }

    /**
     * Cari entri rute untuk path bersih. Mengembalikan null jika tidak cocok.
     *
     * @return array{handler: string, script: string}|null
     */
    public static function routeFor(string $path): ?array
    {
        if (isset(self::ROUTES[$path])) {
            return self::ROUTES[$path];
        }
        // Rute playlist berbasis slug: music/<nama-playlist> — depth SAMA dengan
        // halaman lain (music/watch, music/beranda) sehingga path relatif aset
        // (../assets/...) tetap resolve ke base proyek. Rute eksak di atas selalu
        // menang; path music/ satu-segmen lain dianggap slug playlist.
        // (path sudah di-rawurldecode oleh resolvePath).
        if (preg_match('#^music/([^/]+)$#', $path, $m)) {
            $_GET['slug'] = $m[1];
            return self::ROUTES['music/playlist'];
        }
        return null;
    }

    /**
     * Dispatch path bersih ke handler. Handler meng-include file .php lama —
     * require relatif di dalamnya di-resolve terhadap direktori file sumber,
     * jadi perilaku identik dengan akses langsung.
     *
     * Menyimulasikan $_SERVER['SCRIPT_NAME']/PHP_SELF agar deteksi halaman
     * (nav.php, activity_logger.php) tetap bekerja tanpa perubahan handler.
     *
     * PENTING (resolve relatif): require/include relatif di dalam handler
     * di-resolve PHP terhadap cwd — BUKAN terhadap direktori file sumber.
     * Saat handler diakses langsung, Apache mengeksekusi dengan cwd = direktori
     * file .php tersebut. Maka sebelum include, router melakukan chdir() ke
     * direktori handler agar SEMUA path relatif (require, fopen, dll.)
     * berperilaku identik dengan akses langsung.
     */
    public static function dispatch(string $path): void
    {
        $route = self::routeFor($path);

        if ($route === null) {
            // 404 — tampilkan halaman error terpadu.
            http_response_code(404);
            $_GET['code'] = 'not_found';
            require dirname(__DIR__, 2) . '/err/index.php';
            exit;
        }

        $root     = dirname(__DIR__, 2);
        $handler  = $root . '/' . $route['handler'];
        $base     = self::basePath();
        $script   = $base . $route['script'];

        // Simulasi lokasi handler asli — SCRIPT_NAME & PHP_SELF dipakai untuk
        // deteksi modul/halaman (partials/nav.php, activity_logger.php).
        $_SERVER['SCRIPT_NAME'] = $script;
        $_SERVER['PHP_SELF']    = $script;
        unset($_SERVER['PATH_INFO']);

        // cwd = direktori handler (meniru akses langsung) → path relatif aman.
        if (!@chdir(dirname($handler))) {
            http_response_code(500);
            exit('Gagal mengubah working directory.');
        }

        require $handler;
        exit;
    }

    /**
     * Bangun URL bersih untuk sebuah rute (relatif terhadap base proyek).
     *
     * @param string       $route Rute bersih (mis. "video/watch")
     * @param array|string $query Query string atau array param (?id=5 atau ['id' => 5])
     */
    public static function url(string $route, array|string $query = []): string
    {
        $base  = self::basePath();
        $route = trim($route, '/');
        $url   = $base . ($route !== '' ? '/' . $route : '');
        if (is_array($query) && $query !== []) {
            $url .= '?' . http_build_query($query);
        } elseif (is_string($query) && $query !== '') {
            $url .= (str_starts_with($query, '?') ? '' : '?') . $query;
        }
        return $url;
    }

}
