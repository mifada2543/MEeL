<?php
// helpers/mfa.php — MFA / TOTP Helpers
if (!function_exists('base32_decode')) {
/* @param string $input Base32-encoded string; @return string Decoded binary string */
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
}

if (!function_exists('generate_mfa_secret')) {
/* @return string Base32 secret key */
function generate_mfa_secret(): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 32; $i++) {
        $secret .= $chars[random_int(0, 31)];
    }
    return $secret;
}
}

if (!function_exists('generate_totp')) {
/* @param string $secret Base32 secret key; @param int|null $time_slice Unix timestamp / 30 (null = waktu sekarang); @return string Kode 6-digit */
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
}

if (!function_exists('verify_totp')) {
/* @param string $secret Base32 secret key; @param string $code Kode 6-digit yang dimasukkan user; @return bool True jika valid */
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
}

if (!function_exists('generate_otpauth_url')) {
/* @param string $secret Base32 secret; @param string $username Username pengguna; @return string OTP Auth URL */
function generate_otpauth_url(string $secret, string $username): string
{
    $issuer = 'MEeL';
    $label = rawurlencode("$issuer:$username");
    return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}";
}
}

if (!function_exists('generate_backup_codes')) {
/* @return array ['plain' => string[], 'hashed' => string[]] */
function generate_backup_codes(): array
{
    $plain = [];
    $hashed = [];
    for ($i = 0; $i < 8; $i++) {

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $plain[] = $code;
        $hashed[] = password_hash($code, PASSWORD_DEFAULT);
    }
    return ['plain' => $plain, 'hashed' => $hashed];
}
}

if (!function_exists('verify_backup_code')) {
/**
 * @param string $hashed_json JSON array of hashed backup codes dari DB
 * @param string $input Input user
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
            array_splice($codes, $i, 1);
            return ['valid' => true, 'remaining' => $codes];
        }
    }
    return ['valid' => false, 'remaining' => null];
}
}
