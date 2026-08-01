<?php
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 * Tests for manifest.php di setiap subfolder assets/css (daftar modul CSS):
 *  1. Setiap entri manifest harus merujuk file yang benar-benar ada.
 *  2. Daftar modul per folder harus sama persis dengan daftar precache di
 *     sw.js (folder video/music/drive/books/admin di-pre-cache; engine & up
 *     tidak di-pre-cache sehingga dilewati dengan markTestSkipped).
 */
class CssManifestTest extends TestCase
{
    /**
     * Semua manifest.php di assets/css/<folder>/.
     *
     * @return array<string, array{0: string}>
     */
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
    public function testManifestModulesMatchSwPrecache(string $manifest): void
    {
        $folder = basename(dirname($manifest));
        $mods   = require $manifest;

        $expected = [];
        foreach ($mods as $mod) {
            $expected[] = "assets/css/{$folder}/{$mod}";
        }
        sort($expected);

        $sw = file_get_contents(MEEL_ROOT . '/sw.js');
        $this->assertNotFalse($sw, 'sw.js tidak bisa dibaca');

        preg_match_all('/^\s*\'([^\']+)\',/m', $sw, $m);
        $precached = array_values(array_filter(
            $m[1],
            static fn (string $url): bool => str_starts_with($url, "assets/css/{$folder}/")
        ));
        sort($precached);

        if ($precached === []) {
            $this->markTestSkipped(
                "Folder '{$folder}' tidak di-pre-cache di sw.js — tidak ada daftar untuk dibandingkan."
            );
            return;
        }

        $this->assertSame(
            $expected,
            $precached,
            "Daftar modul '{$folder}' di sw.js tidak sinkron dengan assets/css/{$folder}/manifest.php"
        );
    }
}
