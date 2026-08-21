<?php
// SSE endpoint: push getServerStats() setiap interval. Client fallback ke polling jika stream gagal.
require_once '../../modules/core/helpers.php';
meel_boot_session();

include '../../auth/config.php';
include '../../auth/auth.php';

require_admin($conn);

// Lepas kunci session — koneksi ini bertahan lama.
session_write_close();

$allowed   = [1000, 3000, 5000, 10000];
$interval  = (int) ($_GET['interval'] ?? 3000);
if (!in_array($interval, $allowed, true)) {
    $interval = 3000;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

@ini_set('zlib.output_compression', 'Off');
@ini_set('output_buffering', 'Off');
@ob_implicit_flush(true);
while (ob_get_level() > 0) {
    @ob_end_flush();
}

set_time_limit(0);

require_once '../../modules/core/System.php';
$sys = new System($conn);

echo ": connected\n\n";
flush();

$seq = 0;
while (!connection_aborted()) {
    $seq++;

    $stats = $sys->getServerStats();
    echo 'id: ' . $seq . "\n";
    echo 'data: ' . json_encode([
        'status'       => 'success',
        'timestamp'    => time(),
        'interval'     => $interval,
        'server_stats' => $stats,
    ], JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();

    for ($i = 0; $i < (int) ceil($interval / 1000); $i++) {
        if (connection_aborted()) {
            break 2;
        }
        sleep(1);
    }
}
