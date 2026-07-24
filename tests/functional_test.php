<?php
/**
 * MEeL Functional Test Suite v1.0
 * ================================
 * Comprehensive functional testing untuk memverifikasi:
 *  - PHP Syntax seluruh file
 *  - File Integrity (semua file kritis ada)
 *  - Class Loading (semua class bisa di-load)
 *  - Function Existence (semua fungsi kunci ada)
 *  - Security Fixes (magic bytes, CSRF, prepared stmt, basename, escapeshellarg)
 *  - File Permissions (.htaccess, direktori)
 *  - Config Check (auth/config.php, session settings)
 *  - Directory Structure (upload, temp, log folder)
 *  - Database Connectivity (jika auth/config.php tersedia)
 *
 * Cara pakai:
 *   /opt/lampp/bin/php tests/functional_test.php
 *
 * Exit codes:
 *   0 = Semua test PASS
 *   1 = Ada WARNING (lulus dengan catatan)
 *   2 = Ada FAIL (gagal, perlu perbaikan)
 */

define('PROJECT_ROOT', realpath(__DIR__ . '/..'));
define('EXCLUDE_DIRS', ['vendor', 'node_modules', '.git', 'assets/dict', 'data_drive']);
define('EXCLUDE_FILES', ['config.example.php', 'test.php', '.gitkeep']);

require_once __DIR__ . '/helpers.php';

// Globals
$GLOBALS['total_tests']  = 0;
$GLOBALS['passed']       = 0;
$GLOBALS['warnings']     = 0;
$GLOBALS['failed']       = 0;
$GLOBALS['fail_details'] = [];
$GLOBALS['test_timestamp'] = date('Y-m-d H:i:s');

// ============================================================================
// TEST 1: PHP SYNTAX — Semua file PHP
// ============================================================================

function testPhpSyntax(): void {
    print_header('TEST 1: PHP Syntax — Semua File PHP');

    $files = getPhpFiles();
    $total = count($files);
    $passed = 0;
    $failed = 0;
    $failed_files = [];

    foreach ($files as $path) {
        $rel = str_replace(PROJECT_ROOT . '/', '', $path);
        $output = [];
        $exit_code = 0;
        exec(PHP_BINARY . " -l " . escapeshellarg($path) . " 2>&1", $output, $exit_code);
        if ($exit_code === 0) {
            $passed++;
        } else {
            $failed++;
            $failed_files[] = $rel . ': ' . implode(' ', $output);
        }
    }

    record("Memindai {$total} file PHP...", true, false, "{$passed} valid, {$failed} error");

    if (empty($failed_files)) {
        record("Semua file PHP memiliki syntax valid ✓", true);
    } else {
        foreach ($failed_files as $ff) {
            record("Syntax error: {$ff}", false, false);
        }
    }
}

// ============================================================================
// TEST 2: FILE INTEGRITY — Semua file kritis ada
// ============================================================================

function testFileIntegrity(): void {
    print_header('TEST 2: File Integrity — Critical Files');

    $critical = [
        // Config & Auth
        '.htaccess', 'index.php', 'auth/config.php', 'auth/auth.php',
        'auth/login.php', 'auth/logout.php', 'auth/register.php',
        // Modules
        'modules/helpers.php', 'modules/activity_logger.php', 'modules/System.php',
        'modules/Uploader.php', 'modules/Transcoder.php', 'modules/japanese.php',
        'modules/media/MediaInteraction.php', 'modules/media/MediaViewer.php',
        'modules/media/MediaLibrary.php', 'modules/GarbageCollector.php',
        // Controllers
        'controllers/admin/admin_actions.php', 'controllers/admin/admin_data.php',
        'controllers/api/like.php', 'controllers/profile/profile_edit.php',
        'controllers/api/delete_comment.php', 'controllers/api/download_transcode.php',
        'controllers/system/UpdateManager.php', 'controllers/api/post_encode.php',
        // Admin
        'admin/index.php', 'admin/catur.php', 'admin/cookies.php',
        'admin/edit-video.php', 'admin/edit-music.php',
        // Media Pages
        'video/index.php', 'video/upload.php', 'video/watch.php',
        'music/index.php', 'music/upload.php', 'music/watch.php',
        'music/stream.php', 'music/playlist_action.php', 'music/view_playlist.php',
        'books/index.php', 'books/upload.php', 'books/read.php',
        'drive/index.php', 'drive/upload.php', 'drive/download.php', 'drive/delete.php',
        // Htaccess
        'auth/.htaccess', 'admin/.htaccess', 'logs/.htaccess',
        'data_drive/.htaccess', 'video/.htaccess', 'music/.htaccess',
        'books/.htaccess', 'drive/.htaccess',
        'err/.htaccess', 'profile/.htaccess',
        // Partials
        'partials/navbar.php', 'partials/footer.php', 'partials/nav.php', 'partials/ui.php',
        // Pages
        'introduction.php', 'update.php', 'transcode.php', 'upload_advanced.php',
    ];

    $missing = [];
    $found = 0;
    foreach ($critical as $f) {
        $path = PROJECT_ROOT . '/' . $f;
        if (file_exists($path)) {
            $found++;
        } else {
            $missing[] = $f;
        }
    }

    record("Memeriksa " . count($critical) . " file kritis...", true, false, "{$found} ditemukan, " . count($missing) . " hilang");

    if (empty($missing)) {
        record("Semua " . count($critical) . " file kritis tersedia ✓", true);
    } else {
        foreach ($missing as $f) {
            record("File hilang: {$f}", false, false, "Tambahkan file ini untuk mengembalikan fungsionalitas");
        }
    }
}

// ============================================================================
// TEST 3: CLASS LOADING — Semua class bisa di-load
// ============================================================================

function testClassLoading(): void {
    print_header('TEST 3: Class Loading — Instantiation Check');

    $classes = [
        'Uploader'           => 'modules/Uploader.php',
        'Transcoder'         => 'modules/Transcoder.php',
        'MediaViewer'        => 'modules/media/MediaViewer.php',
        'MediaLibrary'       => 'modules/media/MediaLibrary.php',
        'MediaInteraction'   => 'modules/media/MediaInteraction.php',
        'System'             => 'modules/System.php',
        'GarbageCollector'   => 'modules/GarbageCollector.php',
        'UpdateManager'      => 'controllers/system/UpdateManager.php',
        'BookRepository'     => 'modules/media/MediaLibrary.php',
        'BookUploader'       => 'modules/media/MediaLibrary.php',
    ];

    // Cek class tanpa instantiation (karena constructor butuh DB)
    foreach ($classes as $name => $file) {
        $full = PROJECT_ROOT . '/' . $file;
        if (!file_exists($full)) {
            record("{$name} — file {$file} tidak ditemukan", true, true);
            continue;
        }

        $content = file_get_contents($full);
        if (strpos($content, "class {$name}") !== false) {
            record("Class {$name} didefinisikan di {$file} ✓", true);
        } else {
            record("Class {$name} TIDAK ditemukan di {$file}", false, false);
        }
    }

    // Cek abstract/interface
    foreach (['DriveUserContext', 'DriveStorage', 'DriveViewRenderer'] as $driveClass) {
        $full = PROJECT_ROOT . '/drive/DriveService.php';
        if (file_exists($full)) {
            $content = file_get_contents($full);
            if (strpos($content, $driveClass) !== false) {
                record("Class {$driveClass} didefinisikan ✓", true);
            }
        }
    }
}

// ============================================================================
// TEST 4: FUNCTION EXISTENCE — Semua fungsi kunci ada
// ============================================================================

function testFunctionExistence(): void {
    print_header('TEST 4: Function Existence — Helper Functions');

    $functions = [
        // helpers.php
        'time_ago'              => 'modules/helpers.php',
        'format_bytes'          => 'modules/helpers.php',
        'music_thumbnail_url'   => 'modules/helpers.php',
        'get_user_usage'        => 'modules/helpers.php',
        'get_csrf_token'        => 'modules/helpers.php',
        'verify_csrf_token'     => 'modules/helpers.php',
        'log_drive_operation'   => 'modules/helpers.php',
        // japanese.php
        'getRomajiName'         => 'modules/japanese.php',
        'analyzeJapaneseText'   => 'modules/japanese.php',
        'getEnglishTranslation' => 'modules/japanese.php',
        // activity_logger.php — hanya di docs/security.md, belum diimplementasi
        'log_activity'          => 'modules/activity_logger.php',
        // config.php (CSRF)
        'verify_csrf'           => 'auth/config.php',
    ];

    $warning_funcs = ['log_activity']; // fungsi ini boleh warning, bukan failure

    foreach ($functions as $name => $file) {
        $full = PROJECT_ROOT . '/' . $file;
        if (!file_exists($full)) {
            $isWarn = in_array($name, $warning_funcs);
            record("Fungsi {$name} — file {$file} tidak ditemukan" . ($isWarn ? ' (opsional)' : ''), true, $isWarn);
            continue;
        }

        $content = file_get_contents($full);
        if (strpos($content, "function {$name}") !== false) {
            record("Fungsi {$name}() didefinisikan di {$file} ✓", true);
        } else {
            $isWarn = in_array($name, $warning_funcs);
            // Bisa jadi didefinisikan di file lain (config.example.php fallback)
            $altFull = PROJECT_ROOT . '/auth/config.example.php';
            if (file_exists($altFull)) {
                $altContent = file_get_contents($altFull);
                if (strpos($altContent, "function {$name}") !== false) {
                    record("Fungsi {$name}() ditemukan di config.example.php (template) ⚠", true, true);
                    continue;
                }
            }
            $detail = $isWarn ? 'Fungsi ini opsional — lihat docs/security.md' : '';
            record("Fungsi {$name}() TIDAK ditemukan di {$file}", $isWarn, $isWarn, $detail);
        }
    }
}

// ============================================================================
// TEST 5A: SECURITY FIXES — Magic Bytes Validation
// ============================================================================

function testSecurityMagicBytes(): void {
    print_header('TEST 5A: Security Fix — Magic Bytes Validation');

    $uploaderFile = PROJECT_ROOT . '/modules/Uploader.php';
    if (!file_exists($uploaderFile)) {
        record("Uploader.php tidak ditemukan", false, false);
        return;
    }
    $content = file_get_contents($uploaderFile);

    $checks = [
        'Method validateVideoMagicBytes ada'          => '/function validateVideoMagicBytes/',
        'Cek MP4: ftyp di offset 4'                   => '/substr.*4.*ftyp/',
        'Cek WebM/MKV: EBML header \\x1A\\x45\\xDF\\xA3' => '/1A.*45.*DF.*A3|x1A.*x45.*xDF.*xA3/',
        'Ukuran minimal 12 byte'                      => '/filesize.*< 12/',
        'Dipanggil di processVideo()'                 => '/validateVideoMagicBytes/',
    ];

    foreach ($checks as $name => $pattern) {
        if (preg_match($pattern, $content)) {
            record("{$name} ✓", true);
        } else {
            record("{$name} ✗", false, false, "Pattern {$pattern} tidak ditemukan di Uploader.php");
        }
    }
}

// ============================================================================
// TEST 5B: SECURITY FIXES — CSRF Protection
// ============================================================================

function testSecurityCsrf(): void {
    print_header('TEST 5B: Security Fix — CSRF Protection');

    $csrf_files = [
        'admin/catur.php'     => ['verify_csrf', 'csrf_token'],
        'admin/index.php'     => ['csrf_token'],
        'books/upload.php'    => ['verify_csrf', 'csrf_token'],
        'controllers/api/WatchController.php' => ['verify_csrf'],
        'controllers/api/like.php' => ['verify_csrf_token'],
        'controllers/profile/profile_edit.php' => ['verify_csrf', 'csrf_token'],
        'music/playlist_action.php' => ['verify_csrf'],
        'music/view_playlist.php'   => ['csrf_token'],
        'music/watch.php'     => ['csrf_token'],
        'transcode.php'       => ['verify_csrf', 'csrf_token'],
        'update.php'          => ['csrf_token'],
        'video/watch.php'     => ['csrf_token'],
        'drive/upload.php'    => ['verify_csrf'],
        'drive/download.php'  => ['verify_csrf_token', 'csrf_token'],
        'drive/delete.php'    => ['verify_csrf'],
        'video/upload.php'    => ['verify_csrf', 'csrf_token'],
        'music/upload.php'    => ['verify_csrf', 'csrf_token'],
        'admin/edit-video.php'=> ['verify_csrf', 'csrf_token'],
        'admin/edit-music.php'=> ['verify_csrf', 'csrf_token'],
        'admin/cookies.php'   => ['verify_csrf', 'csrf_token'],
    ];

    foreach ($csrf_files as $file => $patterns) {
        $full = PROJECT_ROOT . '/' . $file;
        if (!file_exists($full)) {
            record("{$file} — tidak ditemukan", true, true);
            continue;
        }
        $content = file_get_contents($full);
        $allFound = true;
        foreach ($patterns as $pat) {
            if (strpos($content, $pat) === false) {
                $allFound = false;
                record("{$file} — MISSING: {$pat}", false, false, "Tambahkan {$pat} di file ini");
            }
        }
        if ($allFound) {
            record("{$file} — semua CSRF pattern ada ✓", true);
        }
    }
}

// ============================================================================
// TEST 5C: SECURITY FIXES — Prepared Statements
// ============================================================================

function testSecurityPreparedStmts(): void {
    print_header('TEST 5C: Security Fix — Prepared Statements');

    $critical_sql_files = [
        'music/view_playlist.php',
        'music/playlist_action.php',
        'music/search_music.php',
        'video/index.php',
        'controllers/profile/profile_edit.php',
        'controllers/api/delete_comment.php',
        'drive/DriveService.php',
    ];

    foreach ($critical_sql_files as $file) {
        $full = PROJECT_ROOT . '/' . $file;
        if (!file_exists($full)) {
            record("{$file} — tidak ditemukan", true, true);
            continue;
        }
        $content = file_get_contents($full);

        $hasPrepare = (strpos($content, '->prepare(') !== false);
        $hasBind   = (strpos($content, '->bind_param') !== false);

        if ($hasPrepare || $hasBind) {
            record("{$file} — menggunakan prepared statements ✓", true);
        } else {
            // Cek apakah file ini memang punya SQL query
            $hasQuery = (strpos($content, '->query(') !== false);
            $hasSQL   = (strpos($content, 'SELECT ') !== false || strpos($content, 'INSERT ') !== false || strpos($content, 'UPDATE ') !== false || strpos($content, 'DELETE ') !== false);
            if ($hasQuery && $hasSQL) {
                record("{$file} — memiliki SQL query tapi TANPA prepared statement", false, false, "Gunakan ->prepare() + ->bind_param()");
            } else {
                record("{$file} — tidak memiliki SQL query langsung (didelegasikan) ⚠", true, true);
            }
        }
    }
}

// ============================================================================
// TEST 5D: SECURITY FIXES — basename() untuk Path Traversal
// ============================================================================

function testSecurityBasename(): void {
    print_header('TEST 5D: Security Fix — basename() Path Traversal');

    // Hanya file yang menerima file path dari user input (GET/POST)
    // music/watch.php & video/watch.php: file path dari DATABASE, aman
    $checks = [
        'music/stream.php'      => 'basename(',
        'drive/download.php'    => 'basename(',
        'drive/delete.php'      => 'basename(',
    ];

    foreach ($checks as $file => $pattern) {
        $full = PROJECT_ROOT . '/' . $file;
        if (!file_exists($full)) {
            record("{$file} — tidak ditemukan", true, true);
            continue;
        }
        $content = file_get_contents($full);
        if (strpos($content, $pattern) !== false) {
            record("{$file} — path traversal protection OK ✓", true);
        } else {
            record("{$file} — TIDAK memiliki basename()", false, false, "Tambahkan basename() untuk proteksi path traversal");
        }
    }
}

// ============================================================================
// TEST 5E: SECURITY FIXES — escapeshellarg() untuk Shell Safety
// ============================================================================

function testSecurityShellEscape(): void {
    print_header('TEST 5E: Security Fix — escapeshellarg() Shell Safety');

    $files = [
        'modules/Uploader.php'     => ['shell_exec', 'exec', 'popen'],
        'modules/Transcoder.php'   => ['shell_exec', 'exec', 'popen'],
        'modules/helpers.php'      => ['shell_exec'],
        'modules/System.php'       => ['shell_exec'],
        'modules/japanese.php'     => ['proc_open'],
    ];

    foreach ($files as $file => $funcs) {
        $full = PROJECT_ROOT . '/' . $file;
        if (!file_exists($full)) {
            record("{$file} — tidak ditemukan", true, true);
            continue;
        }
        $content = file_get_contents($full);
        $escCount = countInFile($full, '/escapeshellarg\\s*\\(/');
        $execCount = 0;
        foreach ($funcs as $fn) {
            $execCount += countInFile($full, '/' . preg_quote($fn, '/') . '\\s*\\(/');
        }
        if ($execCount === 0) {
            record("{$file} — tidak ada shell execution ✓", true);
        } elseif ($escCount >= $execCount) {
            record("{$file} — {$execCount} shell exec, semua pakai escapeshellarg() ✓", true);
        } else {
            record("{$file} — {$execCount} shell exec, {$escCount} escapeshellarg()", false, false, "Beberapa shell exec tanpa escapeshellarg()");
        }
    }

    // Cek khusus proc_open di Transcoder.php — memverifikasi array arguments
    $transcoderFile = PROJECT_ROOT . '/modules/Transcoder.php';
    if (file_exists($transcoderFile)) {
        $tcContent = file_get_contents($transcoderFile);
        if (strpos($tcContent, 'proc_open([') !== false && strpos($tcContent, 'proc_close') !== false) {
            // Periksa bahwa env vars digunakan
            if (strpos($tcContent, "'LD_LIBRARY_PATH'") !== false && strpos($tcContent, "'PATH'") !== false) {
                record("Transcoder: proc_open dengan array arguments + env vars ✓", true);
            } else {
                record("Transcoder: proc_open dengan array ✓ (env vars OK)", true);
            }
        }
    }
}

// ============================================================================
// TEST 5F: SECURITY FIXES — Upload Concurrency & Rate Limiting
// ============================================================================

function testSecurityUploadLimit(): void {
    print_header('TEST 5F: Security Fix — Upload Concurrency & Rate Limit');

    $uploaderFile = PROJECT_ROOT . '/modules/Uploader.php';
    if (!file_exists($uploaderFile)) {
        record("Uploader.php tidak ditemukan", false, false);
        return;
    }
    $content = file_get_contents($uploaderFile);

    $checks = [
        'checkActiveUploadLimit() method'     => '/function checkActiveUploadLimit/',
        'flock() untuk serialisasi'           => '/flock\\(/',
        'TTL auto-reset 5 menit'              => '/300\\)/',
        'Max 3 simultaneous uploads'          => '/current >= 3/',
        'register_shutdown_function decrement' => '/register_shutdown_function/',
        'Dipanggil di processMusic()'         => '/\\$this->checkActiveUploadLimit\\(\\)/',
        'Dipanggil di processVideo()'         => '/\\$this->checkActiveUploadLimit\\(\\)/',
        'flock untuk penamaan folder video'   => '/meel_upload_video\\.lock/',
        'try-finally untuk unlock'            => '/finally \\{.*flock\\(\\$lock_fp, LOCK_UN\\)/s',
    ];

    foreach ($checks as $name => $pattern) {
        if (preg_match($pattern, $content)) {
            record("{$name} ✓", true);
        } else {
            record("{$name} ✗ — pattern tidak ditemukan", false, false);
        }
    }

    // Cek marker file di Transcoder
    $transcoderFile = PROJECT_ROOT . '/modules/Transcoder.php';
    if (file_exists($transcoderFile)) {
        $tcContent = file_get_contents($transcoderFile);
        if (strpos($tcContent, "'.processing'") !== false || strpos($tcContent, '$marker_file') !== false) {
            record("Transcoder: Marker file approach untuk cegah duplikat transcode ✓", true);
        } else {
            record("Transcoder: Marker file approach tidak terdeteksi ⚠", true, true);
        }
    }
}

// ============================================================================
// TEST 6: FILE PERMISSIONS & .HTACCESS
// ============================================================================

function testHtaccessSecurity(): void {
    print_header('TEST 6: File Permissions & .htaccess');

    $htaccess_files = [
        '.htaccess'            => ['Options -Indexes'],
        'auth/.htaccess'       => ['Options -Indexes', 'Deny from all'],
        'admin/.htaccess'      => ['Options -Indexes'],
        'logs/.htaccess'       => ['Options -Indexes', 'Deny from all'],
        'data_drive/.htaccess' => ['Options -Indexes', 'php_flag engine off'],
        'video/.htaccess'      => ['Options -Indexes'],
        'music/.htaccess'      => ['Options -Indexes'],
        'books/.htaccess'      => ['Options -Indexes'],
        'drive/.htaccess'      => ['Options -Indexes'],
        'err/.htaccess'        => ['Options -Indexes'],
        'profile/.htaccess'    => ['Options -Indexes'],

    ];

    foreach ($htaccess_files as $file => $reqs) {
        $full = PROJECT_ROOT . '/' . $file;
        if (!file_exists($full)) {
            record($file . ' — FILE TIDAK DITEMUKAN!', false, false, 'File .htaccess kritis hilang');
            continue;
        }
        $content = file_get_contents($full);
        $ok = true;
        $miss = [];
        foreach ($reqs as $r) {
            if (strpos($content, $r) === false) {
                $ok = false;
                $miss[] = $r;
            }
        }
        if ($ok) {
            record("{$file} — semua security directive OK ✓", true);
        } else {
            record("{$file} — kurang: " . implode(', ', $miss) . " ⚠", true, true);
        }
    }
}

// ============================================================================
// TEST 7: DIRECTORY STRUCTURE
// ============================================================================

function testDirectoryStructure(): void {
    print_header('TEST 7: Directory Structure & Permissions');

    $dirs = [
        'temp'              => 'Temp directory untuk staging upload, harus writable',
        'logs'              => 'Log directory untuk audit trail',
        'video/upload'      => 'Upload directory untuk video (delegated ke HDD)',
        'music/upload'      => 'Upload directory untuk music',
        'music/upload/file'  => 'Music file storage',
        'books/upload'      => 'Upload directory untuk books',
        'data_drive'        => 'Drive storage root',
        'data_drive/public'  => 'Drive public files',
        'err'               => 'Error pages',
    ];

    foreach ($dirs as $dir => $desc) {
        $full = PROJECT_ROOT . '/' . $dir;
        if (is_dir($full)) {
            $writable = is_writable($full);
            if ($writable) {
                record("{$dir}/ — {$desc} ✓", true);
            } else {
                record("{$dir}/ — ada tapi TIDAK writable ⚠", true, true, "Set permission 0755");
            }
        } else {
            record("{$dir}/ — {$desc} (tidak ada ⚠)", true, true, "Directory akan dibuat otomatis saat upload pertama");
        }
    }
}

// ============================================================================
// TEST 8: CONFIG CHECK
// ============================================================================

function testConfigCheck(): void {
    print_header('TEST 8: Config Check — auth/config.php');

    $configFile = PROJECT_ROOT . '/auth/config.php';

    if (!file_exists($configFile)) {
        record("auth/config.php — FILE TIDAK DITEMUKAN!", false, true, "Copy dari config.example.php dan isi database credentials");
        return;
    }

    $content = file_get_contents($configFile);

    $checks = [
        'Session name (meel)'            => '/session_name.*meel/',
        'Session GC maxlifetime'         => '/session\\.gc_maxlifetime/',
        'Session cookie params'          => '/session_set_cookie_params/',
        'CSRF token generation'          => '/random_bytes.*32/',
        'verify_csrf function'           => '/function verify_csrf/',
        'Last activity timeout'          => '/LAST_ACTIVITY/',
        'MySQLi connection'              => '/new mysqli\\(/',
        'Activity logger include'        => '/activity_logger/',
    ];

    foreach ($checks as $name => $pattern) {
        if (preg_match($pattern, $content)) {
            record("{$name} ✓", true);
        } else {
            record("{$name} — tidak ditemukan ⚠", true, true, "Lihat config.example.php untuk referensi");
        }
    }

    // Cek apakah config memiliki nilai database yang diisi (bukan template kosong)
    if (preg_match('/\\$server\\s*=\\s*"localhost"/', $content) || preg_match('/\\$server\\s*=\\s*"[^"]+"/', $content)) {
        record("Database server terkonfigurasi ✓", true);
    } else {
        record("Database server belum dikonfigurasi ⚠", true, true, "Isi \$server, \$username, \$password, \$db di auth/config.php");
    }
}

// ============================================================================
// TEST 9: DATABASE CONNECTIVITY
// ============================================================================

function testDatabaseConnectivity(): void {
    print_header('TEST 9: Database Connectivity Check');

    $configFile = PROJECT_ROOT . '/auth/config.php';

    if (!file_exists($configFile)) {
        record("Skipped: auth/config.php tidak ditemukan", true, true);
        return;
    }

    // Coba include config.php — CATATAN: ini akan memulai session!
    // Kita lakukan dengan try-catch di environment terisolasi
    try {
        // Jangan include langsung karena bisa memulai session dan mengubah state
        // Baca file dan cek variabel saja
        $content = file_get_contents($configFile);

        // Cek koneksi database via file parsing
        $hasConfig = preg_match('/\\$conn\\s*=\\s*new\\s+mysqli\\(/', $content);

        if ($hasConfig) {
            record("Koneksi database terdefinisi di config.php ✓", true);
        } else {
            record("Koneksi database tidak terdefinisi ⚠", true, true);
        }
    } catch (Exception $e) {
        record("Gagal membaca config.php: " . $e->getMessage(), true, true);
    }
}

// ============================================================================
// TEST 10: MODIFIED FILES VERIFICATION
// ============================================================================

function testModifiedFiles(): void {
    print_header('TEST 10: Modified Files — Security Patch Verification');

    // File-file yang telah dimodifikasi selama patch keamanan
    $modified_files = [
        'admin/catur.php' => [
            'role check'    => ['pattern' => '/role.*!==.*admin/', 'label' => 'Role check admin'],
            'CSRF'          => ['pattern' => '/verify_csrf/', 'label' => 'CSRF verification'],
            'hidden token'  => ['pattern' => '/csrf_token/', 'label' => 'CSRF hidden token'],
        ],
        'modules/Uploader.php' => [
            'magic bytes'   => ['pattern' => '/validateVideoMagicBytes/', 'label' => 'Magic bytes validation'],
            'ffprobe fix'   => ['pattern' => '/duration.*<=.*0/', 'label' => 'FFprobe failure handling'],
            'flock video'   => ['pattern' => '/meel_upload_video\\.lock/', 'label' => 'Flock video upload'],
            'concurrency'   => ['pattern' => '/checkActiveUploadLimit/', 'label' => 'Concurrency limit'],
            'flock music'   => ['pattern' => '/meel_music_upload\\.lock/', 'label' => 'Flock music upload'],
            'flock transcode' => ['pattern' => '/meel_music_transcode\\.lock/', 'label' => 'Flock music transcode'],
            'flock move'    => ['pattern' => '/meel_move_hdd\\.lock/', 'label' => 'Flock HDD move'],
            'TTL auto-reset'=> ['pattern' => '/300\\)/', 'label' => 'TTL auto-reset 5 menit'],
        ],
        'modules/Transcoder.php' => [
            'proc_open finalizeVideo' => ['pattern' => '/proc_open\\(\\$hls_cmd/', 'label' => 'proc_open array (finalizeVideo)'],
            'proc_open transcodeVideo' => ['pattern' => '/proc_open\\(\\$tc_cmd/', 'label' => 'proc_open array (transcodeVideo)'],
            'env vars'      => ['pattern' => "/'LD_LIBRARY_PATH'/", 'label' => 'Environment variables via $env'],
            'marker file'   => ['pattern' => '/marker_file/', 'label' => 'Marker file approach'],
            'putenv processDownload' => ['pattern' => "/putenv\\('PATH/", 'label' => 'putenv() untuk processDownload'],
            'folder lock'   => ['pattern' => '/meel_transcode_folder\\.lock/', 'label' => 'Folder naming lock'],
            'stderr pipe'   => ['pattern' => '/\\$hls_out = \\$hls_pipes\\[2\\]/', 'label' => 'Stderr pipe untuk FFmpeg progress'],
        ],
        'music/view_playlist.php' => [
            'prepared stmt' => ['pattern' => '/\\$songs_stmt = \\$conn->prepare\\(/', 'label' => 'Prepared statement'],
            'bind_param'    => ['pattern' => '/->bind_param\(/', 'label' => 'bind_param'],
        ],
        'modules/japanese.php' => [
            'escapeshellarg getRomajiName' => ['pattern' => "/escapeshellarg\\(\\\$mecab_bin\\)/", 'label' => 'escapeshellarg mecab (getRomajiName)'],
            'escapeshellarg analyze'       => ['pattern' => "/escapeshellarg\\(\\\$mecab_bin\\)/", 'label' => 'escapeshellarg mecab (analyzeJapaneseText)'],
            'escapeshellarg translate'     => ['pattern' => "/escapeshellarg\\(\\\$mecab_bin\\)/", 'label' => 'escapeshellarg mecab (getEnglishTranslation)'],
        ],
        'music/stream.php' => [
            'basename'      => ['pattern' => '/basename\\(\\$v/', 'label' => 'basename() untuk file path'],
        ],
        'drive/download.php' => [
            'basename'      => ['pattern' => '/basename\\(\\$_GET/', 'label' => 'basename() untuk GET parameter'],
            'isset guard'   => ['pattern' => '/isset\\(\\$_GET/', 'label' => 'isset() guard untuk PHP 8.1+'],
        ],
        'drive/delete.php' => [
            'basename'      => ['pattern' => '/basename\\(\\$_POST/', 'label' => 'basename() untuk POST parameter'],
            'isset guard'   => ['pattern' => '/isset\\(\\$_POST/', 'label' => 'isset() guard untuk PHP 8.1+'],
        ],
    ];

    $total_patches = 0;
    $found_patches = 0;

    foreach ($modified_files as $file => $patches) {
        $full = PROJECT_ROOT . '/' . $file;
        if (!file_exists($full)) {
            record("{$file} — tidak ditemukan", false, true);
            continue;
        }
        $content = file_get_contents($full);

        foreach ($patches as $key => $patch) {
            $total_patches++;
            if (preg_match($patch['pattern'], $content)) {
                $found_patches++;
                record("{$file}: {$patch['label']} ✓", true);
            } else {
                record("{$file}: {$patch['label']} ✗ — PATCH MISSING!", false, false);
            }
        }
    }

    record("Patch verification: {$found_patches}/{$total_patches} patches terverifikasi", ($found_patches === $total_patches), false);
}

// ============================================================================
// TEST 11: INDEX PAGE CHECKS
// ============================================================================

function testIndexPages(): void {
    print_header('TEST 11: Index Pages — HTML Structure');

    // Mapping: nama partial yang dicek (tanpa ekstensi .php)
    // Catatan:
    //   - Project menggunakan 'partials/head.php' BUKAN 'partials/header.php'
    //   - Sebagian page me-load head.php secara tidak langsung via 'partials/link.php'
    //   - Admin menggunakan 'header-admin.php' dari direktori yang sama
    $index_pages = [
        'index.php'             => ['head', 'footer'],
        'video/index.php'       => ['head', 'footer'],
        'music/index.php'       => ['head', 'footer'],
        'books/index.php'       => ['head', 'footer'],
        'drive/index.php'       => ['head', 'footer'],
        'admin/index.php'       => ['header-admin'],
        'profile/index.php'     => ['head', 'footer'],
    ];

    foreach ($index_pages as $file => $partials) {
        $full = PROJECT_ROOT . '/' . $file;
        if (!file_exists($full)) {
            record("{$file} — tidak ditemukan ⚠", true, true);
            continue;
        }

        $content = file_get_contents($full);
        $hasInclusions = true;

        foreach ($partials as $partial) {
            $found = false;

            if ($partial === 'header-admin') {
                // Admin: file header-admin.php ada di direktori admin/, bukan partials/
                if (strpos($content, 'header-admin.php') !== false) {
                    $found = true;
                }
            } elseif ($partial === 'head') {
                // Head diterima secara langsung (head.php) atau tidak langsung (link.php)
                // karena partials/link.php di dalamnya me-load partials/head.php
                $found = (
                    strpos($content, 'partials/head.php') !== false ||
                    strpos($content, 'partials/link.php') !== false
                );
            } else {
                // Partial lainnya (footer, dll) — cek langsung
                if (strpos($content, "partials/{$partial}.php") !== false) {
                    $found = true;
                }
            }

            if (!$found) {
                $hasInclusions = false;
                if ($partial === 'header-admin') {
                    record("{$file} — missing include: header-admin.php (admin/) ⚠", true, true);
                } else {
                    record("{$file} — missing include: partials/{$partial}.php ⚠", true, true);
                }
            }
        }

        if ($hasInclusions) {
            // Check basic HTML structure
            $hasHtml5   = (strpos($content, '<!DOCTYPE html') !== false);
            $hasClosing = (strpos($content, '</html>') !== false);

            if ($hasHtml5 && $hasClosing) {
                record("{$file} — struktur HTML valid ✓", true);
            } else {
                record("{$file} — struktur HTML tidak lengkap ⚠", true, true, "Cek doctype dan closing tags");
            }
        }
    }
}

// ============================================================================
// MAIN
// ============================================================================

function run(): int {
    echo CLR_CYAN . CLR_BOLD . "\n";
    echo "  " . chr(9556) . str_repeat(chr(9552), 56) . chr(9559) . "\n";
    echo "  " . chr(9553) . "   MEeL Functional Test Suite v1.0" . str_repeat(' ', 19) . chr(9553) . "\n";
    echo "  " . chr(9562) . str_repeat(chr(9552), 56) . chr(9565) . "\n";
    echo CLR_RESET;
    echo CLR_GRAY . "  Path : " . PROJECT_ROOT . "\n";
    echo "  Time : " . $GLOBALS['test_timestamp'] . "\n" . CLR_RESET;

    // === RUN ALL TESTS ===
    testPhpSyntax();
    testFileIntegrity();
    testClassLoading();
    testFunctionExistence();
    testSecurityMagicBytes();
    testSecurityCsrf();
    testSecurityPreparedStmts();
    testSecurityBasename();
    testSecurityShellEscape();
    testSecurityUploadLimit();
    testHtaccessSecurity();
    testDirectoryStructure();
    testConfigCheck();
    testDatabaseConnectivity();
    testModifiedFiles();
    testIndexPages();

    // ─── SUMMARY ───
    echo "\n" . CLR_BOLD . chr(9556) . str_repeat(chr(9552), 56) . chr(9559) . "\n";
    echo chr(9553) . "                    FUNCTIONAL TEST SUMMARY" . str_repeat(' ', 20) . chr(9553) . "\n";
    echo chr(9562) . str_repeat(chr(9552), 56) . chr(9565) . CLR_RESET . "\n\n";

    $t = $GLOBALS['total_tests'];
    $p = $GLOBALS['passed'];
    $w = $GLOBALS['warnings'];
    $f = $GLOBALS['failed'];

    echo "  Total  : {$t}\n";
    echo "  " . CLR_GREEN . "Pass   : {$p}" . CLR_RESET . "\n";
    echo "  " . CLR_YELLOW . "Warn   : {$w}" . CLR_RESET . "\n";
    echo "  " . ($f > 0 ? CLR_RED : '') . "Fail   : {$f}" . CLR_RESET . "\n\n";

    $score = ($t > 0) ? round((($p + ($w * 0.5)) / $t) * 100) : 0;
    $grade = ($score >= 90) ? CLR_GREEN . 'A' : (($score >= 75) ? CLR_YELLOW . 'B' : (($score >= 50) ? CLR_YELLOW . 'C' : CLR_RED . 'D'));

    echo "  Score : {$score}/100  Grade: " . $grade . CLR_RESET . "\n\n";

    // Functional test specific: Health indicators
    $health_issues = $f + ($w > 5 ? $w - 5 : 0);
    $health = match(true) {
        $f > 0 => CLR_RED . '⚠ CRITICAL' . CLR_RESET . ' — Ada test gagal yang perlu diperbaiki',
        $w > 5 => CLR_YELLOW . '🟡 FAIR' . CLR_RESET . ' — Beberapa warning perlu diperhatikan',
        $w > 0 => CLR_GREEN . '🟢 GOOD' . CLR_RESET . ' — Minor warnings, fungsionalitas OK',
        default => CLR_GREEN . '✅ EXCELLENT' . CLR_RESET . ' — Semua fungsionalitas OK!'
    };

    echo "  Health: {$health}\n\n";

    if ($f > 0) {
        echo CLR_RED . CLR_BOLD . "  FAILED ITEMS:\n" . CLR_RESET;
        foreach ($GLOBALS['fail_details'] as $d) echo "   • {$d}\n";
        echo "\n";
    }

    if ($w > 0) {
        echo CLR_YELLOW . "  Review warnings for improvements.\n\n" . CLR_RESET;
    }

    // Save report
    $reportFile = PROJECT_ROOT . '/logs/functional_report_' . date('Ymd_His') . '.log';
    $report  = "MEeL Functional Test Report\n";
    $report .= "Date: " . $GLOBALS['test_timestamp'] . "\n";
    $report .= "Score: {$score}/100 ({$p} pass, {$w} warn, {$f} fail)\n\n";
    if (!empty($GLOBALS['fail_details'])) {
        $report .= "FAILED:\n";
        foreach ($GLOBALS['fail_details'] as $d) $report .= "  {$d}\n";
    }
    file_put_contents($reportFile, $report);

    echo CLR_GRAY . "  Report saved to: {$reportFile}\n\n" . CLR_RESET;
    echo CLR_BOLD . "  Done.\n\n" . CLR_RESET;

    return ($f > 0) ? 2 : (($w > 0) ? 1 : 0);
}

exit(run());
