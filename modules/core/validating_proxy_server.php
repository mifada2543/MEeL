<?php
// modules/core/validating_proxy_server.php
// ─────────────────────────────────────────────────────────────────────────────
// Validating forward proxy (CLI ONLY). yt-dlp runs behind this proxy so that
// EVERY destination — including every redirect hop — is resolved and checked
// against SsrfGuard before any bytes are exchanged. This closes the open
// redirect → private IP SSRF gap that a single pre-flight validation cannot
// cover (redirects are re-resolved by the client itself).
//
//   * HTTP requests arrive as absolute-URI requests (GET http://host/...):
//     the proxy validates the target, then re-writes the request to
//     origin-form and forwards it to the validated public IP.
//   * HTTPS requests arrive as CONNECT host:port: the proxy validates the
//     target, answers 200 Connection Established, then byte-tunnels.
//   * A rejected target (private/reserved IP, blocked hostname, unresolvable
//     host, malformed request) is refused with an error response — the
//     downloader can never reach it.
//
// SECURITY BOUNDARY:
//   * Binds to 127.0.0.1 only. No external party can use this proxy.
//   * The Host header the client sends is preserved for the upstream request,
//     so TLS SNI / virtual-host routing is unaffected.
//   * Resolution is done ONCE per hop and the connection goes to the resolved
//     public IP — the same DNS-rebinding protection as pinHttpUrl().
//   * Fail closed: anything the guard cannot positively accept is refused.
//
// USAGE (spawned by ValidatingProxy.php — do not run manually):
//     php validating_proxy_server.php
//   Prints "PORT <n>\nREADY\n" on stdout once the listener is bound, then
//   serves until terminated (SIGTERM).
// ─────────────────────────────────────────────────────────────────────────────

if (PHP_SAPI !== 'cli') {
    exit(1); // web access never reaches the proxy
}

require_once __DIR__ . '/SsrfGuard.php';

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

const PROXY_IDLE_TIMEOUT = 300; // detik per koneksi tanpa aktivitas
const PROXY_MAX_LIFETIME = 3600; // detik maksimal hidup proses
const PROXY_CHUNK = 65536;

// ─── Listener ───

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "ERR bind: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
$port = (int) substr(strrchr($name, ':'), 1);

// Parent tells the spawner the chosen port, then signals readiness.
fwrite(STDOUT, "PORT $port\nREADY\n");
fflush(STDOUT);

$guard = new SsrfGuard();

// Reap child yang selesai secara asinkron — cegah zombie menumpuk di antara
// dua koneksi (pcntl_waitpid WNOHANG saja di loop tidak cukup cepat).
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGCHLD, static function (): void {
        pcntl_waitpid(-1, $status, WNOHANG);
    });
}

// ─── Request parsing ───

/**
 * Read the HTTP request head (request line + headers) from a client socket.
 * @return string[]|null [method, target, version, header_lines] or null on error
 */
/**
 * Baca header request sampai terminator \r\n\r\n (dengan timeout & batas ukuran).
 * @return array{0: string, 1: string, 2: string, 3: list<string>}|null [method, target, version, baris-header]
 */
function readRequestHead($client): ?array
{
    $head = '';
    $deadline = microtime(true) + 30;
    while (strpos($head, "\r\n\r\n") === false) {
        $chunk = @fread($client, 8192);
        if ($chunk === false || ($chunk === '' && feof($client))) {
            return null;
        }
        if ($chunk !== '') {
            $head .= $chunk;
            if (strlen($head) > 65536) {
                return null; // header terlalu besar — tolak
            }
        }
        if (microtime(true) > $deadline) {
            return null;
        }
        // Beri waktu CPU ke proses lain sambil menunggu data
        usleep(2000);
    }

    $lines   = explode("\r\n", $head);
    $request = array_shift($lines);
    $parts   = preg_split('/\s+/', trim($request));
    if (count($parts) < 3) {
        return null;
    }

    return [$parts[0], $parts[1], $parts[2], $lines];
}

/**
 * Parse "host" / "host:port" / "[v6]" / "[v6]:port" into [host, port].
 */
function parseTarget(string $target, int $defaultPort): array
{
    $target = trim($target);
    if (str_starts_with($target, '[')) {
        $end = strpos($target, ']');
        if ($end === false) {
            return ['', $defaultPort];
        }
        $host = substr($target, 1, $end - 1);
        $rest = substr($target, $end + 1);
        $port = (int) ltrim($rest, ':');
        if ($port <= 0) {
            $port = $defaultPort;
        }
        return [$host, $port];
    }

    $parts = explode(':', $target);
    $host  = $parts[0];
    $port  = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : $defaultPort;
    if ($port <= 0 || $port > 65535) {
        $port = $defaultPort;
    }
    return [$host, $port];
}

/**
 * Validate a target host:port with SsrfGuard and open a TCP connection to the
 * first validated public IP. Throws RuntimeException when refused or on
 * connection failure — the caller turns that into a proxy error response.
 */
function connectValidated(SsrfGuard $guard, string $host, int $port)
{
    if ($host === '') {
        throw new RuntimeException('target kosong');
    }

    // resolvePublicAddresses() rejects private/reserved IPs, blocked
    // hostnames, non-ASCII hosts, unresolvable hosts, and mixed answers.
    // No separate, later lookup is used — we connect to the validated IP.
    $addresses = $guard->resolvePublicAddresses($host);
    if ($addresses === []) {
        throw new RuntimeException('tidak ada alamat publik');
    }

    $ip  = $addresses[0];
    $url = (strpos($ip, ':') !== false) ? "tcp://[$ip]:$port" : "tcp://$ip:$port";

    $errno = 0;
    $errstr = '';
    $upstream = @stream_socket_client($url, $errno, $errstr, 15);
    if ($upstream === false) {
        throw new RuntimeException('koneksi ke target gagal');
    }
    stream_set_timeout($upstream, PROXY_IDLE_TIMEOUT);
    return $upstream;
}

/** Send a plain-text error response and close. */
function refuse($client, string $reason): void
{
    @fwrite($client, "HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\nContent-Type: text/plain\r\n\r\nPROXY REFUSED: $reason");
    @fclose($client);
}

/** Bidirectional byte tunnel (CONNECT mode) until both sides close. */
function tunnel($client, $upstream): void
{
    $clientEof = false;
    $upEof = false;

    while (!($clientEof && $upEof)) {
        $read = [];
        if (!$clientEof) {
            $read[] = $client;
        }
        if (!$upEof) {
            $read[] = $upstream;
        }
        if ($read === []) {
            break;
        }
        $write = null;
        $except = null;
        $selected = @stream_select($read, $write, $except, PROXY_IDLE_TIMEOUT);
        if ($selected === false || $selected === 0) {
            break; // timeout / error — putuskan tunnel
        }

        foreach ($read as $socket) {
            $data = @fread($socket, PROXY_CHUNK);
            if ($data === false || ($data === '' && feof($socket))) {
                // EOF di satu sisi: half-close sisi lain (biarkan data sisa mengalir)
                if ($socket === $client) {
                    $clientEof = true;
                    @stream_socket_shutdown($upstream, STREAM_SHUT_WR);
                } else {
                    $upEof = true;
                    @stream_socket_shutdown($client, STREAM_SHUT_WR);
                }
                continue;
            }
            if ($data === '') {
                continue; // belum ada data
            }
            $target = ($socket === $client) ? $upstream : $client;
            @fwrite($target, $data);
        }
    }

    @fclose($client);
    @fclose($upstream);
}

/** Relay an HTTP absolute-URI request to the validated target. */
function relayHttp(SsrfGuard $guard, $client, string $method, string $target, string $version, array $headerLines): void
{
    $parts = parse_url($target);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        refuse($client, 'URL target tidak valid');
        return;
    }
    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'http') {
        // https hanya boleh lewat CONNECT — jangan pernah relay langsung
        refuse($client, 'skema tidak didukung');
        return;
    }

    $host = strtolower(rtrim((string) $parts['host'], '.'));
    $port = isset($parts['port']) ? (int) $parts['port'] : 80;
    if ($port <= 0 || $port > 65535) {
        $port = 80;
    }

    try {
        $upstream = connectValidated($guard, $host, $port);
    } catch (RuntimeException $e) {
        refuse($client, 'target tidak diizinkan atau tidak terjangkau');
        return;
    }

    // Rewrite ke origin-form + pertahankan Host asli (routing virtual host).
    $path = ($parts['path'] ?? '');
    if ($path === '') {
        $path = '/';
    }
    if (isset($parts['query']) && $parts['query'] !== '') {
        $path .= '?' . $parts['query'];
    }

    $out = "$method $path $version\r\n";
    foreach ($headerLines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        // Hop-by-hop headers TIDAK boleh diteruskan: Host (diganti satu),
        // Connection/Keep-Alive/TE/Upgrade (kita paksa Connection: close),
        // Proxy-* (milik proxy). Meneruskan Connection: keep-alive bisa membuat
        // upstream menahan socket terbuka dan menggantung relay di bawah.
        if (preg_match('/^(host|connection|proxy-connection|keep-alive|te|upgrade|transfer-encoding):/i', $trimmed)) {
            continue;
        }
        $out .= $trimmed . "\r\n";
    }
    $out .= "Host: $host:$port\r\n";
    $out .= "Connection: close\r\n\r\n";

    @fwrite($upstream, $out);

    // Relay respons (sampai EOF upstream — Connection: close di atas).
    $relayDeadline = microtime(true) + PROXY_IDLE_TIMEOUT;
    while (!feof($upstream)) {
        if (microtime(true) > $relayDeadline) {
            break; // upstream macet / tidak menutup koneksi — jangan loop tanpa batas
        }
        $chunk = @fread($upstream, PROXY_CHUNK);
        if ($chunk === false) {
            break;
        }
        if ($chunk === '') {
            $meta = stream_get_meta_data($upstream);
            if (!empty($meta['timed_out'])) {
                break; // timeout baca — putuskan relay
            }
            continue;
        }
        if (@fwrite($client, $chunk) === false) {
            break;
        }
    }

    @fclose($upstream);
    @fclose($client);
}

/** Handle one client connection (runs in a forked child). */
function handleClient($client, SsrfGuard $guard): void
{
    stream_set_timeout($client, PROXY_IDLE_TIMEOUT);

    $head = readRequestHead($client);
    if ($head === null) {
        @fclose($client);
        return;
    }
    [$method, $target, $version, $headerLines] = $head;
    $method = strtoupper($method);

    if ($method === 'CONNECT') {
        [$host, $port] = parseTarget($target, 443);
        try {
            $upstream = connectValidated($guard, $host, $port);
        } catch (RuntimeException $e) {
            refuse($client, 'target tidak diizinkan atau tidak terjangkau');
            return;
        }
        @fwrite($client, "HTTP/1.1 200 Connection Established\r\n\r\n");
        tunnel($client, $upstream);
        return;
    }

    // Metode lain → relay sebagai request HTTP absolute-URI
    relayHttp($guard, $client, $method, $target, $version, $headerLines);
}

// ─── Accept loop (fork per connection) ───

$startedAt = time();
while (time() - $startedAt < PROXY_MAX_LIFETIME) {
    $client = @stream_socket_accept($server, 300);
    if ($client === false) {
        // Reap zombie child tanpa blok
        if (function_exists('pcntl_waitpid')) {
            pcntl_waitpid(-1, $status, WNOHANG);
        }
        continue;
    }

    if (function_exists('pcntl_fork')) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            @fclose($client); // gagal fork — tolak koneksi
            continue;
        }
        if ($pid === 0) {
            // Child: tangani satu koneksi lalu keluar
            fclose($server);
            handleClient($client, $guard);
            exit(0);
        }
        // Parent: tutup salinan socket-nya, lanjut accept
        fclose($client);
        pcntl_waitpid(-1, $status, WNOHANG);
        continue;
    }

    // Fallback tanpa pcntl: tangani sekuensial (satu koneksi dalam satu waktu)
    handleClient($client, $guard);
}

fclose($server);
exit(0);
