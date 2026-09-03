<?php
use PHPUnit\Framework\TestCase;

require_once MEEL_ROOT . '/modules/media/ArchiveGuard.php';

/**
 * @covers ArchiveGuard
 */
class ArchiveGuardTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = MEEL_ROOT . '/temp/archive_guard_test_' . bin2hex(random_bytes(4));
        @mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    private function makeZip(array $entries, string $zipPath): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
    }

    public function testValidCbzExtractsToDestDir(): void
    {
        $archive = $this->tmp . '/valid.cbz';
        $dest    = $this->tmp . '/manga/valid';
        $this->makeZip([
            'page-001.jpg' => str_repeat('A', 100),
            'page-002.jpg' => str_repeat('B', 100),
        ], $archive);

        $guard = new ArchiveGuard($this->tmp . '/manga');
        $result = $guard->extractSafe($archive, $dest);

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame(2, $result['entries']);
        $this->assertFileExists($dest . '/page-001.jpg');
        $this->assertFileExists($dest . '/page-002.jpg');
    }

    public function testChaptersWithSubdirsExtract(): void
    {
        $archive = $this->tmp . '/chapters.cbz';
        $dest    = $this->tmp . '/manga/ch';
        $this->makeZip([
            'ch1/page-001.jpg' => 'data',
            'ch1/page-002.jpg' => 'data',
            'ch2/page-001.jpg' => 'data',
        ], $archive);

        $guard = new ArchiveGuard($this->tmp . '/manga');
        $result = $guard->extractSafe($archive, $dest);

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertFileExists($dest . '/ch1/page-001.jpg');
        $this->assertFileExists($dest . '/ch2/page-001.jpg');
    }

    public function testRejectsDotDotTraversal(): void
    {
        $archive = $this->tmp . '/evil.zip';
        $dest    = $this->tmp . '/manga/evil';
        $this->makeZip(['../evil.php' => '<?php system($_GET["c"]); ?>'], $archive);

        $guard = new ArchiveGuard($this->tmp . '/manga');
        $result = $guard->extractSafe($archive, $dest);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('traversal', strtolower($result['error'] ?? ''));
        $this->assertFileDoesNotExist($this->tmp . '/evil.php');
    }

    public function testRejectsAbsolutePath(): void
    {
        $archive = $this->tmp . '/abs.zip';
        $dest    = $this->tmp . '/manga/abs';
        $this->makeZip(['/tmp/pwned.jpg' => 'data'], $archive);

        $guard = new ArchiveGuard($this->tmp . '/manga');
        $result = $guard->extractSafe($archive, $dest);

        $this->assertFalse($result['ok']);
    }

    public function testRejectsBackslashTraversal(): void
    {
        $archive = $this->tmp . '/win.zip';
        $dest    = $this->tmp . '/manga/win';
        $this->makeZip(["..\\..\\evil.php" => 'x'], $archive);

        $guard = new ArchiveGuard($this->tmp . '/manga');
        $result = $guard->extractSafe($archive, $dest);

        $this->assertFalse($result['ok']);
    }

    public function testNullByteInNameCannotEscapeDestination(): void
    {
        // ZipArchive truncates nama pada null byte saat addFromString,
        // sehingga file aman berada di dalam dest. Buat zip mentah berisi
        // null byte di nama untuk memastikan guard menolaknya bila ada.
        $archive = $this->tmp . '/null.zip';
        $dest    = $this->tmp . '/manga/null';
        $name    = "page.jpg\0.png";

        // Minimal ZIP dengan satu entry ber-nama null-byte (local + central).
        $content = 'X';
        $crc     = crc32($content);
        $local   = "PK\x03\x04" . pack('v', 20) . pack('v', 0) . pack('v', 0)
            . pack('v', 0) . pack('v', 0) . pack('V', $crc)
            . pack('V', strlen($content)) . pack('V', strlen($content))
            . pack('v', strlen($name)) . pack('v', 0)
            . $name . $content;
        $offset  = 0;
        $central = "PK\x01\x02" . pack('v', 20) . pack('v', 20) . pack('v', 0)
            . pack('v', 0) . pack('v', 0) . pack('v', 0)
            . pack('V', $crc) . pack('V', strlen($content)) . pack('V', strlen($content))
            . pack('v', strlen($name)) . pack('v', 0) . pack('v', 0)
            . pack('v', 0) . pack('v', 0) . pack('V', 0)
            . pack('V', $offset) . $name;
        $eocd = "PK\x05\x06" . pack('v', 0) . pack('v', 0) . pack('v', 1) . pack('v', 1)
            . pack('V', strlen($central)) . pack('V', strlen($local))
            . pack('v', 0);
        file_put_contents($archive, $local . $central . $eocd);

        $guard = new ArchiveGuard($this->tmp . '/manga');
        $result = $guard->extractSafe($archive, $dest);

        // Guard menolak entry dengan null byte (atau setidaknya tidak
        // pernah menulis file di luar direktori dest).
        if ($result['ok']) {
            $this->assertFileDoesNotExist(dirname($dest) . '/page.jpg');
            $this->assertFileDoesNotExist($this->tmp . '/page.jpg');
        } else {
            $this->assertStringContainsString('null', strtolower($result['error'] ?? ''));
        }
    }

    public function testRejectsExecutableFileInsideArchive(): void
    {
        $archive = $this->tmp . '/php.zip';
        $dest    = $this->tmp . '/manga/php';
        $this->makeZip(['shell.php' => '<?php phpinfo(); ?>'], $archive);

        $guard = new ArchiveGuard($this->tmp . '/manga');
        $result = $guard->extractSafe($archive, $dest);

        $this->assertFalse($result['ok']);
    }

    public function testRejectsInvalidZip(): void
    {
        $archive = $this->tmp . '/bad.zip';
        file_put_contents($archive, 'not a zip at all');

        $guard = new ArchiveGuard($this->tmp . '/manga');
        $result = $guard->extractSafe($archive, $dest = $this->tmp . '/manga/bad');

        $this->assertFalse($result['ok']);
    }

    public function testRejectsExcessiveEntryCount(): void
    {
        $archive = $this->tmp . '/many.zip';
        $zip = new ZipArchive();
        $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        for ($i = 0; $i < MAX_ARCHIVE_ENTRIES + 5; $i++) {
            $zip->addFromString("p{$i}.jpg", 'x');
        }
        $zip->close();

        $guard = new ArchiveGuard($this->tmp . '/manga');
        $result = $guard->extractSafe($archive, $this->tmp . '/manga/many');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('entri', strtolower($result['error'] ?? ''));
    }

    public function testRejectsOversizedSingleEntry(): void
    {
        $archive = $this->tmp . '/big.zip';
        $this->makeZip(['huge.jpg' => str_repeat('Z', (int) MAX_ARCHIVE_ENTRY_BYTES + 1024)], $archive);

        $guard = new ArchiveGuard($this->tmp . '/manga');
        $result = $guard->extractSafe($archive, $this->tmp . '/manga/big');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('besar', strtolower($result['error'] ?? ''));
    }

    public function testDoesNotOverwriteExistingFiles(): void
    {
        $archive = $this->tmp . '/dup.zip';
        $dest    = $this->tmp . '/manga/dup';
        @mkdir($dest, 0755, true);
        file_put_contents($dest . '/page-001.jpg', 'ORIGINAL');

        $this->makeZip(['page-001.jpg' => 'NEW-CONTENT'], $archive);

        $guard = new ArchiveGuard($this->tmp . '/manga');
        $result = $guard->extractSafe($archive, $dest);

        $this->assertTrue($result['ok']);
        $this->assertSame('ORIGINAL', file_get_contents($dest . '/page-001.jpg'));
    }
}
