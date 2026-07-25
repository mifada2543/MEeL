<?php
include 'config.php';
require_once __DIR__ . '/../modules/core/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('meel');
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    $next = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    header("Location: " . base_url('/auth/login.php?next=' . $next));
    exit;
}

// AMBIL DATA TERBARU DARI DATABASE
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT last_session_id, role FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();

if ($user_data) {
    // Session hijack check — hanya jika last_session_id TIDAK kosong
    // (saat baru logout, last_session_id di-reset ke NULL oleh logout.php)
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if ($user_data['role'] !== 'admin' && !empty($user_data['last_session_id']) && $user_data['last_session_id'] !== session_id()) {
            session_destroy();
            header("Location: " . base_url('/auth/login.php?error=session_expired'));
            exit;
        }
    }
    $stmt = $conn->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $_SESSION['role'] = $user_data['role'];
}
