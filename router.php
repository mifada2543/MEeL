<?php
// Front Controller MEeL-HUB — semua request di-rewrite ke sini oleh .htaccess.
// Handler di-include oleh MeelRouter::dispatch(); bootstrap dilakukan handler masing-masing.

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "router.php hanya untuk request HTTP.\n");
    exit(1);
}

require_once __DIR__ . '/modules/core/Router.php';

MeelRouter::dispatch(MeelRouter::resolvePath());
