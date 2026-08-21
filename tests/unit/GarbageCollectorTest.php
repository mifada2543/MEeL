<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers GarbageCollector
 *
 * Verifikasi perilaku pembersihan temp/cache yang nyata:
 * - cleanDirectory(): hapus file/direktori stale (> 5 menit), pertahankan yang
 * fresh, dan JANGAN sentuh cache persisten yt-dlp (ytdlp-cache).
 * - run(): end-to-end — bersihkan rate-limit file kadaluarsa lewat
 * RateLimiter::cleanup(), pertahankan yang masih dalam window, dan no-op
 * pada panggilan kedua (static $hasRun).
 */
class GarbageCollectorTest extends TestCase
{
    private string $testTempDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test temp directory
        $this->testTempDir = MEEL_ROOT . '/temp/gc_test_' . uniqid();
        @mkdir($this->testTempDir, 0755, true);

        self::resetHasRun();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->testTempDir);
        parent::tearDown();
    }

    /* Reset static flag agar run() bisa diuji berulang antar-test. */
    private static function resetHasRun(): void
    {
        $ref = new ReflectionClass(GarbageCollector::class);
        $prop = $ref->getProperty('hasRun');
        $prop->setAccessible(true);
        $prop->setValue(false);
    }

    /* Buat file dengan mtime terkontrol (usia dalam detik). */
    private function touchAged(string $path, int $ageSeconds): void
    {
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, 'x');
        touch($path, time() - $ageSeconds);
    }

    /* Panggil private GarbageCollector::cleanDirectory() via reflection. */
    private function cleanDirectory(string $dir): void
    {
        $ref = new ReflectionClass(GarbageCollector::class);
        $method = $ref->getMethod('cleanDirectory');
        $method->setAccessible(true);
        $method->invoke(null, $dir);
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

    // cleanDirectory(): pembersihan file/direktori temp

    public function testCleanDirectoryRemovesStaleFilesButKeepsFresh(): void
    {
        $stale = $this->testTempDir . '/stale.tmp';
        $fresh = $this->testTempDir . '/fresh.tmp';
        $this->touchAged($stale, 400); // > STALE_SECONDS (300)
        $this->touchAged($fresh, 10);

        $this->cleanDirectory($this->testTempDir);

        $this->assertFileDoesNotExist($stale, 'File dengan mtime > 5 menit harus dihapus');
        $this->assertFileExists($fresh, 'File dengan mtime < 5 menit harus dipertahankan');
    }

    public function testCleanDirectoryRemovesStaleDirectoryRecursively(): void
    {
        $sub = $this->testTempDir . '/stale-sub';
        $this->touchAged($sub . '/nested/file.bin', 400);
        touch($sub, time() - 400);

        $this->cleanDirectory($this->testTempDir);

        $this->assertDirectoryDoesNotExist($sub, 'Direktori stale harus dihapus beserta isinya');
    }

    public function testCleanDirectoryKeepsFreshDirectory(): void
    {
        $sub = $this->testTempDir . '/fresh-sub';
        $this->touchAged($sub . '/file.bin', 10);
        touch($sub, time() - 10);

        $this->cleanDirectory($this->testTempDir);

        $this->assertDirectoryExists($sub);
        $this->assertFileExists($sub . '/file.bin');
    }

    public function testCleanDirectorySkipsYtdlpPersistentCache(): void
    {
        $cache = $this->testTempDir . '/ytdlp-cache';
        $this->touchAged($cache . '/entries.db', 400);
        touch($cache, time() - 400);

        $this->cleanDirectory($this->testTempDir);

        $this->assertDirectoryExists($cache, 'ytdlp-cache persisten tidak boleh dihapus');
        $this->assertFileExists($cache . '/entries.db');
    }

    // run(): end-to-end (temp dir + RateLimiter::cleanup)

    /* Arahkan RateLimiter::$storageDir ke direktori test terisolasi.
     * Temp/ratelimit asli milik daemon (Apache, mode 755) — menulis ke sana
     * dari CLI test gagal (Permission denied). Konvensi sama dengan
     * RateLimiterTest: override storageDir via reflection, restore di tearDown.
     */
    private string $origRateStorageDir = '';

    private function isolateRateLimiterDir(): string
    {
        $ref = new ReflectionClass(RateLimiter::class);
        $prop = $ref->getProperty('storageDir');
        $prop->setAccessible(true);
        $this->origRateStorageDir = (string) $prop->getValue();

        $rateDir = $this->testTempDir . '/ratelimit';
        @mkdir($rateDir, 0755, true);
        $prop->setValue($rateDir . '/');
        return $rateDir;
    }

    private function restoreRateLimiterDir(): void
    {
        $ref = new ReflectionClass(RateLimiter::class);
        $prop = $ref->getProperty('storageDir');
        $prop->setAccessible(true);
        $prop->setValue($this->origRateStorageDir);
    }

    public function testRunCleansExpiredRateLimitCacheButKeepsRecent(): void
    {
        $rateDir = $this->isolateRateLimiterDir();

        // File rate-limit kadaluarsa: window_start 2 jam lalu (> 1 jam max)
        $expired = $rateDir . '/gc_test_expired.cache';
        file_put_contents($expired, json_encode([
            'count' => 5,
            'window_start' => time() - 7200,
        ]));

        $recent = $rateDir . '/gc_test_recent.cache';
        file_put_contents($recent, json_encode([
            'count' => 2,
            'window_start' => time(),
        ]));

        // Pastikan dir ratelimit dianggap fresh oleh cleanDirectory(temp)
        // (level-1 GC) sehingga hanya RateLimiter::cleanup() yang menilai file.
        touch($rateDir, time());

        GarbageCollector::run();

        $this->assertFileDoesNotExist($expired, 'Rate-limit file dengan window_start > 1 jam harus dibersihkan');
        $this->assertFileExists($recent, 'Rate-limit file dalam window harus dipertahankan');

        $this->restoreRateLimiterDir();
    }

    public function testRunSecondCallIsNoOpViaStaticFlag(): void
    {
        $rateDir = $this->isolateRateLimiterDir();
        touch($rateDir, time());

        $expired = $rateDir . '/gc_test_first.cache';
        file_put_contents($expired, json_encode([
            'count' => 5,
            'window_start' => time() - 7200,
        ]));

        GarbageCollector::run();
        $this->assertFileDoesNotExist($expired, 'run() pertama membersihkan file kadaluarsa');

        // File kadaluarsa BARU dibuat setelah run() pertama — run() kedua no-op.
        $late = $rateDir . '/gc_test_late.cache';
        file_put_contents($late, json_encode([
            'count' => 5,
            'window_start' => time() - 7200,
        ]));

        GarbageCollector::run();

        $this->assertFileExists($late, 'run() kedua harus no-op (static $hasRun) — file tidak dibersihkan');

        $this->restoreRateLimiterDir();
    }
}
