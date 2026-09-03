<?php
require_once __DIR__ . '/../modules/core/helpers.php';
meel_boot_session();
include 'config.php';
if (isset($_SESSION['user_id'])) {
    log_activity($conn, (int)$_SESSION['user_id'], 'logout');
    $stmt = $conn->prepare("UPDATE users SET last_session_id = NULL WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $stmt->close();
}
$_SESSION = array();
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path'    => $params['path'],
        'domain'  => $params['domain'],
        'secure'  => $params['secure'],
        'httponly'=> $params['httponly'],
        'samesite'=> $params['samesite'] ?? 'Lax',
    ]);
}
session_destroy();            header("Location: login");
