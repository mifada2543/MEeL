<?php
// ─────────────────────────────────────────────────────────────────────────────
// SSRF-safe URL validation — the single source of truth for every outbound
// request the application makes on behalf of a user (currently the Advanced
// Upload / yt-dlp pipeline in Transcoder.php).
//
// SECURITY BOUNDARY:
//   * Only http/https are allowed. Nothing else may ever reach a subprocess.
//   * Hostnames are resolved up front and EVERY returned A/AAAA record is
//     checked against explicit private/reserved IPv4 & IPv6 ranges. A single
//     non-public record rejects the URL (blocks mixed-answer DNS tricks).
//   * IP literals (v4 & v6) are validated directly, with no DNS involvement.
//   * filter_var(FILTER_VALIDATE_URL) is NOT used for the security decision —
//     parsing is manual so unusual-but-valid representations behave predictably.
//   * A hostname denylist is kept as defense-in-depth only; the authoritative
//     check is always the resolved-address validation.
//   * Resolution fails closed: unresolvable hosts are rejected.
//
// The caller (Transcoder) additionally pins plain-HTTP connections to the
// validated public IP and forces the original Host header, which closes the
// DNS-rebinding window between validation and the real outbound request.
// ─────────────────────────────────────────────────────────────────────────────
final class SsrfGuard
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    // Defense-in-depth hostname patterns. These are NOT the primary control
    // (DNS validation is), but they catch obvious special names even when the
    // system resolver behaves unexpectedly.
    private const BLOCKED_EXACT_HOSTS = [
        'localhost',
        'localhost.localdomain',
        'ip6-localhost',
        'ip6-loopback',
        'broadcasthost',
    ];
    private const BLOCKED_HOST_SUFFIXES = [
        '.localhost',
        '.local',
        '.localdomain',
        '.internal',
        '.lan',
        '.home.arpa',
        '.test',
        '.example',
        '.invalid',
        '.onion',
    ];

    /**
     * Validate a user-supplied URL for outbound use.
     *
     * @param string $url The raw URL (scheme, host, optional port/path/query).
     * @throws \RuntimeException With a safe, generic message when the URL is
     *         not safe to fetch. Never includes the URL, IPs or resolver details.
     */
    public function validate(string $url): void
    {
        if (strlen($url) > 2048) {
            throw new \RuntimeException('URL terlalu panjang.');
        }

        // Strict, manual scheme allowlist. This also rejects scheme-less
        // inputs and protocol-relative URLs ("//host/path").
        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Protokol URL tidak didukung.');
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new \RuntimeException('URL tidak valid.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new \RuntimeException('Protokol URL tidak didukung.');
        }

        // Reject embedded credentials — they add no legitimate value here and
        // are commonly used to obscure the real destination host.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('URL tidak valid.');
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($host === '' || !$this->isAsciiHost($host)) {
            throw new \RuntimeException('URL tidak valid.');
        }

        if ($this->isBlockedHostname($host)) {
            throw new \RuntimeException('Alamat tujuan tidak diizinkan.');
        }

        // Validating here performs the DNS resolution once and checks every
        // address. The caller may reuse resolvePublicAddresses() for the same
        // host in the same request to pin the connection (no second, unrelated
        // lookup is used for a security decision).
        $this->resolvePublicAddresses($host);
    }

    /**
     * Resolve a host to its public addresses, rejecting the host if ANY
     * A/AAAA record is non-public or if no records exist (fail closed).
     *
     * @param string $host Hostname or IP literal (IPv6 may be in brackets).
     * @return string[] Public IP addresses (dotted quad / canonical IPv6).
     * @throws \RuntimeException On private/reserved addresses or resolution failure.
     */
    public function resolvePublicAddresses(string $host): array
    {
        $host = strtolower(rtrim($host, '.'));

        // IP literal → validate directly, no DNS round-trip at all.
        $literal = $this->extractIpLiteral($host);
        if ($literal !== null) {
            if ($this->isPrivateIp($literal)) {
                throw new \RuntimeException('Alamat tujuan tidak diizinkan.');
            }
            return [$literal];
        }

        // Non-ASCII (IDN) hostnames are rejected: punycode handling would need
        // a second representation and hides the resolved address from review.
        if (!$this->isAsciiHost($host)) {
            throw new \RuntimeException('URL tidak valid.');
        }

        if ($this->isBlockedHostname($host)) {
            throw new \RuntimeException('Alamat tujuan tidak diizinkan.');
        }

        if (!function_exists('dns_get_record')) {
            // Fail closed: without a resolver we cannot guarantee safety.
            throw new \RuntimeException('Resolusi alamat tidak tersedia.');
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            throw new \RuntimeException('Hostname tidak dapat di-resolve.');
        }

        $addresses = [];
        foreach ($records as $record) {
            if (($record['type'] ?? '') === 'A' && !empty($record['ip'])) {
                $addresses[] = $record['ip'];
            } elseif (($record['type'] ?? '') === 'AAAA' && !empty($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }
        if ($addresses === []) {
            throw new \RuntimeException('Hostname tidak dapat di-resolve.');
        }

        $addresses = array_values(array_unique($addresses));
        foreach ($addresses as $address) {
            if ($this->isPrivateIp($address)) {
                // One bad record poisons the whole answer (DNS rebinding
                // defenses rely on rejecting mixed public/private answers).
                throw new \RuntimeException('Alamat tujuan tidak diizinkan.');
            }
        }

        return $addresses;
    }

    /**
     * Explicit private/reserved range check for a single IP (IPv4 or IPv6).
     *
     * @param string $ip IP in dotted-quad or canonical IPv6 notation.
     * @return bool True when the address must not be reached (or is unparsable).
     */
    public function isPrivateIp(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            // Unparsable → treat as unsafe (fail closed).
            return true;
        }

        $length = strlen($binary);
        if ($length === 4) {
            return $this->isPrivateIpv4($binary);
        }
        if ($length === 16) {
            return $this->isPrivateIpv6($binary);
        }

        return true;
    }

    /**
     * Rewrite a validated http:// URL so the connection is pinned to the
     * resolved public IP while preserving the original Host header.
     *
     * Used by the download pipeline to prevent a second (attacker-influenced)
     * DNS lookup between validation and the real request, and to make
     * cross-host redirects to private targets fail (the forced Host header no
     * longer matches the redirect target).
     *
     * @param string $url Validated http(s) URL.
     * @return array{0:string,1:string} [pinned_url, extra yt-dlp args] —
     *         https URLs are returned untouched (TLS SNI/cert validation must
     *         keep the hostname) together with an empty extra-args string.
     * @throws \RuntimeException When the URL is unsafe or unresolved.
     */
    public function pinHttpUrl(string $url): array
    {
        $this->validate($url);

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $hostRaw = (string) ($parts['host'] ?? '');
        $host = strtolower(rtrim($hostRaw, '.'));

        if ($scheme !== 'http' || $host === '') {
            return [$url, ''];
        }

        $addresses = $this->resolvePublicAddresses($host);
        $ip = $addresses[0];

        $isV6 = strpos($ip, ':') !== false;
        $ipHost = $isV6 ? '[' . $ip . ']' : $ip;

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        $pinnedUrl = $scheme . '://' . $ipHost . $port . $path . $query;

        // Keep the original hostname for the Host header. Brackets are ONLY
        // legal for IPv6 literals (RFC 7230) — never wrap a DNS hostname even
        // when it happened to resolve to an IPv6 address.
        $headerHost = $host;
        if ($this->extractIpLiteral($host) !== null
            && strpos($host, ':') !== false
            && !str_starts_with($headerHost, '[')
        ) {
            $headerHost = '[' . $headerHost . ']';
        }

        // LIMITATION (documented): https URLs are returned untouched — TLS
        // SNI/certificate validation requires the hostname. yt-dlp will follow
        // redirects; a redirect target is re-resolved by the client WITHOUT
        // passing through this guard. HTTP downloads are protected because the
        // forced Host header breaks cross-host redirects to private targets.
        // Full redirect-pinning for https would require a validating proxy and
        // is out of scope for this deployment.
        $extra = '--add-header ' . escapeshellarg('Host: ' . $headerHost . $port);
        return [$pinnedUrl, $extra];
    }

    private function isPrivateIpv4(string $binary): bool
    {
        $n = unpack('N', $binary)[1]; // unsigned 32-bit

        // 0.0.0.0/8
        if (($n >> 24) === 0) return true;
        // 10.0.0.0/8
        if (($n >> 24) === 10) return true;
        // 100.64.0.0/10 (CGNAT)
        if (($n & 0xFFC00000) === 0x64400000) return true;
        // 127.0.0.0/8
        if (($n >> 24) === 127) return true;
        // 169.254.0.0/16 (link-local)
        if (($n >> 16) === 0xA9FE) return true;
        // 172.16.0.0/12
        if (($n >> 20) === 0xAC1) return true;
        // 192.0.0.0/24 (IETF protocol assignments)
        if (($n >> 8) === 0xC00000) return true;
        // 192.0.2.0/24 (TEST-NET-1)
        if (($n >> 8) === 0xC00002) return true;
        // 192.168.0.0/16
        if (($n >> 16) === 0xC0A8) return true;
        // 198.18.0.0/15 (benchmarking)
        if (($n & 0xFFFE0000) === 0xC6120000) return true;
        // 198.51.100.0/24 (TEST-NET-2)
        if (($n >> 8) === 0xC63364) return true;
        // 203.0.113.0/24 (TEST-NET-3)
        if (($n >> 8) === 0xCB0071) return true;
        // 224.0.0.0/4 multicast + 240.0.0.0/4 reserved + broadcast
        if (($n >> 28) >= 0xE) return true;

        return false;
    }

    private function isPrivateIpv6(string $binary): bool
    {
        $bytes = array_values(unpack('C16', $binary));

        // ::/128 (unspecified)
        if ($binary === str_repeat("\0", 16)) return true;
        // ::1/128 (loopback)
        if (substr($binary, 0, 15) === str_repeat("\0", 15) && $bytes[15] === 1) return true;
        // ::ffff:0:0/96 — IPv4-mapped: validate the embedded IPv4 explicitly
        // so ::ffff:127.0.0.1 etc. are caught by the IPv4 range logic.
        if (substr($binary, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            return $this->isPrivateIpv4(substr($binary, 12, 4));
        }
        // 64:ff9b::/96 (NAT64 well-known prefix)
        if (substr($binary, 0, 8) === "\x00\x64\xff\x9b\x00\x00\x00\x00") return true;
        // 100::/64 (discard-only)
        if (substr($binary, 0, 8) === "\x01\x00\x00\x00\x00\x00\x00\x00") return true;
        // 2001:db8::/32 (documentation)
        if (substr($binary, 0, 4) === "\x20\x01\x0d\xb8") return true;
        // 2001:10::/28 (ORCHID) — 2001:0010:xxxx::/28, i.e. byte3 in 0x10–0x1F
        if ($bytes[0] === 0x20 && $bytes[1] === 0x01 && $bytes[2] === 0x00 && ($bytes[3] & 0xF0) === 0x10) return true;
        // 2002::/16 (6to4)
        if (substr($binary, 0, 2) === "\x20\x02") return true;
        // 3fff::/20 (documentation)
        if ($bytes[0] === 0x3f && ($bytes[1] & 0xF0) === 0xF0) return true;
        // fc00::/7 (unique-local)
        if (($bytes[0] & 0xFE) === 0xFC) return true;
        // fe80::/10 (link-local)
        if ($bytes[0] === 0xFE && ($bytes[1] & 0xC0) === 0x80) return true;
        // fec0::/10 (site-local, deprecated)
        if ($bytes[0] === 0xFE && ($bytes[1] & 0xC0) === 0xC0) return true;
        // ff00::/8 (multicast)
        if ($bytes[0] === 0xFF) return true;

        return false;
    }

    private function isBlockedHostname(string $host): bool
    {
        if (in_array($host, self::BLOCKED_EXACT_HOSTS, true)) {
            return true;
        }
        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }
        return false;
    }

    private function isAsciiHost(string $host): bool
    {
        return preg_match('/^[\x00-\x7F]+$/D', $host) === 1;
    }

    /**
     * @return string|null The bare IP when $host is an IPv4 or IPv6 literal
     *         (brackets stripped), otherwise null.
     */
    private function extractIpLiteral(string $host): ?string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $candidate = substr($host, 1, -1);
            return filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? $candidate
                : null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $host;
        }
        return null;
    }
}
