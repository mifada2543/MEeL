<?php
include '../auth/config.php';
include '../auth/auth.php';

// Guard terpusat: harus login + role admin
require_admin($conn);

// Action handler
$message = null;
$message_type = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $message = 'CSRF Token tidak valid!';
        $message_type = 'error';
    } else {
        $action = $_POST['action'];
        if ($action === 'delete_room' && !empty($_POST['room_code'])) {
            $code = $_POST['room_code'];
            $conn->begin_transaction();
            try {
                $d1 = $conn->prepare("DELETE FROM moves WHERE room_code = ?");
                $d1->bind_param("s", $code);
                $d1->execute();
                $moved = $d1->affected_rows;

                $d2 = $conn->prepare("DELETE FROM rooms WHERE room_code = ?");
                $d2->bind_param("s", $code);
                $d2->execute();

                $conn->commit();
                $message = "Room <strong>$code</strong> berhasil dihapus ($moved moves dihapus).";
            } catch (RuntimeException $e) {
                $conn->rollback();
                $message = "Gagal menghapus room: " . $e->getMessage();
                $message_type = 'error';
            }
        }
        elseif ($action === 'purge_inactive') {
            $result = purgeInactiveRooms($conn);
            $message = "Purge selesai: <strong>{$result['rooms']}</strong> room dan <strong>{$result['moves']}</strong> moves dihapus.";
        }
    }
}
if (isset($_GET['auto_cleanup'])) {
    header('Content-Type: application/json');
    if (!verify_csrf_token($_GET['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF token tidak valid.']);
        exit;
    }
    $result = purgeInactiveRooms($conn);
    logCleanup($conn, $result);
    echo json_encode(['success' => true, 'rooms' => $result['rooms'], 'moves' => $result['moves'], 'time' => date('H:i:s')]);
    exit;
}

// Helpers
function purgeInactiveRooms(mysqli $conn): array
{
    $sql = "
        SELECT r.room_code
        FROM rooms r
        LEFT JOIN (
            SELECT room_code, MAX(created_at) AS last_move
            FROM moves
            GROUP BY room_code
        ) m ON r.room_code = m.room_code
        WHERE
            -- Room belum punya lawan & dibuat > 10 menit lalu
            (r.black_joined = 0 AND r.created_at < NOW() - INTERVAL 10 MINUTE)
            OR
            -- Room sudah penuh tapi tidak ada gerakan > 10 menit
            (r.black_joined = 1 AND (m.last_move IS NULL OR m.last_move < NOW() - INTERVAL 10 MINUTE))
    ";

    $res = $conn->query($sql);
    if (!$res || $res->num_rows === 0) return ['rooms' => 0, 'moves' => 0];
    $codes = [];
    while ($row = $res->fetch_assoc()) $codes[] = $row['room_code'];
    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes));
    $dm = $conn->prepare("DELETE FROM moves WHERE room_code IN ($placeholders)");
    $dm->bind_param($types, ...$codes);
    $dm->execute();
    $movesDeleted = $dm->affected_rows;
    $dr = $conn->prepare("DELETE FROM rooms WHERE room_code IN ($placeholders)");
    $dr->bind_param($types, ...$codes);
    $dr->execute();
    $roomsDeleted = $dr->affected_rows;
    return ['rooms' => $roomsDeleted, 'moves' => $movesDeleted];
}

function logCleanup(mysqli $conn, array $result): void
{
    $logLine = date('[Y-m-d H:i:s]') . " AUTO-CLEANUP chess: {$result['rooms']} rooms, {$result['moves']} moves deleted\n";
    @file_put_contents(__DIR__ . '/../logs/chess_cleanup.log', $logLine, FILE_APPEND | LOCK_EX);
}

// Fetch data untuk tampilan
$rooms_result = $conn->query("
    SELECT
        r.room_code,
        r.black_joined,
        r.created_at,
        COUNT(m.id) AS total_moves,
        MAX(m.created_at) AS last_activity
    FROM rooms r
    LEFT JOIN moves m ON r.room_code = m.room_code
    GROUP BY r.room_code, r.black_joined, r.created_at
    ORDER BY r.created_at DESC
");
$rooms = $rooms_result ? $rooms_result->fetch_all(MYSQLI_ASSOC) : [];

$stats = [
    'total_rooms'  => count($rooms),
    'active'       => count(array_filter($rooms, fn($r) => $r['black_joined'] == 1)),
    'waiting'      => count(array_filter($rooms, fn($r) => $r['black_joined'] == 0)),
    'total_moves'  => array_sum(array_column($rooms, 'total_moves')),
];

$log_file = __DIR__ . '/../logs/chess_cleanup.log';
$log_lines = [];
if (file_exists($log_file)) {
    $all = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $log_lines = array_slice(array_reverse($all), 0, 20);
}

$page_title  = 'Chess Room Manager';
$media_type  = 'analytics';
$back_url    = 'index.php';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL Admin - Chess Room Manager. Monitor dan kelola sesi permainan catur, hapus room tidak aktif.">
    <meta property="og:title" content="Chess Manager · MEeL Admin">
    <meta property="og:description" content="Panel admin MEeL untuk memonitor dan mengelola sesi permainan catur.">
    <title>Chess Manager · MEeL Admin</title>
    <link rel="stylesheet" href="../assets/css/font.css">
    <?php include '../partials/link.php'; ?>
    <?php $scripts_root = '../';
    include '../partials/scripts.php'; ?>
    <link rel="stylesheet" href="../assets/css/admin/catur.css?v=<?= filemtime('../assets/css/admin/catur.css') ?>">
</head>

<body class="min-h-screen">

    <?php require 'header-admin.php'; ?>
    <main class="max-w-7xl mx-auto px-6 py-8 space-y-6">

        <!-- Flash message -->
        <?php if ($message): ?>
            <div class="flex items-start gap-3 px-4 py-3 rounded-lg text-sm
        <?= $message_type === 'error' ? 'bg-red-500/10 border border-red-500/20 text-red-400' : 'bg-green-500/10 border border-green-500/20 text-green-400' ?>">
                <i data-lucide="<?= $message_type === 'error' ? 'x-circle' : 'check-circle' ?>" class="w-4 h-4 mt-0.5 shrink-0"></i>
                <span><?= $message ?></span>
            </div>
        <?php endif; ?>
        <!-- Header row -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-lg font-bold text-white">Chess Room Manager</h1>
                <p class="text-xs text-gray-500 mt-0.5">Monitor & kelola seluruh sesi permainan catur</p>
            </div>
            <div class="flex items-center gap-2">
                <!-- Auto-cleanup countdown -->
                <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-xs text-gray-400">
                    <i data-lucide="timer" class="w-3.5 h-3.5 text-blue-400"></i>
                    <span>Auto-cleanup: <span id="countdown" class="text-blue-400 font-mono font-bold">10:00</span></span>
                </div>
                <!-- Manual purge -->
                <form method="POST"
                    onsubmit="return meelConfirmForm(event, { title:'Purge Room', text:'Hapus semua room tidak aktif sekarang?', confirmButtonText:'PURGE' })">
                    <input type="hidden" name="action" value="purge_inactive">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <button type="submit"
                        class="flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-lg bg-red-500/10 border border-red-500/20 text-red-400 hover:bg-red-500/20 transition-colors">
                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> Purge Sekarang
                    </button>
                </form>
            </div>
        </div>

        <!-- Stats cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <?php
            $stat_cards = [
                ['label' => 'Total Room',   'value' => $stats['total_rooms'],  'icon' => 'layout-grid',    'color' => 'text-blue-400'],
                ['label' => 'Aktif',        'value' => $stats['active'],       'icon' => 'swords',         'color' => 'text-green-400'],
                ['label' => 'Menunggu',     'value' => $stats['waiting'],      'icon' => 'clock',          'color' => 'text-yellow-400'],
                ['label' => 'Total Moves',  'value' => $stats['total_moves'],  'icon' => 'move',           'color' => 'text-purple-400'],
            ];
            foreach ($stat_cards as $s): ?>
                <div class="card px-4 py-4 flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-white/5 flex items-center justify-center shrink-0">
                        <i data-lucide="<?= $s['icon'] ?>" class="w-4 h-4 <?= $s['color'] ?>"></i>
                    </div>
                    <div>
                        <p class="text-[11px] text-gray-500"><?= $s['label'] ?></p>
                        <p class="text-xl font-bold text-white leading-tight"><?= $s['value'] ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Room table -->
        <div class="card overflow-hidden">
            <div class="px-5 py-4 border-b border-white/5 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                    <i data-lucide="table" class="w-4 h-4 text-gray-400"></i> Daftar Room
                </h2>
                <span class="text-[11px] text-gray-500"><?= count($rooms) ?> room</span>
            </div>

            <?php if (empty($rooms)): ?>
                <div class="py-16 text-center text-gray-600 text-sm">
                    <i data-lucide="inbox" class="w-8 h-8 mx-auto mb-2 opacity-40"></i>
                    <p>Tidak ada room aktif</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-[11px] uppercase tracking-widest text-gray-600 border-b border-white/5">
                                <th class="px-5 py-3 text-left">Room Code</th>
                                <th class="px-5 py-3 text-left">Status</th>
                                <th class="px-5 py-3 text-left">Total Moves</th>
                                <th class="px-5 py-3 text-left">Dibuat</th>
                                <th class="px-5 py-3 text-left">Aktivitas Terakhir</th>
                                <th class="px-5 py-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php foreach ($rooms as $room):
                                $now = new DateTime();
                                $created = new DateTime($room['created_at']);
                                $last_act = $room['last_activity'] ? new DateTime($room['last_activity']) : null;
                                $idle_minutes = $last_act ? (int)(($now->getTimestamp() - $last_act->getTimestamp()) / 60) : null;

                                if ($room['black_joined'] == 0) {
                                    $status = 'waiting';
                                    $status_label = 'Menunggu Lawan';
                                } elseif ($idle_minutes !== null && $idle_minutes < 10) {
                                    $status = 'active';
                                    $status_label = 'Sedang Bermain';
                                } else {
                                    $status = 'idle';
                                    $status_label = 'Tidak Aktif';
                                }
                            ?>
                                <tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="px-5 py-3">
                                        <span class="font-mono font-bold text-white tracking-widest"><?= htmlspecialchars($room['room_code']) ?></span>
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="badge-<?= $status ?> text-[10px] font-bold uppercase tracking-widest px-2.5 py-1 rounded-md">
                                            <?= $status_label ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-gray-400"><?= (int)$room['total_moves'] ?></td>
                                    <td class="px-5 py-3 text-gray-500 text-xs"><?= $created->format('d M Y, H:i') ?></td>
                                    <td class="px-5 py-3 text-gray-500 text-xs">
                                        <?php if ($last_act): ?>
                                            <?= $last_act->format('H:i:s') ?>
                                            <span class="text-gray-600">(<?= $idle_minutes ?>m lalu)</span>
                                        <?php else: ?>
                                            <span class="text-gray-700">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3 text-center">
                                        <form method="POST" onsubmit="return meelConfirmForm(event, { title:'Hapus Room', text:'Hapus room <?= htmlspecialchars($room['room_code']) ?> beserta semua moves-nya?', confirmButtonText:'HAPUS' })">
                                            <input type="hidden" name="action" value="delete_room">
                                            <input type="hidden" name="room_code" value="<?= htmlspecialchars($room['room_code']) ?>">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <button type="submit"
                                                class="inline-flex items-center gap-1 text-[10px] font-bold uppercase text-red-500/70 hover:text-red-400 border border-red-500/10 hover:border-red-500/30 bg-red-500/5 hover:bg-red-500/10 px-2.5 py-1.5 rounded-md transition-all">
                                                <i data-lucide="trash" class="w-3 h-3"></i> Hapus
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Activity Log -->
        <div class="card overflow-hidden">
            <div class="px-5 py-4 border-b border-white/5 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                    <i data-lucide="scroll-text" class="w-4 h-4 text-gray-400"></i> Log Auto-Cleanup
                </h2>
                <span class="text-[11px] text-gray-500">20 entri terakhir</span>
            </div>
            <div class="p-5 space-y-1 max-h-64 overflow-y-auto">
                <?php if (empty($log_lines)): ?>
                    <p class="text-[12px] text-gray-700">Belum ada log cleanup.</p>
                <?php else: ?>
                    <?php foreach ($log_lines as $line): ?>
                        <p class="log-entry"><?= htmlspecialchars($line) ?></p>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div id="live-log" class="px-5 pb-4 space-y-1"></div>
        </div>

    </main>

    <script>
        // Bridge PHP → JS: token CSRF untuk endpoint auto_cleanup
        window.MEEL_ADMIN_CSRF = <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="../assets/js/admin/catur.js?v=<?= filemtime('../assets/js/admin/catur.js') ?>"></script>
</body>

</html>
