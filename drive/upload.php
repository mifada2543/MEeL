<?php
require '../auth/auth.php';
require '../auth/config.php';
require '../modules/core/helpers.php';
require __DIR__ . '/DriveService.php';

$user = DriveUserContext::fromSession($_SESSION);
$user->authorize();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit_upload'], $_FILES['file_drive'])) {
    header('Location: index.php');
    exit();
}

// CSRF Token Validation
if (!verify_csrf()) {
    http_response_code(403);
    echo htmlspecialchars('CSRF token tidak valid.', ENT_QUOTES, 'UTF-8');
    exit();
}

$storage = new DriveStorage(dirname(__DIR__) . '/data_drive', $user);

require_once '../modules/core/System.php';
$sys = new System($conn);
$user_id = $_SESSION['user_id'];

// FIX: Use prepared statement untuk SQL query
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$user_role = $user_data['role'] ?? 'user';
$stmt->close();

$limit = $sys->checkRateLimit($user_id, 'drive_files', $user_role);
if (!$limit['allowed']) {
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

    if ($exception->getMessage() === 'quota_full') {
        header('Location: index.php?status=quota_full');
        exit();
    }

    http_response_code(400);
    echo htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
}

