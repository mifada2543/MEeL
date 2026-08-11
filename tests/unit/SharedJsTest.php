<?php
use PHPUnit\Framework\TestCase;

/**
 * Verifikasi file JS hasil dedup:
 *  - assets/js/shared/state-keys.js      (konstanta storage key terpusat)
 *  - assets/js/shared/download-backup-codes.js (format file TXT backup codes)
 *
 * @coversNothing
 */
class SharedJsTest extends TestCase
{
    /** Literal asli sebelum dedup — nilai konstanta harus identik. */
    private const EXPECTED_KEYS = [
        'AUDIO_STATE'       => 'meel_audio_state',
        'SKIP_RESUME_ONCE'  => 'skip_resume_once',
        'GLOBAL_LOOP'       => 'meel_global_loop',
        'LAST_PLAYLIST_ID'  => 'meel_last_playlist_id',
        'AUTONEXT_ENABLED'  => 'meel_autonext_enabled',
        'AUTONAV'           => 'meel_autonav',
        'EQ_STATE'          => 'meel_music_eq_state',
        'HEALTH_ALERT'      => 'meel_health_alert',
        'GLOW_ENABLED'      => 'meel_glow_enabled',
    ];

    private function rootPath(): string
    {
        return realpath(__DIR__ . '/../..');
    }

    private function readSource(string $relative): string
    {
        $path = $this->rootPath() . '/' . $relative;
        $this->assertFileExists($path, "File tidak ditemukan: $relative");
        $content = file_get_contents($path);
        $this->assertNotFalse($content, "Gagal membaca: $relative");
        return $content;
    }

    /**
     * Semua file JS di assets/js kecuali vendor (compatibilitas/) dan
     * state-keys.js itu sendiri (yang memang berisi nilai literal).
     *
     * @return string[] Path relatif, terurut.
     */
    private function allAssetsJsFiles(): array
    {
        $root = $this->rootPath() . '/assets/js';
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'js') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($this->rootPath() . '/')));
            if (strpos($rel, 'assets/js/compatibilitas/') === 0 || $rel === 'assets/js/shared/state-keys.js') {
                continue;
            }
            $files[] = $rel;
        }
        sort($files);
        return $files;
    }

    /** @return array<string,string> Peta KEY => nilai dari Object.freeze(...) */
    private function parseStateKeys(): array
    {
        $src = $this->readSource('assets/js/shared/state-keys.js');
        if (!preg_match('/Object\.freeze\(\{([\s\S]*?)\}\)\s*;/', $src, $m)) {
            $this->fail('state-keys.js: blok Object.freeze tidak ditemukan.');
        }
        preg_match_all('/^\s*([A-Z_]+):\s*\'([^\']*)\',?\s*$/m', $m[1], $pairs, PREG_SET_ORDER);
        $map = [];
        foreach ($pairs as $p) {
            $map[$p[1]] = $p[2];
        }
        return $map;
    }

    public function testStateKeysConstantsMatchOriginals(): void
    {
        $map      = $this->parseStateKeys();
        $expected = self::EXPECTED_KEYS;
        $this->assertCount(count($expected), $map, 'Jumlah konstanta tidak sesuai.');
        // Bandingkan order-independent: urutan konstanta di file bukan kontrak.
        ksort($expected);
        ksort($map);
        $this->assertSame(
            $expected,
            $map,
            'Nilai konstanta MEEL_KEYS harus identik dengan literal asli sebelum dedup.'
        );
    }

    public function testNoStrayKeyLiteralsRemainInSource(): void
    {
        $keys    = array_values(self::EXPECTED_KEYS);
        $pattern = '/["\'](' . implode('|', array_map('preg_quote', $keys)) . ')["\']/';
        $files   = $this->allAssetsJsFiles();
        $this->assertNotEmpty($files, 'Tidak ada file JS yang dipindai.');
        foreach ($files as $rel) {
            $src = $this->readSource($rel);
            $this->assertSame(
                0,
                preg_match_all($pattern, $src),
                "Masih ada literal storage key di $rel — harus memakai MEEL_KEYS."
            );
        }
    }

    public function testAllMeelKeysReferencesAreDefined(): void
    {
        $defined = array_keys(self::EXPECTED_KEYS);
        $pattern = '/MEEL_KEYS\.([A-Z_]+)/';
        foreach ($this->allAssetsJsFiles() as $rel) {
            $src = $this->readSource($rel);
            if (preg_match_all($pattern, $src, $m)) {
                foreach ($m[1] as $ref) {
                    $this->assertContains(
                        $ref,
                        $defined,
                        "Referensi MEEL_KEYS.$ref di $rel tidak terdefinisi di state-keys.js."
                    );
                }
            }
        }
    }

    /* ── download-backup-codes.js (via harness Node + mock browser) ── */

    private static function nodeAvailable(): bool
    {
        $output = [];
        $code   = 0;
        exec('node --version 2>&1', $output, $code);
        return $code === 0;
    }

    /** @return array<string,mixed> Hasil observasi dari harness. */
    private function runNodeHarness(string $scenario): array
    {
        if (!self::nodeAvailable()) {
            $this->markTestSkipped('Node.js tidak tersedia di lingkungan ini.');
        }
        $harness = $this->rootPath() . '/tests/js/download-backup-codes.harness.js';
        $this->assertFileExists($harness, 'Harness tidak ditemukan.');
        $output = [];
        $exit   = 0;
        exec('node ' . escapeshellarg($harness) . ' ' . escapeshellarg($scenario) . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, 'Harness gagal: ' . implode("\n", $output));
        $decoded = json_decode(end($output), true);
        $this->assertIsArray($decoded, 'Output harness bukan JSON: ' . implode("\n", $output));
        return $decoded;
    }

    public function testDownloadBackupCodesNormalFormat(): void
    {
        $r = $this->runNodeHarness('normal');

        $this->assertSame(1, $r['clicked']);
        $this->assertSame(1, $r['appended']);
        $this->assertSame(1, $r['removed']);
        $this->assertTrue($r['blobCreated']);
        $this->assertSame('text/plain;charset=utf-8', $r['blobType']);
        $this->assertSame('MEeL-backup-codes-alice.txt', $r['downloadName']);
        $this->assertSame('blob:mock', $r['href']);

        $lines = explode("\n", $r['blobParts'][0]);
        $this->assertCount(10, $lines, 'Teks harus diakhiri newline (9 baris + trailing).');
        $this->assertSame('MEeL — MFA Backup Codes', $lines[0]);
        $this->assertSame('User: alice', $lines[1]);
        $this->assertMatchesRegularExpression('/^Generated: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $lines[2]);
        $this->assertSame('', $lines[3]);
        $this->assertSame('Setiap kode hanya bisa digunakan SEKALI.', $lines[4]);
        $this->assertSame('Simpan di tempat yang aman!', $lines[5]);
        $this->assertSame('', $lines[6]);
        $this->assertSame('  111111', $lines[7]);
        $this->assertSame('  222222', $lines[8]);
        $this->assertSame('', $lines[9]);
    }

    public function testDownloadBackupCodesEmptyCodesNoop(): void
    {
        foreach (['empty', 'unset'] as $scenario) {
            $r = $this->runNodeHarness($scenario);

            $this->assertFalse($r['blobCreated'], "[$scenario] Harus no-op.");
            $this->assertSame(0, $r['clicked'], "[$scenario] Harus no-op.");
            $this->assertSame(0, $r['appended'], "[$scenario] Harus no-op.");
            $this->assertSame(0, $r['removed'], "[$scenario] Harus no-op.");
            $this->assertNull($r['blobParts'], "[$scenario] Harus no-op.");
        }
    }

    public function testStateKeysRuntimeFreezeAndValues(): void
    {
        $r = $this->runNodeHarness('keys');

        $this->assertTrue($r['frozen'], 'MEEL_KEYS harus Object.freeze.');
        $this->assertTrue($r['mutationBlocked'], 'Mutasi properti MEEL_KEYS harus gagal.');
        $expected = self::EXPECTED_KEYS;
        $actual   = $r['keys'];
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual, 'Nilai runtime harus identik.');
    }

    public function testDownloadBackupCodesFallsBackToUser(): void
    {
        $r = $this->runNodeHarness('noUser');

        $this->assertTrue($r['blobCreated']);
        $this->assertSame('MEeL-backup-codes-user.txt', $r['downloadName']);
        $lines = explode("\n", $r['blobParts'][0]);
        $this->assertSame('User: user', $lines[1]);
    }
}
