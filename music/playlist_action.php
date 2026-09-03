<?php
require_once '../modules/core/helpers.php';
meel_boot_session();
include '../auth/config.php';

function playlist_back_url(): string
{
    $back = 'beranda';
    if (isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER'] !== '') {
        $ref_host = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
        if ($ref_host === ($_SERVER['HTTP_HOST'] ?? '')) {
            $back = $_SERVER['HTTP_REFERER'];
        }
    }
    return $back;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login');
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    header('Location: ' . playlist_back_url());
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$action  = $_POST['action'] ?? '';

function redirect(string $url): never
{
    $allowed_prefixes = [
        'watch.php', 'view_playlist.php', 'index.php',
        'watch', 'playlist', 'beranda', 'music/',
    ];
    $safe = false;
    foreach ($allowed_prefixes as $prefix) {
        if (str_starts_with($url, $prefix)) {
            $safe = true;
            break;
        }
    }
    if (!$safe
        && str_starts_with($url, '/')
        && !str_starts_with($url, '//')
        && !str_contains($url, '://')) {
        $safe = true;
    }
    if (!$safe) {
        $url = 'beranda';
    }
    header("Location: $url");
    exit;
}

if ($action === 'create_playlist') {
    $name     = trim($_POST['playlist_name'] ?? '');
    $music_id = (int) ($_POST['music_id'] ?? 0);

    if ($name !== '') {
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
            error_log('playlist_action: ' . $e->getMessage());
            header('Location: ' . playlist_back_url());
            exit;
        }
    }
    redirect(base_url('/music/watch?id=' . (int)$music_id) . '&msg=playlist_created');
}

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
    redirect(base_url('/music/watch?id=' . (int)$music_id) . '&msg=added_to_playlist');
}

if ($action === 'remove_from_playlist') {
    $pivot_id    = (int) ($_POST['pivot_id']    ?? 0);
    $playlist_id = (int) ($_POST['playlist_id'] ?? 0);

    $stmt = $conn->prepare('DELETE FROM playlist_tracks WHERE id = ?');
    $stmt->bind_param('i', $pivot_id);
    $stmt->execute();
    $stmt->close();
    redirect(base_url('/music/playlist?id=' . (int)$playlist_id));
}

if ($action === 'delete_playlist') {
    $playlist_id = (int) ($_POST['playlist_id'] ?? 0);

    $conn->begin_transaction();
    try {
        $stmt_tracks = $conn->prepare('DELETE FROM playlist_tracks WHERE playlist_id = ?');
        $stmt_tracks->bind_param('i', $playlist_id);
        $stmt_tracks->execute();
        $stmt_tracks->close();

        
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
    redirect(base_url('/music/beranda?msg=playlist_deleted'));
}
