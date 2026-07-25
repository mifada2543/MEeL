<?php
/**
 * PHPUnit bootstrap for MEeL.
 *
 * Loads autoloader and core files so tests can use classes/functions.
 */

// ── Project root ─────────────────────────────────────────────────────────────
define('MEEL_ROOT', dirname(__DIR__));

// ── Load core modules ─────────────────────────────────────────────────────────
require_once MEEL_ROOT . '/modules/autoload.php';

// Load helpers (guard functions wrapped in function_exists)
require_once MEEL_ROOT . '/modules/core/helpers.php';

// ── Load integration test helpers ─────────────────────────────────────────────
// DbTestHelper provides a real DB connection with transaction isolation
// for integration tests. Keep it out of the production autoloader.
require_once __DIR__ . '/DbTestHelper.php';

// ── Error reporting ───────────────────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ── Override $_SERVER defaults for CLI-safe helper functions ──────────────────
if (!isset($_SERVER['SCRIPT_NAME'])) {
    $_SERVER['SCRIPT_NAME'] = '/MEeL/index.php';
}
if (!isset($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = '/opt/lampp/htdocs';
}
if (!isset($_SERVER['REMOTE_ADDR'])) {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

// ── Create temp directories for file-based tests ──────────────────────────────
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
