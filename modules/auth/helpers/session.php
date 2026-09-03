<?php
if (!function_exists('meel_boot_session')) {
    function meel_boot_session(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $timeout = 43200; 
            ini_set('session.gc_maxlifetime', $timeout);
            $secure_cookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
            session_set_cookie_params([
                'lifetime' => $timeout,
                'path'     => '/',
                'secure'   => $secure_cookie,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_name('meel');
            session_start();
        }
    }
}
