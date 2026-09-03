<?php


if (!function_exists('is_admin')) {
function is_admin(mysqli $conn): bool
{
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    return get_user_role($conn, (int)$_SESSION['user_id']) === 'admin';
}
}


if (!function_exists('require_admin')) {
function require_admin(mysqli $conn): void
{
    if (!is_admin($conn)) {
        $_GET['code'] = 'denied';
        die(include __DIR__ . '/../../../err/index.php');
    }
}
}
