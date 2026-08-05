<?php
/**
 * MEeL Drive — AJAX Refresh Endpoint
 * Invalidates dir_size cache and returns fresh storage usage data as JSON.
 *
 * GET /controllers/api/ajax_refresh.php
 * Response: { status, storage_usage, storage_percentage, formatted_usage }
 */
require '../../auth/auth.php';
require '../../auth/config.php';
require '../../modules/core/helpers.php';

header('Content-Type: application/json');

$user = DriveUserContext::fromSession($_SESSION);
$user->authorize();

$storageUsage = 0;
$storagePct   = 0;
$formatted    = '0 B / 20 GB';

if ($user->isMember()) {
    // Hapus cache dir_size agar data benar-benar fresh
    invalidate_dir_size_cache($user->username);

    $storageUsage = get_user_usage($user->username);
    $limit        = 20 * 1024 * 1024 * 1024; // 20 GB
    $storagePct   = min(100, round(($storageUsage / $limit) * 100, 1));
    $formatted    = format_bytes($storageUsage) . ' / 20 GB';
}

echo json_encode([
    'status'              => 'success',
    'storage_usage'       => $storageUsage,
    'storage_percentage'  => $storagePct,
    'formatted_usage'     => $formatted,
]);
