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

    public function testAnalyzeJapaneseTextWithAlias(): void
    {
        $result = analyzeJapaneseText('プロジェクトセカイ カラフルステージ!');
        $this->assertStringContainsString('Project Sekai', $result['english']);
        $this->assertStringContainsString('Colorful Stage', $result['english']);
    }

    // ─── Alias baru (japanese_aliases.php) ──────────────────────────────────

    public function testAnalyzeJapaneseTextWithTouhouAlias(): void
    {
        $result = analyzeJapaneseText('東方プロジェクト ボスラッシュ');
        $this->assertStringContainsString('Touhou', $result['english']);
    }

    public function testAnalyzeJapaneseTextWithNightcordAlias(): void
    {
        $result = analyzeJapaneseText('25時、ナイトコードで。 歌ってみた');
        $this->assertStringContainsString('Nightcord at 25:00', $result['english']);
    }

    public function testAnalyzeJapaneseTextWithCharacterAlias(): void
    {
        $result = analyzeJapaneseText('天馬司 & 鳳えむ');
        $this->assertStringContainsString('Tenma Tsukasa', $result['english']);
        $this->assertStringContainsString('Otori Emu', $result['english']);
    }

    public function testAnalyzeJapaneseTextWithNicknameAlias(): void
    {
        // Nickname プロセカ / ワンオポ juga harus dikenali
        $result = analyzeJapaneseText('プロセカ ワンオポ');
        $this->assertStringContainsString('Project Sekai', $result['english']);
        $this->assertStringContainsString('Wonderlands x Showtime', $result['english']);
    }

    public function testAnalyzeJapaneseTextWithEnsembleStarsAlias(): void
    {
        $result = analyzeJapaneseText('あんさんぶるスターズ コラボ');
        $this->assertStringContainsString('Ensemble Stars', $result['english']);
    }

}
