<?php
// modules/auth/loader.php — Muat seluruh infrastruktur keamanan.
//
// Satu-satunya titik masuk untuk logika keamanan aplikasi, agar audit
// keamanan cukup memeriksa direktori modules/auth/:
//   helpers/authz.php        — guard admin & otorisasi halaman
//   helpers/csrf.php         — token CSRF
//   helpers/session.php      — inisialisasi session aman (cookie flags)
//   helpers/stream_auth.php  — otorisasi akses streaming audio
//   helpers/mfa.php          — helper MFA (TOTP)
//   helpers/user.php         — role & usage user
//   RateLimiter.php          — pembatasan request per endpoint/user
//   SsrfGuard.php            — validasi URL SSRF-safe (yt-dlp pipeline)
//   ValidatingProxy.php      — manajer proses forward proxy (SSRF defense)
//   validating_proxy_server.php — CLI forward proxy (SsrfGuard per hop);
//                                 TIDAK di-require — di-spawn sebagai subproses
//                                 oleh ValidatingProxy.php (script CLI ber-akcept-loop)
require_once __DIR__ . '/helpers/authz.php';
require_once __DIR__ . '/helpers/csrf.php';
require_once __DIR__ . '/helpers/session.php';
require_once __DIR__ . '/helpers/stream_auth.php';
require_once __DIR__ . '/helpers/mfa.php';
require_once __DIR__ . '/helpers/user.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/SsrfGuard.php';
require_once __DIR__ . '/ValidatingProxy.php';
