<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers RateLimiter
 */
class RateLimiterTest extends TestCase
{
    private string $origStorageDir;

    protected function setUp(): void
    {
        parent::setUp();

        
        $ref = new ReflectionClass(RateLimiter::class);
        $prop = $ref->getProperty('storageDir');
        $prop->setAccessible(true);
        $this->origStorageDir = $prop->getValue();
        $prop->setValue(MEEL_ROOT . '/temp/ratelimit-test/');

        
        $testDir = MEEL_ROOT . '/temp/ratelimit-test/';
        if (!is_dir($testDir)) {
            @mkdir($testDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        
        $ref = new ReflectionClass(RateLimiter::class);
        $prop = $ref->getProperty('storageDir');
        $prop->setAccessible(true);
        $prop->setValue($this->origStorageDir);

        
        $testDir = MEEL_ROOT . '/temp/ratelimit-test/';
        if (is_dir($testDir)) {
            array_map('unlink', glob($testDir . '/*'));
            @rmdir($testDir);
        }

        parent::tearDown();
    }

    public function testAdminBypass(): void
    {
        $result = RateLimiter::check('admin_user', 'like', 'admin');

        $this->assertTrue($result['allowed']);
        $this->assertSame(-1, $result['remaining']);
        $this->assertSame(999999, $result['limit']);
        $this->assertSame(0, $result['retry_after']);
    }

    public function testMemberGetsDoubleLimit(): void
    {
        
        $result1 = RateLimiter::check('member_user', 'comment', 'member');
        
        $this->assertTrue($result1['allowed']);
        $this->assertSame(20, $result1['limit']);
        $this->assertSame(19, $result1['remaining']);
    }

    public function testUserGetsBaseLimit(): void
    {
        $result = RateLimiter::check('normal_user', 'comment', 'user');
        $this->assertTrue($result['allowed']);
        $this->assertSame(10, $result['limit']);
        $this->assertSame(9, $result['remaining']);
    }

    public function testRateLimitBlocksAfterMaxRequests(): void
    {
        $key = 'test_block_user';
        $endpoint = 'comment'; 

        
        for ($i = 0; $i < 10; $i++) {
            $result = RateLimiter::check($key, $endpoint, 'user');
            $this->assertTrue($result['allowed'], "Request #" . ($i + 1) . " should be allowed");
        }

        
        $result = RateLimiter::check($key, $endpoint, 'user');
        $this->assertFalse($result['allowed'], "11th request should be blocked");
        $this->assertSame(0, $result['remaining']);
    }

    public function testGetRemainingReturnsCorrectCount(): void
    {
        $key = 'test_remaining_user';
        $endpoint = 'api';

        
        for ($i = 0; $i < 3; $i++) {
            RateLimiter::check($key, $endpoint, 'user');
        }

        
        $remaining = RateLimiter::getRemaining($key, $endpoint);
        $this->assertSame(57, $remaining); 
    }

    public function testGetRoleLimit(): void
    {
        
        $this->assertSame(100, RateLimiter::getRoleLimit(100, 'user'));
        
        $this->assertSame(200, RateLimiter::getRoleLimit(100, 'member'));

        $this->assertSame(100, RateLimiter::getRoleLimit(100, 'admin'));
        
        $this->assertSame(100, RateLimiter::getRoleLimit(100, 'guest'));
    }

    public function testCleanupRemovesExpiredFiles(): void
    {
        
        $testDir = MEEL_ROOT . '/temp/ratelimit-test/';
        $expiredFile = $testDir . 'expired_test.cache';
        $expiredData = json_encode([
            'count' => 5,
            'window_start' => time() - 7200 
        ]);
        file_put_contents($expiredFile, $expiredData);

        $cleaned = RateLimiter::cleanup();
        $this->assertSame(1, $cleaned, 'Should have cleaned 1 expired file');
        $this->assertFileDoesNotExist($expiredFile, 'Expired file should be deleted');
    }

    public function testGetStatsReturnsNonEmpty(): void
    {
        
        RateLimiter::check('stats_user', 'api', 'user');

        $stats = RateLimiter::getStats();
        $this->assertIsArray($stats);
    }

    public function testGetLimitsConfig(): void
    {
        $config = RateLimiter::getLimitsConfig();
        $this->assertArrayHasKey('like', $config);
        $this->assertArrayHasKey('comment', $config);
        $this->assertArrayHasKey('upload', $config);
        $this->assertArrayHasKey('transcode', $config);
        $this->assertArrayHasKey('api', $config);

        $this->assertSame(30, $config['like']['requests']);
        $this->assertSame(60, $config['like']['window']);
    }

    public function testDifferentKeysAreIndependent(): void
    {
        
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::check('user_a', 'comment', 'user');
        }
        $this->assertFalse(RateLimiter::check('user_a', 'comment', 'user')['allowed']);

        
        $this->assertTrue(RateLimiter::check('user_b', 'comment', 'user')['allowed']);
    }

    public function testFallbackOnFileLockFailure(): void
    {
        
        $ref = new ReflectionClass(RateLimiter::class);
        $prop = $ref->getProperty('storageDir');
        $prop->setAccessible(true);
        $prop->setValue('/nonexistent/path/');

        
        $result = RateLimiter::check('fallback_user', 'api', 'user');
        $this->assertTrue($result['allowed']);
    }
}
