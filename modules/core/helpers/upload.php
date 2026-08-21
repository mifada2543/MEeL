<?php

if (!function_exists('meel_upload_allowed_table')) {
function meel_upload_allowed_table(string $table): string
{
    return in_array($table, ['music', 'video'], true) ? $table : '';
}
}

if (!function_exists('get_hourly_upload_count')) {
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
}

/* Total seluruh upload user pada tabel tertentu. */
if (!function_exists('get_total_upload_count')) {
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
}

if (!function_exists('get_upload_hourly_limit')) {
function get_upload_hourly_limit(string $user_role): int
{
    require_once __DIR__ . '/../../auth/RateLimiter.php';
    return RateLimiter::getRoleLimit(2, $user_role);
}
}
