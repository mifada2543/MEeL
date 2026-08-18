<?php
// controllers/api/server_stats_sse.php — Server-Sent Events untuk Server Stats.
//
// Koneksi SSE (GET, admin only): server mendorong snapshot getServerStats()
// setiap interval. Interval dari query ?interval= (1000|3000|5000|10000 ms,
// default 3000) — diatur lewat dropdown polling di dashboard admin.
//
// Client: assets/js/admin/index.js (EventSource). Bila stream macet/gagal
// (mis. proxy buffering), client otomatis fallback ke polling biasa
// (api/server-stats) sehingga dashboard tetap berfungsi.
require_once '../../modules/core/helpers.php';
meel_boot_session();

include '../../auth/config.php';
include '../../auth/auth.php';

// Khusus admin — non-admin / session habis ditolak di sini (sebelum header SSE).
require_admin($conn);

// Lepas kunci session: koneksi ini bertahan lama dan tidak boleh memblokir
// request lain dari user yang sama.
session_write_close();

// Interval dari query string, dibatasi ke nilai yang diizinkan.
$allowed   = [1000, 3000, 5000, 10000];
$interval  = (int) ($_GET['interval'] ?? 3000);
if (!in_array($interval, $allowed, true)) {
    $interval = 3000;
}

// ── Header SSE ──
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // nginx; tidak berbahaya di Apache
header('Connection: keep-alive');

// Matikan buffering/kompresi yang bisa menahan pesan agar tiap flush() sampai.
@ini_set('zlib.output_compression', 'Off');
@ini_set('output_buffering', 'Off');
@ob_implicit_flush(true);
while (ob_get_level() > 0) {
    @ob_end_flush();
}

// Koneksi panjang — jangan biarkan PHP timeout (CLI: tidak berpengaruh).
set_time_limit(0);

require_once '../../modules/core/System.php';
$sys = new System($conn);

// Ping pembuka — beberapa proxy menunggu data pertama sebelum meneruskan stream.
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

    // Tunggu interval dalam langkah 1 detik; keluar segera jika client putus.
    for ($i = 0; $i < (int) ceil($interval / 1000); $i++) {
        if (connection_aborted()) {
            break 2;
        }
        sleep(1);
    }
}
