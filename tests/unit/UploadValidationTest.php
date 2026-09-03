<?php
use PHPUnit\Framework\TestCase;

require_once MEEL_ROOT . '/modules/autoload.php';
require_once MEEL_ROOT . '/modules/core/helpers.php';

/**
 * @covers meel_magic_extension_ok
 * @covers meel_sanitize_upload_filename
 * @covers meel_read_magic_bytes
 */
class UploadValidationTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = MEEL_ROOT . '/temp/upload_val_test_' . bin2hex(random_bytes(4));
        @mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tmp, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    private function fileWith(string $bytes): string
    {
        $p = $this->tmp . '/' . bin2hex(random_bytes(4));
        file_put_contents($p, $bytes);
        return $p;
    }

    public function testAudioMagicBytesAccepted(): void
    {
        $cases = [
            ["OggS\x00\x02", 'ogg'],      // Ogg
            ["OggS\x00\x02", 'opus'],     // Opus (Ogg container)
            ["fLaC\x00\x00\x00\x22", 'flac'],
            ["RIFF\x24\x00\x00\x00WAVE", 'wav'],
            ["ID3\x04\x00\x00\x00\x00\x00\x00", 'mp3'],
            ["\xFF\xFB\x90\x00", 'mp3'],  // MP3 frame sync
        ];
        foreach ($cases as [$bytes, $ext]) {
            $this->assertSame('', meel_magic_extension_ok($this->fileWith($bytes), $ext, 'audio'), "ext=$ext");
        }
    }

    public function testAudioRejectsPhpPayload(): void
    {
        $err = meel_magic_extension_ok($this->fileWith('<?php system($_GET[0]); ?>'), 'mp3', 'audio');
        $this->assertNotSame('', $err);
    }

    public function testVideoMagicBytesAccepted(): void
    {
        $this->assertSame('', meel_magic_extension_ok($this->fileWith("\x1A\x45\xDF\xA3\x01"), 'mkv', 'video'));
        $this->assertSame('', meel_magic_extension_ok($this->fileWith("\x00\x00\x00\x18ftypisom"), 'mp4', 'video'));
    }

    public function testVideoRejectsHtml(): void
    {
        $err = meel_magic_extension_ok($this->fileWith('<html><body>hi</body></html>'), 'mp4', 'video');
        $this->assertNotSame('', $err);
    }

    public function testImageMagicBytesAccepted(): void
    {
        $this->assertSame('', meel_magic_extension_ok($this->fileWith("\xFF\xD8\xFF\xE0\x00"), 'jpg', 'image'));
        $this->assertSame('', meel_magic_extension_ok($this->fileWith("\x89PNG\x0D\x0A\x1A\x0A"), 'png', 'image'));
        $this->assertSame('', meel_magic_extension_ok($this->fileWith("GIF89a\x01\x00"), 'gif', 'image'));
        $this->assertSame('', meel_magic_extension_ok($this->fileWith("RIFF\x10\x00\x00\x00WEBPVP8 "), 'webp', 'image'));
    }

    public function testImageRejectsHtml(): void
    {
        $err = meel_magic_extension_ok($this->fileWith('<script>alert(1)</script>'), 'png', 'image');
        $this->assertNotSame('', $err);
    }

    public function testPdfMagicBytes(): void
    {
        $this->assertSame('', meel_magic_extension_ok($this->fileWith("%PDF-1.7\n%"), 'pdf', 'pdf'));
        $err = meel_magic_extension_ok($this->fileWith('#!/bin/sh\nrm -rf /'), 'pdf', 'pdf');
        $this->assertNotSame('', $err);
    }

    public function testArchiveMagicBytes(): void
    {
        $this->assertSame('', meel_magic_extension_ok($this->fileWith("PK\x03\x04\x14\x00"), 'zip', 'archive'));
        $this->assertSame('', meel_magic_extension_ok($this->fileWith("PK\x05\x06\x00\x00"), 'cbz', 'archive'));
        $err = meel_magic_extension_ok($this->fileWith('MZ\x90\x00'), 'zip', 'archive');
        $this->assertNotSame('', $err);
    }

    public function testSanitizeFilenameRemovesTraversal(): void
    {
        $this->assertSame('safe_name.jpg', meel_sanitize_upload_filename('../../safe_name.jpg'));
        $this->assertSame('a_b', meel_sanitize_upload_filename("a\\b"));
        $this->assertStringNotContainsString("\0", meel_sanitize_upload_filename("x\0y.jpg"));
        $this->assertStringNotContainsString('/', meel_sanitize_upload_filename('a/b/c.php'));
    }
}
