<?php
// Keamanan: hanya dari CLI
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Access denied. Jalankan dari terminal: php database/backfill_english_metadata.php');
}

// Parse argumen CLI
$dryRun = false;
$limit  = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int)$m[1];
    }
}

// Bootstrap (pola sama dengan database/migrate.php)
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../modules/core/helpers.php';
require_once __DIR__ . '/../modules/core/japanese.php';

if (!isset($conn) || !$conn instanceof \mysqli || $conn->connect_error) {
    echo "[MEeL] ❌ Gagal terhubung ke database. Periksa auth/config.php.\n";
    exit(1);
}

echo "[MEeL] " . ($dryRun ? 'DRY-RUN (tidak menulis DB)' : 'BACKFILL') . " dimulai...\n";

// Proses satu tabel dalam batch kecil
function backfill_process_table(\mysqli $conn, string $table, string $columns, int $batchSize, ?int $limit, bool $dryRun, array &$stats): void
{
    $offset    = 0;
    $processed = 0;
    $updated   = 0;

    while (true) {

        $fetchLimit = $batchSize;
        if ($limit !== null) {
            $remaining  = $limit - $processed;
            if ($remaining <= 0) break;
            $fetchLimit = min($batchSize, $remaining);
        }

        $sql = "SELECT id, {$columns} FROM {$table} ORDER BY id ASC LIMIT {$fetchLimit} OFFSET {$offset}";
        $res = $conn->query($sql);
        if (!$res || $res->num_rows === 0) break;

        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;

        foreach ($rows as $row) {
            $id    = (int)$row['id'];
            $title = trim((string)($row['title'] ?? ''));

            // konsisten dengan Uploader dan admin/edit-*.php
            if ($table === 'video') {
                $meta = generate_search_metadata($title);
            } else {
                $artist = trim((string)($row['artist'] ?? ''));
                $album  = trim((string)($row['album'] ?? ''));
                $meta   = generate_search_metadata($title, $artist, $album);
            }

            $oldMeta = $row['search_metadata'] ?? null;
            if ($meta !== $oldMeta) {
                if (!$dryRun) {
                    $stmt = $conn->prepare("UPDATE {$table} SET search_metadata = ? WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param('si', $meta, $id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
                $updated++;
            }

            $processed++;
            $stats['processed']++;

            if (($processed % 10) === 0) {
                echo "[MEeL] {$table}: {$processed} baris diproses...\n";
            }

            usleep(100000);
        }

        $offset += $fetchLimit;
    }

    echo "[MEeL] ✓ {$table} selesai: {$processed} diproses, {$updated} diupdate"
        . ($dryRun ? ' (DRY-RUN)' : '') . "\n";
}

$batchSize = 50;
$stats     = ['processed' => 0];

backfill_process_table($conn, 'video', 'title, search_metadata', $batchSize, $limit, $dryRun, $stats);
backfill_process_table($conn, 'music', 'title, artist, album, search_metadata', $batchSize, $limit, $dryRun, $stats);

echo "[MEeL] Selesai. Total baris diproses: {$stats['processed']}\n";
$conn->close();
