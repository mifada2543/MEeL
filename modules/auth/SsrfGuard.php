<?php










final class SsrfGuard
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    
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

    

    public function validate(string $url): void
    {
        if (strlen($url) > 2048) {
            throw new \RuntimeException('URL terlalu panjang.');
        }

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

        
        
        $this->resolvePublicAddresses($host);
    }

    

    public function resolvePublicAddresses(string $host): array
    {
        $host = strtolower(rtrim($host, '.'));

        
        $literal = $this->extractIpLiteral($host);
        if ($literal !== null) {
            if ($this->isPrivateIp($literal)) {
                throw new \RuntimeException('Alamat tujuan tidak diizinkan.');
            }
            return [$literal];
        }

        if (!$this->isAsciiHost($host)) {
            throw new \RuntimeException('URL tidak valid.');
        }

        if ($this->isBlockedHostname($host)) {
            throw new \RuntimeException('Alamat tujuan tidak diizinkan.');
        }

        if (!function_exists('dns_get_record')) {
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
                throw new \RuntimeException('Alamat tujuan tidak diizinkan.');
            }
        }

        return $addresses;
    }

    

    public function isPrivateIp(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
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

        $headerHost = $host;
        if ($this->extractIpLiteral($host) !== null
            && strpos($host, ':') !== false
            && !str_starts_with($headerHost, '[')
        ) {
            $headerHost = '[' . $headerHost . ']';
        }

        
        
        
        
        $extra = '--add-header ' . escapeshellarg('Host: ' . $headerHost . $port);
        return [$pinnedUrl, $extra];
    }

    private function isPrivateIpv4(string $binary): bool
    {
        $n = unpack('N', $binary)[1];

        if (($n >> 24) === 0) return true;
        if (($n >> 24) === 10) return true;
        if (($n & 0xFFC00000) === 0x64400000) return true;
        if (($n >> 24) === 127) return true;
        if (($n >> 16) === 0xA9FE) return true;
        if (($n >> 20) === 0xAC1) return true;
        if (($n >> 8) === 0xC00000) return true;
        if (($n >> 8) === 0xC00002) return true;
        if (($n >> 16) === 0xC0A8) return true;
        if (($n & 0xFFFE0000) === 0xC6120000) return true;
        if (($n >> 8) === 0xC63364) return true;
        if (($n >> 8) === 0xCB0071) return true;
        if (($n >> 28) >= 0xE) return true;

        return false;
    }

    private function isPrivateIpv6(string $binary): bool
    {
        $bytes = array_values(unpack('C16', $binary));

        if ($binary === str_repeat("\0", 16)) return true;
        if (substr($binary, 0, 15) === str_repeat("\0", 15) && $bytes[15] === 1) return true;
        
        if (substr($binary, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            return $this->isPrivateIpv4(substr($binary, 12, 4));
        }
        if (substr($binary, 0, 8) === "\x00\x64\xff\x9b\x00\x00\x00\x00") return true;
        if (substr($binary, 0, 8) === "\x01\x00\x00\x00\x00\x00\x00\x00") return true;
        if (substr($binary, 0, 4) === "\x20\x01\x0d\xb8") return true;
        if ($bytes[0] === 0x20 && $bytes[1] === 0x01 && $bytes[2] === 0x00 && ($bytes[3] & 0xF0) === 0x10) return true;
        if (substr($binary, 0, 2) === "\x20\x02") return true;
        if ($bytes[0] === 0x3f && ($bytes[1] & 0xF0) === 0xF0) return true;
        if (($bytes[0] & 0xFE) === 0xFC) return true;
        if ($bytes[0] === 0xFE && ($bytes[1] & 0xC0) === 0x80) return true;
        if ($bytes[0] === 0xFE && ($bytes[1] & 0xC0) === 0xC0) return true;
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
