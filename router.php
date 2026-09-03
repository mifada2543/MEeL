<?php



if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "router.php hanya untuk request HTTP.\n");
    exit(1);
}

require_once __DIR__ . '/modules/core/Router.php';

MeelRouter::dispatch(MeelRouter::resolvePath());
