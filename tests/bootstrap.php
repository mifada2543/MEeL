<?php
define('MEEL_ROOT', dirname(__DIR__));
require_once MEEL_ROOT . '/modules/autoload.php';
require_once MEEL_ROOT . '/modules/core/helpers.php';
require_once __DIR__ . '/DbTestHelper.php';
error_reporting(E_ALL);
ini_set('display_errors', '1');


if (!isset($_SERVER['SCRIPT_NAME'])) {
    $_SERVER['SCRIPT_NAME'] = '/MEeL/index.php';
}
if (!isset($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = '/opt/lampp/htdocs';
}
if (!isset($_SERVER['REMOTE_ADDR'])) {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}




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


$tempDirs = [
    MEEL_ROOT . '/temp',
    MEEL_ROOT . '/temp/ratelimit',
    MEEL_ROOT . '/temp/cache',
];
foreach ($tempDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    
    @chmod($dir, 0777);
}
