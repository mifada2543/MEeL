<?php
/* MEeL Admin — Activity Log Viewer */

include '../auth/config.php';
include '../auth/auth.php';
include_once '../modules/core/helpers.php';

// Guard terpusat: harus login + role admin
require_admin($conn);
require_once __DIR__ . '/../modules/media/AdminActivityRepository.php';

$logRepo = new AdminActivityRepository($conn);

// ─── Filter & Pagination ───
$action_filter = $_GET['action'] ?? '';
$search_q     = trim($_GET['q'] ?? '');
$days         = max(1, min(365, (int)($_GET['days'] ?? 7)));
$page         = max(1, (int)($_GET['page'] ?? 1));
$per_page     = 50;

// ─── Clear Old Logs (POST) ───
$clear_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_older_than'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $clear_msg = 'CSRF Token tidak valid.';
    } else {
        $clear_days = max(1, (int)($_POST['clear_days'] ?? 30));
        $deleted    = $logRepo->clearOlderThan($clear_days);
        $next_id    = 1;
        $max_res    = $conn->query('SELECT MAX(id) AS max_id FROM activity_log');
        if ($max_res) {
            $max_row = $max_res->fetch_assoc();
            $next_id = $max_row['max_id'] ? (int)$max_row['max_id'] + 1 : 1;
        }
        $clear_msg = "Berhasil menghapus {$deleted} log lebih dari {$clear_days} hari. Auto-increment di-reset ke {$next_id}.";
    }
}

// ─── Clear All Logs (POST) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_all_logs'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $clear_msg = 'CSRF Token tidak valid.';
    } else {
        if ($logRepo->clearAll()) {
            $clear_msg = 'Semua log aktivitas berhasil dihapus. Auto-increment telah di-reset ke 1.';
        } else {
            $clear_msg = 'Gagal menghapus log: ' . $conn->error;
        }
    }
}

// ─── Query via repository (filter, count, rows, actions, stats) ───
$logRepo->buildFilter($action_filter, $search_q, $days);

$total_rows = $logRepo->countFiltered();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page = min($page, $total_pages); // Cegah offset tak berguna
$offset = ($page - 1) * $per_page;

$rows       = $logRepo->fetchPage($per_page, $offset);
$all_actions = $logRepo->getDistinctActions();
$stats      = $logRepo->getWeeklyStats();

// ─── Multi-Format Export ───
$export_format = $_GET['export'] ?? '';
if (in_array($export_format, ['csv', 'json', 'xls'], true)) {
    $timestamp = date('Y-m-d_H-i-s');
    $filename_base = "activity-log-export-{$timestamp}";
    $rows = $logRepo->fetchAll();

    switch ($export_format) {
        // ─── CSV ───
        case 'csv':
            header('Content-Type: text/csv; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$filename_base}.csv\"");

            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8
            fputcsv($output, ['ID', 'User ID', 'Username', 'Action', 'Media Type', 'Media ID', 'IP Address', 'Waktu']);

            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['id'],
                    $row['user_id'] ?? '',
                    $row['username'] ?? 'Guest',
                    $row['action'],
                    $row['media_type'] ?? '',
                    $row['media_id'] ?? '',
                    $row['ip_address'] ?? '',
                    $row['created_at']
                ]);
            }
            fclose($output);
            break;

        // ─── JSON ───
        case 'json':
            header('Content-Type: application/json; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$filename_base}.json\"");

            $json_data = array_map(function ($r) {
                return [
                    'id'         => (int)$r['id'],
                    'user_id'    => $r['user_id'] !== null ? (int)$r['user_id'] : null,
                    'username'   => $r['username'] ?? 'Guest',
                    'action'     => $r['action'],
                    'media_type' => $r['media_type'] ?? '',
                    'media_id'   => $r['media_id'] !== null ? (int)$r['media_id'] : null,
                    'ip_address' => $r['ip_address'] ?? '',
                    'created_at' => $r['created_at'],
                ];
            }, $rows);

            echo json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;

        // ─── XLS (XML Spreadsheet 2003) ───
        case 'xls':
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$filename_base}.xls\"");

            echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
            echo '  xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
            echo '  xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
            echo '  xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
            echo '  <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">' . "\n";
            echo '    <Author>MEeL Admin</Author>' . "\n";
            echo '    <Created>' . date('c') . '</Created>' . "\n";
            echo '  </DocumentProperties>' . "\n";
            echo '  <Worksheet ss:Name="Activity Log">' . "\n";
            echo '    <Table>' . "\n";

            $headers = ['ID', 'User ID', 'Username', 'Action', 'Media Type', 'Media ID', 'IP Address', 'Waktu'];
            echo '      <Row>' . "\n";
            foreach ($headers as $h) {
                echo '        <Cell><Data ss:Type="String">' . htmlspecialchars($h) . '</Data></Cell>' . "\n";
            }
            echo '      </Row>' . "\n";
            $xls_cell = function ($val, string $type = 'String'): string {
                if ($val === null || $val === '') {
                    return '        <Cell><Data ss:Type="String"></Data></Cell>' . "\n";
                }
                if ($type === 'Number') {
                    return '        <Cell><Data ss:Type="Number">' . (int)$val . '</Data></Cell>' . "\n";
                }
                return '        <Cell><Data ss:Type="String">' . htmlspecialchars((string)$val) . '</Data></Cell>' . "\n";
            };

            foreach ($rows as $row) {
                echo '      <Row>' . "\n";
                echo $xls_cell($row['id'], 'Number');
                echo $xls_cell($row['user_id'], 'Number');
                echo $xls_cell($row['username'] ?? 'Guest');
                echo $xls_cell($row['action']);
                echo $xls_cell($row['media_type']);
                echo $xls_cell($row['media_id'], 'Number');
                echo $xls_cell($row['ip_address']);
                echo $xls_cell($row['created_at']);
                echo '      </Row>' . "\n";
            }

            echo '    </Table>' . "\n";
            echo '  </Worksheet>' . "\n";
            echo '</Workbook>' . "\n";
            break;
    }
    exit;
}

// ─── Preview Handler ───
if (isset($_GET['preview']) && $_GET['preview'] === '1' && in_array($_GET['format'] ?? '', ['csv', 'json', 'xls'], true)) {
    $preview_format = $_GET['format'];
    $all_rows = $logRepo->fetchAll();
    $preview_total = count($all_rows);
    $preview_limit = 15;
    $preview_rows = array_slice($all_rows, 0, $preview_limit);

    $content = '';
    switch ($preview_format) {
        case 'csv':
            $content = '';
            $h = ['ID', 'User ID', 'Username', 'Action', 'Media Type', 'Media ID', 'IP Address', 'Waktu'];
            $content .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $h)) . "\n";
            foreach ($preview_rows as $r) {
                $vals = [
                    $r['id'],
                    $r['user_id'] ?? '',
                    $r['username'] ?? 'Guest',
                    $r['action'],
                    $r['media_type'] ?? '',
                    $r['media_id'] ?? '',
                    $r['ip_address'] ?? '',
                    $r['created_at']
                ];
                $content .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $vals)) . "\n";
            }
            if ($preview_total > $preview_limit) {
                $content .= "\n... dan " . ($preview_total - $preview_limit) . " baris lainnya\n";
            }
            break;

        case 'json':
            $json_out = array_map(function ($r) {
                return [
                    'id'         => (int)$r['id'],
                    'user_id'    => $r['user_id'] !== null ? (int)$r['user_id'] : null,
                    'username'   => $r['username'] ?? 'Guest',
                    'action'     => $r['action'],
                    'media_type' => $r['media_type'] ?? '',
                    'media_id'   => $r['media_id'] !== null ? (int)$r['media_id'] : null,
                    'ip_address' => $r['ip_address'] ?? '',
                    'created_at' => $r['created_at'],
                ];
            }, $preview_rows);
            $content = json_encode($json_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($preview_total > $preview_limit) {
                $content .= "\n\n/* ... dan {$preview_total} total baris (ditampilkan {$preview_limit}) */";
            }
            break;

        case 'xls':
            $content = "ID\tUser ID\tUsername\tAction\tMedia Type\tMedia ID\tIP Address\tWaktu\n";
            foreach ($preview_rows as $r) {
                $content .= implode("\t", [
                    $r['id'],
                    $r['user_id'] ?? '',
                    $r['username'] ?? 'Guest',
                    $r['action'],
                    $r['media_type'] ?? '',
                    $r['media_id'] ?? '',
                    $r['ip_address'] ?? '',
                    $r['created_at']
                ]) . "\n";
            }
            if ($preview_total > $preview_limit) {
                $content .= "\n... dan " . ($preview_total - $preview_limit) . " baris lainnya\n";
            }
            break;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'format'        => $preview_format,
        'total'         => $preview_total,
        'preview_count' => min($preview_total, $preview_limit),
        'content'       => $content,
    ]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="id">

<head>
<?php
$_META_TITLE = 'MEeL | Activity Log';
$_META_DESC  = 'MEeL Activity Log — Audit trail untuk monitoring aktivitas pengguna.';
include __DIR__ . '/../partials/link.php';
$scripts_root = '../';
include __DIR__ . '/../partials/scripts.php';
?>
    <?php foreach (require __DIR__ . '/../assets/css/admin/manifest.php' as $__f): ?>
        <link rel="stylesheet" href="../assets/css/admin/<?= $__f ?>?v=<?= filemtime(__DIR__ . '/../assets/css/admin/' . $__f) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="../assets/css/admin/activity_log.css?v=<?= filemtime('../assets/css/admin/activity_log.css') ?>">
</head>

<body class="text-gray-300 min-h-screen">

    <?php
    $page_title = 'Activity Log';
    $media_type = 'analytics';
    $back_url = 'index.php';
    include 'header-admin.php';
    ?>
    <div class="max-w-7xl mx-auto px-6 md:px-10 xl:px-16 py-8">

        <!-- Header -->
        <div class="flex items-center gap-5 mb-10">
            <div class="w-14 h-14 rounded-2xl bg-blue-500/15 border border-blue-500/25 flex items-center justify-center shrink-0">
                <i data-lucide="activity" class="w-6 h-6 text-blue-500"></i>
            </div>
            <div>
                <h1 class="text-3xl md:text-4xl font-extrabold text-white leading-tight tracking-tight">Activity Log</h1>
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500 mt-1.5">Audit Trail</p>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-5 mb-8">
            <div class="glass p-5 rounded-2xl border-l-4 border-blue-500">
                <p class="text-[9px] font-bold text-gray-500 uppercase mb-1.5">7 Hari Terakhir</p>
                <span class="text-2xl font-bold text-white"><?= number_format($stats['total']) ?></span>
                <span class="text-[10px] text-gray-500 ml-1.5">events</span>
            </div>
            <div class="glass p-5 rounded-2xl border-l-4 border-green-500">
                <p class="text-[9px] font-bold text-gray-500 uppercase mb-1.5">User Aktif</p>
                <span class="text-2xl font-bold text-white"><?= number_format($stats['unique_users']) ?></span>
                <span class="text-[10px] text-gray-500 ml-1.5">users</span>
            </div>
            <div class="glass p-5 rounded-2xl border-l-4 border-purple-500">
                <p class="text-[9px] font-bold text-gray-500 uppercase mb-1.5">Total Log</p>
                <span class="text-2xl font-bold text-white"><?= number_format($total_rows) ?></span>
                <span class="text-[10px] text-gray-500 ml-1.5">entries</span>
            </div>
            <div class="glass p-5 rounded-2xl border-l-4 border-orange-500">
                <p class="text-[9px] font-bold text-gray-500 uppercase mb-1.5">Halaman</p>
                <span class="text-2xl font-bold text-white"><?= $page ?>/<?= $total_pages ?></span>
            </div>
        </div>

        <!-- Clear Message -->
        <?php if ($clear_msg): ?>
            <div class="mb-8 p-5 rounded-2xl text-sm flex items-center gap-3 bg-green-500/10 text-green-400 border border-green-500/20">
                <i data-lucide="check-circle" class="w-5 h-5 shrink-0"></i>
                <?= htmlspecialchars($clear_msg) ?>
            </div>
        <?php endif; ?>
        <!-- Filters -->
        <div class="glass p-6 md:p-8 rounded-2xl mb-8 filter-section relative z-40 overflow-visible" id="filter-section">
            <div class="flex flex-col lg:flex-row items-start justify-between gap-6 w-full min-w-0">
                <div class="w-full lg:w-1/4 min-w-0 relative z-30">
                    <label class="text-[9px] font-bold text-gray-500 uppercase tracking-widest mb-2.5 block">
                        <i data-lucide="activity" class="w-3 h-3 inline mr-1.5"></i> Action
                    </label>
                    <div class="relative" id="action-dropdown-container">
                        <input type="hidden" name="action" id="action-input" value="<?= htmlspecialchars($action_filter) ?>">
                        <button type="button"
                            onclick="toggleActionDropdown()"
                            class="action-dropdown-btn w-full rounded-xl pl-4 pr-11 py-3 text-xs text-gray-300 flex items-center justify-between relative z-20 h-[42px]"
                            id="action-dropdown-trigger">
                            <span class="truncate" id="action-dropdown-label">
                                <?= $action_filter ? htmlspecialchars($action_filter) : 'Semua Aksi' ?>
                            </span>
                            <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-gray-500 shrink-0"></i>
                        </button>

                        <!-- Dropdown Panel (diberi z-50 dan shadow-2xl agar melayang sempurna di atas elemen lain) -->
                        <div id="action-dropdown-panel"
                            class="action-dropdown-panel hidden absolute left-0 right-0 mt-1.5 rounded-xl z-50 py-1 shadow-2xl bg-[#131720]">
                            <button type="button"
                                onclick="selectAction('')"
                                data-value=""
                                class="action-dropdown-option w-full text-left px-4 py-3 text-xs text-gray-300 <?= empty($action_filter) ? 'active' : '' ?>">
                                <span class="flex items-center gap-2.5">
                                    <i data-lucide="circle" class="w-2.5 h-2.5 text-gray-600"></i>
                                    Semua Aksi
                                </span>
                            </button>
                            <?php foreach ($all_actions as $act): ?>
                                <button type="button"
                                    onclick="selectAction('<?= htmlspecialchars($act, ENT_QUOTES) ?>')"
                                    data-value="<?= htmlspecialchars($act, ENT_QUOTES) ?>"
                                    class="action-dropdown-option w-full text-left px-4 py-3 text-xs text-gray-300 <?= $action_filter === $act ? 'active' : '' ?>">
                                    <span class="flex items-center gap-2.5">
                                        <i data-lucide="circle" class="w-2.5 h-2.5 text-gray-600"></i>
                                        <?= htmlspecialchars($act) ?>
                                    </span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Search -->
                <div class="w-full lg:w-1/4 min-w-0 relative z-10">
                    <label class="text-[9px] font-bold text-gray-500 uppercase tracking-widest mb-2.5 block">
                        <i data-lucide="search" class="w-3 h-3 inline mr-1.5"></i> Cari Username / IP
                    </label>
                    <div class="relative">
                        <input type="text" name="q" id="search-input" value="<?= htmlspecialchars($search_q) ?>"
                            placeholder="Cari username atau IP..."
                            class="w-full bg-[#131720] border border-white/10 rounded-xl pl-4 pr-4 text-xs text-gray-300 outline-none focus:border-blue-500 transition-all placeholder:text-gray-600 h-[42px]">
                    </div>
                </div>

                <!-- Days (Pill Buttons) -->
                <div class="w-full lg:flex-1 min-w-0 relative z-10">
                    <label class="text-[9px] font-bold text-gray-500 uppercase tracking-widest mb-2.5 block">
                        <i data-lucide="calendar" class="w-3 h-3 inline mr-1.5"></i> Rentang
                    </label>
                    <input type="hidden" name="days" id="days-input" value="<?= $days ?>">
                    <div class="flex flex-wrap gap-1.5 items-center min-h-[42px] max-w-full">
                        <?php foreach ([1 => '24 Jam', 3 => '3 Hari', 7 => '7 Hari', 14 => '14 Hari', 30 => '30 Hari', 90 => '90 Hari', 365 => '1 Tahun'] as $d => $label): ?>
                            <button type="button"
                                onclick="selectDays(<?= $d ?>)"
                                class="pill-btn <?= $days === $d ? 'active-blue' : '' ?>"
                                data-days="<?= $d ?>">
                                <?= $label ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>

            <!-- Action Buttons -->
            <div class="flex items-center gap-4 mt-6 pt-5 border-t border-white/[.04]">
                <button type="button" onclick="submitFilters()"
                    class="bg-blue-600 hover:bg-blue-500 text-white text-[10px] font-bold px-6 py-3 rounded-xl transition-all uppercase tracking-wider inline-flex items-center gap-2">
                    <i data-lucide="filter" class="w-3.5 h-3.5"></i>
                    Terapkan
                </button>
                <a href="activity-log"
                    class="text-[10px] text-gray-500 hover:text-white px-4 py-3 transition-all uppercase tracking-wider inline-flex items-center gap-2 rounded-xl hover:bg-white/[.03]">
                    <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                    Reset
                </a>
            </div>
        </div>

        <script src="../assets/js/admin/activity_log.js?v=<?= filemtime('../assets/js/admin/activity_log.js') ?>"></script>

        <!-- Table -->
        <div class="glass rounded-2xl overflow-hidden relative z-0">
            <div class="scroll-table" style="max-height:70vh;">
                <table class="w-full text-left text-[11px]">
                    <thead class="text-gray-500 uppercase text-[9px] font-black tracking-widest">
                        <tr>
                            <th class="py-3 px-4 w-14">#</th>
                            <th class="py-3 px-4">User</th>
                            <th class="py-3 px-4">Action</th>
                            <th class="py-3 px-4">Media</th>
                            <th class="py-3 px-4 text-center">ID</th>
                            <th class="py-3 px-4 hidden md:table-cell">IP Address</th>
                            <th class="py-3 px-4 text-right">Waktu</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <?php if ($rows && $rows->num_rows > 0): ?>
                            <?php while ($row = $rows->fetch_assoc()):
                                $action = $row['action'];
                                if (str_contains($action, 'login') || str_contains($action, 'logout')) {
                                    $ac_color = 'text-blue-400 bg-blue-500/10 border-blue-500/20';
                                    $ac_icon  = $action === 'login' ? 'log-in' : 'log-out';
                                } elseif (str_contains($action, 'upload')) {
                                    $ac_color = 'text-green-400 bg-green-500/10 border-green-500/20';
                                    $ac_icon  = 'upload-cloud';
                                } elseif (str_contains($action, 'ban') || str_contains($action, 'unban')) {
                                    $ac_color = 'text-red-400 bg-red-500/10 border-red-500/20';
                                    $ac_icon  = 'shield-alert';
                                } elseif (str_contains($action, 'delete') || str_contains($action, 'reject') || str_contains($action, 'kick')) {
                                    $ac_color = 'text-red-400 bg-red-500/10 border-red-500/20';
                                    $ac_icon  = 'trash-2';
                                } elseif (str_contains($action, 'approve')) {
                                    $ac_color = 'text-green-400 bg-green-500/10 border-green-500/20';
                                    $ac_icon  = 'user-check';
                                } else {
                                    $ac_color = 'text-gray-400 bg-white/5 border-white/10';
                                    $ac_icon  = 'circle';
                                }
                            ?>
                                <tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="py-3 px-4 font-mono text-gray-600"><?= (int)$row['id'] ?></td>
                                    <td class="py-3 px-4">
                                        <span class="font-bold text-white">
                                            <?= htmlspecialchars($row['username'] ?? '—') ?>
                                        </span>
                                        <?php if ($row['user_id'] === null): ?>
                                            <span class="text-[8px] text-gray-600 ml-1">(guest)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="action-badge <?= $ac_color ?>">
                                            <i data-lucide="<?= $ac_icon ?>" class="w-3 h-3"></i>
                                            <?= htmlspecialchars($action) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-gray-400">
                                        <?= !empty($row['media_type']) ? htmlspecialchars($row['media_type']) : '<span class="text-gray-600">—</span>' ?>
                                    </td>
                                    <td class="py-3 px-4 text-center font-mono text-gray-500">
                                        <?= !empty($row['media_id']) ? (int)$row['media_id'] : '—' ?>
                                    </td>
                                    <td class="py-3 px-4 hidden md:table-cell font-mono text-gray-500 text-[10px]">
                                        <?= htmlspecialchars($row['ip_address'] ?? '—') ?>
                                    </td>
                                    <td class="py-3 px-4 text-right text-gray-400 text-[10px] whitespace-nowrap">
                                        <?= date('d/m/Y H:i', strtotime($row['created_at'])) ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="py-12 text-center text-gray-500 text-xs italic">
                                    <i data-lucide="inbox" class="w-8 h-8 mx-auto mb-3 opacity-30"></i>
                                    <p>Belum ada data log untuk filter ini.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="flex items-center justify-center gap-2 mt-6">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"
                        class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-[11px] text-gray-400 hover:text-white hover:bg-white/10 transition-all">
                        ‹ Prev
                    </a>
                <?php endif; ?>
                <?php
                $start = max(1, $page - 2);
                $end   = min($total_pages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
                        class="px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-white/5 border border-white/10 text-gray-400 hover:text-white hover:bg-white/10' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"
                        class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-[11px] text-gray-400 hover:text-white hover:bg-white/10 transition-all">
                        Next ›
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <!-- Clear Old Logs -->
        <div class="glass p-6 rounded-2xl mt-8 border border-red-500/20">
            <div class="flex items-center gap-3 mb-4">
                <div class="p-2 rounded-xl bg-red-500/10 border border-red-500/20">
                    <i data-lucide="trash-2" class="w-4 h-4 text-red-400"></i>
                </div>
                <div>
                    <h3 class="text-xs font-bold text-gray-300">Maintenance Log</h3>
                    <p class="text-[9px] text-gray-500">Hapus log lama secara permanen untuk menghemat ruang database.</p>
                </div>
            </div>
            <form method="POST" class="flex items-center gap-3 flex-wrap" onsubmit="return meelConfirmForm(event, { title:'Hapus Log', text:'Hapus permanen semua log yang lebih lama dari periode yang dipilih?', confirmButtonText:'HAPUS' })" id="clear-logs-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="clear_days" id="clear-days-input" value="30">
                <input type="hidden" name="clear_older_than" value="1">
                <div class="flex flex-wrap items-center gap-1.5">
                    <?php foreach ([7 => '7 Hari', 14 => '14 Hari', 30 => '30 Hari', 90 => '90 Hari', 365 => '1 Tahun'] as $d => $label): ?>
                        <button type="button"
                            onclick="selectClearDays(<?= $d ?>)"
                            class="pill-btn <?= $d === 30 ? 'active-red' : '' ?>"
                            data-clear-days="<?= $d ?>">
                            <?= $label ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <button type="submit"
                    class="bg-red-600/10 text-red-400 border border-red-500/20 hover:bg-red-600 hover:text-white text-[10px] font-bold px-5 py-2.5 rounded-xl transition-all uppercase tracking-wider inline-flex items-center gap-1.5">
                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                    Hapus Log
                </button>
            </form>
        </div>
        <br>
        <!-- Clear ALL Logs -->
        <div class="glass p-6 rounded-2xl mt-5 border border-red-500/30">
            <div class="flex items-center gap-3 mb-4">
                <div class="p-2 rounded-xl bg-red-600/15 border border-red-500/30">
                    <i data-lucide="alert-triangle" class="w-4 h-4 text-red-400"></i>
                </div>
                <div>
                    <h3 class="text-xs font-bold text-gray-300">Hapus Semua Log</h3>
                    <p class="text-[9px] text-gray-500">Hapus <strong class="text-red-400">seluruh</strong> log aktivitas dan reset auto-increment ke 1. Tindakan ini <strong class="text-red-400">tidak dapat dibatalkan</strong>.</p>
                </div>
            </div>

            <!-- Backup sebelum hapus -->
            <div class="flex items-center gap-3 flex-wrap mb-4 p-3 rounded-xl bg-amber-500/10 border border-amber-500/20">
                <i data-lucide="info" class="w-4 h-4 text-amber-400 shrink-0"></i>
                <p class="text-[10px] text-amber-300 flex-1">
                    Backup dulu sebelum menghapus? Semua log akan hilang permanen.
                </p>
                <div class="flex items-center gap-1.5 shrink-0">
                    <button type="button" onclick="previewExport('csv')"
                        class="text-[10px] font-bold text-emerald-400 hover:text-emerald-300 px-2 py-2 rounded-xl border border-amber-500/30 hover:bg-emerald-500/10 hover:border-emerald-500/30 transition-all uppercase tracking-wider inline-flex items-center gap-1"
                        title="Preview CSV">
                        <i data-lucide="eye" class="w-3 h-3"></i>
                    </button>
                    <span class="text-amber-500/20">|</span>
                    <button type="button" onclick="previewExport('json')"
                        class="text-[10px] font-bold text-sky-400 hover:text-sky-300 px-2 py-2 rounded-xl border border-amber-500/30 hover:bg-sky-500/10 hover:border-sky-500/30 transition-all uppercase tracking-wider inline-flex items-center gap-1"
                        title="Preview JSON">
                        <i data-lucide="eye" class="w-3 h-3"></i>
                    </button>
                    <span class="text-amber-500/20">|</span>
                    <button type="button" onclick="previewExport('xls')"
                        class="text-[10px] font-bold text-violet-400 hover:text-violet-300 px-2 py-2 rounded-xl border border-amber-500/30 hover:bg-violet-500/10 hover:border-violet-500/30 transition-all uppercase tracking-wider inline-flex items-center gap-1"
                        title="Preview XLS">
                        <i data-lucide="eye" class="w-3 h-3"></i>
                    </button>
                </div>
            </div>

            <form method="POST" onsubmit="return meelConfirmForm(event, { title:'⚠️ Hapus Semua Log', text:'Apakah Anda yakin ingin menghapus SELURUH log aktivitas?\n\nAuto-increment ID akan di-reset ke 1. Tindakan ini tidak dapat dibatalkan!', icon:'warning', confirmButtonText:'HAPUS SEMUA', confirmButtonColor:'#dc2626' })" id="clear-all-logs-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="clear_all_logs" value="1">
                <button type="submit"
                    class="bg-red-600/20 text-red-400 border border-red-500/30 hover:bg-red-600 hover:text-white text-[10px] font-bold px-6 py-3 rounded-xl transition-all uppercase tracking-wider inline-flex items-center gap-2">
                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                    Hapus Semua & Reset ID
                </button>
            </form>
        </div>

    </div>

    <script>
        <?php if ($clear_msg): ?>
            Swal.fire({
                title: 'Selesai!',
                text: <?= json_encode($clear_msg) ?>,
                icon: 'success',
                confirmButtonColor: '#3b82f6',
                background: '#0e1118',
                color: '#fff'
            });
        <?php endif; ?>
    </script>
</body>

</html>
