<?php

// ════════════════════════════════════════════════════════════════
// helpers/mfa.php — MFA / TOTP Helpers
//
// Bagian dari pecahan modules/core/helpers.php.
// Dimuat oleh helpers/main.php.
//
// Semua fungsi dibungkus function_exists() guard sebagai
// defense-in-depth terhadap double-include.
// ════════════════════════════════════════════════════════════════

if (!function_exists('base32_decode')) {
/**
 * Decode base32 string (RFC 4648) ke binary.
 *
 * @param string $input Base32-encoded string
 * @return string Decoded binary string
 */
function base32_decode(string $input): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper($input);
    $output = '';
    $buffer = 0;
    $bits_left = 0;

    for ($i = 0; $i < strlen($input); $i++) {
        $ch = $input[$i];
        if ($ch === '=') break;
        $pos = strpos($alphabet, $ch);
        if ($pos === false) continue;

        $buffer = ($buffer << 5) | $pos;
        $bits_left += 5;
        if ($bits_left >= 8) {
            $bits_left -= 8;
            $output .= chr(($buffer >> $bits_left) & 0xff);
        }
    }
    return $output;
}
} // end function_exists('base32_decode')

if (!function_exists('generate_mfa_secret')) {
/**
 * Generate random base32 secret untuk TOTP (32 karakter).
 *
 * @return string Base32 secret key
 */
function generate_mfa_secret(): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 32; $i++) {
        $secret .= $chars[random_int(0, 31)];
    }
    return $secret;
}
} // end function_exists('generate_mfa_secret')

if (!function_exists('generate_totp')) {
/**
 * Generate kode TOTP 6-digit (RFC 6238) untuk secret & waktu tertentu.
 *
 * @param string   $secret     Base32 secret key
 * @param int|null $time_slice Unix timestamp / 30 (null = waktu sekarang)
 * @return string Kode 6-digit
 */
function generate_totp(string $secret, ?int $time_slice = null): string
{
    if ($time_slice === null) {
        $time_slice = (int)floor(time() / 30);
    }

    $secret_bin = base32_decode($secret);
    $time_bytes = pack('J', $time_slice);
    $hmac = hash_hmac('sha1', $time_bytes, $secret_bin, true);

    $offset = ord($hmac[19]) & 0x0f;
    $code = (ord($hmac[$offset]) & 0x7f) << 24
          | (ord($hmac[$offset + 1]) & 0xff) << 16
          | (ord($hmac[$offset + 2]) & 0xff) << 8
          | (ord($hmac[$offset + 3]) & 0xff);

    return str_pad((string)($code % 1000000), 6, '0', STR_PAD_LEFT);
}
} // end function_exists('generate_totp')

if (!function_exists('verify_totp')) {
/**
 * Verifikasi kode TOTP dengan toleransi window ±1 interval (total 3 kemungkinan).
 *
 * @param string $secret Base32 secret key
 * @param string $code   Kode 6-digit yang dimasukkan user
 * @return bool True jika valid
 */
function verify_totp(string $secret, string $code): bool
{
    $time_slice = (int)floor(time() / 30);
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(generate_totp($secret, $time_slice + $i), $code)) {
            return true;
        }
    }
    return false;
}
} // end function_exists('verify_totp')

if (!function_exists('generate_otpauth_url')) {
/**
 * Generate otpauth:// URL untuk ditampilkan sebagai QR code.
 *
 * @param string $secret   Base32 secret
 * @param string $username Username pengguna
 * @return string OTP Auth URL
 */
function generate_otpauth_url(string $secret, string $username): string
{
    $issuer = 'MEeL';
    $label = rawurlencode("$issuer:$username");
    return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}";
}
} // end function_exists('generate_otpauth_url')

if (!function_exists('generate_backup_codes')) {
/**
 * Generate 8 backup codes (masing-masing 8 digit angka) dan return
 * array dengan codes plain text + hashed.
 *
 * @return array ['plain' => string[], 'hashed' => string[]]
 */
function generate_backup_codes(): array
{
    $plain = [];
    $hashed = [];
    for ($i = 0; $i < 8; $i++) {
        // 6 digit angka acak — kompatibel dengan input field maxlength=6 di halaman verifikasi
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $plain[] = $code;
        $hashed[] = password_hash($code, PASSWORD_DEFAULT);
    }
    return ['plain' => $plain, 'hashed' => $hashed];
}
} // end function_exists('generate_backup_codes')

if (!function_exists('verify_backup_code')) {
/**
 * Verifikasi backup code, dan return array sisa kode jika valid.
 *
 * @param string $hashed_json JSON array of hashed backup codes dari DB
 * @param string $input       Input user
 * @return array ['valid' => bool, 'remaining' => string[]|null]
 */
function verify_backup_code(string $hashed_json, string $input): array
{
    $codes = json_decode($hashed_json, true);
    if (!is_array($codes)) {
        return ['valid' => false, 'remaining' => null];
    }

    foreach ($codes as $i => $hash) {
        if (password_verify($input, $hash)) {
            // Hapus kode yang sudah dipakai
            array_splice($codes, $i, 1);
            return ['valid' => true, 'remaining' => $codes];
        }
    }
    return ['valid' => false, 'remaining' => null];
}
} // end function_exists('verify_backup_code')
