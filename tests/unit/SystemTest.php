<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers System
 *
 * Verifikasi getServerStats():
 *  - bentuk array output konsisten (dipakai admin/index.php & api/server-stats)
 *  - info identitas server di-cache ke temp/cache/server_stats_info.json
 *    sehingga polling realtime tidak menjalankan perintah shell berulang.
 */
class SystemTest extends TestCase
{
    // Path cache test — di-override lewat phpunit.xml (MEEL_SERVER_STATS_CACHE)
    // karena temp/cache produksi milik web server dan tidak writable oleh
    // proses test CLI.
    private static function cacheFile(): string
    {
        return MEEL_ROOT . '/' . ltrim(MEEL_SERVER_STATS_CACHE, '/');
    }

    private ?System $system = null;
    private ?DbTestHelper $db = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new DbTestHelper();
        $this->system = new System($this->db->getConnection());

        // Mulai dari cache bersih agar deterministik. clearstatcache() penting:
        // phpunit berjalan satu proses, dan PHP me-cache hasil stat file
        // (tanpa ini, file_exists bisa mengembalikan hasil lama).
        clearstatcache();
        if (file_exists(self::cacheFile())) {
            @unlink(self::cacheFile());
        }
        clearstatcache();
    }

    protected function tearDown(): void
    {
        $this->db->rollback();
        $this->db->close();
        parent::tearDown();
    }

    public function testGetServerStatsShape(): void
    {
        $stats = $this->system->getServerStats();

        foreach (['cpu', 'ram', 'swap', 'uptime', 'network', 'info'] as $key) {
            $this->assertArrayHasKey($key, $stats, "Missing top-level key: $key");
        }

        foreach (['cores', 'load_1m', 'load_5m', 'load_15m', 'usage_perc'] as $key) {
            $this->assertArrayHasKey($key, $stats['cpu'], "Missing cpu.$key");
        }
        foreach (['total', 'used', 'avail', 'usage_perc'] as $key) {
            $this->assertArrayHasKey($key, $stats['ram'], "Missing ram.$key");
        }
        foreach (['total', 'used', 'usage_perc'] as $key) {
            $this->assertArrayHasKey($key, $stats['swap'], "Missing swap.$key");
        }
        foreach (['seconds', 'days', 'hours', 'mins', 'text'] as $key) {
            $this->assertArrayHasKey($key, $stats['uptime'], "Missing uptime.$key");
        }
        foreach (['rx', 'tx'] as $key) {
            $this->assertArrayHasKey($key, $stats['network'], "Missing network.$key");
        }
        foreach (['hostname', 'os', 'kernel', 'php_version', 'processes'] as $key) {
            $this->assertArrayHasKey($key, $stats['info'], "Missing info.$key");
        }

        $this->assertGreaterThanOrEqual(1, $stats['cpu']['cores']);
        $this->assertIsNumeric($stats['ram']['total']);
        $this->assertMatchesRegularExpression('/^\d+d \d+h \d+m$/', $stats['uptime']['text']);

        // Bila ada interface non-loopback, total network harus terbaca
        // (regresi regex lama hanya cocok interface bernama eth/ens/enp/wlan
        // tanpa suffix angka → selalu 0).
        $hasNonLo = false;
        $net_lines = @file('/proc/net/dev');
        if ($net_lines) {
            foreach ($net_lines as $line) {
                if (preg_match('/^\s*([a-zA-Z0-9_.-]+):/', $line, $m) && $m[1] !== 'lo') {
                    $hasNonLo = true;
                    break;
                }
            }
        }
        if ($hasNonLo) {
            $this->assertGreaterThan(0, $stats['network']['rx'] + $stats['network']['tx']);
        }
    }

    public function testInfoCacheReused(): void
    {
        $this->assertFileDoesNotExist(self::cacheFile());

        $this->system->getServerStats();
        $this->assertFileExists(self::cacheFile(), 'Cache info server harus dibuat setelah panggilan pertama.');

        $mtime = filemtime(self::cacheFile());
        clearstatcache();

        // Panggilan kedua masih dalam TTL → harus memakai cache (mtime tidak berubah).
        $this->system->getServerStats();
        clearstatcache();
        $this->assertSame($mtime, filemtime(self::cacheFile()));
    }
}
