<?php
/**
 * router.php — Front Controller MEeL-HUB
 *
 * Satu-satunya titik masuk untuk semua halaman web & API. Semua permintaan
 * yang bukan file/aset nyata di-rewrite ke file ini oleh .htaccess:
 *
 * /video/watch?id=5            → router.php (route: video/watch)
 * /music/watch?id=5            → router.php (route: music/watch)
 * /admin/edit-video?id=5       → router.php (route: admin/edit-video)
 * /api/like                    → router.php (route: api/like)
 *
 * Alur:
 * 1. Resolve path bersih dari REQUEST_URI (relatif terhadap base proyek).
 * 2. MeelRouter::dispatch() mencocokkan ke routing table dan meng-include
 * handler (file .php lama) — require relatif di dalam handler tetap
 * di-resolve PHP terhadap direktori file sumber, jadi handler TIDAK
 * perlu diubah.
 * 3. $_SERVER['SCRIPT_NAME']/PHP_SELF disimulasikan ke handler asli agar
 * deteksi halaman (partials/nav.php, activity_logger.php) tetap bekerja.
 *
 * Bootstrap (session, DB, headers) dilakukan oleh handler masing-masing —
 * persis seperti saat diakses langsung — karena seluruh guard (meel_boot_session,
 * !defined, !isset($conn)) sudah idempotent.
 *
 * @license GPL v3
 */

// Deteksi CLI (mis. saat testing) — tidak ada HTTP request.
if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "router.php hanya untuk request HTTP.\n");
    exit(1);
}

require_once __DIR__ . '/modules/core/Router.php';

MeelRouter::dispatch(MeelRouter::resolvePath());
