<?php
require_once __DIR__ . '/../../auth/config.php';
require_once __DIR__ . '/../../auth/auth.php';
require_once __DIR__ . '/../../modules/core/helpers.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Silakan login terlebih dahulu.']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$action    = $_POST['action'] ?? '';
$response  = ['status' => 'error', 'message' => 'Aksi tidak dikenal.'];

if ($action === 'generate_backup' || $action === 'download_backup') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $response['message'] = 'Method tidak diizinkan.';
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $response['message'] = 'Sesi keamanan kadaluarsa. Refresh halaman.';
    } else {
        $stmt = $conn->prepare("SELECT password, mfa_enabled FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user_data) {
            $response['message'] = 'User tidak ditemukan.';
        } elseif (!$user_data['mfa_enabled']) {
            $response['message'] = 'MFA belum diaktifkan.';
        } else {
            $password_raw = $_POST['password'] ?? '';
            $stored_hash  = $user_data['password'];

            $_SESSION['backup_pwd_attempts'] = ($_SESSION['backup_pwd_attempts'] ?? 0);

            if (isset($_SESSION['backup_pwd_lock_until']) && time() < $_SESSION['backup_pwd_lock_until']) {
                $remaining = $_SESSION['backup_pwd_lock_until'] - time();
                $response['message'] = "Terlalu banyak percobaan. Coba lagi dalam {$remaining} detik.";
            } elseif (!password_verify($password_raw, $stored_hash)) {
                $_SESSION['backup_pwd_attempts']++;
                if ($_SESSION['backup_pwd_attempts'] >= 5) {
                    $_SESSION['backup_pwd_lock_until'] = time() + 300;
                    $_SESSION['backup_pwd_attempts'] = 0;
                }
                $response['message'] = 'Password salah.';
            } else {
                $_SESSION['backup_pwd_attempts'] = 0;
                unset($_SESSION['backup_pwd_lock_until']);

                $backup = generate_backup_codes();
                $hashed_json = json_encode($backup['hashed']);

                $stmt = $conn->prepare("UPDATE users SET mfa_backup_codes = ? WHERE id = ?");
                $stmt->bind_param("si", $hashed_json, $user_id);
                $stmt->execute();
                $stmt->close();

                log_activity($conn, $user_id, 'backup_codes_generated');

                if ($action === 'download_backup') {
                    $username  = $_SESSION['username'] ?? 'user';
                    $date_text = date('Y-m-d H:i:s');
                    $lines = [
                        "MEeL — MFA Backup Codes",
                        "User: {$username}",
                        "Generated: {$date_text}",
                        "",
                        "Setiap kode hanya bisa digunakan SEKALI.",
                        "Simpan di tempat yang aman!",
                        "",
                    ];
                    foreach ($backup['plain'] as $code) {
                        $lines[] = "  {$code}";
                    }
                    $content = implode("\n", $lines) . "\n";

                    header('Content-Type: text/plain; charset=utf-8');
                    header('Content-Disposition: attachment; filename="MEeL-backup-codes-' . $username . '.txt"');
                    header('Content-Length: ' . strlen($content));
                    header('Cache-Control: no-store, private');
                    echo $content;
                    exit;
                }

                $response = [
                    'status'  => 'success',
                    'message' => 'Kode cadangan baru berhasil dibuat.',
                    'codes'   => $backup['plain'],
                ];
            }
        }
    }
}

if ($action !== 'download_backup') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response);
    exit;
}
