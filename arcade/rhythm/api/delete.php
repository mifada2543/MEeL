<?php
/**
 * MEeL!Mania — Delete Beatmap API
 * POST /arcade/rhythm/api/delete.php
 *
 * Fields:
 *   - song_id (required)
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed', 405);
}

$user_id = require_auth();
$user_role = get_user_role($conn, $user_id);
$is_admin = ($user_role === 'admin');

// CSRF check
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])
    || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    api_error('CSRF token tidak valid.');
}

$song_id = (int) ($_POST['song_id'] ?? 0);
if ($song_id <= 0) {
    api_error('Song ID tidak valid.');
}

// Fetch song
$stmt = $conn->prepare("SELECT id, user_id, audio_file, cover_file, beatmap_path FROM arcade_song WHERE id = ?");
$stmt->bind_param("i", $song_id);
$stmt->execute();
$song = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$song) {
    api_error('Beatmap tidak ditemukan.', 404);
}

// Authorization: only owner or admin
if (!$is_admin && (int)$song['user_id'] !== $user_id) {
    api_error('Tidak punya izin menghapus beatmap ini.', 403);
}

$conn->begin_transaction();
try {
    // Delete from DB
    $stmt = $conn->prepare("DELETE FROM arcade_song WHERE id = ?");
    $stmt->bind_param("i", $song_id);
    if (!$stmt->execute()) throw new RuntimeException('Gagal hapus dari database.');
    $stmt->close();

    // Delete files
    if (!empty($song['audio_file'])) {
        @unlink($AUDIO_DIR . $song['audio_file']);
    }
    if (!empty($song['cover_file'])) {
        @unlink($COVER_DIR . $song['cover_file']);
    }
    if (!empty($song['beatmap_path'])) {
        @unlink(__DIR__ . '/../' . $song['beatmap_path']);
    }

    $conn->commit();

    api_respond([
        'success' => true,
        'message' => 'Beatmap berhasil dihapus.',
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    api_error('Gagal menghapus beatmap: ' . $e->getMessage(), 500);
}
