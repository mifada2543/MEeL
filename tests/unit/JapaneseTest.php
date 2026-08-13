<?php
use PHPUnit\Framework\TestCase;

/* @coversNothing */
class JapaneseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Load japanese.php (guarded by function_exists)
        require_once MEEL_ROOT . '/modules/core/japanese.php';
    }

    /**
     * Test yang memerlukan romaji aktual dari MeCab di-skip bila mecab
     * tidak tersedia di lingkungan (mis. CI runner tanpa apt install mecab).
     * Test berbasis alias/kamus tetap jalan karena tidak butuh mecab.
     */
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
        // Pure ASCII text should pass through
        $result = getRomajiName('hello world');
        $this->assertStringContainsString('hello', $result);
    }

    public function testGetRomajiNameWithSpecialChars(): void
    {
        $this->skipIfNoMecab();
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
        $this->skipIfNoMecab();
        $result = analyzeJapaneseText('プロジェクトセカイ カラフルステージ!');
        $this->assertStringContainsString('Project Sekai', $result['english']);
        $this->assertStringContainsString('Colorful Stage', $result['english']);
    }

    // ─── Alias baru (japanese_aliases.php) ───

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

    // ─── Partikel & homofon tidak boleh diterjemahkan per-token ───
    public function testAnalyzeJapaneseTextDoesNotGlossParticlesAsHomophones(): void
    {
        $this->skipIfNoMecab();
        // が, の, ば adalah partikel — sebelumnya salah jadi "moth", "indicates possessive", "place"
        $result = analyzeJapaneseText('君が飛び降りるのならば');
        $this->assertStringContainsString('kimi-ga-tobioriru-no-nara-ba', $result['romaji']);
        $this->assertStringNotContainsString('moth', $result['english']);
        $this->assertStringNotContainsString('indicates possessive', $result['english']);
        $this->assertStringNotContainsString('place', $result['english']);
    }

    public function testAnalyzeJapaneseTextFullCoverAliasWins(): void
    {
        // Alias frasa penuh menutupi seluruh judul → dipakai sebagai terjemahan final
        $result = analyzeJapaneseText('君が飛び降りるのならば');
        $this->assertSame("In case you're gonna jump", $result['english']);
    }

    public function testAnalyzeJapaneseTextConditionalPatternAlias(): void
    {
        // Pola 'のならば' / 'ならば' diterjemahkan sebagai unit, bukan kata-per-kata
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
