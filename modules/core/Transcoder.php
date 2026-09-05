<?php
if (!defined('MEEL_HDD_BASE')) {
    define('MEEL_HDD_BASE', '/path/to/your/media');
    define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
    define('MEEL_HDD_VIDEO_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'video/');
    define('MEEL_HDD_THUMB_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'thumbnail/');
    define('MEEL_HDD_MUSIC_UPLOAD', MEEL_HDD_BASE . '/music/upload/');
    define('MEEL_HDD_BOOKS_UPLOAD', MEEL_HDD_BASE . '/books/upload/');
    define('MEEL_HDD_DRIVE',        MEEL_HDD_BASE . '/drive/');
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/japanese.php';
require_once __DIR__ . '/GarbageCollector.php';
require_once __DIR__ . '/../transcoder/FfmpegUtils.php';
require_once __DIR__ . '/../exceptions/ProcessException.php';
require_once __DIR__ . '/../exceptions/DownloadException.php';
require_once __DIR__ . '/../exceptions/TranscodeException.php';
require_once __DIR__ . '/ProgressObserver.php';
require_once __DIR__ . '/../auth/SsrfGuard.php';
require_once __DIR__ . '/../auth/ValidatingProxy.php';
require_once __DIR__ . '/TranscoderBase.php';
require_once __DIR__ . '/../transcoder/DownloadService.php';
require_once __DIR__ . '/../transcoder/EncodeService.php';
require_once __DIR__ . '/../transcoder/TranscodeService.php';

/**
 * Transcoder — facade/orchestrator untuk pipeline media.
 *
 * File ini sengaja dijadikan tipis. Implementasi per-responsibility dipisah
 * ke service class agar tiap class punya satu alasan berubah:
 *
 *   modules/transcoder/DownloadService.php   — download URL (yt-dlp) + finalisasi
 *   modules/transcoder/EncodeService.php     — encode audio mentah → .ogg (ffmpeg)
 *   modules/transcoder/TranscodeService.php  — transcode video HLS → mp3/ogg/m4a
 *
 * State bersama (koneksi DB, user, child-process tracking, temp path, PID file)
 * hidup di TranscoderBase yang di-extend oleh facade dan tiap service. Facade
 * tetap menyediakan API yang sama seperti sebelum refactor sehingga caller
 * existing (upload_advanced.php, transcode.php, controllers/api/*) tidak
 * berubah sama sekali.
 */
class Transcoder extends TranscoderBase
{
    private ?DownloadService  $downloadService  = null;
    private ?EncodeService    $encodeService    = null;
    private ?TranscodeService $transcodeService = null;

    public function __construct(
        \mysqli $db_connection,
        int $session_user_id,
        callable|ProgressObserver|null $progressListener = null
    ) {
        parent::__construct($db_connection, $session_user_id, $progressListener);
    }

    public function setProgressListener(callable|ProgressObserver|null $listener): void
    {
        parent::setProgressListener($listener);
        // Selaraskan observer agar service yang sudah dibuat ikut menerima.
        foreach ([$this->downloadService, $this->encodeService, $this->transcodeService] as $svc) {
            $svc?->setProgressListener($listener);
        }
    }

    public function terminateAllProcesses(): void
    {
        // Terminate proses milik service (download/encode/transcode) dulu,
        // lalu proses yang tercatat langsung di instance ini.
        foreach ([$this->downloadService, $this->encodeService, $this->transcodeService] as $svc) {
            $svc?->terminateAllProcesses();
        }
        parent::terminateAllProcesses();
    }

    // ------------------------------------------------------------------
    // Delegasi — API lama tetap tersedia di facade.
    // ------------------------------------------------------------------

    public function processDownload(string $url, string $type): string
    {
        if ($this->downloadService === null) {
            $this->downloadService = new DownloadService($this->conn, $this->user_id);
            $this->downloadService->setProgressListener($this->getProgressObserver());
        }
        return $this->downloadService->processDownload($url, $type);
    }

    public function encodeMusic(
        string $temp_file,
        string $title,
        string $artist,
        string $album,
        int    $duration,
        string $description = 'Upload by MEeL Engine'
    ): array {
        if ($this->encodeService === null) {
            $this->encodeService = new EncodeService($this->conn, $this->user_id);
            $this->encodeService->setProgressListener($this->getProgressObserver());
        }
        return $this->encodeService->encodeMusic(
            $temp_file,
            $title,
            $artist,
            $album,
            $duration,
            $description
        );
    }

    public function transcodeVideo(int $video_id, string $format = 'mp3'): array
    {
        if ($this->transcodeService === null) {
            $this->transcodeService = new TranscodeService($this->conn, $this->user_id);
            $this->transcodeService->setProgressListener($this->getProgressObserver());
        }
        return $this->transcodeService->transcodeVideo($video_id, $format);
    }

    /**
     * Cek kepemilikan output transcode di sesi aktif user (lihat
     * TranscodeService::ownsTranscodeFile() — dipakai download_transcode.php).
     */
    public static function ownsTranscodeFile(string $outputFilename): bool
    {
        return TranscodeService::ownsTranscodeFile($outputFilename);
    }
}
