<?php
/**
 * MEeL — Auth Helpers (Shared)
 * ═══════════════════════════════════════════════════════════════
 * Fungsi bersama yang dipakai oleh auth/login.php & auth/register.php.
 * Diekstrak untuk menghilangkan duplikasi 5 blok logika identik:
 *
 *   1. auth_boot_session()           — bootstrap session + redirect jika sudah login
 *   2. auth_get_ip()                 — alamat IP klien
 *   3. auth_back_url()               — back_url dari HTTP_REFERER
 *   4. auth_ip_lockout_status()      — cek & bersihkan lockout IP (login_attempts)
 *   5. auth_record_failed_attempt()  — catat percobaan gagal ke IP + threshold lock
 *   6. auth_recheck_lockout()        — re-check lockout setelah POST
 *   7. auth_validate_credentials()   — validasi username/password (registrasi)
 *
 * ⚠️ Yang TIDAK dipindah ke sini (tetap di halaman masing-masing):
 *   - Session-based fail-counter login (login_fail_count / login_locked_until)
 *   - Session rate-limit registrasi (reg_attempts)
 *   - Verifikasi password (password_verify) & hashing (password_hash)
 *   - Alur MFA (login.php) dan INSERT user (register.php)
 *   Alasan: grep keamanan di tests/security_test.php mengecek pola-pola itu
 *   langsung di auth/login.php & auth/register.php.
 */

if (!function_exists('auth_boot_session')) {
/**
 * Bootstrap session: set cookie params + session_name('meel') + start.
 * Jika user sudah login, redirect ke index.php.
 * (Identik dengan blok awal login.php & register.php.)
 */
function auth_boot_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $timeout = 43200; // 12 jam
        session_set_cookie_params($timeout, "/");
        session_name('meel');
        session_start();
    }
    if (isset($_SESSION['user_id'])) {
        header("Location: ../index.php");
        exit;
    }
}
}

if (!function_exists('auth_get_ip')) {
/**
 * Alamat IP klien dengan fallback '0.0.0.0'.
 */
function auth_get_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
}

if (!function_exists('auth_back_url')) {
/**
 * Hitung $back_url dari HTTP_REFERER — HANYA jika referer berasal dari host
 * yang sama (perbandingan EXACT via parse_url, bukan substring) dan bukan
 * dari file yang ada di $exclude.
 *
 * Keamanan: validasi lama memakai strpos($ref, $host) yang bisa ditembus
 * dengan referer palsu seperti "https://evil.com/<host-asli>/apa-saja"
 * (substring match lolos padahal host aslinya beda → potensi open redirect
 * pada link "kembali" setelah login/register). Sekarang host dibandingkan
 * secara EXACT (strcasecmp) terhadap hasil parse_url(PHP_URL_HOST), dan
 * pengecekan $exclude dilakukan terhadap bagian PATH saja (bukan string
 * referer utuh) supaya konsisten aman.
 *
 * @param string[] $exclude Nama file yang tidak boleh menjadi back_url
 *                          (mis. login.php, register.php, revoked.php, banned.php)
 */
function auth_back_url(array $exclude = ['login.php', 'register.php']): string
{
    $back_url = '../index.php';

    if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
        $ref  = $_SERVER['HTTP_REFERER'];
        // HTTP_HOST bisa menyertakan port (mis. "localhost:8080") — strip port
        // agar bisa dibandingkan dengan PHP_URL_HOST (yang tanpa port).
        $host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), PHP_URL_HOST);

        $refHost = parse_url($ref, PHP_URL_HOST);

        if ($refHost !== null && $host !== null && strcasecmp($refHost, $host) === 0) {
            $refPath    = parse_url($ref, PHP_URL_PATH) ?? '';
            $isExcluded = false;
            foreach ($exclude as $file) {
                if (strpos($refPath, $file) !== false) {
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
/**
 * Cek status lockout IP di tabel login_attempts. Jika lockout sudah
 * expired, reset barisnya (DELETE). Dipanggil sebelum form processing.
 *
 * @return array{locked: bool, remaining: int}
 */
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
/**
 * Catat percobaan gagal ke IP (tabel login_attempts): upsert counter,
 * lalu jika melewati ambang batas, set locked_until.
 *
 * @return bool true jika lockout BARU diterapkan pada pemanggilan ini
 */
function auth_record_failed_attempt(mysqli $conn, string $ip, int $max_attempts, int $lockout_time): bool
{
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

    // Cek apakah IP sudah melebihi batas
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
/**
 * Re-check lockout IP setelah POST processing (kalau baru kena lock).
 *
 * @return array{locked: bool, remaining: int}
 */
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
/**
 * Validasi username & password untuk registrasi.
 *
 * @return string|null Pesan error, atau null jika valid
 */
function auth_validate_credentials(string $user, string $pass): ?string
{
    // 1. Validasi Panjang Karakter
    if (strlen($user) < 8 || strlen($pass) < 8) {
        return "Username min 8 karakter, Password min 8 karakter!";
    }
    // 2. Hanya huruf, angka, underscore
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $user)) {
        return "Username hanya boleh berisi huruf, angka, dan underscore (_)!";
    }
    // 3. Blacklist username 'Guest' (dicadangkan sistem)
    if (stripos($user, 'guest') !== false) {
        return "Username 'Guest' tidak dapat didaftarkan karena dicadangkan untuk sistem!";
    }
    return null;
}
}
