<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers SwPrecache
 * Tests for manifest.php di setiap subfolder assets/css (daftar modul CSS)
 * dan generator precache service worker (SwPrecache):
 *  1. Setiap entri manifest harus merujuk file yang benar-benar ada.
 *  2. SEMUA folder modul yang punya manifest.php harus masuk precache SW —
 *     generator dinamis (SwPrecache) mengambilnya otomatis dari manifest.php,
 *     jadi menambah folder modul baru tidak perlu menyentuh sw.js lagi.
 *  3. Semua entri precache (aset tetap + modul) harus merujuk file yang ada.
 *  4. SW_VERSION hasil generator harus deterministik (tidak berubah antar
 *     pemanggilan) — penting agar browser tidak update SW tiap kunjungan.
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
     * Semua folder modul (yang punya manifest.php) harus ter-precache.
     * Generator SwPrecache::moduleAssets() mengambilnya otomatis dari
     * manifest.php — test ini menjaga agar list tetap sinkron.
     *
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

    /**
     * Setiap entri precache (aset tetap + semua modul) harus ada di disk —
     * mencegah daftar basi di SwPrecache::baseAssets() atau modul yang lupa
     * di-upload merusak install service worker (cache.addAll gagal total).
     */
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

    /**
     * SW_VERSION harus deterministik: dua pemanggilan menghasilkan nilai sama.
     * Format: v2-<hash 10 char>. Kalau tidak deterministik, browser akan
     * melakukan update service worker pada SETIAP kunjungan.
     */
    public function testSwVersionIsDeterministic(): void
    {
        $v1 = SwPrecache::version();
        $v2 = SwPrecache::version();

        $this->assertMatchesRegularExpression('/^v2-[0-9a-f]{10}$/', $v1, 'Format SW_VERSION tidak sesuai');
        $this->assertSame($v1, $v2, 'SW_VERSION harus deterministik antar pemanggilan');
    }
}
