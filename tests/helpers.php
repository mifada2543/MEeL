<?php
/**
 * tests/helpers.php
 *
 * Shared helper functions untuk test suite (functional_test & security_test).
 * Diekstrak untuk menghilangkan duplikasi 5 fungsi identik di kedua file test.
 *
 * @package MEeL\Tests
 */

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', realpath(__DIR__ . '/..'));
}

// Color codes
if (!defined('CLR_GREEN'))  { define('CLR_GREEN',  "\033[32m"); }
if (!defined('CLR_RED'))    { define('CLR_RED',    "\033[31m"); }
if (!defined('CLR_YELLOW')) { define('CLR_YELLOW', "\033[33m"); }
if (!defined('CLR_CYAN'))   { define('CLR_CYAN',   "\033[36m"); }
if (!defined('CLR_BOLD'))   { define('CLR_BOLD',   "\033[1m"); }
if (!defined('CLR_RESET'))  { define('CLR_RESET',  "\033[0m"); }
if (!defined('CLR_GRAY'))   { define('CLR_GRAY',   "\033[90m"); }

if (!function_exists('p')) {
/**
 * Print a colored test message.
 */
function p(string $msg = '', string $color = ''): void {
    $prefix = match($color) {
        CLR_GREEN  => '  ✓ ',
        CLR_RED    => '  ✗ ',
        CLR_YELLOW => '  ⚠ ',
        default    => '    '
    };
    echo $color . $prefix . $msg . CLR_RESET . "\n";
}
}

if (!function_exists('print_header')) {
/**
 * Print a section header with box drawing.
 */
function print_header(string $title): void {
    echo "\n" . CLR_CYAN . CLR_BOLD . "╔══ " . str_repeat('═', 60) . "╗\n";
    echo "║   " . str_pad($title, 56) . "║\n";
    echo "╚══ " . str_repeat('═', 60) . "╝" . CLR_RESET . "\n\n";
}
}

if (!function_exists('record')) {
/**
 * Record a test result and increment counters.
 */
function record(string $name, bool $pass, bool $isWarning = false, string $detail = ''): void {
    $GLOBALS['total_tests']++;
    if ($pass && !$isWarning) {
        $GLOBALS['passed']++;
        p($name, CLR_GREEN);
    } elseif ($isWarning) {
        $GLOBALS['warnings']++;
        p($name . ($detail ? " — {$detail}" : ''), CLR_YELLOW);
    } else {
        $GLOBALS['failed']++;
        $GLOBALS['fail_details'][] = "FAIL {$name}" . ($detail ? " — {$detail}" : '');
        p($name . ($detail ? " — {$detail}" : ''), CLR_RED);
    }
}
}

if (!function_exists('getPhpFiles')) {
/**
 * Get all PHP files in project excluding defined directories.
 */
function getPhpFiles(): array {
    static $exclude_dirs = null;
    if ($exclude_dirs === null) {
        $exclude_dirs = defined('EXCLUDE_DIRS') ? EXCLUDE_DIRS : ['vendor', 'node_modules', '.git', 'assets/dict', 'data_drive'];
    }
    static $exclude_files = null;
    if ($exclude_files === null) {
        $exclude_files = defined('EXCLUDE_FILES') ? EXCLUDE_FILES : ['config.example.php', 'test.php', '.gitkeep'];
    }

    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(PROJECT_ROOT, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        $path = $file->getPathname();
        foreach ($exclude_dirs as $ex) {
            if (strpos($path, '/' . $ex . '/') !== false || strpos($path, '\\' . $ex . '\\') !== false)
                continue 2;
        }
        if (in_array($file->getBasename(), $exclude_files)) continue;
        if ($file->getExtension() === 'php') $files[] = $path;
    }
    sort($files);
    return $files;
}
}

if (!function_exists('stripPhpComments')) {
/**
 * Strip PHP comments (//, #, /* * /) from code.
 * Digunakan untuk mencegah false positive saat mencari pola kode
 * di file yang memiliki komentar mengandung keyword yang sama.
 *
 * @param string $code Konten PHP source
 * @return string Konten tanpa komentar
 */
function stripPhpComments(string $code): string {
    // Hapus multi-line comment /* ... */
    $code = preg_replace('/\/\*.*?\*\//s', '', $code);
    // Hapus single-line comment // ... dan # ...
    $code = preg_replace('/\/\/.*$/m', '', $code);
    $code = preg_replace('/(?:^|\s)#.*$/m', '', $code);
    return $code;
}
}

if (!function_exists('countInFile')) {
/**
 * Count occurrences of a pattern in file content (excluding comments).
 * Komentar PHP (//, #, /* * /) di-strip terlebih dahulu agar
 * false positive dari keyword di komentar/docblock tidak terhitung.
 *
 * @param string $path    Path ke file
 * @param string $pattern Regex pattern untuk dicari
 * @return int Jumlah occurrence
 */
function countInFile(string $path, string $pattern): int {
    $content = file_get_contents($path);
    $content = stripPhpComments($content);
    return preg_match_all($pattern, $content);
}
}
