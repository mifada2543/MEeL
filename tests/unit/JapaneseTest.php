<?php
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class JapaneseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        
        require_once MEEL_ROOT . '/modules/core/japanese.php';
    }

    

    protected function skipIfNoMecab(): void
    {
        if (!function_exists('meel_mecab_available') || !meel_mecab_available()) {
            $this->markTestSkipped('mecab tidak tersedia — test romaji di-skip');
        }
    }

    public function testGetRomajiNameEmptyString(): void
    {
        $this->assertSame('untitled', getRomajiName(''));
    }

    public function testGetRomajiNameWithLatinText(): void
    {
        
        $result = getRomajiName('hello world');
        $this->assertStringContainsString('hello', $result);
    }

    public function testGetRomajiNameWithSpecialChars(): void
    {
        $this->skipIfNoMecab();
        
        $result = getRomajiName('初音ミク【テスト】');
        
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
        $this->skipIfNoMecab();
        $result = analyzeJapaneseText('プロジェクトセカイ カラフルステージ!');
        $this->assertStringContainsString('Project Sekai', $result['english']);
        $this->assertStringContainsString('Colorful Stage', $result['english']);
    }

    

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
        
        $result = analyzeJapaneseText('プロセカ ワンオポ');
        $this->assertStringContainsString('Project Sekai', $result['english']);
        $this->assertStringContainsString('Wonderlands x Showtime', $result['english']);
    }

    public function testAnalyzeJapaneseTextWithEnsembleStarsAlias(): void
    {
        $result = analyzeJapaneseText('あんさんぶるスターズ コラボ');
        $this->assertStringContainsString('Ensemble Stars', $result['english']);
    }

    
    public function testAnalyzeJapaneseTextDoesNotGlossParticlesAsHomophones(): void
    {
        $this->skipIfNoMecab();
        
        $result = analyzeJapaneseText('君が飛び降りるのならば');
        $this->assertStringContainsString('kimi-ga-tobioriru-no-nara-ba', $result['romaji']);
        $this->assertStringNotContainsString('moth', $result['english']);
        $this->assertStringNotContainsString('indicates possessive', $result['english']);
        $this->assertStringNotContainsString('place', $result['english']);
    }

    public function testAnalyzeJapaneseTextFullCoverAliasWins(): void
    {
        
        $result = analyzeJapaneseText('君が飛び降りるのならば');
        $this->assertSame("In case you're gonna jump", $result['english']);
    }

    public function testAnalyzeJapaneseTextConditionalPatternAlias(): void
    {
        
        $result = analyzeJapaneseText('跳べるならば');
        $this->assertStringContainsString('if', $result['english']);
    }

    public function testAnalyzeJapaneseTextVoicebankAliasSkipsTokenGloss(): void
    {
        $result = analyzeJapaneseText('プロポーズ / 可不');
        $this->assertStringContainsString('Kafu', $result['english']);
        $this->assertStringNotContainsString('acceptable', $result['english']);
        $this->assertStringNotContainsString('un-', $result['english']);
    }

}
