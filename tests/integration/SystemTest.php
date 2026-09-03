<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers System
 */
class SystemTest extends TestCase
{
    
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

        
        $this->system->getServerStats();
        clearstatcache();
        $this->assertSame($mtime, filemtime(self::cacheFile()));
    }
}
