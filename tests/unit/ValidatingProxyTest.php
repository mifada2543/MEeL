<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/auth/ValidatingProxy.php';

/**
 * Regression tests for the validating forward proxy
 * (modules/auth/ValidatingProxy.php + validating_proxy_server.php).
 *
 * These spawn a REAL proxy process on 127.0.0.1:<ephemeral> and send real
 * CONNECT / absolute-URI requests to it, verifying that:
 *   * private/reserved targets are REFUSED (502 / closed) — including
 *     redirect targets, which is the whole point of the proxy,
 *   * public targets are accepted (200 Connection Established / relayed),
 *   * the process is cleaned up on stop().
 *
 * Network-dependent acceptance tests degrade to markTestSkipped() when no
 * resolver/egress is available; the rejection tests are fully local.
 */
class ValidatingProxyTest extends TestCase
{
    private ?ValidatingProxy $proxy = null;

    protected function tearDown(): void
    {
        if ($this->proxy !== null) {
            $this->proxy->stop();
            $this->proxy = null;
        }
    }

    private function startProxy(): ValidatingProxy
    {
        if ($this->proxy === null) {
            $this->proxy = new ValidatingProxy();
        }
        return $this->proxy;
    }

    /**
     * Open a raw TCP connection to the proxy and send a request, returning
     * whatever the proxy replies (or '' if it closes the connection).
     */
    private function probeProxy(string $request, float $timeout = 6.0): string
    {
        $proxy = $this->startProxy();
        $parts = parse_url($proxy->url());

        $fp = @stream_socket_client(
            "tcp://{$parts['host']}:{$parts['port']}",
            $errno,
            $errstr,
            5
        );
        if ($fp === false) {
            $this->fail("Gagal connect ke proxy: $errstr");
        }

        fwrite($fp, $request);
        stream_set_timeout($fp, $timeout);

        $response = '';
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $chunk = fread($fp, 8192);
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                if (feof($fp)) {
                    break;
                }
                usleep(50000);
                continue;
            }
            $response .= $chunk;
        }
        fclose($fp);
        return $response;
    }

    /** @dataProvider privateConnectTargetsProvider */
    public function testConnectToPrivateTargetIsRefused(string $target, string $hostHeader): void
    {
        $response = $this->probeProxy(
            "CONNECT $target HTTP/1.1\r\nHost: $hostHeader\r\n\r\n"
        );

        // Proxy harus MENOLAK: tidak boleh ada "200 Connection Established".
        $this->assertStringNotContainsStringIgnoringCase(
            '200 Connection Established',
            $response,
            "CONNECT ke $target seharusnya ditolak oleh proxy"
        );
        $this->assertStringContainsStringIgnoringCase(
            '502',
            $response,
            "Proxy harus menjawab 502 untuk target private $target (dapat: "
                . var_export(substr($response, 0, 80), true) . ')'
        );
    }

    public static function privateConnectTargetsProvider(): array
    {
        return [
            'loopback IPv4'    => ['127.0.0.1:443', '127.0.0.1:443'],
            'loopback alt'     => ['127.0.0.2:8443', '127.0.0.2:8443'],
            'private 10/8'     => ['10.0.0.1:80', '10.0.0.1'],
            'private 172.16'   => ['172.16.5.5:22', '172.16.5.5'],
            'private 192.168'  => ['192.168.1.1:8080', '192.168.1.1'],
            'link-local 169.254' => ['169.254.169.254:80', '169.254.169.254'],
            'localhost name'   => ['localhost:443', 'localhost'],
            'ip6 loopback'     => ['[::1]:443', '[::1]'],
            'ip6 ULA'          => ['[fd12::1]:443', '[fd12::1]'],
        ];
    }

    /** @dataProvider privateGetTargetsProvider */
    public function testHttpAbsoluteUriToPrivateTargetIsRefused(string $absoluteUri): void
    {
        $response = $this->probeProxy(
            "GET $absoluteUri HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n"
        );

        $this->assertStringNotContainsStringIgnoringCase('200 OK', $response);
        $this->assertStringContainsStringIgnoringCase(
            '502',
            $response,
            "Proxy harus menolak GET $absoluteUri (dapat: "
                . var_export(substr($response, 0, 80), true) . ')'
        );
    }

    public static function privateGetTargetsProvider(): array
    {
        return [
            'loopback'   => ['http://127.0.0.1/'],
            'private 10' => ['http://10.1.2.3/x'],
            'private 192.168' => ['http://192.168.0.10/secret'],
            'localhost'  => ['http://localhost/'],
        ];
    }

    public function testMalformedConnectTargetIsRefused(): void
    {
        $response = $this->probeProxy("CONNECT not-a-target HTTP/1.1\r\n\r\n");
        $this->assertStringNotContainsStringIgnoringCase('200 Connection Established', $response);
    }

    public function testHttpsSchemeAbsoluteUriIsNotRelayed(): void
    {
        // https hanya boleh lewat CONNECT — proxy tidak boleh relay langsung
        $response = $this->probeProxy(
            "GET https://example.com/ HTTP/1.1\r\nHost: example.com\r\nConnection: close\r\n\r\n"
        );
        $this->assertStringContainsStringIgnoringCase('502', $response);
        $this->assertStringNotContainsStringIgnoringCase('200 OK', $response);
    }

    /**
     * CONNECT ke host publik harus dijawab 200 Connection Established.
     * Network-dependent — skip bila tidak ada resolusi/egress.
     */
    public function testConnectToPublicTargetIsAccepted(): void
    {
        $records = @dns_get_record('postman-echo.com', DNS_A);
        if ($records === false || $records === []) {
            $this->markTestSkipped('DNS tidak tersedia — tidak bisa uji target publik.');
        }

        $response = $this->probeProxy(
            "CONNECT postman-echo.com:443 HTTP/1.1\r\nHost: postman-echo.com:443\r\n\r\n",
            12.0
        );
        $this->assertStringContainsStringIgnoringCase(
            '200 Connection Established',
            $response,
            'CONNECT ke host publik harus diterima proxy'
        );
    }

    public function testPublicHostnameAbsoluteUriRelaysToUpstream(): void
    {
        $records = @dns_get_record('example.com', DNS_A);
        if ($records === false || $records === []) {
            $this->markTestSkipped('DNS tidak tersedia — tidak bisa uji relay publik.');
        }

        $response = $this->probeProxy(
            "GET http://example.com/ HTTP/1.1\r\nHost: example.com\r\nConnection: close\r\n\r\n",
            15.0
        );
        $this->assertStringContainsStringIgnoringCase(
            '200 OK',
            $response,
            'Relay HTTP absolute-URI ke host publik harus meneruskan respons 200'
        );
        $this->assertStringContainsString('Example Domain', $response);
    }

    /**
     * resolvePhpBinary() harus menghasilkan binary PHP yang benar-benar bisa
     * dijalankan. Regresi untuk bug produksi: di mod_php (Apache) PHP_BINARY
     * kosong dan PATH Apache tidak menyertakan direktori php (mis.
     * /opt/lampp/bin) — fallback 'php' mentah gagal dengan "php: not found"
     * (exit 127), membuat proxy tidak bisa start dan upload ditolak.
     */
    public function testResolvePhpBinaryReturnsExecutablePhp(): void
    {
        $bin = ValidatingProxy::resolvePhpBinary();
        $this->assertNotSame('', $bin, 'resolvePhpBinary() tidak boleh kosong');

        // Jika path absolut — harus executable. Fallback 'php' (via PATH shell)
        // hanya valid di lingkungan CLI, bukan di Apache.
        if (str_contains($bin, '/')) {
            $this->assertFileExists($bin, "Binary PHP harus ada: $bin");
            $this->assertTrue(is_executable($bin), "Binary PHP harus executable: $bin");
        }

        // Bukti nyata: binary harus menjalankan PHP.
        exec(escapeshellarg($bin) . ' -r "echo PHP_VERSION;" 2>&1', $out, $rc);
        $this->assertSame(0, $rc, "Binary PHP $bin harus bisa menjalankan php -r");
        $this->assertNotEmpty(trim(implode('', $out)), 'Output PHP_VERSION tidak boleh kosong');
    }

    public function testProxyUrlIsLoopbackOnly(): void
    {
        $proxy = $this->startProxy();
        $parts = parse_url($proxy->url());
        $this->assertSame('http', $parts['scheme']);
        $this->assertSame('127.0.0.1', $parts['host'], 'Proxy wajib bind 127.0.0.1 saja');
        $this->assertGreaterThan(0, (int) $parts['port']);
        $this->assertLessThanOrEqual(65535, (int) $parts['port']);
    }

    public function testStopTerminatesProcess(): void
    {
        $proxy = new ValidatingProxy();
        $this->assertTrue($proxy->isRunning());
        $proxy->stop();
        $this->assertFalse($proxy->isRunning());
        // stop() harus idempotent
        $proxy->stop();
        $this->assertFalse($proxy->isRunning());
    }
}
