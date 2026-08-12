<?php
require_once '../modules/core/helpers.php';
meel_boot_session();
include '../auth/config.php';

if (!isset($_SESSION['user_id'])) die('Silakan login terlebih dahulu.');

// Verifikasi token untuk semua aksi playlist
if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    die('CSRF Token tidak valid.');
}

$user_id = (int) $_SESSION['user_id'];
$action  = $_POST['action'] ?? '';

function redirect(string $url): never
{
    // Validasi redirect — hanya izinkan URL relatif atau internal
    $allowed_prefixes = ['watch.php', 'view_playlist.php', 'index.php'];
    $safe = false;
    foreach ($allowed_prefixes as $prefix) {
        if (str_starts_with($url, $prefix)) {
            $safe = true;
            break;
        }
    }
    if (!$safe) {
        $url = 'index.php';
    }
    header("Location: $url");
    exit;
}

// ─── 1. BUAT PLAYLIST BARU ───
if ($action === 'create_playlist') {
    $name     = trim($_POST['playlist_name'] ?? '');
    $music_id = (int) ($_POST['music_id'] ?? 0);

    if ($name !== '') {
        // TRANSACTION: Atomic playlist + track creation
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('INSERT INTO playlists (user_id, name) VALUES (?, ?)');
            $stmt->bind_param('is', $user_id, $name);
            if (!$stmt->execute()) {
                throw new \RuntimeException('Gagal membuat playlist: ' . $stmt->error);
            }
            $new_playlist_id = $stmt->insert_id;
            $stmt->close();

            if ($music_id > 0) {
                $stmt2 = $conn->prepare('INSERT INTO playlist_tracks (playlist_id, music_id) VALUES (?, ?)');
                $stmt2->bind_param('ii', $new_playlist_id, $music_id);
                if (!$stmt2->execute()) {
                    throw new \RuntimeException('Gagal menambahkan track: ' . $stmt2->error);
                }
                $stmt2->close();
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            die('Error: ' . $e->getMessage());
        }
    }
    redirect("watch.php?id=$music_id&msg=playlist_created");
}

// ─── 2. TAMBAH LAGU KE PLAYLIST ───
if ($action === 'add_to_playlist') {
    $playlist_id = (int) ($_POST['playlist_id'] ?? 0);
    $music_id    = (int) ($_POST['music_id']    ?? 0);

    $conn->begin_transaction();
    try {
        $check = $conn->prepare('SELECT id FROM playlist_tracks WHERE playlist_id = ? AND music_id = ?');
        $check->bind_param('ii', $playlist_id, $music_id);
        $check->execute();
        $exists = (int) $check->get_result()->num_rows;
        $check->close();

        if (!$exists) {
            $stmt = $conn->prepare('INSERT INTO playlist_tracks (playlist_id, music_id) VALUES (?, ?)');
            $stmt->bind_param('ii', $playlist_id, $music_id);
            if (!$stmt->execute()) {
                throw new \RuntimeException('Gagal menambahkan ke playlist: ' . $stmt->error);
            }
            $stmt->close();
        }

        $conn->commit();
    } catch (\Throwable $e) {
        $conn->rollback();
        die('Error: ' . $e->getMessage());
    }
    redirect("watch.php?id=$music_id&msg=added_to_playlist");
}

// ─── 3. HAPUS LAGU DARI PLAYLIST ───
if ($action === 'remove_from_playlist') {
    $pivot_id    = (int) ($_POST['pivot_id']    ?? 0);
    $playlist_id = (int) ($_POST['playlist_id'] ?? 0);

    $stmt = $conn->prepare('DELETE FROM playlist_tracks WHERE id = ?');
    $stmt->bind_param('i', $pivot_id);
    $stmt->execute();
    $stmt->close();
    redirect("view_playlist.php?id=$playlist_id");
}

// ─── 4. HAPUS TOTAL PLAYLIST ───
if ($action === 'delete_playlist') {
    $playlist_id = (int) ($_POST['playlist_id'] ?? 0);

    // TRANSACTION: Hapus playlist beserta semua track di dalamnya
    $conn->begin_transaction();
    try {
        $stmt_tracks = $conn->prepare('DELETE FROM playlist_tracks WHERE playlist_id = ?');
        $stmt_tracks->bind_param('i', $playlist_id);
        $stmt_tracks->execute();
        $stmt_tracks->close();

        // Hapus playlist (hanya milik user yang bersangkutan)
        $stmt = $conn->prepare('DELETE FROM playlists WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $playlist_id, $user_id);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Gagal menghapus playlist: ' . $stmt->error);
        }
        $stmt->close();

        $conn->commit();
    } catch (\Throwable $e) {
        $conn->rollback();
        die('Error: ' . $e->getMessage());
    }
    redirect('index.php?msg=playlist_deleted');
}
