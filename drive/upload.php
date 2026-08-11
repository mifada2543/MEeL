<?php
require '../auth/auth.php';
require '../auth/config.php';
require '../modules/core/helpers.php';
require __DIR__ . '/DriveService.php';

$user = DriveUserContext::fromSession($_SESSION);
$user->authorize();

// Detect AJAX request
$isAjax = (isset($_GET['ajax']) && $_GET['ajax'] === '1');

if ($isAjax) {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file_drive'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Parameter tidak lengkap.']);
        exit();
    }
} else {
    // Non-AJAX: Form submit klik tombol — submit_upload WAJIB ada
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit_upload'], $_FILES['file_drive'])) {
        header('Location: index.php');
        exit();
    }
}

// CSRF Token Validation
if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    if ($isAjax) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'CSRF token tidak valid.']);
        exit();
    }
    http_response_code(403);
    echo htmlspecialchars('CSRF token tidak valid.', ENT_QUOTES, 'UTF-8');
    exit();
}

$storage = new DriveStorage(dirname(__DIR__) . '/data_drive', $user);

require_once '../modules/core/System.php';
$sys = new System($conn);
$user_id = $_SESSION['user_id'];

$user_role = get_user_role($conn, (int)$user_id);

$limit = $sys->checkRateLimit($user_id, 'drive_files', $user_role);
if (!$limit['allowed']) {
    if ($isAjax) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Batas unggah terlampaui. Coba lagi dalam ' . $limit['minutes'] . ' menit.']);
        exit();
    }
    header('Location: index.php?status=rate_limit&minutes=' . $limit['minutes']);
    exit();
}

try {
    $storage->enforceQuota($_FILES['file_drive'], 20 * 1024 * 1024 * 1024);
    $result = $storage->upload($_FILES['file_drive'], $_POST['scope'] ?? DriveStorage::SCOPE_PRIVATE);

    // Audit Logging
    log_drive_operation(
        $user_id,
        $user->username,
        'upload',
        $result['filename'],
        $result['type'],
        $result['scope'],
        'success'
    );

    if ($isAjax) {
        // Hitung storage usage terkini untuk update bar
        $storageUsage = 0;
        $storagePct = 0;
        if ($user->isMember()) {
            invalidate_dir_size_cache($user->username);
            $storageUsage = get_user_usage($user->username);
            $limit = 20 * 1024 * 1024 * 1024;
            $storagePct = min(100, round(($storageUsage / $limit) * 100, 1));
        }

        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => 'Unggah berhasil.',
            'filename' => $result['filename'],
            'type' => $result['type'],
            'scope' => $result['scope'],
            'csrf_token' => get_csrf_token(),
            'storage_usage' => $storageUsage,
            'storage_percentage' => $storagePct
        ]);
        exit();
    }

    header('Location: index.php?scope=' . urlencode($result['scope']) . '&status=success');
    exit();
} catch (RuntimeException $exception) {
    // Log failed upload
    log_drive_operation(
        $user_id,
        $user->username,
        'upload',
        $_FILES['file_drive']['name'] ?? 'unknown',
        $_POST['type'] ?? 'unknown',
        $_POST['scope'] ?? 'unknown',
        'failed: ' . $exception->getMessage()
    );

    $errMsg = $exception->getMessage();

    if ($isAjax) {
        $httpCode = ($errMsg === 'quota_full') ? 413 : 400;
        http_response_code($httpCode);
        header('Content-Type: application/json');
        $responseMsg = ($errMsg === 'quota_full') ? 'Kuota penyimpanan penuh.' : $errMsg;
        echo json_encode(['status' => 'error', 'message' => $responseMsg]);
        exit();
    }

    if ($errMsg === 'quota_full') {
        header('Location: index.php?status=quota_full');
        exit();
    }

    http_response_code(400);
    echo htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8');
}
