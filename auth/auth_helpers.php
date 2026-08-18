<?php
if (!function_exists('auth_boot_session')) {

    function auth_boot_session(): void
    {
        // Inisialisasi session terpusat (cookie flags aman) — lihat modules/auth/helpers/session.php
        require_once __DIR__ . '/../modules/auth/helpers/session.php';
        meel_boot_session();
        if (isset($_SESSION['user_id'])) {
            header("Location: ../");
            exit;
        }
    }
}
if (!function_exists('auth_get_ip')) {
    function auth_get_ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
if (!function_exists('auth_is_loopback')) {
    function auth_is_loopback(): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (strpos($ip, '::ffff:') === 0) {
            $ip = substr($ip, 7);
        }
        return in_array($ip, ['127.0.0.1', '::1', 'localhost'], true)
            || ($ip !== '' && strpos($ip, '127.') === 0);
    }
}
if (!function_exists('auth_back_url')) {
    /* @param string[] $exclude Nama file yang tidak boleh menjadi back_url */
    function auth_back_url(array $exclude = ['login.php', 'register.php']): string
    {
        $back_url = '../';
        if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
            $ref  = $_SERVER['HTTP_REFERER'];
            $host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), PHP_URL_HOST);

            $refHost = parse_url($ref, PHP_URL_HOST);

            if ($refHost !== null && $host !== null && strcasecmp($refHost, $host) === 0) {
                $refPath    = parse_url($ref, PHP_URL_PATH) ?? '';
                $isExcluded = false;
                foreach ($exclude as $file) {
                    // Cocokkan nama file lama (login.php) maupun clean URL (/auth/login)
                    if (strpos($refPath, $file) !== false
                        || strpos($refPath, pathinfo($file, PATHINFO_FILENAME)) !== false) {
                        $isExcluded = true;
                        break;
                    }
                }
                if (!$isExcluded) {
                    $back_url = $ref;
                }
            }
        }

        return $back_url;
    }
}

if (!function_exists('auth_ip_lockout_status')) {
    /* @return array{locked: bool, remaining: int} */
    function auth_ip_lockout_status(mysqli $conn, string $ip): array
    {
        $locked    = false;
        $remaining = 0;

        $stmt_ip = $conn->prepare("SELECT attempts, locked_until FROM login_attempts WHERE ip_address = ?");
        if ($stmt_ip) {
            $stmt_ip->bind_param("s", $ip);
            $stmt_ip->execute();
            $ip_result = $stmt_ip->get_result();
            if ($ip_row = $ip_result->fetch_assoc()) {
                if ($ip_row['locked_until'] !== null) {
                    $lock_ts = strtotime($ip_row['locked_until']);
                    if (time() < $lock_ts) {
                        $locked    = true;
                        $remaining = $lock_ts - time();
                    } else {
                        // Lockout expired — reset
                        $stmt_del = $conn->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
                        $stmt_del->bind_param("s", $ip);
                        $stmt_del->execute();
                        $stmt_del->close();
                    }
                }
            }
            $stmt_ip->close();
        }

        return ['locked' => $locked, 'remaining' => $remaining];
    }
}

if (!function_exists('auth_record_failed_attempt')) {
    /* @return bool true jika lockout BARU diterapkan pada pemanggilan ini */
    function auth_record_failed_attempt(mysqli $conn, string $ip, int $max_attempts, int $lockout_time): bool
    {

        if (auth_is_loopback()) {
            return false;
        }
        $locked_now = false;
        $stmt_ups = $conn->prepare(
            "INSERT INTO login_attempts (ip_address, attempts, last_attempt_at, locked_until)
         VALUES (?, 1, NOW(), NULL)
         ON DUPLICATE KEY UPDATE
             attempts = attempts + 1,
             last_attempt_at = NOW()"
        );
        if ($stmt_ups) {
            $stmt_ups->bind_param("s", $ip);
            $stmt_ups->execute();
            $stmt_ups->close();
        }
        $stmt_chk = $conn->prepare("SELECT attempts FROM login_attempts WHERE ip_address = ?");
        if ($stmt_chk) {
            $stmt_chk->bind_param("s", $ip);
            $stmt_chk->execute();
            $chk_res = $stmt_chk->get_result();
            if ($chk_row = $chk_res->fetch_assoc()) {
                if ($chk_row['attempts'] >= $max_attempts) {
                    $lock_ts = date('Y-m-d H:i:s', time() + $lockout_time);
                    $stmt_lock = $conn->prepare("UPDATE login_attempts SET locked_until = ? WHERE ip_address = ?");
                    $stmt_lock->bind_param("ss", $lock_ts, $ip);
                    $stmt_lock->execute();
                    $stmt_lock->close();
                    $locked_now = true;
                }
            }
            $stmt_chk->close();
        }
        return $locked_now;
    }
}

if (!function_exists('auth_recheck_lockout')) {
    /* @return array{locked: bool, remaining: int} */
    function auth_recheck_lockout(mysqli $conn, string $ip): array
    {
        $locked    = false;
        $remaining = 0;
        $stmt_ip2 = $conn->prepare("SELECT locked_until FROM login_attempts WHERE ip_address = ? AND locked_until IS NOT NULL");
        if ($stmt_ip2) {
            $stmt_ip2->bind_param("s", $ip);
            $stmt_ip2->execute();
            $ip2_res = $stmt_ip2->get_result();
            if ($ip2_row = $ip2_res->fetch_assoc()) {
                $lock_ts = strtotime($ip2_row['locked_until']);
                if (time() < $lock_ts) {
                    $locked    = true;
                    $remaining = $lock_ts - time();
                }
            }
            $stmt_ip2->close();
        }
        return ['locked' => $locked, 'remaining' => $remaining];
    }
}

if (!function_exists('auth_validate_credentials')) {
    /* @return string|null Pesan error, atau null jika valid */
    function auth_validate_credentials(string $user, string $pass): ?string
    {
        if (strlen($user) < 8 || strlen($pass) < 8) {
            return "Username min 8 karakter, Password min 8 karakter!";
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $user)) {
            return "Username hanya boleh berisi huruf, angka, dan underscore (_)!";
        }
        // Username 'Guest' dicadangkan sistem
        if (stripos($user, 'guest') !== false) {
            return "Username 'Guest' tidak dapat didaftarkan karena dicadangkan untuk sistem!";
        }
        return null;
    }
}
