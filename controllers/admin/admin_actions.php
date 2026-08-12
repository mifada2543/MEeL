<?php
// ─── Guard Direct Access ───
if (!defined('MEEL_ADMIN_CONTEXT')) {
    die(include __DIR__ . '/../../err/denied.php');
}

// ─── Verifikasi Role Admin ───
if (!is_admin($conn)) {
    die(include __DIR__ . '/../../err/denied.php');
}

// ─── CSRF Guard ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf_token($_POST['csrf_token'] ?? null)) {
    header("Location: index.php?msg=CSRF_Token_Invalid");
    exit();
}

// ─── BAN IP ───
if (isset($_POST['ban_ip'])) {
    $ip   = $_POST['ip_target'];
    $reason = !empty($_POST['ban_reason']) ? $_POST['ban_reason'] : "Manual Ban by Admin";

    $stmt = $conn->prepare("INSERT IGNORE INTO ip_ban (ip_address, reason) VALUES (?, ?)");
    $stmt->bind_param("ss", $ip, $reason);
    $stmt->execute();
    log_activity($conn, (int)$_SESSION['user_id'], 'ban_ip', 'ip', 0);
    header("Location: index.php?msg=IP_Banned");
    exit();
}

// ─── UNBAN IP ───
if (isset($_POST['unban_ip'])) {
    $stmt = $conn->prepare("DELETE FROM ip_ban WHERE ip_address = ?");
    $stmt->bind_param("s", $_POST['unban_ip']);
    $stmt->execute();
    log_activity($conn, (int)$_SESSION['user_id'], 'unban_ip', 'ip', 0);
    header("Location: index.php?msg=IP_Unbanned#unban");
    exit();
}

// ─── CLEAR INACTIVE GUESTS ───
if (isset($_POST['clear_all_guests'])) {
    $stmt = $conn->prepare("DELETE FROM users WHERE role = 'guest' AND is_active = 0");
    if ($stmt->execute()) {
        $result_ai = $conn->query("SELECT COALESCE(MAX(id), 0) + 1 AS new_ai FROM users");
        if ($result_ai) {
            $new_ai = (int)$result_ai->fetch_assoc()['new_ai'];
            $conn->query("ALTER TABLE users AUTO_INCREMENT = " . (int)$new_ai);
        }
        header("Location: index.php?msg=Guests_Cleared_Efficiently");
    } else {
        header("Location: index.php?msg=Error_Cleaning");
    }
    exit();
}

// ─── CLEAN STUCK QUEUES ───
if (isset($_POST['clean_stuck_queues'])) {
    require_once __DIR__ . '/../../modules/core/System.php';
    $sys     = new System($conn);
    $cleaned = $sys->cleanStuckQueues();
    $url     = "index.php?msg=Queues_Cleaned_{$cleaned}#queues";

    if (!headers_sent()) {
        header("Location: " . $url);
    } else {
        echo "<script>window.location.href='$url';</script>";
    }
    exit();
}

// ─── FORCE STOP QUEUE ───
if (isset($_POST['force_stop_queue'])) {
    require_once __DIR__ . '/../../modules/core/System.php';
    $sys = new System($conn);
    $sys->forceStopQueue((int)$_POST['queue_id'], $_POST['task_type']);

    $url = "index.php?msg=Queue_Force_Stopped#queues";
    if (!headers_sent()) {
        header("Location: " . $url);
    } else {
        echo "<script>window.location.href='$url';</script>";
    }
    exit();
}

// ─── APPROVE USER ───
if (isset($_POST['approve_id'])) {
    $stmt = $conn->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
    $stmt->bind_param("i", $_POST['approve_id']);
    $stmt->execute();
    log_activity($conn, (int)$_SESSION['user_id'], 'approve_user', 'user', (int)$_POST['approve_id']);

    if (function_exists('invalidate_user_role_cache')) {
        invalidate_user_role_cache();
    }
    header("Location: index.php?msg=Approved");
    exit();
}

// ─── REJECT USER ───
if (isset($_POST['reject_id'])) {
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND is_active = 2");
    $stmt->bind_param("i", $_POST['reject_id']);
    $stmt->execute();
    log_activity($conn, (int)$_SESSION['user_id'], 'reject_user', 'user', (int)$_POST['reject_id']);
    header("Location: index.php?msg=Rejected");
    exit();
}

// ─── DELETE USER ───
if (isset($_POST['delete_user_id'])) {
    $id = (int)$_POST['delete_user_id'];

    if ($id === (int)($_SESSION['user_id'] ?? 0)) {
        header("Location: index.php?msg=Cannot_Delete_Self");
        exit();
    }

    if (get_user_role($conn, $id) === 'admin') {
        header("Location: index.php?msg=Cannot_Delete_Admin");
        exit();
    }

    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    log_activity($conn, (int)$_SESSION['user_id'], 'delete_user', 'user', $id);
    header("Location: index.php?msg=User_Deleted");
    exit();
}

// ─── CLEAN ORPHAN FILES ───
if (isset($_POST['clean_orphans'])) {
    $files = json_decode($_POST['files_to_delete'], true);
    foreach ((array)$files as $f) {
        if (file_exists($f)) @unlink($f);
    }
    header("Location: index.php?status=cleaned#system_check");
    exit();
}

// ─── KICK USER ───
if (isset($_POST['kick_user'])) {
    $stmt = $conn->prepare("UPDATE users SET
        last_session_id = 'KICKED',
        last_page       = 'KICKED BY ADMIN',
        last_activity   = DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        WHERE username = ?");
    $stmt->bind_param("s", $_POST['kick_user']);
    $stmt->execute();
    log_activity($conn, (int)$_SESSION['user_id'], 'kick_user', 'user', 0);
    header("Location: index.php?msg=Kicked_Success#monitor");
    exit();
}

// ─── RESET MFA USER ───
if (isset($_GET['reset_mfa']) && isset($_GET['user_id'])) {
    // CSRF guard
    if (!verify_csrf_token($_GET['csrf_token'] ?? null)) {
        header("Location: ../admin/mfa_reset.php?msg=csrf_invalid");
        exit;
    }

    $target_id = (int)$_GET['user_id'];

    $check = $conn->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $check->bind_param("i", $target_id);
    $check->execute();
    $target = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$target) {
        header("Location: ../admin/mfa_reset.php?msg=user_not_found");
        exit;
    }

    if ($target['role'] === 'admin') {
        header("Location: ../admin/mfa_reset.php?msg=cannot_reset_admin");
        exit;
    }

    $stmt = $conn->prepare("UPDATE users SET mfa_enabled = 0, mfa_secret = NULL, mfa_backup_codes = NULL WHERE id = ?");
    $stmt->bind_param("i", $target_id);
    if ($stmt->execute()) {
        log_activity($conn, (int)$_SESSION['user_id'], 'reset_mfa', 'user', $target_id);
        header("Location: ../admin/mfa_reset.php?msg=reset_ok&user=" . urlencode($target['username']));
    } else {
        header("Location: ../admin/mfa_reset.php?msg=reset_failed");
    }
    $stmt->close();
    exit;
}
