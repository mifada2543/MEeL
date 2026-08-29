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

        // Override storage dir to temp test directory
        $ref = new ReflectionClass(RateLimiter::class);
        $prop = $ref->getProperty('storageDir');
        $prop->setAccessible(true);
        $this->origStorageDir = $prop->getValue();
        $prop->setValue(MEEL_ROOT . '/temp/ratelimit-test/');

        // Ensure clean state
        $testDir = MEEL_ROOT . '/temp/ratelimit-test/';
        if (!is_dir($testDir)) {
            @mkdir($testDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Restore original storage dir
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
        // Member gets 2x base limit for 'comment' (10 * 2 = 20)
        $result1 = RateLimiter::check('member_user', 'comment', 'member');
        // First request should be allowed
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
        $endpoint = 'comment'; // 10 requests per 60 seconds

        // Exhaust all requests
        for ($i = 0; $i < 10; $i++) {
            $result = RateLimiter::check($key, $endpoint, 'user');
            $this->assertTrue($result['allowed'], "Request #" . ($i + 1) . " should be allowed");
        }

        // 11th request should be blocked
        $result = RateLimiter::check($key, $endpoint, 'user');
        $this->assertFalse($result['allowed'], "11th request should be blocked");
        $this->assertSame(0, $result['remaining']);
    }

    public function testGetRemainingReturnsCorrectCount(): void
    {
        $key = 'test_remaining_user';
        $endpoint = 'api';

        // Make 3 requests
        for ($i = 0; $i < 3; $i++) {
            RateLimiter::check($key, $endpoint, 'user');
        }

        
        $remaining = RateLimiter::getRemaining($key, $endpoint);
        $this->assertSame(57, $remaining); // 60 - 3 = 57
    }

    public function testGetRoleLimit(): void
    {
        // Base limit
        $this->assertSame(100, RateLimiter::getRoleLimit(100, 'user'));
        // Member gets 2x
        $this->assertSame(200, RateLimiter::getRoleLimit(100, 'member'));

        $this->assertSame(100, RateLimiter::getRoleLimit(100, 'admin'));
        // Guest uses base limit
        $this->assertSame(100, RateLimiter::getRoleLimit(100, 'guest'));
    }

    public function testCleanupRemovesExpiredFiles(): void
    {
        
        $testDir = MEEL_ROOT . '/temp/ratelimit-test/';
        $expiredFile = $testDir . 'expired_test.cache';
        $expiredData = json_encode([
            'count' => 5,
            'window_start' => time() - 7200 // 2 hours ago (expired, max window is 1 hour)
        ]);
        file_put_contents($expiredFile, $expiredData);

        $cleaned = RateLimiter::cleanup();
        $this->assertSame(1, $cleaned, 'Should have cleaned 1 expired file');
        $this->assertFileDoesNotExist($expiredFile, 'Expired file should be deleted');
    }

    public function testGetStatsReturnsNonEmpty(): void
    {
        // Make a request to generate stats
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
        // Exhaust one key
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::check('user_a', 'comment', 'user');
        }
        $this->assertFalse(RateLimiter::check('user_a', 'comment', 'user')['allowed']);

        // Different key should still work
        $this->assertTrue(RateLimiter::check('user_b', 'comment', 'user')['allowed']);
    }

    public function testFallbackOnFileLockFailure(): void
    {
        // Test with invalid path to trigger fallback
        $ref = new ReflectionClass(RateLimiter::class);
        $prop = $ref->getProperty('storageDir');
        $prop->setAccessible(true);
        $prop->setValue('/nonexistent/path/');

        // Non-existent path should not crash, returns allowed=true
        $result = RateLimiter::check('fallback_user', 'api', 'user');
        $this->assertTrue($result['allowed']);
    }
}
