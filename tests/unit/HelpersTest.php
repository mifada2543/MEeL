<?php
use PHPUnit\Framework\TestCase;

/* @coversNothing */
class HelpersTest extends TestCase
{
    // ─── format_bytes() ───

    /** @dataProvider bytesProvider */
    public function testFormatBytes(int|float $bytes, int $precision, string $expected): void
    {
        $this->assertSame($expected, format_bytes($bytes, $precision));
    }

    public static function bytesProvider(): array
    {
        return [
            'zero bytes'       => [0, 2, '0 B'],
            'bytes'            => [500, 0, '500 B'],
            'KB'               => [2048, 2, '2 KB'],
            'MB'               => [5 * 1024 * 1024, 2, '5 MB'],
            'GB'               => [3 * 1024 * 1024 * 1024, 2, '3 GB'],
            'TB'               => [2 * 1024 * 1024 * 1024 * 1024, 2, '2 TB'],
            'precision 0 MB'   => [7 * 1024 * 1024 + 512 * 1024, 0, '8 MB'],
            'negative clamped' => [-100, 2, '0 B'],
            'large GB'         => [10.5 * 1024 * 1024 * 1024, 1, '10.5 GB'],
        ];
    }

    // ─── time_ago() ───

    /** @dataProvider timeAgoProvider */
    public function testTimeAgo(int $secondsAgo, string $expectedRegex): void
    {
        $timestamp = time() - $secondsAgo;
        $result = time_ago($timestamp);
        $this->assertMatchesRegularExpression($expectedRegex, $result);
    }

    public static function timeAgoProvider(): array
    {
        return [
            'just now'     => [0, '/Baru saja/'],
            'seconds'      => [5, '/5 detik yang lalu/'],
            'minutes'      => [120, '/2 menit yang lalu/'],
            'hours'        => [3600 * 3, '/3 jam yang lalu/'],
            'days'         => [86400 * 7, '/7 hari yang lalu/'],
            'months'       => [2592000 * 2, '/2 bulan yang lalu/'],
            'years'        => [31104000 * 1, '/1 tahun yang lalu/'],
        ];
    }

    // ─── get_audio_mime_type() ───

    /** @dataProvider mimeTypeProvider */
    public function testGetAudioMimeType(string $ext, string $expected): void
    {
        $this->assertSame($expected, get_audio_mime_type($ext));
    }

    public static function mimeTypeProvider(): array
    {
        return [
            'mp3'      => ['mp3', 'audio/mpeg'],
            'm4a'      => ['m4a', 'audio/mp4'],
            'ogg'      => ['ogg', 'audio/ogg'],
            'opus'     => ['opus', 'audio/ogg'],
            'flac'     => ['flac', 'audio/flac'],
            'wav'      => ['wav', 'audio/wav'],
            'uppercase MP3' => ['MP3', 'audio/mpeg'],
            'unknown'  => ['aac', 'audio/ogg'],
        ];
    }

    // ─── get_audio_format_label() ───

    /** @dataProvider formatLabelProvider */
    public function testGetAudioFormatLabel(string $ext, string $expected): void
    {
        $this->assertSame($expected, get_audio_format_label($ext));
    }

    public static function formatLabelProvider(): array
    {
        return [
            'mp3'      => ['mp3', 'MP3'],
            'ogg'      => ['ogg', 'OPUS'],
            'opus'     => ['opus', 'OPUS'],
            'flac'     => ['flac', 'FLAC'],
            'wav'      => ['wav', 'WAV'],
            'm4a'      => ['m4a', 'M4A'],
        ];
    }

    // ─── get_audio_format_description() ───

    /** @dataProvider formatDescriptionProvider */
    public function testGetAudioFormatDescription(string $ext, string $expectedContains): void
    {
        $result = get_audio_format_description($ext);
        $this->assertStringContainsString($expectedContains, $result);
        $this->assertStringContainsString('codec', $result);
    }

    public static function formatDescriptionProvider(): array
    {
        return [
            'ogg'  => ['ogg', 'modern'],
            'opus' => ['opus', 'modern'],
            'm4a'  => ['m4a', 'kompatibilitas'],
            'mp3'  => ['mp3', 'populer'],
            'flac' => ['flac', 'terbaik'],
        ];
    }

    // ─── detectProtocol() ───

    public function testDetectProtocolDefaultHttp(): void
    {
        // Save and clear HTTPS-related server vars
        $origHttps = $_SERVER['HTTPS'] ?? null;
        $origForwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;

        unset($_SERVER['HTTPS']);
        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
        unset($_SERVER['HTTP_CF_VISITOR']);

        $protocol = detectProtocol();
        $this->assertSame('http', $protocol);

        // Restore
        if ($origHttps !== null) $_SERVER['HTTPS'] = $origHttps;
        if ($origForwardedProto !== null) $_SERVER['HTTP_X_FORWARDED_PROTO'] = $origForwardedProto;
    }

    // ─── base_url() ───

    public function testBaseUrl(): void
    {
        // Use the constant if defined, otherwise path from SCRIPT_NAME
        $result = base_url('index.php');
        $this->assertStringEndsWith('index.php', $result);

        // base_url() without args always ends with /
        $result2 = base_url();
        $this->assertStringEndsWith('/', $result2);

        // Verify path concatenation
        $result3 = base_url('css/style.css');
        $this->assertStringEndsWith('/css/style.css', $result3);
    }

    public function testBaseUrlFallbackFromProjectRoot(): void
    {
        $helpers = realpath(__DIR__ . '/../../modules/core/helpers.php');
        $this->assertNotFalse($helpers, 'modules/core/helpers.php tidak ditemukan');

        $projectRoot = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/../..')), '/');
        $docRoot     = dirname($projectRoot);
        $expected    = '/' . basename($projectRoot);

        $code = '$_SERVER["SCRIPT_NAME"]=' . var_export($expected . '/admin/index.php', true) . ';'
            . '$_SERVER["DOCUMENT_ROOT"]=' . var_export($docRoot, true) . ';'
            . 'require ' . var_export($helpers, true) . ';'
            . 'echo base_url("/auth/login.php?next=x");';

        $output = [];
        $exit   = 0;
        exec(PHP_BINARY . ' -d display_errors=0 -r ' . escapeshellarg($code) . ' 2>&1', $output, $exit);

        $this->assertSame(0, $exit, 'Subprocess helpers gagal: ' . implode("\n", $output));
        $this->assertCount(1, $output, 'Output subprocess tidak valid: ' . implode("\n", $output));
        $this->assertSame($expected . '/auth/login.php?next=x', trim($output[0]));
    }

    // ─── check_disk_space() ───

    public function testCheckDiskSpaceOnExistingPath(): void
    {

        $result = check_disk_space(1, MEEL_ROOT);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('free', $result);
        $this->assertArrayHasKey('required', $result);
        $this->assertArrayHasKey('path', $result);
        $this->assertSame(1, $result['required']);
    }

    public function testCheckDiskSpaceOnNonExistentPath(): void
    {
        $result = check_disk_space(100, '/nonexistent/path/that/doesnt/exist');
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('free', $result);
        $this->assertArrayHasKey('path', $result);
    }

    // ─── CSRF functions ───

    public function testCsrfTokenFunctions(): void
    {
        // Simulate session
        $_SESSION['csrf_token'] = 'test_token_123';
        $this->assertSame('test_token_123', get_csrf_token());

        // Verify correct token
        $this->assertTrue(verify_csrf_token('test_token_123'));
        // Verify wrong token
        $this->assertFalse(verify_csrf_token('wrong_token'));
    }

    // ─── dir_size() ───

    public function testDirSizeOnNonExistentPath(): void
    {
        $size = dir_size('/nonexistent/path/xxx');
        $this->assertSame(0.0, $size);
    }

    public function testDirSizeOnExistingDirectory(): void
    {
        // Create a temp test directory with a file
        $testDir = MEEL_ROOT . '/temp/dirsize_test';
        if (!is_dir($testDir)) {
            @mkdir($testDir, 0755, true);
        }
        file_put_contents($testDir . '/test.txt', str_repeat('A', 100));

        $size = dir_size($testDir, 1); // 1 second TTL to avoid cache issues
        $this->assertGreaterThan(0, $size);

        // Cleanup
        @unlink($testDir . '/test.txt');
        @rmdir($testDir);
    }

    // ─── generate_search_metadata() ───
    public function testGenerateSearchMetadataWithAliases(): void
    {
        $result = generate_search_metadata('プロジェクトセカイ カラフルステージ!');
        $this->assertStringContainsString('project sekai', $result);
        $this->assertStringContainsString('colorful stage', $result);
        $this->assertStringContainsString('purojekutosekai', $result); // romaji
        $this->assertSame(mb_strtolower($result, 'UTF-8'), $result);   // lowercase
    }

    public function testGenerateSearchMetadataPlainText(): void
    {
        $result = generate_search_metadata('Hello World Test');
        $this->assertStringContainsString('hello world test', $result);
        $this->assertSame(mb_strtolower($result, 'UTF-8'), $result);
    }

    // ─── lang_map() / lang_label() + alias subtitle_lang_map()/subtitle_lang_label() ───

    public function testLangMapHasAllLanguages(): void
    {
        $map = lang_map();
        $this->assertCount(15, $map);
        $this->assertSame('Indonesia', $map['id']);
        $this->assertSame('English', $map['en']);
        $this->assertSame('日本語', $map['ja']);
        $this->assertArrayHasKey('vi', $map);
    }

    public function testLangLabelKnownLanguage(): void
    {
        $this->assertSame('Indonesia', lang_label('id'));
        $this->assertSame('English', lang_label('EN')); // case-insensitive
        $this->assertSame('日本語', lang_label('ja'));
    }

    public function testLangLabelUnknownFallsBackToUppercase(): void
    {
        $this->assertSame('XX', lang_label('xx'));
        $this->assertSame('PT-BR', lang_label('pt-br'));
    }

    public function testLangLabelConsistentWithMap(): void
    {
        foreach (lang_map() as $code => $label) {
            $this->assertSame($label, lang_label($code));
        }
    }

    public function testSubtitleAliasesMatchGeneric(): void
    {
        $this->assertSame(lang_map(), subtitle_lang_map());
        $this->assertSame(lang_label('ja'), subtitle_lang_label('ja'));
        $this->assertSame(lang_label('pt-br'), subtitle_lang_label('pt-br'));
    }

    // ─── get_user_role() requires DB, skip basic test ───
    }
