<?php
// controllers/api/server_stats.php — Endpoint realtime Server Stats admin.
//
// Dipanggil via AJAX polling (GET) oleh assets/js/admin/index.js setiap
// beberapa detik untuk memperbarui kartu CPU / RAM / Swap / Network pada
// dashboard admin tanpa reload halaman. Tidak ada efek samping — hanya
// membaca snapshot server via System::getServerStats().
require_once '../../modules/core/helpers.php';
meel_boot_session();

include '../../auth/config.php';
include '../../auth/auth.php';

header('Content-Type: application/json');

// Khusus admin — non-admin / session habis ditolak di sini.
require_admin($conn);

// Lepas kunci session agar polling tidak memblokir request lain user ini
// (getServerStats() menjalankan beberapa perintah shell).
session_write_close();

require_once '../../modules/core/System.php';
$sys           = new System($conn);
$server_stats  = $sys->getServerStats();

echo json_encode([
    'status'       => 'success',
    'timestamp'    => time(),
    'server_stats' => $server_stats,
]);
