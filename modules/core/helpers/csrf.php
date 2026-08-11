<?php
// helpers/csrf.php — CSRF Token Helpers
// Bagian dari pecahan modules/core/helpers.php.
// Dimuat oleh helpers/main.php.
// Semua fungsi dibungkus function_exists() guard sebagai
// defense-in-depth terhadap double-include.
/* Get CSRF token dari session (sudah diinisialisasi di config.php) */
if (!function_exists('get_csrf_token')) {
function get_csrf_token(): string
{
    return $_SESSION['csrf_token'] ?? '';
}
} // end function_exists('get_csrf_token')

/* @param string|null $token Token CSRF (opsional). Jika null, ambil dari $_POST['csrf_token']; @return bool True jika token valid */
if (!function_exists('verify_csrf_token')) {
function verify_csrf_token(?string $token = null): bool
{
    if ($token === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
    }
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}
} // end function_exists('verify_csrf_token')
