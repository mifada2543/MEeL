<?php
spl_autoload_register(function (string $class) {
    $map = [
        // Core modules
        'System'             => __DIR__ . '/core/System.php',
        'Uploader'           => __DIR__ . '/core/Uploader.php',
        'Transcoder'         => __DIR__ . '/core/Transcoder.php',
        'MediaLibrary'       => __DIR__ . '/media/MediaLibrary.php',
        'BookRepository'     => __DIR__ . '/media/MediaLibrary.php',
        'BookUploader'       => __DIR__ . '/media/MediaLibrary.php',
        'MediaViewer'        => __DIR__ . '/media/MediaViewer.php',
        'MediaInteraction'   => __DIR__ . '/media/MediaInteraction.php',
        'GarbageCollector'   => __DIR__ . '/core/GarbageCollector.php',
        'RateLimiter'         => __DIR__ . '/core/RateLimiter.php',

        // Media
        'SearchEngine'        => __DIR__ . '/media/SearchEngine.php',

        // Drive service
        'DriveService'       => __DIR__ . '/../drive/DriveService.php',

        // PWA service worker
        'SwPrecache'         => __DIR__ . '/core/SwPrecache.php',

    ];

    if (isset($map[$class])) {
        require_once $map[$class];
    }
});
