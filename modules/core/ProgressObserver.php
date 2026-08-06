<?php
/**
 * File: modules/core/ProgressObserver.php
 *
 * Kontrak observer untuk melaporkan progress pemrosesan media dari
 * lapisan bisnis (Transcoder) ke lapisan presentasi (browser/CLI/API).
 *
 * Decoupling ini memastikan Transcoder TIDAK lagi menulis output HTML/JS
 * secara langsung (`echo "<script>meelPhase(...)</script>"; flush();`),
 * sehingga class dapat dieksekusi bersih dari:
 *   - CLI scripts / cron jobs  → observer null, tanpa polusi output buffer
 *   - API endpoints            → observer custom (JSON, log, dsb.)
 *   - Halaman browser          → BrowserProgressObserver (lihat file terpisah)
 *
 * @package MEeL\Core
 */

/**
 * Interface ProgressObserver — kontrak penerima event progress.
 *
 * Stage yang di-emit oleh Transcoder:
 *   'download_start'     data: ['url' => string]        — mulai unduh (URL sumber)
 *   'transcode_start'    data: []                        — mulai transcode (HLS / audio)
 *   'phase'              data: ['phase' => string]       — pindah fase overlay ('transcode', 'sprite', ...)
 *   'download_progress'  data: ['pct' => int]            — progress unduh (persen)
 *                        + opsi: 'eta','speed','size','frag'
 *   'transcode_progress' data: ['pct' => int, 'label' => ?string] — progress ffmpeg
 *   'sprite_progress'    data: ['pct' => int, 'label' => ?string] — progress sprite VTT
 *   'done'               data: ['title' => string, 'url' => string]
 *   'done_transcode'     data: ['title' => string, 'download_link' => string]
 *   'redirect'           data: ['url' => string]         — navigasi browser (music → post_encode)
 *   'error'              data: ['message' => string]     — error fatal yang ditampilkan ke user
 */
interface ProgressObserver
{
    /**
     * Terima event progress dari lapisan bisnis.
     *
     * Implementasi TIDAK boleh melempar exception — kesalahan observer
     * (mis. koneksi terputus) tidak boleh menggagalkan pemrosesan media.
     *
     * @param string $stage Nama stage/event (lihat daftar di docblock class)
     * @param array<string, mixed> $data Payload event
     */
    public function onProgress(string $stage, array $data = []): void;
}

/**
 * CallableProgressObserver — adapter agar callable polos bisa dipakai
 * sebagai ProgressObserver:
 *
 *   $transcoder = new Transcoder($conn, $uid, function (string $stage, array $data) {
 *       fwrite(STDERR, "[{$stage}] " . json_encode($data) . PHP_EOL);
 *   });
 *
 * @package MEeL\Core
 */
final class CallableProgressObserver implements ProgressObserver
{
    /** @var callable(string $stage, array $data): void */
    private $handler;

    /**
     * @param callable(string $stage, array $data): void $handler
     */
    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    /**
     * {@inheritDoc}
     */
    public function onProgress(string $stage, array $data = []): void
    {
        ($this->handler)($stage, $data);
    }
}
