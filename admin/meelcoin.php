<?php
include '../auth/config.php';
include '../auth/auth.php';
include_once '../modules/core/helpers.php';
include_once '../modules/core/activity_logger.php';

require_admin($conn);

define('MEEL_ADMIN_CONTEXT', true);

include '../controllers/admin/admin_actions.php';

require_once __DIR__ . '/../modules/core/helpers/settings.php';
require_once __DIR__ . '/../modules/core/MeelCoin.php';

$meelcoin_settings = [
    'meelcoin_enabled'       => get_site_setting($conn, 'meelcoin_enabled', '1'),
    'meelcoin_upload_cost'   => get_site_setting($conn, 'meelcoin_upload_cost', '5'),
    'meelcoin_advanced_cost' => get_site_setting($conn, 'meelcoin_advanced_cost', '10'),
    'meelcoin_user_max'      => get_site_setting($conn, 'meelcoin_user_max', '25'),
    'meelcoin_user_refill'   => get_site_setting($conn, 'meelcoin_user_refill', '15'),
    'meelcoin_member_max'    => get_site_setting($conn, 'meelcoin_member_max', '50'),
    'meelcoin_member_refill' => get_site_setting($conn, 'meelcoin_member_refill', '25'),
    'meelcoin_refill_hours'  => get_site_setting($conn, 'meelcoin_refill_hours', '5'),
];

$msg = $_GET['msg'] ?? null;

$all_users = $conn->query("SELECT id, username, role, meelcoin FROM users WHERE role != 'guest' ORDER BY role ASC, username ASC");
$user_list = [];
if ($all_users) {
    while ($u = $all_users->fetch_assoc()) {
        $user_list[] = $u;
    }
}

$target_user_id = (int)($_GET['user_id'] ?? $_POST['target_user_id'] ?? 0);
$target_user = null;
if ($target_user_id > 0) {
    $stmt = $conn->prepare("SELECT id, username, role, meelcoin FROM users WHERE id = ?");
    $stmt->bind_param("i", $target_user_id);
    $stmt->execute();
    $target_user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MEeLCoin Settings | MEeL Admin</title>
    <?php include '../partials/link.php'; ?>
    <?php foreach (require __DIR__ . '/../assets/css/admin/manifest.php' as $__f): ?>
        <link href="../assets/css/admin/<?= $__f ?>" rel="stylesheet">
    <?php endforeach; ?>
</head>
<body class="bg-[#0b0e14] min-h-screen">
    <?php
    $is_admin = true;
    $page_title = 'MEeLCoin Settings';
    $media_type = 'dashboard';
    $back_url = 'index.php';
    include 'header-admin.php';
    ?>
    <div style="max-width:640px;margin:0 auto;padding:32px 16px;">

        <div style="display:flex;align-items:center;gap:16px;margin-bottom:32px;">
            <div style="width:48px;height:48px;border-radius:16px;background:rgba(234,179,8,0.15);border:1px solid rgba(234,179,8,0.25);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#eab308" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"></path><path d="M12 18V6"></path></svg>
            </div>
            <div>
                <h1 style="font-size:22px;font-weight:800;color:#fff;line-height:1.2;margin:0;">MEeLCoin Settings</h1>
                <p style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#6b7280;margin-top:4px;">Upload Currency System</p>
            </div>
        </div>

        <?php if ($msg): ?>
            <div style="margin-bottom:24px;padding:12px 16px;border-radius:12px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.2);color:#4ade80;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;">
                <?= htmlspecialchars(str_replace('_', ' ', $msg)) ?>
            </div>
        <?php endif; ?>

        <div class="glass" style="border-radius:24px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,0.4);border:1px solid rgba(234,179,8,0.2);">
            <div style="padding:24px;border-bottom:1px solid rgba(234,179,8,0.1);background:rgba(234,179,8,0.05);">
                <div style="display:flex;align-items:center;gap:8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#eab308" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                    <h3 style="font-size:12px;font-weight:700;color:#eab308;text-transform:uppercase;">Konfigurasi MEeLCoin</h3>
                </div>
            </div>

            <div style="padding:24px;">
                <form method="POST" style="display:flex;flex-direction:column;gap:24px;">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px;border-radius:16px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.05);">
                        <div>
                            <div style="font-size:12px;font-weight:700;color:#fff;">Aktifkan MEeLCoin</div>
                            <div style="font-size:10px;color:#6b7280;margin-top:4px;">Gunakan sistem coin untuk upload. Nonaktifkan untuk kembali ke rate limit per jam.</div>
                        </div>
                        <label class="admin-toggle">
                            <input type="hidden" name="meelcoin_enabled" value="0">
                            <input type="checkbox" name="meelcoin_enabled" value="1" <?= $meelcoin_settings['meelcoin_enabled'] === '1' ? 'checked' : '' ?> onchange="toggleMeelCoinConfig(this.checked)">
                            <div class="admin-toggle-track"></div>
                        </label>
                    </div>

                    <div id="meelcoin-config" style="<?= $meelcoin_settings['meelcoin_enabled'] !== '1' ? 'display:none;opacity:0.3;pointer-events:none;' : '' ?>">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                            <div style="padding:16px;border-radius:16px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.05);">
                                <div class="admin-label" style="margin-bottom:12px;">Biaya Upload</div>
                                <div style="display:flex;flex-direction:column;gap:12px;">
                                    <div class="admin-field">
                                        <label class="admin-label">Upload Biasa (coin)</label>
                                        <input type="number" name="meelcoin_upload_cost" value="<?= htmlspecialchars($meelcoin_settings['meelcoin_upload_cost']) ?>" min="1" class="admin-input">
                                    </div>
                                    <div class="admin-field">
                                        <label class="admin-label">Upload Advanced (coin)</label>
                                        <input type="number" name="meelcoin_advanced_cost" value="<?= htmlspecialchars($meelcoin_settings['meelcoin_advanced_cost']) ?>" min="1" class="admin-input">
                                    </div>
                                </div>
                            </div>

                            <div style="padding:16px;border-radius:16px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.05);">
                                <div class="admin-label" style="margin-bottom:12px;">Refill Settings</div>
                                <div style="display:flex;flex-direction:column;gap:12px;">
                                    <div class="admin-field">
                                        <label class="admin-label">Interval Refill (jam)</label>
                                        <input type="number" name="meelcoin_refill_hours" value="<?= htmlspecialchars($meelcoin_settings['meelcoin_refill_hours']) ?>" min="1" class="admin-input">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;">
                            <div style="padding:16px;border-radius:16px;background:rgba(59,130,246,0.05);border:1px solid rgba(59,130,246,0.2);">
                                <div style="font-size:10px;font-weight:700;color:#60a5fa;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:12px;">User Role</div>
                                <div style="display:flex;flex-direction:column;gap:12px;">
                                    <div class="admin-field">
                                        <label class="admin-label">Max Coin</label>
                                        <input type="number" name="meelcoin_user_max" value="<?= htmlspecialchars($meelcoin_settings['meelcoin_user_max']) ?>" min="1" class="admin-input" style="border-color:rgba(59,130,246,0.3);">
                                    </div>
                                    <div class="admin-field">
                                        <label class="admin-label">Refill per Cycle</label>
                                        <input type="number" name="meelcoin_user_refill" value="<?= htmlspecialchars($meelcoin_settings['meelcoin_user_refill']) ?>" min="0" class="admin-input" style="border-color:rgba(59,130,246,0.3);">
                                    </div>
                                </div>
                            </div>

                            <div style="padding:16px;border-radius:16px;background:rgba(168,85,247,0.05);border:1px solid rgba(168,85,247,0.2);">
                                <div style="font-size:10px;font-weight:700;color:#c084fc;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:12px;">Member Role</div>
                                <div style="display:flex;flex-direction:column;gap:12px;">
                                    <div class="admin-field">
                                        <label class="admin-label">Max Coin</label>
                                        <input type="number" name="meelcoin_member_max" value="<?= htmlspecialchars($meelcoin_settings['meelcoin_member_max']) ?>" min="1" class="admin-input" style="border-color:rgba(168,85,247,0.3);">
                                    </div>
                                    <div class="admin-field">
                                        <label class="admin-label">Refill per Cycle</label>
                                        <input type="number" name="meelcoin_member_refill" value="<?= htmlspecialchars($meelcoin_settings['meelcoin_member_refill']) ?>" min="0" class="admin-input" style="border-color:rgba(168,85,247,0.3);">
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <button type="submit" name="save_meelcoin_settings" class="admin-btn admin-btn-primary admin-btn-block">
                        Simpan Pengaturan MEeLCoin
                    </button>
                </form>

                <div id="meelcoin-manual" style="margin-top:24px;padding:16px;border-radius:16px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.05);<?= $meelcoin_settings['meelcoin_enabled'] !== '1' ? 'display:none;opacity:0.3;pointer-events:none;' : '' ?>">
                    <div class="admin-label" style="margin-bottom:12px;">Manual Coin Adjustment</div>
                    <form method="POST" style="display:flex;flex-direction:column;gap:8px;">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div style="display:flex;gap:8px;">
                            <select name="target_user_id" required class="admin-select" style="width:35%;" onchange="window.location.href='meelcoin.php?user_id=' + this.value">
                                <option value="">Pilih User...</option>
                                <?php foreach ($user_list as $u): ?>
                                    <option value="<?= $u['id'] ?>" <?= $target_user_id === (int)$u['id'] ? 'selected' : '' ?>>
                                        #<?= $u['id'] ?> — <?= htmlspecialchars($u['username']) ?> (<?= ucfirst($u['role']) ?>) — <?= $u['meelcoin'] ?> coin
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select name="coin_action" class="admin-select" style="width:25%;">
                                <option value="add">Tambah</option>
                                <option value="remove">Kurangi</option>
                            </select>
                            <input type="number" name="coin_amount" placeholder="Jumlah" min="1" required class="admin-input" style="width:25%;">
                            <button type="submit" name="adjust_meelcoin_user" class="admin-btn admin-btn-primary admin-btn-sm" style="width:15%;">
                                Apply
                            </button>
                        </div>
                    </form>
                    <?php if ($target_user): ?>
                        <div style="margin-top:10px;padding:10px 14px;border-radius:10px;background:rgba(234,179,8,0.08);border:1px solid rgba(234,179,8,0.15);font-size:11px;color:#fbbf24;">
                            <strong><?= htmlspecialchars($target_user['username']) ?></strong> — ID: #<?= $target_user['id'] ?> — Role: <?= ucfirst($target_user['role']) ?> — Coin saat ini: <strong><?= $target_user['meelcoin'] ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/admin/shared/modal.js?v=<?= filemtime('../assets/js/admin/shared/modal.js') ?>"></script>
    <script src="../assets/js/admin/shared/hover-effects.js?v=<?= filemtime('../assets/js/admin/shared/hover-effects.js') ?>"></script>
    <script>if (typeof lucide !== 'undefined') lucide.createIcons();</script>
    <script>
    function toggleMeelCoinConfig(enabled) {
        var cfg = document.getElementById('meelcoin-config');
        var manual = document.getElementById('meelcoin-manual');
        if (!cfg) return;
        if (enabled) {
            cfg.style.display = '';
            cfg.style.opacity = '1';
            cfg.style.pointerEvents = '';
            manual.style.display = '';
            manual.style.opacity = '1';
            manual.style.pointerEvents = '';
        } else {
            cfg.style.display = 'none';
            cfg.style.opacity = '0.3';
            cfg.style.pointerEvents = 'none';
            manual.style.display = 'none';
            manual.style.opacity = '0.3';
            manual.style.pointerEvents = 'none';
        }
    }
    </script>
</body>
</html>
