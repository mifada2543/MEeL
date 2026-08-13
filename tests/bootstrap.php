<?php
/**
 * PHPUnit bootstrap for MEeL.
 *
 * Loads autoloader and core files so tests can use classes/functions.
 */

// ─── Project root ───
define('MEEL_ROOT', dirname(__DIR__));

// ─── Load core modules ───
require_once MEEL_ROOT . '/modules/autoload.php';

// Load helpers (guard functions wrapped in function_exists)
require_once MEEL_ROOT . '/modules/core/helpers.php';

// ─── Load integration test helpers ───
// DbTestHelper provides a real DB connection with transaction isolation
// for integration tests. Keep it out of the production autoloader.
require_once __DIR__ . '/DbTestHelper.php';

// ─── Error reporting ───
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ─── Override $_SERVER defaults for CLI-safe helper functions ───
if (!isset($_SERVER['SCRIPT_NAME'])) {
    $_SERVER['SCRIPT_NAME'] = '/MEeL/index.php';
}
if (!isset($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = '/opt/lampp/htdocs';
}
if (!isset($_SERVER['REMOTE_ADDR'])) {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

// ─── Japanese tests: helper untuk cek ketersediaan mecab ───
// getMecabPath()/resolve_binary() mengembalikan kandidat pertama walau tidak
// executable, jadi kita verifikasi dengan menjalankan mecab sungguhan.
if (!function_exists('meel_mecab_available')) {
    function meel_mecab_available(): bool
    {
        static $available = null;
        if ($available !== null) {
            return $available;
        }
        $candidates = ['/usr/bin/mecab', '/usr/local/bin/mecab'];
        $bin = '';
        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                $bin = $candidate;
                break;
            }
        }
        if ($bin === '') {
            $resolved = trim((string)shell_exec('command -v mecab 2>/dev/null'));
            if ($resolved !== '') {
                $bin = $resolved;
            }
        }
        if ($bin === '') {
            return $available = false;
        }
        $out = [];
        $exit = 1;
        // Jalankan mecab dengan satu baris teks — sukses bila exit 0 dan ada
        // output berisi "EOS" (mecab dengan stdin kosong tidak mencetak apa pun)
        $proc = @proc_open(
            $bin . ' 2>/dev/null',
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($proc)) {
            return $available = false;
        }
        fwrite($pipes[0], "テスト\n");
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $available = ($exit === 0 && str_contains((string)$stdout, 'EOS'));
        return $available;
    }
}

// ─── Create temp directories for file-based tests ───
$tempDirs = [
    MEEL_ROOT . '/temp',
    MEEL_ROOT . '/temp/ratelimit',
    MEEL_ROOT . '/temp/cache',
];
foreach ($tempDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    // Ensure writable by current user
    @chmod($dir, 0777);
}
