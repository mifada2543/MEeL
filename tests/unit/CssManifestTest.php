<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers SwPrecache
 */
class CssManifestTest extends TestCase
{
    
    public static function manifestProvider(): array
    {
        $out = [];
        foreach (glob(MEEL_ROOT . '/assets/css/*/manifest.php') ?: [] as $manifest) {
            $folder = basename(dirname($manifest));
            $out[$folder] = [$manifest];
        }
        ksort($out);
        return $out;
    }

    /**
     * @dataProvider manifestProvider
     */
    public function testEveryManifestEntryResolvesToFile(string $manifest): void
    {
        $mods = require $manifest;

        $this->assertIsArray($mods, 'manifest.php harus mengembalikan array');
        $this->assertNotEmpty($mods, 'manifest.php tidak boleh kosong');

        $dir = dirname($manifest);
        foreach ($mods as $i => $mod) {
            $this->assertIsString($mod, "Entri #{$i} harus string");
            $this->assertFileExists(
                $dir . '/' . $mod,
                "Entri #{$i} '{$mod}' di " . basename(dirname($manifest)) . '/manifest.php tidak ditemukan'
            );
        }
    }

    /**
     * @dataProvider manifestProvider
     */
    public function testAllManifestFoldersArePrecached(string $manifest): void
    {
        $folder = basename(dirname($manifest));
        $mods   = require $manifest;

        $expected = [];
        foreach ($mods as $mod) {
            $expected[] = "assets/css/{$folder}/{$mod}";
        }
        sort($expected);

        $precached = array_values(array_filter(
            SwPrecache::all(),
            static fn (string $url): bool => str_starts_with($url, "assets/css/{$folder}/")
        ));
        sort($precached);

        $this->assertSame(
            $expected,
            $precached,
            "Daftar modul '{$folder}' di precache SW tidak sinkron dengan assets/css/{$folder}/manifest.php"
        );
    }

    public function testAllPrecacheEntriesResolveToFile(): void
    {
        $missing = [];
        foreach (SwPrecache::all() as $rel) {
            if (!is_file(MEEL_ROOT . '/' . $rel)) {
                $missing[] = $rel;
            }
        }
        $this->assertSame(
            [],
            $missing,
            'Entri precache berikut tidak ditemukan di disk (SW install akan gagal):'
        );
    }

    public function testSwVersionIsDeterministic(): void
    {
        $v1 = SwPrecache::version();
        $v2 = SwPrecache::version();

        $this->assertMatchesRegularExpression('/^v2-[0-9a-f]{10}$/', $v1, 'Format SW_VERSION tidak sesuai');
        $this->assertSame($v1, $v2, 'SW_VERSION harus deterministik antar pemanggilan');
    }
}
