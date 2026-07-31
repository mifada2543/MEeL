<?php

// ════════════════════════════════════════════════════════════════
// helpers/user.php — User Role & Usage Helpers
//
// Bagian dari pecahan modules/core/helpers.php.
// Dimuat oleh helpers/main.php.
//
// Semua fungsi dibungkus function_exists() guard sebagai
// defense-in-depth terhadap double-include.
// ════════════════════════════════════════════════════════════════

if (!function_exists('get_user_usage')) {
function get_user_usage(string $username): int|float
{
    // Catatan: file ini berada di modules/core/helpers/, sehingga
    // dibutuhkan dirname(__DIR__, 3) untuk mencapai root proyek.
    $path = dirname(__DIR__, 3) . "/data_drive/private_admins/" . $username;
    if (!is_dir($path)) return 0;

    // Delegasikan ke dir_size() yang sudah memiliki cache + fallback
    return dir_size($path);
}
} // end function_exists('get_user_usage')

/**
 * Get user role dengan cache session + static cache per request.
 * Prioritas:
 *   1. Static cache (per-request, tercepat)
 *   2. $_SESSION['role'] (lintas request, mengurangi query DB)
 *   3. Query DB (jika belum ada di cache)
 *
 * Setelah role diambil dari DB, simpan ke session agar request
 * berikutnya tidak perlu query ulang.
 *
 * @param \mysqli $conn   Koneksi database
 * @param int     $user_id ID user
 * @return string Role user ('admin', 'member', 'user', 'guest')
 */
if (!function_exists('get_user_role')) {
function get_user_role(mysqli $conn, int $user_id): string
{
    // Level 1: Static cache per-request (paling cepat)
    static $cache = [];
    if (isset($cache[$user_id])) {
        return $cache[$user_id];
    }

    // Level 2: Session cache (lintas request, cegah query tiap halaman)
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

    // Simpan ke cache
    $cache[$user_id] = $role;

    // Simpan ke session jika ini user yang sedang login
    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $user_id) {
        $_SESSION['role'] = $role;
    }

    return $role;
}
} // end function_exists('get_user_role')

/**
 * Invalidate role cache di session — panggil saat role user berubah.
 */
if (!function_exists('invalidate_user_role_cache')) {
function invalidate_user_role_cache(): void
{
    unset($_SESSION['role']);
}
} // end function_exists('invalidate_user_role_cache')
