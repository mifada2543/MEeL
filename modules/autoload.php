<?php
/**
 * MEeL-HUB — Manual Autoloader
 * 
 * PSR-4-like autoloader tanpa Composer.
 * Gunakan: require_once __DIR__ . '/autoload.php';
 * 
 * Cara pakai:
 *   // Di auth/config.php atau entry point manapun:
 *   require_once __DIR__ . '/../modules/autoload.php';
 * 
 *   // Class akan otomatis di-load:
 *   $library = new MediaLibrary($conn);
 *   $uploader = new Uploader($conn, $user_id, $username);
 *   $viewer = new MediaViewer($conn, $user_id, 'video', $id);
 */

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
        
        // Drive service
        'DriveService'       => __DIR__ . '/../drive/DriveService.php',
    ];

    if (isset($map[$class])) {
        require_once $map[$class];
    }
});
