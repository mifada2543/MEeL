<?php
// helpers/user.php — User Role & Usage Helpers
if (!function_exists('get_user_usage')) {
function get_user_usage(string $username): int|float
{
    // Base path storage Drive di-resolve terpusat lewat meel_drive_base_path()
    // (MEEL_HDD_DRIVE bila didefinisikan, fallback <root>/data_drive) supaya
    // konsisten dengan DriveStorage — lihat modules/core/helpers/storage.php.
    $path = meel_drive_base_path() . '/private_admins/' . $username;
    if (!is_dir($path)) return 0;

    return dir_size($path);
}
} // end function_exists('get_user_usage')

/* @param \mysqli $conn Koneksi database; @param int $user_id ID user; @return string Role user ('admin', 'member', 'user', 'guest') */
if (!function_exists('get_user_role')) {
function get_user_role(mysqli $conn, int $user_id): string
{
    // Level 1: Static cache per-request (paling cepat)
    static $cache = [];
    if (isset($cache[$user_id])) {
        return $cache[$user_id];
    }

    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $user_id && isset($_SESSION['role'])) {
        $role = $_SESSION['role'];
        $cache[$user_id] = $role;
        return $role;
    }

    // Level 3: Query database
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $role = $stmt->get_result()->fetch_assoc()['role'] ?? 'user';
    $stmt->close();

    $cache[$user_id] = $role;

    // Simpan ke session jika ini user yang sedang login
    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $user_id) {
        $_SESSION['role'] = $role;
    }

    return $role;
}
} // end function_exists('get_user_role')

/* Invalidate role cache di session — panggil saat role user berubah. */
if (!function_exists('invalidate_user_role_cache')) {
function invalidate_user_role_cache(): void
{
    unset($_SESSION['role']);
}
} // end function_exists('invalidate_user_role_cache')

/* Hapus akun guest non-aktif lalu reset AUTO_INCREMENT users (aksi admin & GC).
 * @param \mysqli $conn Koneksi database aktif
 * @return ?int Jumlah guest dihapus, atau null jika query gagal
 */
if (!function_exists('purge_guest_users')) {
function purge_guest_users(mysqli $conn): ?int
{
    $stmt = $conn->prepare("DELETE FROM users WHERE role = 'guest' AND is_active = 0");
    if (!$stmt) {
        return null;
    }
    $ok      = $stmt->execute();
    $deleted = $stmt->affected_rows; // baca sebelum close
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
} // end function_exists('purge_guest_users')
