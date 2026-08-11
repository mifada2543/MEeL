<?php
// helpers/upload.php — Upload Quota & Limit Helpers
// Bagian dari pecahan modules/core/helpers.php.
// Dimuat oleh helpers/main.php.
// Semua fungsi dibungkus function_exists() guard sebagai
// defense-in-depth terhadap double-include.

/* Tabel media yang didukung untuk query quota upload.
 * Mengembalikan '' untuk input tak dikenal — pemanggil harus
 * memperlakukan '' sebagai hasil kosong, bukan fallback ke tabel lain,
 * agar typo (mis. 'musics') tidak diam-diam menampilkan data salah. */
if (!function_exists('meel_upload_allowed_table')) {
function meel_upload_allowed_table(string $table): string
{
    return in_array($table, ['music', 'video'], true) ? $table : '';
}
} // end function_exists('meel_upload_allowed_table')

/* Jumlah upload user dalam 1 jam terakhir — window sama dengan System::checkRateLimit(). */
if (!function_exists('get_hourly_upload_count')) {
/**
 * @param \mysqli $conn Koneksi database
 * @param int $user_id ID user
 * @param string $table Tabel media: 'music' atau 'video'
 * @return int Jumlah upload dalam 1 jam terakhir (0 jika tabel tak dikenal)
 */
function get_hourly_upload_count(\mysqli $conn, int $user_id, string $table): int
{
    $table = meel_upload_allowed_table($table);
    if ($table === '') return 0;
    $stmt  = $conn->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE user_id = ? AND upload_date > NOW() - INTERVAL 1 HOUR");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $count;
}
} // end function_exists('get_hourly_upload_count')

/* Total seluruh upload user pada tabel tertentu. */
if (!function_exists('get_total_upload_count')) {
/**
 * @param \mysqli $conn Koneksi database
 * @param int $user_id ID user
 * @param string $table Tabel media: 'music' atau 'video'
 * @return int Total seluruh upload user (0 jika tabel tak dikenal)
 */
function get_total_upload_count(\mysqli $conn, int $user_id, string $table): int
{
    $table = meel_upload_allowed_table($table);
    if ($table === '') return 0;
    $stmt  = $conn->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $count;
}
} // end function_exists('get_total_upload_count')

/* Limit upload per jam — konsisten dengan System::checkRateLimit() (member 2x lipat). */
if (!function_exists('get_upload_hourly_limit')) {
/**
 * @param string $user_role Role user ('admin', 'member', 'user', dll.)
 * @return int Limit upload per jam (2 untuk user, 4 untuk member)
 */
function get_upload_hourly_limit(string $user_role): int
{
    require_once __DIR__ . '/../RateLimiter.php';
    return RateLimiter::getRoleLimit(2, $user_role);
}
} // end function_exists('get_upload_hourly_limit')
