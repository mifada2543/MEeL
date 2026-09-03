<?php
if (!function_exists('get_user_usage')) {
function get_user_usage(string $username): int|float
{
    $path = meel_drive_base_path() . '/private_admins/' . $username;
    if (!is_dir($path)) return 0;

    return dir_size($path);
}
}


if (!function_exists('get_user_role')) {
function get_user_role(mysqli $conn, int $user_id): string
{
    static $cache = [];
    if (isset($cache[$user_id])) {
        return $cache[$user_id];
    }

    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $user_id && isset($_SESSION['role'])) {
        $role = $_SESSION['role'];
        $cache[$user_id] = $role;
        return $role;
    }

    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $role = $stmt->get_result()->fetch_assoc()['role'] ?? 'user';
    $stmt->close();

    $cache[$user_id] = $role;

    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $user_id) {
        $_SESSION['role'] = $role;
    }

    return $role;
}
}


if (!function_exists('invalidate_user_role_cache')) {
function invalidate_user_role_cache(): void
{
    unset($_SESSION['role']);
}
}



if (!function_exists('purge_guest_users')) {
function purge_guest_users(mysqli $conn): ?int
{
    $stmt = $conn->prepare("DELETE FROM users WHERE role = 'guest' AND is_active = 0");
    if (!$stmt) {
        return null;
    }
    $ok      = $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();
    if (!$ok) {
        return null;
    }

    if ($deleted > 0) {
        $result = $conn->query("SELECT COALESCE(MAX(id), 0) + 1 AS new_ai FROM users");
        if ($result) {
            $row = $result->fetch_assoc();
            $conn->query("ALTER TABLE users AUTO_INCREMENT = " . (int)$row['new_ai']);
        }
    }
    return $deleted;
}
}
