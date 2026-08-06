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
 * Struktur:
 *   AbstractWatchController — base class: state bersama (conn, user_id, id, viewer),
 *       handleRequest() (view + komentar + CSRF + rate limit), isLoggedIn(),
 *       dan baseViewData() untuk key data yang sama di semua halaman watch.
 *   VideoWatchController  — subclass video (subtitle detection, HLS, VTT thumbnail).
 *   MusicWatchController  — subclass music (playlist queue, format audio, next song).
 *
 * @package MEeL\Controllers
 */

require_once __DIR__ . '/../../modules/core/helpers.php';
require_once __DIR__ . '/../../modules/core/RateLimiter.php';
require_once __DIR__ . '/../../modules/media/MediaViewer.php';

// ════════════════════════════════════════════════════════════════
// ABSTRACT BASE: WATCH CONTROLLER
// ════════════════════════════════════════════════════════════════

abstract class AbstractWatchController
{
    protected \mysqli $conn;
    protected ?int $user_id;
    protected int $id;
    protected MediaViewer $viewer;

    public function __construct(
        \mysqli $conn,
        ?int $user_id,
        int $id,
        string $media_type
    ) {
        $this->conn    = $conn;
        $this->user_id = $user_id;
        $this->id      = $id;
        $this->viewer  = new MediaViewer($conn, $user_id, $media_type, $id);
    }

    /**
     * Catat view + handle comment POST.
     * Panggil sebelum output apapun.
     */
    public function handleRequest(): void
    {
        $this->viewer->recordView();

        if ($this->isLoggedIn() && isset($_POST['send'])) {
            if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
                die('CSRF Token tidak valid.');
            }

            // RATE LIMIT: 10 comments per menit per user
            $rateKey  = 'user_' . ($this->user_id ?? 0);
            $rateRole = get_user_role($this->conn, $this->user_id ?? 0);
            $rateCheck = RateLimiter::check($rateKey, 'comment', $rateRole);
            if (!$rateCheck['allowed']) {
                $_SESSION['error'] = 'Terlalu banyak komentar. Coba lagi dalam ' . $rateCheck['retry_after'] . ' detik.';
                header('Location: ' . $this->commentRedirectUrl());
                exit;
            }

            if ($this->viewer->addComment($_POST)) {
                header('Location: ' . $this->commentRedirectUrl());
                exit;
            }
        }
    }

    /**
     * URL redirect setelah komentar POST.
     * Subclass boleh override untuk menambah parameter (mis. playlist_id).
     */
    protected function commentRedirectUrl(): string
    {
        return "watch.php?id={$this->id}#comment-section";
    }

    public function isLoggedIn(): bool
    {
        return isset($this->user_id);
    }

    /**
     * Key data yang sama di semua halaman watch.
     * @param mixed $rekom Result rekomendasi yang sudah di-fetch (opsional,
     *                     untuk menghindari query ganda); null = fetch di sini.
     */
    protected function baseViewData(array $v, $rekom = null): array
    {
        // Guest tidak melihat komentar — skip query berat (full scan comments)
        $comments_data = $this->isLoggedIn()
            ? $this->viewer->getComments()
            : ['grouped' => [], 'user_map' => []];

        return [
            'id'               => $this->id,
            'user_id'          => $this->user_id,
            'is_logged_in'     => $this->isLoggedIn(),
            'v'                => $v,
            'user_interaction' => $this->viewer->getUserInteraction(),
            'comments_grouped' => $comments_data['grouped'],
            'user_map'         => $comments_data['user_map'],
            'rekom'            => $rekom ?? $this->viewer->getRecommendations(15),
        ];
    }
}

// ════════════════════════════════════════════════════════════════
// VIDEO WATCH CONTROLLER
// ════════════════════════════════════════════════════════════════

class VideoWatchController extends AbstractWatchController
{
    private ?array $mediaData = null;

    public function __construct(\mysqli $conn, ?int $user_id, int $id)
    {
        parent::__construct($conn, $user_id, $id, 'video');
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
     * Kumpulkan semua data yang dibutuhkan view video.
     * @return array Semua variabel untuk template
     */
    public function getViewData(): array
    {
        $this->requireMedia();
        $v = $this->mediaData;

        $video_src = 'upload/' . $v['filename'];
        $is_hls    = (pathinfo($video_src, PATHINFO_EXTENSION) === 'm3u8');
        $video_dir = dirname($video_src);
        $vtt_src   = file_exists($video_dir . '/thumbnails.vtt')
            ? $video_dir . '/thumbnails.vtt'
            : '';

        // ── Subtitle: deteksi semua file .vtt di folder video ──────────────
        // Konvensi penamaan: {folder}.{lang}.vtt (mis. video-folder.id.vtt)
        // thumbnails.vtt di-exclude karena itu untuk preview thumbnail.
        $subtitles = [];
        foreach (glob($video_dir . '/*.vtt') ?: [] as $sub_file) {
            $sub_base = basename($sub_file);
            if ($sub_base === 'thumbnails.vtt') continue;

            // Ekstrak kode bahasa dari pola {folder}.{lang}.vtt
            $lang = 'und';
            if (preg_match('/\.([a-z]{2,3}(?:-[a-z]{2,8})?)\.vtt$/i', $sub_base, $m)) {
                $lang = strtolower($m[1]);
            }

            $subtitles[] = [
                'src'   => $video_dir . '/' . $sub_base,
                'lang'  => $lang,
                'label' => subtitle_lang_label($lang),
            ];
        }

        // Urutkan: 'id' (default) di depan, lalu alfabetis
        usort($subtitles, function ($a, $b) {
            if ($a['lang'] === 'id') return -1;
            if ($b['lang'] === 'id') return 1;
            return strcmp($a['lang'], $b['lang']);
        });

        return array_merge($this->baseViewData($v), [
            'video_src' => $video_src,
            'is_hls'    => $is_hls,
            'vtt_src'   => $vtt_src,
            'subtitles' => $subtitles,
        ]);
    }
}

// ════════════════════════════════════════════════════════════════
// MUSIC WATCH CONTROLLER
// ════════════════════════════════════════════════════════════════

class MusicWatchController extends AbstractWatchController
{
    private int $playlist_id;
    private ?array $mediaData = null;

    public function __construct(
        \mysqli $conn,
        ?int $user_id,
        int $id,
        int $playlist_id = 0
    ) {
        parent::__construct($conn, $user_id, $id, 'music');
        $this->playlist_id = $playlist_id;
    }

    protected function commentRedirectUrl(): string
    {
        return "watch.php?id={$this->id}&playlist_id={$this->playlist_id}#comment-section";
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

        $playlist_data    = $this->viewer->getPlaylistQueue($this->playlist_id);
        $queue_query      = $playlist_data['queue'] ?? null;
        $next_url         = $playlist_data['next_url'] ?? '';
        $playlist_context = $this->playlist_id;

        $rekom = $this->viewer->getRecommendations(15);

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

        $preloadVal       = ($ext === 'flac') ? 'none' : 'metadata';
        $file_size_bytes  = !empty($v['filename'])
            ? (@filesize(__DIR__ . '/../../music/upload/file/' . $v['filename']) ?: 0)
            : 0;

        return array_merge($this->baseViewData($v, $rekom), [
            'playlist_id'      => $this->playlist_id,
            'playlist_context' => $playlist_context,
            'queue_query'      => $queue_query,
            'next_song_url'    => $next_song_url,
            'file_size_bytes'  => $file_size_bytes,
            'fmt_label'        => $fmt_label,
            'deskripsi'        => $deskripsi,
            'mimeType'         => $mimeType,
            'preloadVal'       => $preloadVal,
        ]);
    }
}
