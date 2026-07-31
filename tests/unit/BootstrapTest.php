<?php
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 * Tests for environment-based constant definitions in modules/core/bootstrap.php.
 *
 * Khususnya relasi:  APP_DEBUG === (MEEL_ENV === 'development')
 *
 * NOTE: bootstrap.php memakai define() yang hanya bisa dijalankan SEKALI per
 * proses PHP. Karena itu setiap skenario diuji dalam subprocess terisolasi
 * (PHP_BINARY) — pola yang sama dengan tests/functional_test.php.
 */
class BootstrapTest extends TestCase
{
    /**
     * Jalankan bootstrap.php dalam subprocess dengan $_SERVER yang disimulasikan.
     *
     * @param string $remoteAddr Nilai $_SERVER['REMOTE_ADDR']
     * @param string $serverName Nilai $_SERVER['SERVER_NAME']
     * @param string $prelude    Kode PHP yang dijalankan SEBELUM require bootstrap
     *                           (untuk menguji override manual define('APP_DEBUG', ...)
     *                           atau define('MEEL_ENV', ...)).
     * @return array{0: string, 1: bool} [MEEL_ENV, APP_DEBUG(bool)]
     */
    private function probe(string $remoteAddr, string $serverName, string $prelude = ''): array
    {
        $bootstrap = realpath(__DIR__ . '/../../modules/core/bootstrap.php');
        $this->assertNotFalse($bootstrap, 'modules/core/bootstrap.php tidak ditemukan');

        $code = $prelude
            . '$_SERVER["REMOTE_ADDR"]=' . var_export($remoteAddr, true) . ';'
            . '$_SERVER["SERVER_NAME"]=' . var_export($serverName, true) . ';'
            . 'require ' . var_export($bootstrap, true) . ';'
            . 'echo MEEL_ENV . "|" . (APP_DEBUG ? "1" : "0");';

        $output = [];
        $exit   = 0;
        exec(PHP_BINARY . ' -d display_errors=0 -r ' . escapeshellarg($code) . ' 2>&1', $output, $exit);

        $this->assertSame(0, $exit, 'Subprocess bootstrap gagal: ' . implode("\n", $output));
        $this->assertCount(1, $output, 'Output subprocess tidak valid: ' . implode("\n", $output));

        [$env, $debug] = explode('|', trim($output[0]));

        return [$env, $debug === '1'];
    }

    /**
     * @dataProvider environmentProvider
     */
    public function testAppDebugFollowsEnvironment(
        string $remoteAddr,
        string $serverName,
        string $prelude,
        string $expectedEnv,
        bool   $expectedDebug,
        bool   $expectInvariant
    ): void {
        [$env, $debug] = $this->probe($remoteAddr, $serverName, $prelude);

        $this->assertSame($expectedEnv, $env);
        $this->assertSame($expectedDebug, $debug);

        // Invariant utama: tanpa override manual, APP_DEBUG === (MEEL_ENV === 'development')
        if ($expectInvariant) {
            $this->assertSame($env === 'development', $debug);
        }
    }

    public static function environmentProvider(): array
    {
        return [
            'localhost auto-detect → development, debug ON'  => [
                '127.0.0.1', 'localhost', '', 'development', true, true,
            ],
            'server_name localhost → development, debug ON'  => [
                '8.8.8.8', 'localhost', '', 'development', true, true,
            ],
            'remote host → production, debug OFF'            => [
                '8.8.8.8', 'meel.example.com', '', 'production', false, true,
            ],
            'maintenance mode → debug OFF'                   => [
                '127.0.0.1', 'localhost', "define('MEEL_ENV', 'maintenance');",
                'maintenance', false, true,
            ],
            'override true menang di produksi'               => [
                '8.8.8.8', 'meel.example.com', "define('APP_DEBUG', true);",
                'production', true, false,
            ],
            'override false menang di development'           => [
                '127.0.0.1', 'localhost', "define('APP_DEBUG', false);",
                'development', false, false,
            ],
        ];
    }
}
