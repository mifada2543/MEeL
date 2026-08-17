#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Access denied. Jalankan dari terminal: php tests/check_deploy.php');
}

define('MEEL_ROOT', rtrim(realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'), '/'));

// ─── Argumen sederhana ───
$url         = null;   // URL basis project untuk probe HTTP (opsional)
$hddOverride = null;   // override MEEL_HDD_BASE untuk testing/CI (opsional)
$color       = true;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--url=')) {
        $url = rtrim(substr($a, 6), '/');
    } elseif (str_starts_with($a, '--hdd=')) {
        $hddOverride = rtrim(substr($a, 6), '/');
        if ($hddOverride === '') {
            fwrite(STDERR, "Nilai --hdd kosong (contoh: --hdd=/path/to/media)\n");
            exit(2);
        }
    } elseif ($a === '--no-color') {
        $color = false;
    } elseif ($a === '--help' || $a === '-h') {
        fwrite(STDOUT, <<<HELP
MEeL Deployment Health Check
  Verifikasi: MEEL_HDD_BASE, upload dirs (folder nyata / symlink deploy), .htaccess upload dirs, mod_rewrite.

Contoh:
  php tests/check_deploy.php
  php tests/check_deploy.php --url=http://localhost/MEeL
  php tests/check_deploy.php --hdd=/tmp/meel-storage/media
  php tests/check_deploy.php --no-color

Exit code: 0 = sehat, 1 = ada FAIL.

HELP);
        exit(0);
    } else {
        fwrite(STDERR, "Argumen tidak dikenal: {$a} (lihat --help)\n");
        exit(2);
    }
}

// ─── State & helper output ───
$passed = 0;
$warned = 0;
$failed = 0;

function c(string $code, string $s): string
{
    global $color;
    return $color ? "\033[{$code}m{$s}\033[0m" : $s;
}

function section(string $title): void
{
    echo "\n" . c('1;36', "== {$title} ==") . "\n";
}

function report(string $status, string $label, string $hint = ''): void
{
    global $passed, $warned, $failed;
    $tag = [
        'PASS' => c('1;32', 'PASS'),
        'WARN' => c('1;33', 'WARN'),
        'FAIL' => c('1;31', 'FAIL'),
    ][$status] ?? $status;

    if ($status === 'PASS') $passed++;
    if ($status === 'WARN') $warned++;
    if ($status === 'FAIL') $failed++;

    echo sprintf('  %-5s  %s', $tag, $label) . ($hint !== '' ? ' — ' . $hint : '') . "\n";
}

function cmd_output(string $cmd): ?string
{
    $out = @shell_exec($cmd . ' 2>&1');
    if (!is_string($out) || trim($out) === '') {
        return null;
    }
    if (preg_match('/:\s*(not found|No such file or directory)\b/i', $out)) {
        return null;
    }
    return $out;
}

// 1. MEEL_HDD_BASE
section('1. MEEL_HDD_BASE (Media Storage)');

$settingsFile = MEEL_ROOT . '/auth/settings.php';
if (!is_file($settingsFile)) {
    report('FAIL', 'auth/settings.php tidak ditemukan',
        'cp auth/settings.example.php auth/settings.php lalu atur MEEL_HDD_BASE');
    $settingsFile = MEEL_ROOT . '/auth/settings.example.php'; // deteksi placeholder
} else {
    try {
        require_once $settingsFile;
        report('PASS', 'auth/settings.php terbaca');
    } catch (\Throwable $e) {
        report('FAIL', 'auth/settings.php gagal di-load: ' . $e->getMessage());
    }
}

$hdd = $hddOverride !== null
    ? $hddOverride
    : (defined('MEEL_HDD_BASE') ? (string) MEEL_HDD_BASE : '');

// dibaca aplikasi (aplikasi tidak tahu apa-apa tentang --hdd).
if ($hddOverride !== null && defined('MEEL_HDD_BASE') && (string) MEEL_HDD_BASE !== '') {
    $realHdd = (string) MEEL_HDD_BASE;
    if (str_contains($realHdd, 'CHANGE_ME') || str_contains($realHdd, '/path/to/your')) {
        report('WARN', "settings.php masih placeholder: {$realHdd} (diabaikan karena --hdd)",
            'perbaiki auth/settings.php sebelum produksi');
    } elseif (!is_dir($realHdd)) {
        report('WARN', "settings.php menunjuk direktori yang tidak ada: {$realHdd} (diabaikan karena --hdd)",
            'mount storage atau perbaiki auth/settings.php');
    }
}

if ($hdd === '') {
    report('FAIL', 'MEEL_HDD_BASE tidak terdefinisi', 'atur di auth/settings.php');
} elseif (str_contains($hdd, 'CHANGE_ME') || str_contains($hdd, '/path/to/your')) {
    report('FAIL', "MEEL_HDD_BASE masih placeholder: {$hdd}", 'ganti dengan path storage asli');
} elseif (!is_dir($hdd)) {
    report('FAIL', "MEEL_HDD_BASE bukan direktori / belum di-mount: {$hdd}",
        'mount storage dulu — lihat docs/en/installation.md §5a (versi id: docs/id/installation.md §5a)');
} elseif (!is_readable($hdd)) {
    report('FAIL', "MEEL_HDD_BASE tidak readable: {$hdd}");
} elseif (!is_writable($hdd)) {
    report('FAIL', "MEEL_HDD_BASE tidak writable: {$hdd}", 'periksa owner/permission folder storage');
} else {
    report('PASS', "MEEL_HDD_BASE OK: {$hdd}" . ($hddOverride !== null ? ' (override --hdd)' : ''));

    // ─── Direktori turunan ───
    $derived = [
        'MEEL_HDD_VIDEO_UPLOAD' => ['video/upload',           'auto'],
        'MEEL_HDD_VIDEO_DIR'    => ['video/upload/video',     'auto'],
        'MEEL_HDD_THUMB_DIR'    => ['video/upload/thumbnail', 'manual'],
        'MEEL_HDD_MUSIC_UPLOAD' => ['music/upload',           'manual'],
        'MEEL_HDD_BOOKS_UPLOAD' => ['books/upload',           'manual'],
        'MEEL_HDD_DRIVE'        => ['drive',                  'auto'],
    ];
    foreach ($derived as $const => [$rel, $mode]) {

        $dir = ($hddOverride !== null || !defined($const))
            ? $hdd . '/' . $rel
            : rtrim((string) constant($const), '/');
        if (!is_dir($dir)) {
            $status = ($mode === 'auto') ? 'WARN' : 'FAIL';
            $hint = ($mode === 'auto')
                ? 'dibuat otomatis saat upload pertama — pastikan parent writable'
                : 'TIDAK dibuat otomatis — upload modul terkait AKAN GAGAL. Buat manual: mkdir -p ' . $dir . ' (atau jalankan install.sh)';
            report($status, "{$const} belum ada: {$dir}", $hint);
        }
    }

    $musicBase = ($hddOverride !== null || !defined('MEEL_HDD_MUSIC_UPLOAD'))
        ? $hdd . '/music/upload'
        : rtrim((string) constant('MEEL_HDD_MUSIC_UPLOAD'), '/');
    $booksBase = ($hddOverride !== null || !defined('MEEL_HDD_BOOKS_UPLOAD'))
        ? $hdd . '/books/upload'
        : rtrim((string) constant('MEEL_HDD_BOOKS_UPLOAD'), '/');

    $requiredSubdirs = [
        'music/upload/file'      => $musicBase . '/file',
        'music/upload/thumbnail' => $musicBase . '/thumbnail',
        'books/upload/pdf'       => $booksBase . '/pdf',
        'books/upload/thumbnail' => $booksBase . '/thumbnail',
    ];
    foreach ($requiredSubdirs as $label => $dir) {
        if (!is_dir($dir)) {
            report('FAIL', "{$label} belum ada: {$dir}",
                'TIDAK dibuat otomatis — upload modul terkait AKAN GAGAL. Buat manual: mkdir -p ' . $dir . ' (atau jalankan install.sh)');
        }
    }
}

// 2. Upload dirs (books / music / video)
section('2. Upload dirs (books / music / video)');

foreach (['books', 'music', 'video'] as $m) {
    $path  = MEEL_ROOT . "/{$m}/upload";
    $label = "{$m}/upload";

    if (is_link($path)) {
        $target    = (string) readlink($path);
        $targetDir = rtrim($target, '/');
        if (!is_dir($targetDir)) {
            report('FAIL', $label, "symlink menunjuk ke target yang TIDAK ADA: {$target} (storage belum di-mount?)");
            continue;
        }
        $expected = $hdd !== '' ? rtrim($hdd, '/') . "/{$m}/upload" : '';
        if ($expected !== '' && $targetDir !== rtrim($expected, '/')) {
            report('WARN', $label, "target ≠ MEEL_HDD_BASE: {$target} (diharapkan {$expected})");
        } elseif (!is_writable($targetDir)) {
            report('FAIL', $label, "target tidak writable: {$target}");
        } else {
            report('PASS', $label, "→ {$target}");
        }
    } elseif (is_dir($path)) {
        $hint = $hdd !== ''
            ? 'folder nyata (fallback) — untuk storage terpusat buat symlink saat deploy: ln -s ' . rtrim($hdd, '/') . "/{$m}/upload " . $path
            : 'folder nyata (fallback ter-track repo) — storage lokal OK';
        report('WARN', $label, $hint);
    } else {
        $hintTarget = $hdd !== '' ? rtrim($hdd, '/') . "/{$m}/upload" : '<MEEL_HDD_BASE>';
        report('FAIL', $label, 'tidak ada — buat symlink: ln -s ' . $hintTarget . " " . $path);
    }
}

section('3. .htaccess Upload Dirs (hardening)');

$requiredDirs = ['php_flag engine off', 'ForceType', 'Options -Indexes'];
foreach (['books/upload', 'music/upload', 'video/upload'] as $u) {
    $ht    = MEEL_ROOT . "/{$u}/.htaccess";
    $label = "{$u}/.htaccess";
    if (!is_file($ht)) {
        report('FAIL', $label,
            'tidak ada — buat dengan pola data_drive/.htaccess (php engine off + ForceType + Options -Indexes)');
        continue;
    }
    $content = @file_get_contents($ht);
    if ($content === false) {
        report('FAIL', $label, 'tidak dapat dibaca (permission?)');
        continue;
    }
    $miss = [];
    foreach ($requiredDirs as $r) {
        if (!str_contains($content, $r)) $miss[] = $r;
    }
    if ($miss === []) {
        report('PASS', $label);
    } else {
        report('FAIL', $label, 'kehilangan directive: ' . implode(', ', $miss));
    }
}

// 3b. Private Drive storage — wajib hard-deny (bukan sekadar Options -Indexes)
// primary: aturan ter-track di data_drive/.htaccess (berlaku untuk semua
// deployment, termasuk saat private_admins/ adalah symlink ke storage eksternal).
$parentHt    = MEEL_ROOT . '/data_drive/.htaccess';
$parentLabel = 'data_drive/.htaccess (deny private_admins)';
if (!is_file($parentHt)) {
    report('FAIL', $parentLabel, 'tidak ada');
} else {
    $parentContent = (string) @file_get_contents($parentHt);
    if (str_contains($parentContent, 'private_admins') && str_contains($parentContent, '[F')) {
        report('PASS', $parentLabel, 'RewriteRule deny aktif — akses langsung diblokir Apache');
    } else {
        report('FAIL', $parentLabel, 'kehilangan RewriteRule deny untuk private_admins');
    }
}

// lapisan kedua (deploy-time, di target storage symlink):
$driveHt    = MEEL_ROOT . '/data_drive/private_admins/.htaccess';
$driveLabel = 'data_drive/private_admins/.htaccess';
if (!is_file($driveHt)) {
    report('WARN', $driveLabel,
        'tidak ada di target storage — buat dengan Require all denied (parent rule tetap aktif)');
} else {
    $driveContent = (string) @file_get_contents($driveHt);
    if (str_contains($driveContent, 'Require all denied')) {
        report('PASS', $driveLabel, 'Require all denied aktif (lapisan 2)');
    } else {
        report('FAIL', $driveLabel, 'kehilangan directive Require all denied');
    }
}

$driveRoot = ($hddOverride !== null || !defined('MEEL_HDD_DRIVE'))
    ? $hdd . '/drive'
    : rtrim((string) constant('MEEL_HDD_DRIVE'), '/');

foreach (['public', 'private_admins'] as $driveSub) {
    $driveEntry = MEEL_ROOT . '/data_drive/' . $driveSub;
    if (!is_link($driveEntry)) {
        continue;
    }
    $target       = (string) readlink($driveEntry);
    $targetNorm   = rtrim($target, '/');
    $expected     = $driveRoot . '/' . $driveSub;
    $expectedNorm = rtrim($expected, '/');
    if ($expectedNorm !== '' && ($targetNorm === $expectedNorm || str_starts_with($targetNorm, $expectedNorm . '/'))) {
        report('PASS', "data_drive/{$driveSub} → {$target} (symlink deploy sah — target di dalam MEEL_HDD_DRIVE)");
    } else {
        report('WARN', "data_drive/{$driveSub} masih symlink → {$target}",
            'target di LUAR MEEL_HDD_DRIVE — jadikan folder nyata ATAU arahkan ke ' . $expected . ' (jangan commit symlink)');
    }
}

// 3c. Validating forward proxy — wajib tersedia untuk download URL (SSRF per hop)
$vpClass = MEEL_ROOT . '/modules/auth/ValidatingProxy.php';
$vpScript = MEEL_ROOT . '/modules/auth/validating_proxy_server.php';
if (!is_file($vpClass) || !is_file($vpScript)) {
    report('FAIL', 'Validating proxy tidak lengkap',
        'butuh modules/auth/ValidatingProxy.php + validating_proxy_server.php');
} elseif (PHP_BINARY === '' || !is_executable(PHP_BINARY)) {
    report('FAIL', 'PHP_BINARY tidak dapat dieksekusi untuk spawn proxy',
        'ValidatingProxy menjalankan php CLI (proc_open)');
} else {
    $proxyOk = false;
    try {
        require_once $vpClass;
        $p = new ValidatingProxy();
        $proxyUrl = $p->url();
        $parts = parse_url($proxyUrl);
        $proxyOk = ($parts['host'] ?? '') === '127.0.0.1' && (int) ($parts['port'] ?? 0) > 0;
        $p->stop();
    } catch (\Throwable $e) {
        $proxyOk = false;
    }
    if ($proxyOk) {
        report('PASS', 'Validating proxy dapat di-spawn (loopback-only)');
    } else {
        report('FAIL', 'Validating proxy gagal di-spawn',
            'periksa izin eksekusi PHP CLI + pcntl/stream_socket_server');
    }
}

// 4. mod_rewrite & PWA (sw.js → sw.js.php)
section('4. mod_rewrite & PWA (sw.js → sw.js.php)');

// 4a. Aturan rewrite di .htaccess root
$rootHt = MEEL_ROOT . '/.htaccess';
if (!is_file($rootHt)) {
    report('FAIL', '.htaccess root tidak ada', 'pastikan AllowOverride All + mod_rewrite aktif di Apache');
} else {
    $ht = (string) @file_get_contents($rootHt);
    $hasEngine = str_contains($ht, 'RewriteEngine On');
    $hasRule   = str_contains($ht, 'sw.js.php');
    if ($hasEngine && $hasRule) {
        report('PASS', 'RewriteRule ^sw\\.js$ sw.js.php [L] ada di .htaccess root');
    } else {
        $miss = [];
        if (!$hasEngine) $miss[] = 'RewriteEngine On';
        if (!$hasRule)   $miss[] = 'RewriteRule ^sw\\.js$ sw.js.php';
        report('FAIL', '.htaccess root kehilangan: ' . implode(' + ', $miss));
    }
}

// 4b. Generator service worker ada
if (is_file(MEEL_ROOT . '/sw.js.php')) {
    report('PASS', 'sw.js.php (generator service worker) ada');
} else {
    report('FAIL', 'sw.js.php tidak ada', 'service worker dinamis hilang — restore dari repo');
}

// 4c. Modul Apache (jika bisa diverifikasi dari CLI)
$apacheBins = ['/opt/lampp/bin/apachectl', '/opt/lampp/bin/httpd', 'apache2ctl', 'apachectl', 'httpd'];
$modOut = null;
foreach ($apacheBins as $bin) {
    $out = cmd_output("{$bin} -M 2>/dev/null");
    if ($out !== null) { $modOut = $out; break; }
}
if ($modOut === null) {
    report('WARN', 'modul Apache tidak bisa diverifikasi dari CLI',
        'pastikan mod_rewrite aktif (apachectl -M | grep rewrite)');
} elseif (stripos($modOut, 'rewrite') !== false) {
    report('PASS', 'mod_rewrite terdeteksi di Apache');
} else {
    report('WARN', 'mod_rewrite TIDAK terdeteksi di Apache',
        'aktifkan: LoadModule rewrite_module modules/mod_rewrite.so');
}

// 4d. Probe HTTP nyata — bukti rewrite + AllowOverride bekerja
$probeBase  = $url !== null ? $url : 'http://localhost/' . basename(MEEL_ROOT);
$probeUrl   = $probeBase . '/sw.js';
$ctx = stream_context_create(['http' => [
    'timeout'       => 4,
    'ignore_errors' => true,
    'header'        => "User-Agent: MEeL-check-deploy\r\n",
]]);
$body = @file_get_contents($probeUrl, false, $ctx);
$status = 0;
$ct     = '';
if (isset($http_response_header) && is_array($http_response_header)) {

    if (preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m)) {
        $status = (int) $m[1];
    }
    foreach ($http_response_header as $h) {
        if (stripos($h, 'Content-Type:') === 0) $ct = trim(substr($h, 13));
    }
}

$isExplicit = $url !== null;
if ($body === false) {
    report('WARN', "probe HTTP gagal: {$probeUrl}", $isExplicit
        ? 'periksa apakah Apache hidup & URL benar'
        : 'lewati dengan --url yang benar, mis. php tests/check_deploy.php --url=http://localhost/MEeL');
} elseif ($status === 200 && stripos($ct, 'javascript') !== false) {
    report('PASS', "rewrite berfungsi: {$probeUrl} → HTTP {$status} ({$ct})");
} elseif ($status === 200) {
    report('FAIL', "sw.js disajikan tapi Content-Type salah: {$ct}",
        'cek header Content-Type di sw.js.php / .htaccess root');
} else {
    report($isExplicit ? 'FAIL' : 'WARN',
        "sw.js tidak di-rewrite: HTTP {$status}", $isExplicit
        ? 'mod_rewrite atau AllowOverride All tidak aktif di vhost Apache'
        : 'kode 404/403 — coba --url yang tepat untuk konfirmasi');
}

// Ringkasan
section('Ringkasan');
echo '  PASS: ' . c('1;32', (string) $passed)
   . '  WARN: ' . c('1;33', (string) $warned)
   . '  FAIL: ' . c('1;31', (string) $failed) . "\n";

if ($failed > 0) {
    echo "\n" . c('1;31', '❌ Ada ' . $failed . ' masalah.') . " Perbaiki lalu jalankan ulang.\n";
    echo "   Storage/mount → docs/en/installation.md §5a (id: docs/id/installation.md §5a)\n";
} else {
    echo "\n" . c('1;32', '✅ Deployment sehat.') . ($warned > 0 ? ' (' . $warned . ' catatan, lihat di atas)' : '') . "\n";
}

exit($failed > 0 ? 1 : 0);
