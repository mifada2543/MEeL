<?php

if (!function_exists('get_site_setting')) {
function get_site_setting(\mysqli $conn, string $key, string $default = ''): string
{
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];

    $stmt = $conn->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    $value = $row ? $row['setting_value'] : $default;
    $cache[$key] = $value;
    return $value;
}
}

if (!function_exists('set_site_setting')) {
function set_site_setting(\mysqli $conn, string $key, string $value): bool
{
    $stmt = $conn->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->bind_param("sss", $key, $value, $value);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}
}

if (!function_exists('get_site_settings_batch')) {
function get_site_settings_batch(\mysqli $conn, array $keys): array
{
    $results = [];
    foreach ($keys as $key => $default) {
        $results[$key] = get_site_setting($conn, $key, $default);
    }
    return $results;
}
}
