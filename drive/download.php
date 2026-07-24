<?php
require '../auth/auth.php';
require '../auth/config.php';
require '../modules/core/helpers.php';
require __DIR__ . '/DriveService.php';

$user = DriveUserContext::fromSession($_SESSION);
$user->authorize();

// CSRF Token Validation untuk GET parameter
if (!isset($_GET['csrf_token']) || !verify_csrf_token($_GET['csrf_token'])) {
    http_response_code(403);
    echo htmlspecialchars('CSRF token tidak valid.', ENT_QUOTES, 'UTF-8');
    exit();
}

// Download adalah operasi baca — tidak perlu rate limit
// Proteksi sudah cukup via CSRF token + autentikasi session

$storage = new DriveStorage(dirname(__DIR__) . '/data_drive', $user);

try {
    $file = $storage->getFileForDownload(
        isset($_GET['file']) ? basename($_GET['file']) : null,
        isset($_GET['type']) ? basename($_GET['type']) : null,
        $_GET['scope'] ?? DriveStorage::SCOPE_PUBLIC
    );

    // Audit Logging
    log_drive_operation(
        $user->userId,
        $user->username,
        'download',
        $file['name'],
        $_GET['type'] ?? 'unknown',
        $_GET['scope'] ?? 'public',
        'success'
    );
    header('X-Content-Type-Options: nosniff');
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file['name']) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . (string) $file['size']);

    readfile($file['path']);
    exit();
} catch (RuntimeException $exception) {
    log_drive_operation(
        $user->userId,
        $user->username,
        'download',
        $_GET['file'] ?? 'unknown',
        $_GET['type'] ?? 'unknown',
        $_GET['scope'] ?? 'unknown',
        'failed: ' . $exception->getMessage()
    );

    http_response_code(404);
    echo htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
}
