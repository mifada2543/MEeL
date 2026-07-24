<?php
session_name('meel');
session_start();
include 'config.php';

// Reset last_session_id agar session lama tidak trigger false kick
// saat user login lagi dengan session ID baru
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
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
header("Location: login.php");
