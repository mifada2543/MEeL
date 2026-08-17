<?php
/**
 * MEeL Functional Test Suite — Struktur & Lingkungan.
 *
 * Verifikasi keamanan statis (CSRF, SQL injection, shell escape, .htaccess,
 * SSRF, hardening Uploader/Transcoder, patch keamanan, dll.) telah
 * dikonsolidasikan ke tests/security_test.php — suite ini fokus pada syntax,
 * struktur, dan kesehatan lingkungan runtime.
 */
define('PROJECT_ROOT', realpath(__DIR__ . '/..'));
define('EXCLUDE_DIRS', ['vendor', 'node_modules', '.git', 'assets/dict', 'data_drive']);
define('EXCLUDE_FILES', ['config.example.php', 'settings.example.php', 'test.php', '.gitkeep']);

require_once __DIR__ . '/helpers.php';

// Globals
$GLOBALS['total_tests']  = 0;
$GLOBALS['passed']       = 0;
$GLOBALS['warnings']     = 0;
$GLOBALS['failed']       = 0;
$GLOBALS['fail_details'] = [];
$GLOBALS['test_timestamp'] = date('Y-m-d H:i:s');

// TEST 1: PHP SYNTAX — Semua file PHP
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

// TEST 2: CLASS LOADING — Semua class bisa di-load
function testClassLoading(): void {
    print_header('TEST 2: Class Loading — Instantiation Check');

    $classes = [
        'Uploader'           => 'modules/core/Uploader.php',
        'Transcoder'         => 'modules/core/Transcoder.php',
        'MediaViewer'        => 'modules/media/MediaViewer.php',
        'MediaLibrary'       => 'modules/media/MediaLibrary.php',
        'MediaInteraction'   => 'modules/media/MediaInteraction.php',
        'System'             => 'modules/core/System.php',
        'GarbageCollector'   => 'modules/core/GarbageCollector.php',
        'SsrfGuard'          => 'modules/auth/SsrfGuard.php',
        'ValidatingProxy'    => 'modules/auth/ValidatingProxy.php',
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

// TEST 3: FUNCTION EXISTENCE — Semua fungsi kunci ada
function testFunctionExistence(): void {
    print_header('TEST 3: Function Existence — Helper Functions');

    $functions = [

        'time_ago'              => 'modules/core/helpers/url.php',
        'format_bytes'          => 'modules/core/helpers/url.php',
        'music_thumbnail_url'   => 'modules/core/helpers/storage.php',
        'get_user_usage'        => 'modules/auth/helpers/user.php',
        'get_csrf_token'        => 'modules/auth/helpers/csrf.php',
        'verify_csrf_token'     => 'modules/auth/helpers/csrf.php',
        'log_drive_operation'   => 'modules/core/helpers/storage.php',
        'generate_search_metadata' => 'modules/core/helpers/metadata.php',
        // japanese.php
        'getRomajiName'         => 'modules/core/japanese.php',
        'analyzeJapaneseText'   => 'modules/core/japanese.php',

        'log_activity'          => 'modules/core/activity_logger.php',

        'verify_csrf_token'     => 'modules/auth/helpers/csrf.php',
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

// TEST 4: DIRECTORY STRUCTURE
function testDirectoryStructure(): void {
    print_header('TEST 4: Directory Structure & Permissions');

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

// TEST 5: CONFIG CHECK
function testConfigCheck(): void {
    print_header('TEST 5: Config Check — auth/config.php');

    $configFile = PROJECT_ROOT . '/auth/config.php';

    if (!file_exists($configFile)) {
        record("auth/config.php — FILE TIDAK DITEMUKAN!", false, true, "Copy dari config.example.php dan isi database credentials");
        return;
    }

    $content = file_get_contents($configFile);

    $checks = [
        'Session name (meel)'            => '/session_name.*meel/',
        'Session GC maxlifetime'         => '/session\.gc_maxlifetime/',
        'Session cookie params'          => '/session_set_cookie_params/',
        'CSRF token generation'          => '/random_bytes.*32/',
        'verify_csrf_token function'     => '/function verify_csrf_token/',
        'Last activity timeout'          => '/LAST_ACTIVITY/',
        'MySQLi connection'              => '/new mysqli\(/',
        'Activity logger include'        => '/activity_logger/',
    ];

    foreach ($checks as $name => $pattern) {
        if (preg_match($pattern, $content)) {
            record("{$name} ✓", true);
        } else {
            record("{$name} — tidak ditemukan ⚠", true, true, "Lihat config.example.php untuk referensi");
        }
    }

    // Mendukung dua pola:
    // - Pola variabel: $server = "localhost" (config.example.php)
    // - Pola langsung: new mysqli("localhost", ...) (config.php)
    $hasServerVar = preg_match('/\$server\s*=\s*"[^"]*"/', $content);
    $hasDirectConn = preg_match('/new\s+mysqli\(\s*"[^"]+"/', $content);

    $settingsFile = PROJECT_ROOT . '/auth/settings.php';
    if (file_exists($settingsFile)) {
        $settingsContent = file_get_contents($settingsFile);
        $hasServerVar = $hasServerVar || preg_match('/\$server\s*=\s*"[^"]*"/', $settingsContent);
    }

    if ($hasServerVar || $hasDirectConn) {
        record("Database server terkonfigurasi ✓", true);
    } else {
        record("Database server belum dikonfigurasi ⚠", true, true, "Isi \$server, \$username, \$password, \$db di auth/settings.php");
    }
}

// TEST 6: DATABASE CONNECTIVITY
function testDatabaseConnectivity(): void {
    print_header('TEST 6: Database Connectivity Check');

    $configFile = PROJECT_ROOT . '/auth/config.php';

    if (!file_exists($configFile)) {
        record("Skipped: auth/config.php tidak ditemukan", true, true);
        return;
    }

    // Coba include config.php — CATATAN: ini akan memulai session!
    // Kita lakukan dengan try-catch di environment terisolasi
    try {
        // Baca file dan cek variabel saja
        $content = file_get_contents($configFile);

        // Cek koneksi database via file parsing
        $hasConfig = preg_match('/\$conn\s*=\s*new\s+mysqli\(/', $content);

        if ($hasConfig) {
            record("Koneksi database terdefinisi di config.php ✓", true);
        } else {
            record("Koneksi database tidak terdefinisi ⚠", true, true);
        }
    } catch (Exception $e) {
        record("Gagal membaca config.php: " . $e->getMessage(), true, true);
    }
}

// TEST 7: INDEX PAGE CHECKS
function testIndexPages(): void {
    print_header('TEST 7: Index Pages — HTML Structure');

    // Mapping: nama partial yang dicek (tanpa ekstensi .php)
    // Catatan:
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

                if (strpos($content, 'header-admin.php') !== false) {
                    $found = true;
                }
            } elseif ($partial === 'head') {

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

// TEST 8: ERROR PAGES — Path Consistency & Depth-Independence
function testErrorPages(): void {
    print_header('TEST 8: Error Pages — Path Consistency');

    $err_pages = [
        'err/index.php',
    ];

    foreach ($err_pages as $file) {
        $full = PROJECT_ROOT . '/' . $file;
        if (!file_exists($full)) {
            record("{$file} — tidak ditemukan", false, false, 'File error page hilang');
            continue;
        }

        $code   = stripPhpComments(file_get_contents($full));
        $issues = [];

        if (strpos($code, '/MEeL/') !== false) {
            $issues[] = 'path hardcoded /MEeL/ ditemukan'; // harus pakai base dinamis
        }
        if (strpos($code, 'meel_base_url_path()') === false) {
            $issues[] = 'meel_base_url_path() tidak dipakai'; // helper base URL terpusat
        }

        if (preg_match("/include\s*['\"]\.\.\/partials\//", $code)) {
            $issues[] = 'include partials CWD-relative (../partials/) ditemukan';
        }
        if (preg_match('/\.\.\/partials\//', $code) && strpos($code, "__DIR__ . '/../partials/") === false) {
            $issues[] = 'include partials tidak __DIR__-based';
        }

        if (empty($issues)) {
            record("{$file} — path konsisten & depth-independent ✓", true);
        } else {
            foreach ($issues as $issue) {
                record("{$file} — {$issue}", false, false, 'Pakai meel_base_url_path() + include __DIR__-based');
            }
        }
    }
}

// MAIN
function run(): int {
    echo CLR_CYAN . CLR_BOLD . "\n";
    echo "  " . chr(9556) . str_repeat(chr(9552), 56) . chr(9559) . "\n";
    echo "  " . chr(9553) . "   MEeL Functional Test Suite v2.0" . str_repeat(' ', 18) . chr(9553) . "\n";
    echo "  " . chr(9562) . str_repeat(chr(9552), 56) . chr(9565) . "\n";
    echo CLR_RESET;
    echo CLR_GRAY . "  Path : " . PROJECT_ROOT . "\n";
    echo "  Time : " . $GLOBALS['test_timestamp'] . "\n" . CLR_RESET;

    // ─── RUN ALL TESTS ───
    testPhpSyntax();
    testClassLoading();
    testFunctionExistence();
    testDirectoryStructure();
    testConfigCheck();
    testDatabaseConnectivity();
    testIndexPages();
    testErrorPages();

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
