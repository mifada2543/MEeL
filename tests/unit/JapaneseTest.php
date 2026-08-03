<?php
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 * Tests for Japanese text processing functions in modules/core/japanese.php
 *
 * Note: These tests depend on MeCab being installed. If MeCab is not
 * available, the functions fall back to basic processing.
 */
class JapaneseTest extends TestCase
{
    private function hasMecab(): bool
    {
        $mecabPath = getMecabPath();

        if (strpos($mecabPath, '/') !== false) {
            return is_executable($mecabPath);
        }

        $resolved = shell_exec('command -v ' . escapeshellarg($mecabPath) . ' 2>/dev/null');
        return trim((string) $resolved) !== '';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Load japanese.php (guarded by function_exists)
        require_once MEEL_ROOT . '/modules/core/japanese.php';
    }

    public function testGetRomajiNameEmptyString(): void
    {
        $this->assertSame('untitled', getRomajiName(''));
    }

    public function testGetRomajiNameWithLatinText(): void
    {
        // Pure ASCII text should pass through
        $result = getRomajiName('hello world');
        $this->assertStringContainsString('hello', $result);
    }

    public function testGetRomajiNameWithSpecialChars(): void
    {
        if (!$this->hasMecab()) {
            $this->markTestSkipped('MeCab binary is not available in this environment.');
        }

        // Special chars should be replaced
        $result = getRomajiName('初音ミク【テスト】');
        // Should contain 'hatsune' (replacement for 初音)
        $this->assertStringContainsString('hatsune', $result);
    }

    public function testAnalyzeJapaneseTextEmpty(): void
    {
        $result = analyzeJapaneseText('');
        $this->assertSame('untitled-media', $result['romaji']);
        $this->assertSame('', $result['english']);
    }

    public function testAnalyzeJapaneseTextBasic(): void
    {
        $result = analyzeJapaneseText('テスト');
        $this->assertArrayHasKey('romaji', $result);
        $this->assertArrayHasKey('english', $result);
        $this->assertIsString($result['romaji']);
        $this->assertIsString($result['english']);
    }

    public function testGetEnglishTranslationEmpty(): void
    {
        $this->assertSame('', getEnglishTranslation(''));
    }
}
