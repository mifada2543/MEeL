<?php
require_once '../../modules/core/helpers.php';
meel_boot_session();

include '../../auth/config.php';
include '../../auth/auth.php';

header('Content-Type: application/json');

require_admin($conn);

session_write_close();

require_once '../../modules/core/System.php';
$sys           = new System($conn);
$server_stats  = $sys->getServerStats();

echo json_encode([
    'status'       => 'success',
    'timestamp'    => time(),
    'server_stats' => $server_stats,
]);
