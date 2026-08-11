<?php
// helpers/session.php — Inisialisasi Session Terpusat (Secure Cookie)
//
// Semua entry point memanggil meel_boot_session() menggantikan pola lama:
//     session_name('meel');
//     session_start();
// supaya cookie sesi SELALU dikirim dengan HttpOnly + SameSite=Lax
// (+ Secure otomatis saat HTTPS), bukan parameter default PHP.
//
// Nilai parameter dibuat PERSIS sama dengan auth/config.php supaya
// konsisten ketika config.php di-include setelahnya — session yang
// sudah aktif membuat blok session config.php menjadi no-op.
if (!function_exists('meel_boot_session')) {
    function meel_boot_session(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $timeout = 43200; // 12 jam — sama dengan auth/config.php
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
