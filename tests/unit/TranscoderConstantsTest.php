<?php
use PHPUnit\Framework\TestCase;

require_once MEEL_ROOT . '/modules/core/TranscoderBase.php';
require_once MEEL_ROOT . '/modules/transcoder/DownloadService.php';
require_once MEEL_ROOT . '/modules/transcoder/EncodeService.php';
require_once MEEL_ROOT . '/modules/transcoder/TranscodeService.php';

/**
 * Regression: setelah Transcoder.php dipecah, konstanta bersama hidup di
 * TranscoderBase. Konstanta harus terlihat dari tiap service subclass —
 * kalau visibility-nya `private` di parent, referensi `self::CONST` di
 * child akan fatal "Undefined constant" saat encode/download/transcode
 * benar-benar berjalan (tidak ter-trigger test biasa karena butuh ffmpeg).
 *
 * @covers TranscoderBase
 * @covers EncodeService
 * @covers DownloadService
 * @covers TranscodeService
 */
class TranscoderConstantsTest extends TestCase
{
    public function testServiceClassesResolveSharedConstants(): void
    {
        $classes = ['EncodeService', 'DownloadService', 'TranscodeService'];
        $constants = [
            'FFMPEG_THREADS',
            'ENV_PREFIX',
            'DOWNLOAD_TIMEOUT',
            'FRAGMENT_RETRY_LIMIT',
            'HLS_SEGMENT_DURATION',
            'FFMPEG_LIB_PATH',
            'TRANSCODE_AUDIO_TIMEOUT',
        ];

        foreach ($classes as $class) {
            $ref = new ReflectionClass($class);
            foreach ($constants as $const) {
                $this->assertTrue(
                    $ref->hasConstant($const),
                    "{$class} tidak bisa mengakses konstanta {$const} dari TranscoderBase"
                );
                // Nilai harus non-empty — memastikan bukan null/stub.
                $this->assertNotEmpty(
                    $ref->getReflectionConstant($const)->getValue(),
                    "Konstanta {$const} kosong pada {$class}"
                );
            }
        }
    }

    public function testFacadeStillDelegatesStaticOwnership(): void
    {
        require_once MEEL_ROOT . '/modules/core/Transcoder.php';
        // Permukaan publik yang dipakai caller eksternal (controllers/views).
        $this->assertTrue(method_exists('Transcoder', 'ownsTranscodeFile'));
        $this->assertTrue(method_exists('Transcoder', 'resolveMusicInputPath'));
        $this->assertTrue(method_exists('Transcoder', 'processDownload'));
        $this->assertTrue(method_exists('Transcoder', 'encodeMusic'));
        $this->assertTrue(method_exists('Transcoder', 'transcodeVideo'));
        $this->assertTrue(method_exists('Transcoder', 'terminateAllProcesses'));
        $this->assertTrue(method_exists('Transcoder', 'killByPidFile'));
    }
}
