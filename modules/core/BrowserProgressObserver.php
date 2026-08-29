<?php
/* @package MEeL\Core */

require_once __DIR__ . '/ProgressObserver.php';

class BrowserProgressObserver implements ProgressObserver
{

    private bool $overlayInjected = false;
    private bool $isAdmin;

    public function __construct(bool $isAdmin = false)
    {
        $this->isAdmin = $isAdmin;
    }

    /* @throws \RuntimeException Tidak dilempar — method ini tidak melempar */
    public function onProgress(string $stage, array $data = []): void
    {
        switch ($stage) {
            case 'download_start':
                $this->injectOverlay('download');
                $this->emitJs('meelDlInfo(' . json_encode($data['url'] ?? '') . ');');
                break;

            case 'transcode_start':
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
                // browser (dokumen di-abort saat navigasi terjadi di tengah
                break;

            case 'error':
                if ($this->isAdmin) {
                    if (!$this->overlayInjected) {
                        $this->injectOverlay('error');
                    }
                    $this->emitJs('meelError(' . json_encode($data['message'] ?? '') . ');');
                } else {
                    $this->emitJs('window.location.href="err/index.php?code=server_error";');
                }
                break;

            default:

                break;
        }
    }

    /* @param string $js Badan JavaScript tanpa tag <script> */
    private function emitJs(string $js): void
    {
        echo '<script>' . $js . '</script>';
        echo str_repeat(' ', 1024);
        flush();
    }

    /* @param string $initialPhase Fase awal overlay ('download' | 'transcode') */
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

    /* @param array<string, mixed> $data */
    private function emitDownloadProgress(array $data): void
    {

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

        if (isset($data['pct'])) {
            $this->emitJs('meelDlPct(' . json_encode($data['pct']) . ');');
        }
    }
}
