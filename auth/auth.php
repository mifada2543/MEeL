<?php
/**
 * MEeL — Authentication Guard
 *
 * ═══════════════════════════════════════════════════════════════
 * CATATAN MFA:
 *   Autentikasi dua faktor (TOTP) di-handle SEPENUHNYA di
 *   auth/login.php dan auth/mfa_verify.php.
 *
 *   ALUR:
 *     1. login.php: password benar → cek mfa_enabled
 *        - Jika aktif: simpan mfa_temp_uid, redirect ke mfa_verify.php
 *        - Jika tidak: login normal (set session user_id)
 *     2. mfa_verify.php: verifikasi TOTP → set user_id + mfa_verified
 *
 *   Karena $_SESSION['user_id'] BARU di-set setelah MFA lulus,
 *   guard di bawah otomatis memblokir akses ke halaman protected
 *   jika MFA belum selesai. Tidak perlu logika tambahan di sini.
 * ═══════════════════════════════════════════════════════════════
 */

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
