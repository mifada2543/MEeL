<?php
// ─── Cegah akses langsung ───
if (!defined('MEEL_MANAGE_ACCESS')) {
    header('HTTP/1.0 403 Forbidden');
    exit('Direct access not allowed.');
}

// ─── Hapus Video ───
function handleDeleteVideo(int $id, int $user_id, mysqli $conn): array
{
        $stmt = $conn->prepare("SELECT filename, thumbnail, user_id FROM video WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $video = $stmt->get_result()->fetch_assoc();

    if (!$video) {
        return ['success' => false, 'message' => 'Video tidak ditemukan.'];
    }

    if ((int)$video['user_id'] !== $user_id) {
        return ['success' => false, 'message' => 'Anda tidak memiliki akses ke video ini.'];
    }

    $pending = [
        'timestamp' => time(),
        'files'     => []
    ];

    // File video (HLS folder atau file mp4 langsung) — di storage terpusat
    $video_base = meel_media_base_path('video') . '/video/';
    $video_file = $video['filename'];
    $video_path = $video_base . $video_file;
    if (file_exists($video_path)) {
        $pending['files'][] = $video_path;
    }

    // Cek kemungkinan folder HLS (nama file tanpa ekstensi)
    $hls_dir = $video_base . pathinfo($video_file, PATHINFO_FILENAME);
    if (is_dir($hls_dir)) {
        $pending['files'][] = $hls_dir;
    }

    if (!empty($video['thumbnail'])) {
        $thumb_path = meel_media_base_path('video') . '/thumbnail/' . $video['thumbnail'];
        if (file_exists($thumb_path)) {
            $pending['files'][] = $thumb_path;
        }
    }

    $stmt_del = $conn->prepare("DELETE FROM video WHERE id = ? AND user_id = ?");
    $stmt_del->bind_param("ii", $id, $user_id);
    if (!$stmt_del->execute()) {
        return ['success' => false, 'message' => 'Gagal menghapus dari database.'];
    }

    if (!empty($pending['files'])) {
        savePendingDeletions($pending);
    }

    include_once __DIR__ . '/../../modules/core/activity_logger.php';
    logActivity($conn, $user_id, 'delete', 'video', $id);

    return ['success' => true, 'message' => 'Video dihapus. File akan dibersihkan otomatis.'];
}

// ─── Hapus Music ───
function handleDeleteMusic(int $id, int $user_id, mysqli $conn): array
{
        $stmt = $conn->prepare("SELECT filename, thumbnail, user_id FROM music WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $music = $stmt->get_result()->fetch_assoc();

    if (!$music) {
        return ['success' => false, 'message' => 'Musik tidak ditemukan.'];
    }

    if ((int)$music['user_id'] !== $user_id) {
        return ['success' => false, 'message' => 'Anda tidak memiliki akses ke musik ini.'];
    }

    $pending = [
        'timestamp' => time(),
        'files'     => []
    ];

    $audio_path = meel_media_base_path('music') . '/file/' . $music['filename'];
    if (file_exists($audio_path)) {
        $pending['files'][] = $audio_path;
    }

    if (!empty($music['thumbnail'])) {
        $thumb_path = meel_media_base_path('music') . '/thumbnail/' . $music['thumbnail'];
        if (file_exists($thumb_path)) {
            $pending['files'][] = $thumb_path;
        }
    }

    $stmt_del = $conn->prepare("DELETE FROM music WHERE id = ? AND user_id = ?");
    $stmt_del->bind_param("ii", $id, $user_id);
    if (!$stmt_del->execute()) {
        return ['success' => false, 'message' => 'Gagal menghapus dari database.'];
    }

    if (!empty($pending['files'])) {
        savePendingDeletions($pending);
    }

    include_once __DIR__ . '/../../modules/core/activity_logger.php';
    logActivity($conn, $user_id, 'delete', 'music', $id);

    return ['success' => true, 'message' => 'Musik dihapus. File akan dibersihkan otomatis.'];
}

// ─── Simpan daftar file yang akan dihapus nanti ───
function savePendingDeletions(array $pending): void
{
    $file = __DIR__ . '/../../temp/pending_delete.json';
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $existing = [];
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        if ($content) {
            $existing = json_decode($content, true) ?? [];
        }
    }

    $existing[] = $pending;

    // Batasi maksimal 1000 entry agar file tidak membesar
    if (count($existing) > 1000) {
        $existing = array_slice($existing, -1000);
    }

    @file_put_contents($file, json_encode($existing, JSON_PRETTY_PRINT), LOCK_EX);
}

// ─── Bersihkan file yang sudah >30 menit sejak dihapus ───
function cleanupPendingDeletions(): int
{
    $file = __DIR__ . '/../../temp/pending_delete.json';
    if (!file_exists($file)) return 0;

    $content = @file_get_contents($file);
    if (!$content) return 0;

    $items = json_decode($content, true);
    if (empty($items)) return 0;

    $cutoff = time() - 1800; // 30 menit
    $remaining = [];
    $cleaned = 0;

    foreach ($items as $item) {
        $timestamp = $item['timestamp'] ?? 0;
        $files = $item['files'] ?? [];

        if ($timestamp <= $cutoff) {
            foreach ($files as $path) {
                if (is_dir($path)) {
                    removeDirectoryRecursive($path);
                } elseif (file_exists($path)) {
                    @unlink($path);
                }
            }
            $cleaned++;
        } else {
            $remaining[] = $item;
        }
    }

    if (!empty($remaining)) {
        @file_put_contents($file, json_encode($remaining, JSON_PRETTY_PRINT), LOCK_EX);
    } else {
        @unlink($file);
    }

    return $cleaned;
}

function removeDirectoryRecursive(string $dir): void
{
    if (!is_dir($dir)) return;
    $items = glob(rtrim($dir, '/') . '/*');
    if ($items) {
        foreach ($items as $item) {
            is_dir($item) ? removeDirectoryRecursive($item) : @unlink($item);
        }
    }
    @rmdir($dir);
}

function logActivity(mysqli $conn, int $user_id, string $action, string $media_type, int $media_id): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, media_type, media_id, ip_address) VALUES (?, ?, ?, ?, ?)");

    if ($stmt === false) {
        // Gagal prepare — log ke error log saja, jangan crash
        error_log('[MEeL] logActivity gagal: ' . $conn->error);
        return;
    }

    $stmt->bind_param("issis", $user_id, $action, $media_type, $media_id, $ip);
    $stmt->execute();
    $stmt->close();
}
