<?php
/**
 * File: modules/core/BrowserProgressObserver.php
 *
 * Presenter (Presentation Layer) untuk ProgressObserver yang mengubah
 * event progress dari Transcoder menjadi output browser streaming:
 * overlay MEeL Engine (`partials/ui.php`) + perintah <script> meel*.
 *
 * Sebelum refactor, logika ini menyatu di dalam Transcoder
 * (`showMEeLOverlay()`, `jsError()`, echo meelPhase/meelDlPct/...).
 * Kini semua output HTML/JS dipindah ke file ini — Transcoder murni
 * melaporkan event, observer memutuskan bagaimana menampilkannya.
 *
 * Halaman pemakai (upload_advanced.php, transcode.php) memasang observer
 * ini saat konstruksi Transcoder:
 *
 *   $transcoder = new Transcoder($conn, $uid, new BrowserProgressObserver());
 *
 * @package MEeL\Core
 */

require_once __DIR__ . '/ProgressObserver.php';

class BrowserProgressObserver implements ProgressObserver
{
    /**
     * Flag overlay sudah di-inject atau belum — cegah double include
     * partials/ui.php dalam satu aliran respons.
     */
    private bool $overlayInjected = false;

    /**
     * {@inheritDoc}
     *
     * @throws \RuntimeException Tidak dilempar — method ini tidak melempar
     */
    public function onProgress(string $stage, array $data = []): void
    {
        switch ($stage) {
            case 'download_start':
                // Overlay + fase download + info URL sumber
                $this->injectOverlay('download');
                $this->emitJs('meelDlInfo(' . json_encode($data['url'] ?? '') . ');');
                break;

            case 'transcode_start':
                // Overlay + fase transcode (dipakai halaman transcode.php)
                $this->injectOverlay('transcode');
                break;

            case 'phase':
                $this->emitJs('meelPhase(' . json_encode($data['phase'] ?? '') . ');');
                break;

            case 'download_progress':
                $this->emitDownloadProgress($data);
                break;

            case 'transcode_progress':
            case 'sprite_progress':
                $this->emitJs(
                    ($stage === 'transcode_progress' ? 'meelTcPct' : 'meelSpPct')
                    . '(' . json_encode($data['pct'] ?? 0)
                    . (isset($data['label']) ? ', ' . json_encode($data['label']) : '')
                    . ');'
                );
                break;

            case 'done':
                $this->emitJs(
                    'meelDone(' . json_encode($data['title'] ?? '') . ', '
                    . json_encode($data['url'] ?? '') . ');'
                );
                break;

            case 'done_transcode':
                $this->emitJs(
                    'meelDoneTranscode(' . json_encode($data['title'] ?? '') . ', '
                    . json_encode($data['download_link'] ?? '') . ');'
                );
                break;

            case 'redirect':
                $this->emitJs('window.location.href = ' . json_encode($data['url'] ?? '') . ';');
                break;

            case 'error':
                $this->emitJs('meelError(' . json_encode($data['message'] ?? '') . ');');
                break;

            default:
                // Stage tak dikenal — abaikan dengan tenang (tidak menggagalkan proses)
                break;
        }
    }

    /**
     * Emit satu perintah <script> + padding agar browser langsung memproses
     * (streaming chunked response).
     *
     * @param string $js Badan JavaScript tanpa tag <script>
     */
    private function emitJs(string $js): void
    {
        echo '<script>' . $js . '</script>';
        echo str_repeat(' ', 1024);
        flush();
    }

    /**
     * Inject aset overlay MEeL Engine di tengah aliran respons.
     *
     * Meniru perilaku lama Transcoder::showMEeLOverlay():
     *  1. Bersihkan output buffer yang ada
     *  2. Header anti-buffering (X-Accel-Buffering, Content-Encoding)
     *  3. Include partials/ui.php (emits <link> CSS + <script> JS + shell overlay)
     *  4. Padding 64KB + perintah fase awal agar browser langsung render
     *
     * @param string $initialPhase Fase awal overlay ('download' | 'transcode')
     */
    private function injectOverlay(string $initialPhase): void
    {
        if ($this->overlayInjected) {
            return;
        }
        $this->overlayInjected = true;

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('X-Accel-Buffering: no');
        header('Content-Encoding: none');

        // Path absolut ke partials/ui.php. File ini berada di modules/core/
        // (dua level di bawah root proyek), jadi wajib dirname(__DIR__, 2) —
        // pemakaian '/../partials/...' akan salah resolve ke modules/partials/
        // (file_exists = false) dan overlay tidak pernah ter-inject.
        $ui_file = dirname(__DIR__, 2) . '/partials/ui.php';
        if (file_exists($ui_file)) {
            include $ui_file;
        } else {
            error_log('[MEeL] WARN: partials/ui.php tidak ditemukan: ' . $ui_file);
        }

        // Padding agar browser langsung flush dan render
        echo str_repeat(' ', 65536);
        echo '<script>meelPhase(' . json_encode($initialPhase) . ');</script>';
        flush();
    }

    /**
     * Emit progress unduh yt-dlp.
     *
     * - Jika hanya 'pct' tersedia → meelDlPct(pct) (fallback regex sederhana)
     * - Jika 'eta' tersedia → meelDlPct(pct, eta, speed, size, frag) (regex lengkap)
     *
     * @param array<string, mixed> $data
     */
    private function emitDownloadProgress(array $data): void
    {
        // Form lengkap (regex baris progres dengan ETA/fragment) — satu panggilan
        // meelDlPct(pct, eta, speed, size, frag) persis seperti perilaku lama.
        if (array_key_exists('eta', $data)) {
            $args = implode(',', array_map(
                'json_encode',
                [
                    $data['pct']   ?? 0,
                    $data['eta']   ?? '',
                    $data['speed'] ?? '',
                    $data['size']  ?? '',
                    $data['frag']  ?? '',
                ]
            ));
            $this->emitJs('meelDlPct(' . $args . ');');
            return;
        }

        // Fallback: hanya persentase
        if (isset($data['pct'])) {
            $this->emitJs('meelDlPct(' . json_encode($data['pct']) . ');');
        }
    }
}
