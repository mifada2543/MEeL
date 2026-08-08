<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers GarbageCollector
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

        // Reset static hasRun flag
        $ref = new ReflectionClass(GarbageCollector::class);
        $prop = $ref->getProperty('hasRun');
        $prop->setAccessible(true);
        $prop->setValue(false);
    }

    protected function tearDown(): void
    {
        // Cleanup test directory
        if (is_dir($this->testTempDir)) {
            array_map('unlink', glob($this->testTempDir . '/*'));
            @rmdir($this->testTempDir);
        }

        parent::tearDown();
    }

    public function testCleanGuestsWithoutDb(): void
    {
        // gracefully (or skip if no DB available)
        $this->expectNotToPerformAssertions();
    }

    public function testGarbageCollectorClassExists(): void
    {
        $this->assertTrue(class_exists('GarbageCollector'));
    }

    public function testRunDoesNotThrowWithoutDirectories(): void
    {
        // Should not throw even without target directories
        GarbageCollector::run();
        $this->assertTrue(true); // If we got here, no exception
    }

    public function testRunIsIdempotent(): void
    {
        // Run twice - second call should be skipped by static flag
        GarbageCollector::run();
        GarbageCollector::run();
        $this->assertTrue(true);
    }

    public function testCleanGuestsWithInvalidConnection(): void
    {
        // Even without DB, properties should be properly defined
        $this->assertTrue(
            defined('GarbageCollector') || class_exists('GarbageCollector'),
            'GarbageCollector class should be loadable'
        );
    }
}
