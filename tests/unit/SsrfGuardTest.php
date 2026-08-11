<?php
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the SSRF guard (modules/core/SsrfGuard.php).
 *
 * These tests verify actual REJECTION/ALLOWANCE behaviour of the guard, not
 * merely that a validation function exists. DNS-dependent cases degrade to
 * markTestSkipped() when no resolver/network is available (e.g. offline dev);
 * in CI (GitHub Actions) DNS is available and they run for real.
 */
class SsrfGuardTest extends TestCase
{
    private ?SsrfGuard $guard = null;

    protected function setUp(): void
    {
        $this->guard = new SsrfGuard();
    }

    private function assertRejected(string $url, string $reason = ''): void
    {
        try {
            $this->guard->validate($url);
        } catch (\RuntimeException $e) {
            $this->addToAssertionCount(1);
            return;
        }
        $this->fail("URL seharusnya DITOLAK: {$url}" . ($reason !== '' ? " ({$reason})" : ''));
    }

    private function assertAllowed(string $url): void
    {
        $this->guard->validate($url); // throws => test fails
        $this->addToAssertionCount(1);
    }

    /** @dataProvider privateIpv4Provider */
    public function testPrivateIpv4LiteralsAreRejected(string $ip): void
    {
        $this->assertTrue($this->guard->isPrivateIp($ip), "{$ip} harus private");
        $this->assertRejected("http://{$ip}/x");
    }

    public static function privateIpv4Provider(): array
    {
        return [
            '0.0.0.0/8'            => ['0.0.0.0'],
            '0.1.2.3'              => ['0.1.2.3'],
            '10/8'                 => ['10.0.0.1'],
            '10.255.255.255'       => ['10.255.255.255'],
            '100.64/10 CGNAT'      => ['100.64.0.1'],
            '100.127.255.254'      => ['100.127.255.254'],
            '127.0.0.1'            => ['127.0.0.1'],
            '127.0.0.2'            => ['127.0.0.2'],
            '169.254/16'           => ['169.254.169.254'],
            '172.16/12'            => ['172.16.0.1'],
            '172.31.255.255'       => ['172.31.255.255'],
            '192.0.0/24'           => ['192.0.0.8'],
            '192.0.2/24 TEST-NET'  => ['192.0.2.1'],
            '192.168/16'           => ['192.168.1.1'],
            '198.18/15'            => ['198.18.0.1'],
            '198.51.100/24'        => ['198.51.100.1'],
            '203.0.113/24'         => ['203.0.113.1'],
            'multicast 224/4'      => ['224.0.0.1'],
            'multicast 239'        => ['239.255.255.255'],
            'reserved 240/4'       => ['240.0.0.1'],
            'broadcast'            => ['255.255.255.255'],
        ];
    }

    /** @dataProvider privateIpv6Provider */
    public function testPrivateIpv6LiteralsAreRejected(string $ip): void
    {
        $this->assertTrue($this->guard->isPrivateIp($ip), "{$ip} harus private");
        $this->assertRejected("http://[{$ip}]/x");
    }

    public static function privateIpv6Provider(): array
    {
        return [
            ':: unspecified'       => ['::'],
            '::1 loopback'         => ['::1'],
            'IPv4-mapped 127.0.0.1' => ['::ffff:127.0.0.1'],
            'IPv4-mapped 10.0.0.1'  => ['::ffff:10.0.0.1'],
            '64:ff9b::/96 NAT64'   => ['64:ff9b::1'],
            '100::/64 discard'     => ['100::1'],
            '2001:db8::/32 doc'    => ['2001:db8::1'],
            '2001:10::/28 ORCHID'  => ['2001:10::1'],
            '2002::/16 6to4'       => ['2002::1'],
            '3fff::/20 doc'        => ['3fff::1'],
            'fc00::/7 ULA'         => ['fc00::1'],
            'fd12:3456::1 ULA'     => ['fd12:3456:abba::1'],
            'fe80::/10 link-local' => ['fe80::1'],
            'febf:: link-local'    => ['febf::1'],
            'fec0::/10 site-local' => ['fec0::1'],
            'ff02::/8 multicast'   => ['ff02::1'],
        ];
    }

    /** @dataProvider publicIpv4Provider */
    public function testPublicIpv4LiteralsAreAllowed(string $ip): void
    {
        $this->assertFalse($this->guard->isPrivateIp($ip), "{$ip} harus publik");
        $this->assertAllowed("http://{$ip}/x");
        $this->assertAllowed("https://{$ip}/x");
    }

    public static function publicIpv4Provider(): array
    {
        return [
            'Google DNS'      => ['8.8.8.8'],
            'Cloudflare DNS'  => ['1.1.1.1'],
            'example.com'     => ['93.184.216.34'],
            'random public'   => ['45.33.32.156'],
        ];
    }

    public function testPublicIpv6LiteralIsAllowed(): void
    {
        $this->assertFalse($this->guard->isPrivateIp('2606:4700::1111'));
        $this->assertAllowed('https://[2606:4700::1111]/');
    }

    /** @dataProvider unsupportedProtocolProvider */
    public function testUnsupportedProtocolsAreRejected(string $url): void
    {
        $this->assertRejected($url);
    }

    public static function unsupportedProtocolProvider(): array
    {
        return [
            'file'       => ['file:///etc/passwd'],
            'ftp'        => ['ftp://example.com/file'],
            'gopher'     => ['gopher://example.com'],
            'javascript' => ['javascript:alert(1)'],
            'data'       => ['data:text/html,hi'],
            'dict'       => ['dict://127.0.0.1:6379/info'],
            'scheme-less' => ['//127.0.0.1/x'],
        ];
    }

    /** @dataProvider malformedUrlProvider */
    public function testMalformedUrlsAreRejected(string $url): void
    {
        $this->assertRejected($url);
    }

    public static function malformedUrlProvider(): array
    {
        return [
            'empty'            => [''],
            'not a url'        => ['not a url'],
            'no host'          => ['http:///path'],
            'bare path'        => ['/etc/passwd'],
            'missing scheme'   => ['127.0.0.1/x'],
            'space in host'    => ['http://exa mple.com/'],
            'non-ascii host'   => ['http://пример.рф/'],
        ];
    }

    /** @dataProvider blockedHostnameProvider */
    public function testSpecialHostnamesAreRejected(string $url): void
    {
        $this->assertRejected($url);
    }

    public static function blockedHostnameProvider(): array
    {
        return [
            'localhost'             => ['http://localhost/'],
            'localhost port'        => ['http://localhost:8080/admin'],
            'localhost.localdomain' => ['http://localhost.localdomain/'],
            'ip6-localhost'         => ['http://ip6-localhost/'],
            'dot-local'             => ['http://printer.local/'],
            'dot-internal'          => ['http://metadata.google.internal/'],
            'dot-lan'               => ['http://nas.lan/'],
            'dot-test'              => ['http://foo.test/'],
            'dot-example'           => ['http://foo.example/'],
            'dot-invalid'           => ['http://foo.invalid/'],
            'dot-onion'             => ['http://xyz.onion/'],
        ];
    }

    public function testEmbeddedCredentialsAreRejected(): void
    {
        $this->assertRejected('http://user:pass@example.com/');
        $this->assertRejected('http://attacker@127.0.0.1/');
    }

    public function testTrailingDotHostIsNormalized(): void
    {
        $this->assertRejected('http://localhost./');
        $this->assertRejected('http://127.0.0.1./x');
    }

    /**
     * Hostname that resolves (via public DNS) to a private address must be
     * rejected — this is the core DNS-rebinding defence.
     */
    public function testHostnameResolvingToPrivateIpIsRejected(): void
    {
        $records = @dns_get_record('127.0.0.1.nip.io', DNS_A);
        if ($records === false || $records === []) {
            $this->markTestSkipped('DNS tidak tersedia di environment ini.');
        }
        $this->assertRejected('http://127.0.0.1.nip.io/x', 'hostname yang resolve ke private IP');
    }

    /** A real public hostname must pass validation. */
    public function testPublicHostnameIsAllowed(): void
    {
        $records = @dns_get_record('example.com', DNS_A);
        if ($records === false || $records === []) {
            $this->markTestSkipped('DNS tidak tersedia di environment ini.');
        }
        $this->assertAllowed('https://example.com/');
        $this->assertAllowed('http://www.youtube.com/watch?v=abc123');
    }

    // ─── HTTP connection pinning ───

    public function testPinHttpUrlRewritesHostToPublicIpAndForcesHostHeader(): void
    {
        $records = @dns_get_record('example.com', DNS_A);
        if ($records === false || $records === []) {
            $this->markTestSkipped('DNS tidak tersedia di environment ini.');
        }

        [$pinned, $extra] = $this->guard->pinHttpUrl('http://example.com/media/file.mp3');
        $this->assertStringStartsWith('http://', $pinned);
        $this->assertStringContainsString('/media/file.mp3', $pinned);
        // Host is rewritten to a validated public IP (never the hostname).
        $host = parse_url($pinned, PHP_URL_HOST);
        $this->assertNotFalse(filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4));
        $this->assertFalse($this->guard->isPrivateIp($host));
        $this->assertStringContainsString('--add-header', $extra);
        $this->assertStringContainsString('Host: example.com', $extra);
    }

    public function testPinHttpUrlKeepsPort(): void
    {
        $records = @dns_get_record('example.com', DNS_A);
        if ($records === false || $records === []) {
            $this->markTestSkipped('DNS tidak tersedia di environment ini.');
        }
        [$pinned] = $this->guard->pinHttpUrl('http://example.com:8080/x');
        $this->assertStringContainsString(':8080', $pinned);
    }

    public function testPinPublicIpv4LiteralHttpUrl(): void
    {
        [$pinned, $extra] = $this->guard->pinHttpUrl('http://8.8.8.8/media/x.mp3');
        $this->assertSame('http://8.8.8.8/media/x.mp3', $pinned);
        $this->assertStringContainsString('Host: 8.8.8.8', $extra);
    }

    public function testPinIpv6LiteralHttpUrlKeepsBracketsInHostHeader(): void
    {
        [$pinned, $extra] = $this->guard->pinHttpUrl('http://[2606:4700::1111]/x');
        $this->assertSame('http://[2606:4700::1111]/x', $pinned);
        // RFC 7230: brackets only for IP literals — here the literal itself.
        $this->assertStringContainsString('Host: [2606:4700::1111]', $extra);
    }

    public function testHttpsUrlIsNotPinnedButStillValidated(): void
    {
        // https must keep the hostname (TLS SNI/cert) — pinning returns the
        // original URL; validation still runs and rejects private hosts.
        [$pinned, $extra] = $this->guard->pinHttpUrl('https://8.8.8.8/x');
        $this->assertSame('https://8.8.8.8/x', $pinned);
        $this->assertSame('', $extra);

        $this->expectException(\RuntimeException::class);
        $this->guard->pinHttpUrl('https://127.0.0.1/x');
    }
}
