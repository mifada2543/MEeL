<?php
/**
 * controllers/api/WatchController.php
 *
 * Action handlers untuk halaman watch video & music.
 * Mengekstrak semua business logic, query, dan data preparation
 * dari view (video/watch.php, music/watch.php) agar view tetap tipis (thin view).
 *
 * Endpoints:
 *   - VideoWatchController::class → video/watch.php
 *   - MusicWatchController::class → music/watch.php
 *
 * @package MEeL\Controllers
 */

require_once __DIR__ . '/../../modules/core/helpers.php';
require_once __DIR__ . '/../../modules/core/RateLimiter.php';
require_once __DIR__ . '/../../modules/media/MediaViewer.php';

// ════════════════════════════════════════════════════════════════
// VIDEO WATCH CONTROLLER
// ════════════════════════════════════════════════════════════════

class VideoWatchController
{
    private \mysqli $conn;
    private ?int $user_id;
    private int $id;
    private MediaViewer $viewer;
    private ?array $mediaData = null;

    public function __construct(\mysqli $conn, ?int $user_id, int $id)
    {
        $this->conn    = $conn;
        $this->user_id = $user_id;
        $this->id      = $id;
        $this->viewer  = new MediaViewer($conn, $user_id, 'video', $id);
    }

    /**
     * Catat view + handle comment POST.
     * Panggil sebelum output apapun.
     */
    public function handleRequest(): void
    {
        $this->viewer->recordView();

        if ($this->isLoggedIn() && isset($_POST['send'])) {
            if (!verify_csrf()) {
                die('CSRF Token tidak valid.');
            }

            // ⚡ RATE LIMIT: 10 comments per menit per user
            $rateKey  = 'user_' . ($this->user_id ?? 0);
            $rateRole = get_user_role($this->conn, $this->user_id ?? 0);
            $rateCheck = RateLimiter::check($rateKey, 'comment', $rateRole);
            if (!$rateCheck['allowed']) {
                $_SESSION['error'] = 'Terlalu banyak komentar. Coba lagi dalam ' . $rateCheck['retry_after'] . ' detik.';
                header("Location: watch.php?id={$this->id}#comment-section");
                exit;
            }

            if ($this->viewer->addComment($_POST)) {
                header("Location: watch.php?id={$this->id}#comment-section");
                exit;
            }
        }
    }

    /**
     * Kumpulkan semua data yang dibutuhkan view video.
     * @return array Semua variabel untuk template
     */
    public function getViewData(): array
    {
        $v = $this->viewer->getMediaData();

        $video_src = 'upload/' . $v['filename'];
        $is_hls    = (pathinfo($video_src, PATHINFO_EXTENSION) === 'm3u8');
        $video_dir = dirname($video_src);
        $vtt_src   = file_exists($video_dir . '/thumbnails.vtt')
            ? $video_dir . '/thumbnails.vtt'
            : '';

        $comments_data    = $this->viewer->getComments();
        $rekom            = $this->viewer->getRecommendations(15);

        return [
            'id'                => $this->id,
            'user_id'           => $this->user_id,
            'is_logged_in'      => $this->isLoggedIn(),
            'v'                 => $v,
            'video_src'         => $video_src,
            'is_hls'            => $is_hls,
            'vtt_src'           => $vtt_src,
            'user_interaction'  => $this->viewer->getUserInteraction(),
            'comments_grouped'  => $comments_data['grouped'],
            'user_map'          => $comments_data['user_map'],
            'rekom'             => $rekom,
        ];
    }

    public function isLoggedIn(): bool
    {
        return isset($this->user_id);
    }
}

// ════════════════════════════════════════════════════════════════
// MUSIC WATCH CONTROLLER
// ════════════════════════════════════════════════════════════════

class MusicWatchController
{
    private \mysqli $conn;
    private ?int $user_id;
    private int $id;
    private int $playlist_id;
    private MediaViewer $viewer;
    private ?array $mediaData = null;

    public function __construct(
        \mysqli $conn,
        ?int $user_id,
        int $id,
        int $playlist_id = 0
    ) {
        $this->conn         = $conn;
        $this->user_id      = $user_id;
        $this->id           = $id;
        $this->playlist_id  = $playlist_id;
        $this->viewer       = new MediaViewer($conn, $user_id, 'music', $id);
    }

    /**
     * Catat view + handle comment POST.
     */
    public function handleRequest(): void
    {
        $this->viewer->recordView();

        if ($this->isLoggedIn() && isset($_POST['send'])) {
            if (!verify_csrf()) {
                die('CSRF Token tidak valid.');
            }

            // ⚡ RATE LIMIT: 10 comments per menit per user
            $rateKey  = 'user_' . ($this->user_id ?? 0);
            $rateRole = get_user_role($this->conn, $this->user_id ?? 0);
            $rateCheck = RateLimiter::check($rateKey, 'comment', $rateRole);
            if (!$rateCheck['allowed']) {
                $_SESSION['error'] = 'Terlalu banyak komentar. Coba lagi dalam ' . $rateCheck['retry_after'] . ' detik.';
                header("Location: watch.php?id={$this->id}&playlist_id={$this->playlist_id}#comment-section");
                exit;
            }

            if ($this->viewer->addComment($_POST)) {
                header("Location: watch.php?id={$this->id}&playlist_id={$this->playlist_id}#comment-section");
                exit;
            }
        }
    }

    /**
     * Ambil media data, redirect jika tidak ditemukan.
     */
    public function requireMedia(): void
    {
        $v = $this->viewer->getMediaData();
        if (!$v) {
            header('Location: index.php');
            exit;
        }
        $this->mediaData = $v;
    }

    /**
     * Kumpulkan semua data yang dibutuhkan view music.
     * @return array Semua variabel untuk template
     */
    public function getViewData(): array
    {
        $this->requireMedia();
        $v = $this->mediaData;

        $comments_data = $this->viewer->getComments();
        $rekom         = $this->viewer->getRecommendations(15);
        $playlist_data = $this->viewer->getPlaylistQueue($this->playlist_id);
        $queue_query   = $playlist_data['queue'] ?? null;
        $next_url      = $playlist_data['next_url'] ?? '';
        $playlist_context = $this->playlist_id;

        // Compute next song URL
        $next_song_url = $next_url;
        if (empty($next_song_url) && $rekom && $rekom->num_rows > 0) {
            $rekom->data_seek(0);
            while ($rec = $rekom->fetch_assoc()) {
                if ((int)$rec['id'] !== $this->id) {
                    $next_song_url = "watch.php?id=" . $rec['id'];
                    break;
                }
            }
            $rekom->data_seek(0);
        }

        // Format detection (via centralized helpers)
        $ext       = strtolower(pathinfo($v['filename'], PATHINFO_EXTENSION));
        $fmt_label = get_audio_format_label($ext);
        $deskripsi = get_audio_format_description($ext);
        $mimeType  = get_audio_mime_type($ext);

        $preloadVal    = ($ext === 'flac') ? 'none' : 'metadata';
        $file_size_bytes = !empty($v['filename'])
            ? (@filesize(__DIR__ . '/../../music/upload/file/' . $v['filename']) ?: 0)
            : 0;

        return [
            'id'                => $this->id,
            'user_id'           => $this->user_id,
            'is_logged_in'      => $this->isLoggedIn(),
            'v'                 => $v,
            'playlist_id'       => $this->playlist_id,
            'playlist_context'  => $playlist_context,
            'user_interaction'  => $this->viewer->getUserInteraction(),
            'comments_grouped'  => $comments_data['grouped'],
            'user_map'          => $comments_data['user_map'],
            'rekom'             => $rekom,
            'queue_query'       => $queue_query,
            'next_song_url'     => $next_song_url,
            'file_size_bytes'   => $file_size_bytes,
            'fmt_label'         => $fmt_label,
            'deskripsi'         => $deskripsi,
            'mimeType'          => $mimeType,
            'preloadVal'        => $preloadVal,
        ];
    }

    public function isLoggedIn(): bool
    {
        return isset($this->user_id);
    }
}
