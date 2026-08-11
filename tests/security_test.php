<?php
define('PROJECT_ROOT', realpath(__DIR__ . '/..'));
define('EXCLUDE_DIRS', ['vendor', 'node_modules', '.git', 'tests', 'temp', 'assets/dict', 'data_drive']);
define('EXCLUDE_FILES', ['config.example.php', 'settings.example.php', 'test.php', '.gitkeep']);

require_once __DIR__ . '/helpers.php';

// Globals
$GLOBALS['total_tests']  = 0;
$GLOBALS['passed']       = 0;
$GLOBALS['warnings']     = 0;
$GLOBALS['failed']       = 0;
$GLOBALS['fail_details'] = [];

// Check if file has a function call pattern
// TEST 1: SQL INJECTION SCAN
function testSqlInjection(): void {
    print_header('TEST 1: SQL Injection ' . chr(8212) . ' Prepared Statement Analysis');

    $files = getPhpFiles();
    $issues   = [];
    $clean    = 0;
    $examined = 0;

    // Static-only SQL patterns (safe to use with query())
    $staticPatterns = [
        '/COUNT\(\*\)/i',
        '/SUM\(/i',
        '/DISTINCT/i',
        '/UNION ALL/i',
        '/CURDATE\(\)/i',
        '/NOW\(\)/i',
    ];

    foreach ($files as $path) {
        $rel     = str_replace(PROJECT_ROOT . '/', '', $path);
        $content = file_get_contents($path);

        $prepCount = countInFile($path, '/\->prepare\s*\(/');
        $bindCount = countInFile($path, '/\->bind_param\s*\(/');

        $examined++;

        preg_match_all('/\->query\s*\(\s*((["\'])(?:(?!\2).)*?\$.*?\2)\s*\)\s*;/s', $content, $qMatches);

        $rawWithVars = array_map('trim', $qMatches[1] ?? []);

        if (empty($rawWithVars)) {
            $clean++;
            continue;
        }

        // Filter out static-only queries
        $risky = [];
        foreach ($rawWithVars as $qry) {
            $isStatic = false;
            foreach ($staticPatterns as $sp) {
                if (preg_match($sp, $qry)) { $isStatic = true; break; }
            }
            // Also filter if it only has simple int casting like (int)$var
            if (preg_match('/=\s*\(int\)/', $qry)) $isStatic = true;
            if (!$isStatic) $risky[] = $qry;
        }

        if (empty($risky)) {
            $clean++;
            continue;
        }

        $hasPrep = ($prepCount > 0);
        $issues[] = [
            'file'  => $rel,
            'count' => count($risky),
            'has_prep' => $hasPrep,
            'sample' => substr($risky[0], 0, 100),
        ];
    }

            $rawTotal = count($issues) + array_sum(array_column($issues, 'count'));
        record("Memindai {$examined} file PHP...", true, false, "{$clean} aman, {$rawTotal} raw queries");

    if (empty($issues)) {
        record("Semua query menggunakan prepared statements", true);
        return;
    }

    foreach ($issues as $iss) {
        if ($iss['has_prep']) {
            record("{$iss['file']} \u{2014} {$iss['count']} raw query (campur prepared statements)", true, true, $iss['sample']);
        } else {
            record("{$iss['file']} \u{2014} {$iss['count']} raw query TANPA prepared statement", false, false, $iss['sample']);
        }
    }
}

// TEST 2: display_errors SCAN
function testDisplayErrors(): void {
    print_header('TEST 2: Error Handling ' . chr(8212) . ' display_errors Setting');

    $files    = getPhpFiles();
    $enabled  = [];
    $disabled = 0;

    foreach ($files as $path) {
        $rel     = str_replace(PROJECT_ROOT . '/', '', $path);
        $content = file_get_contents($path);

        if (preg_match('/ini_set\s*\(\s*["\']display_errors["\']\s*,\s*["\']?1["\']?\s*\)/', $content)) {
            $enabled[] = $rel;
        } elseif (preg_match('/ini_set\s*\(\s*["\']display_errors["\']\s*,\s*["\']?0["\']?\s*\)/', $content)) {
            $disabled++;
        }
    }

    // stream.php uses error_reporting(0) which is acceptable
    $enabled = array_values(array_filter($enabled, fn($f) => !in_array($f, [
        'music/stream.php',
        'modules/core/bootstrap.php',
    ], true)));

    record("Memindai " . count($files) . " file...", true, false, "{$disabled} dimatikan, " . count($enabled) . " masih menyala");

    if (empty($enabled)) {
        record("Semua file production sudah mematikan display_errors", true);
    } else {
        foreach ($enabled as $f) {
            record("{$f} \u{2014} display_errors masih menyala (1)", false, false, "Ganti ke ini_set('display_errors', 0)");
        }
    }
}

// TEST 3: CSRF PROTECTION
function testCsrfProtection(): void {
    print_header('TEST 3: CSRF Protection ' . chr(8212) . ' Anti-CSRF Token');

    $files      = getPhpFiles();
    $protected  = 0;
    $unprot     = [];
    $totalForms = 0;

    foreach ($files as $path) {
        $rel     = str_replace(PROJECT_ROOT . '/', '', $path);
        $content = file_get_contents($path);

        $hasForm   = (preg_match('/<form.*method\s*=\s*["\']post["\']/is', $content) === 1);
        $hasPost   = (strpos($content, '$_POST[') !== false);
        if (!$hasForm && !$hasPost) continue;

        $totalForms++;
        $hasVerify = (strpos($content, 'verify_csrf_token(') !== false);
        $hasToken  = (strpos($content, 'csrf_token') !== false);

        if ($hasVerify || $hasToken) {
            $protected++;
            if ($hasPost && !$hasVerify && !strpos($rel, 'drive/')) {
                // Has token field but no verification call
                $unprot[] = $rel;
            }
        } else {
            $unprot[] = $rel;
        }
    }

    record("Memindai {$totalForms} file dengan form/POST handler...", true, false, "{$protected} terlindungi");

    if (empty($unprot)) {
        record("Semua form POST sudah memiliki CSRF protection", true);
    } else {
        foreach ($unprot as $f) {
            record("{$f} \u{2014} Tidak ada CSRF token atau verify_csrf_token()", true, true);
        }
    }
}

// TEST 4: XSS PROTECTION
function testXssProtection(): void {
    print_header('TEST 4: XSS Protection ' . chr(8212) . ' htmlspecialchars Usage');

    $files  = getPhpFiles();
    $issues = [];
    $totalOut = 0;
    $totalHs  = 0;

    foreach ($files as $path) {
        $rel     = str_replace(PROJECT_ROOT . '/', '', $path);
        $content = file_get_contents($path);

        // Count all PHP output constructs with variables
        $outPatterns = [
            '/\<\?\=\s*\$/',           // <?= $var
            '/echo\s+\$/',             // echo $var
            '/print\s+\$/',            // print $var
            '/\<\?\=\s*htmlspecialchars/', // already escaped
        ];

        $rawOut = 0;
        foreach ($outPatterns as $i => $pat) {
            if ($i === 3) continue; // skip the escaped one for counting
            preg_match_all($pat, $content, $m);
            $rawOut += count($m[0]);
        }

        // Count htmlspecialchars usage
        $hsCount = countInFile($path, '/htmlspecialchars\s*\(/');

        $totalOut += $rawOut;
        $totalHs  += $hsCount;

        if ($rawOut > 10 && $hsCount === 0) {
            $issues[] = ['file' => $rel, 'out' => $rawOut, 'hs' => $hsCount];
        }
        // Also flag large files that have very low ratio
        if ($rawOut > 30 && $hsCount < 5) {
            $issues[] = ['file' => $rel, 'out' => $rawOut, 'hs' => $hsCount];
        }
    }

    record("Memindai {$totalOut} output variabel...", true, false, "{$totalHs} htmlspecialchars dipanggil");

    if (empty($issues)) {
        record("Output variabel menggunakan htmlspecialchars secara konsisten", true);
    } else {
        foreach ($issues as $iss) {
            record("{$iss['file']} \u{2014} {$iss['out']} output, {$iss['hs']} htmlspecialchars", true, true);
        }
    }
}

// TEST 5: FILE UPLOAD SECURITY
function testFileUploadSecurity(): void {
    print_header('TEST 5: File Upload Security');

    $uploads = [
        'video/upload.php'            => ['delegated to Uploader::processVideo()', 'Uploader|in_array'],
        'music/upload.php'            => ['delegated to Uploader::processMusic()', 'Uploader|in_array'],
        'books/upload.php'            => ['delegated to BookUploader::handleUpload()', 'BookUploader|ZipArchive'],
        'controllers/profile/profile_edit.php'=> ['MIME check', 'in_array.*file_type'],
        'drive/upload.php'            => ['delegated to DriveService::upload()', 'DriveStorage|validateFileByMagicBytes'],
        'modules/core/Uploader.php'        => ['ext + blacklist + magic bytes', 'preg_match.*php|validateVideoMagicBytes'],
    ];

    foreach ($uploads as $file => $info) {
        $full = PROJECT_ROOT . '/' . $file;
        if (!file_exists($full)) {
            record("{$file} \u{2014} tidak ditemukan", true, true);
            continue;
        }
        $content = file_get_contents($full);
        $patterns = explode(', ', $info[1]);
        $ok = true;
        foreach ($patterns as $pat) {
            if (preg_match('/' . $pat . '/i', $content) !== 1) { $ok = false; break; }
        }
        if ($ok) record("{$file} \u{2014} {$info[0]} OK", true);
        else     record("{$file} \u{2014} {$info[0]} (perlu review)", true, true);
    }
}

// TEST 6: PATH TRAVERSAL
function testPathTraversal(): void {
    print_header('TEST 6: Path Traversal Protection');

    $checks = [
        'controllers/api/download_transcode.php' => ['basename', 'preg_match', 'pathinfo'],
        'drive/download.php'                 => ['basename', 'DriveStorage|getFileForDownload'],
        'drive/delete.php'                   => ['basename', 'DriveStorage|delete'],
        'music/stream.php'                   => ['getMediaData', 'basename|\(int\)'],
    ];

    foreach ($checks as $file => $pats) {
        $full = PROJECT_ROOT . '/' . $file;
        if (!file_exists($full)) {
            record("{$file} \u{2014} tidak ditemukan", true, true);
            continue;
        }
        $content = file_get_contents($full);
        $ok = true;
        foreach ($pats as $pat) {
            if (preg_match('/' . $pat . '/i', $content) !== 1) { $ok = false; break; }
        }
        if ($ok) record("{$file} \u{2014} path traversal protection OK", true);
        else     record("{$file} \u{2014} perlu review validasi filename", true, true);
    }
}

// TEST 7: .HTACCESS SECURITY
function testHtaccessSecurity(): void {
    print_header('TEST 7: .htaccess & HTTP Security Headers');

    // ─── 7a: Cek semua folder sensitif wajib punya .htaccess ───
    $sensitiveDirs = [
        // PHP-include only folders
        'controllers', 'controllers/admin', 'controllers/api', 'controllers/profile', 'controllers/system',
        'modules', 'modules/core', 'modules/core/helpers', 'modules/media', 'modules/transcoder', 'modules/exceptions',
        'partials', 'drive/templates', 'docs/partials',
        // Auth & config
        'auth', 'database',
        // Error & temp
        'err', 'temp',
        // Logs & tests
        'logs', 'tests',
        // Upload dirs (harus disable PHP)
        // Catatan: data_drive/private_admins bukan folder nyata di repo
        // (symlink ke storage eksternal) — deny-nya diatur di data_drive/.htaccess.
        'data_drive', 'books/upload', 'music/upload', 'video/upload',
    ];

    $missing = [];
    foreach ($sensitiveDirs as $dir) {
        $htPath = PROJECT_ROOT . '/' . $dir . '/.htaccess';
        if (!file_exists($htPath)) {
            $missing[] = $dir;
        }
    }

    if (empty($missing)) {
        record("Semua " . count($sensitiveDirs) . " folder sensitif punya .htaccess", true);
    } else {
        foreach ($missing as $d) {
            record("{$d}/ \u{2014} TIDAK PUNYA .htaccess!", false, false);
        }
    }

    // ─── 7b: Verifikasi directive spesifik per folder ───
    $checks = [
        '.htaccess'                 => ['Options -Indexes', 'X-Content-Type-Options', 'Deny from all'],
        'auth/.htaccess'            => ['Options -Indexes', 'Deny from all'],
        'admin/.htaccess'           => ['Options -Indexes', 'FilesMatch'],
        'logs/.htaccess'            => ['Options -Indexes', 'Deny from all'],
        'data_drive/.htaccess'      => ['php_flag engine off', 'ForceType', 'Options -Indexes', 'RewriteRule ^private_admins'],
        'books/upload/.htaccess'    => ['php_flag engine off', 'ForceType', 'Options -Indexes'],
        'music/upload/.htaccess'    => ['php_flag engine off', 'ForceType', 'Options -Indexes'],
        'video/upload/.htaccess'    => ['php_flag engine off', 'ForceType', 'Options -Indexes'],
        'books/.htaccess'           => ['Options -Indexes'],
        'video/.htaccess'           => ['Options -Indexes'],
        'music/.htaccess'           => ['Options -Indexes'],
        'drive/.htaccess'           => ['Options -Indexes'],
        'controllers/.htaccess'     => ['Deny from all'],
        'modules/.htaccess'         => ['Deny from all'],
        'modules/core/.htaccess'    => ['Deny from all'],
        'modules/exceptions/.htaccess' => ['Deny from all'],
        'partials/.htaccess'        => ['Deny from all'],
        'docs/partials/.htaccess'   => ['Deny from all'],
        'drive/templates/.htaccess' => ['Deny from all'],
        'tests/.htaccess'           => ['Deny from all'],
    ];

    foreach ($checks as $file => $reqs) {
        $full = PROJECT_ROOT . '/' . $file;
        if (!file_exists($full)) {
            record($file . ' \u{2014} FILE TIDAK DITEMUKAN!', false, false, 'Buat .htaccess');
            continue;
        }
        $content = file_get_contents($full);
        $ok  = true;
        $miss = [];
        foreach ($reqs as $r) {
            if (strpos($content, $r) === false) { $ok = false; $miss[] = $r; }
        }
        if ($ok) record("{$file} \u{2014} semua security directive OK", true);
        else     record("{$file} \u{2014} kurang: " . implode(', ', $miss), true, true);
    }
}

// TEST 8: SESSION SECURITY
function testSessionSecurity(): void {
    print_header('TEST 8: Session & Authentication Security');

    $checks = [
        'Session name unik (meel)'        => ['auth/config.php', '/session_name.*meel/'],
        'Session timeout (gc_maxlifetime)' => ['auth/config.php', '/session\.gc_maxlifetime/'],
        'HTTP-only cookie params'          => ['auth/config.php', '/session_set_cookie_params/'],
        'CSRF token generation'            => ['auth/config.php', '/random_bytes.*32/'],
        'Activity timeout check'           => ['auth/config.php', '/LAST_ACTIVITY/'],
        'Session hijack protection'        => ['auth/auth.php', '/last_session_id/'],
        'Password hashing'                 => ['auth/register.php', '/password_hash/'],
        'Password verification'            => ['auth/login.php', '/password_verify/'],
        'Brute force (login lockout)'      => ['auth/login.php', '/login_locked/'],
        'Rate limit (register)'            => ['auth/register.php', '/reg_attempts/'],
        'IP Ban system'                    => ['modules/core/activity_logger.php', '/ip_ban/'],
        'Session kick on hijack'           => ['modules/core/activity_logger.php', '/session_destroy/'],
        'Logout proper (session destroy)'  => ['auth/logout.php', '/session_destroy/'],
        'Logout clears cookie'             => ['auth/logout.php', '/setcookie.*session/'],
    ];

    foreach ($checks as $name => $c) {
        $full = PROJECT_ROOT . '/' . $c[0];
        if (!file_exists($full)) {
            record("{$name} \u{2014} file tidak ditemukan", true, true);
            continue;
        }
        $content = file_get_contents($full);
        if (preg_match($c[1], $content)) record("{$name} OK", true);
        else                              record("{$name} \u{2014} tidak terdeteksi", true, true);
    }
}

// TEST 9: HTTP SECURITY HEADERS (CSP)
function testCspHeaders(): void {
    print_header('TEST 9: HTTP Security Headers & CSP');

    $cfg = PROJECT_ROOT . '/auth/config.php';
    if (!file_exists($cfg)) {
        record("config.php tidak ditemukan", true, true);
        return;
    }

    $content = file_get_contents($cfg);
    $hdrs = [
        'X-Frame-Options'           => 'SAMEORIGIN',
        'X-Content-Type-Options'    => 'nosniff',
        'Referrer-Policy'           => 'strict-origin',
        'Permissions-Policy'        => 'camera',
        'Content-Security-Policy'   => "default-src 'self'",
        'Cross-Origin-Opener-Policy'=> 'same-origin',
    ];

    foreach ($hdrs as $name => $val) {
        if (strpos($content, $name) !== false && strpos($content, $val) !== false) {
            record("Header {$name} OK", true);
        } else {
            record("Header {$name} \u{2014} tidak terpasang", false, true, "Tambahkan di config.php");
        }
    }

    if (strpos($content, 'Strict-Transport-Security') !== false) {
        record("HSTS (HTTPS) OK", true);
    } else {
        record("HSTS \u{2014} tidak terdeteksi (opsional untuk HTTP-only)", true, true);
    }
}

// TEST 10: COMMAND INJECTION (SHELL EXEC)
function testCommandInjection(): void {
    print_header('TEST 10: Command Injection ' . chr(8212) . ' Shell Execution Safety');

    $risky = [
        'modules/core/Uploader.php'     => ['shell_exec', 'exec', 'popen'],
        'modules/core/Transcoder.php'   => ['shell_exec', 'exec', 'popen'],
        'modules/core/helpers/storage.php' => ['shell_exec'], // dir_size() — dipecah dari helpers.php
        'modules/core/System.php'       => ['shell_exec'],
        'auth/config.example.php'  => ['proc_open', 'shell_exec'],
        'modules/core/japanese.php'     => ['proc_open'],
    ];

    foreach ($risky as $file => $funcs) {
        $full = PROJECT_ROOT . '/' . $file;
        if (!file_exists($full)) {
            record("{$file} \u{2014} tidak ditemukan", true, true);
            continue;
        }
        $content = file_get_contents($full);
        $escCount = countInFile($full, '/escapeshellarg\s*\(/');
        $execCount = 0;
        foreach ($funcs as $fn) {
            $execCount += countInFile($full, '/' . preg_quote($fn, '/') . '\s*\(/');
        }
        if ($execCount === 0) {
            record("{$file} \u{2014} tidak ada shell execution", true);
        } elseif ($escCount >= $execCount) {
            record("{$file} \u{2014} {$execCount} shell exec, semua pakai escapeshellarg", true);
        } else {
            record("{$file} \u{2014} {$execCount} shell exec, {$escCount} escapeshellarg", true, true, "Ada yang mungkin tidak terproteksi");
        }
    }
}

// TEST 11: PASSWORD POLICY
function testPasswordPolicy(): void {
    print_header('TEST 11: Password Policy & Strength');

    $checks = [

        ['Min 8 karakter password',          'auth/auth_helpers.php', '/strlen.*pass.*8|min.*8/'],
        ['Brute force lockout',              'auth/login.php',    '/login_fail_count/'],
        ['Lockout timeout',                  'auth/login.php',    '/lockout_time/'],
        ['Username regex (alpha numeric)',   'auth/auth_helpers.php', '/preg_match.*a-zA-Z0-9/'],
        ['Guest username blacklist',         'auth/auth_helpers.php', '/stripos.*guest/'],
    ];

    foreach ($checks as $c) {
        $full = PROJECT_ROOT . '/' . $c[1];
        if (!file_exists($full)) {
            record("{$c[0]} \u{2014} file tidak ditemukan", true, true);
            continue;
        }
        $content = file_get_contents($full);
        if (preg_match($c[2], $content)) record("{$c[0]} OK", true);
        else                              record("{$c[0]} \u{2014} policy tidak terdeteksi", true, true);
    }
}

// TEST 12: FILE INTEGRITY
function testFileIntegrity(): void {
    print_header('TEST 12: File Integrity ' . chr(8212) . ' Critical Files');

    $critical = [
        '.htaccess', 'auth/config.php', 'auth/auth.php', 'auth/login.php',
        'auth/logout.php', 'auth/register.php', 'auth/auth_helpers.php',
        'modules/core/helpers.php',
        'modules/core/helpers/main.php', 'modules/core/helpers/storage.php',
        'modules/core/activity_logger.php', 'modules/core/System.php', 'modules/core/Uploader.php',
        'modules/core/Transcoder.php', 'modules/core/SsrfGuard.php', 'modules/core/ValidatingProxy.php',
        'modules/core/validating_proxy_server.php', 'modules/media/MediaInteraction.php',
        'modules/media/MediaViewer.php',
        'modules/media/MediaLibrary.php', 'modules/core/GarbageCollector.php', 'modules/core/japanese.php',
        'admin/.htaccess', 'auth/.htaccess', 'data_drive/.htaccess',
        'drive/DriveService.php', 'drive/stream.php', 'controllers/admin/admin_actions.php', 'controllers/admin/admin_data.php',
        'controllers/profile/profile_edit.php',
        'controllers/api/like.php', 'controllers/api/delete_comment.php',
        'controllers/api/download_transcode.php', 'controllers/system/UpdateManager.php',
    ];

    $missing = [];
    foreach ($critical as $f) {
        if (!file_exists(PROJECT_ROOT . '/' . $f)) $missing[] = $f;
    }

    if (empty($missing)) {
        record("Semua " . count($critical) . " file kritis ditemukan", true);
    } else {
        foreach ($missing as $f) record("File hilang: {$f}", false, false);
    }
}

// TEST 13: SSRF & PRIVATE DRIVE HARDENING
function testSsrfAndPrivateDrive(): void {
    print_header('TEST 13: SSRF Guard & Private Drive Hardening');

    // ─── 13a: SsrfGuard ada dan dipakai di jalur download ───
    $guardFile = PROJECT_ROOT . '/modules/core/SsrfGuard.php';
    if (!file_exists($guardFile)) {
        record('modules/core/SsrfGuard.php — FILE TIDAK DITEMUKAN!', false, false);
    } else {
        $gc = file_get_contents($guardFile);
        foreach (['function validate', 'function isPrivateIp', 'function resolvePublicAddresses', 'function pinHttpUrl'] as $m) {
            if (strpos($gc, $m) !== false) {
                record("SsrfGuard::{$m}() ada", true);
            } else {
                record("SsrfGuard — metode {$m}() TIDAK ditemukan", false, false);
            }
        }
    }

    $tcFile = PROJECT_ROOT . '/modules/core/Transcoder.php';
    if (file_exists($tcFile)) {
        $tc = file_get_contents($tcFile);
        if (strpos($tc, 'new SsrfGuard()') !== false) {
            record('Transcoder memanggil SsrfGuard sebelum yt-dlp', true);
        } else {
            record('Transcoder TIDAK memakai SsrfGuard — SSRF terbuka!', false, false);
        }
        if (strpos($tc, 'FILTER_VALIDATE_URL') === false) {
            record('Transcoder tidak lagi mengandalkan filter_var(FILTER_VALIDATE_URL)', true);
        } else {
            record('Transcoder masih memakai FILTER_VALIDATE_URL sebagai validasi URL', false, false);
        }
        if (strpos($tc, '$dl_extra') !== false && strpos($tc, 'pinHttpUrl') !== false) {
            record('HTTP pinning aktif (SsrfGuard::pinHttpUrl + $dl_extra)', true);
        } else {
            record('HTTP pinning (SsrfGuard::pinHttpUrl + $dl_extra) TIDAK terdeteksi', false, false);
        }

        $guardContent = $guardFile !== null && file_exists($guardFile) ? (string) file_get_contents($guardFile) : '';
        if (strpos($guardContent, '--add-header') !== false) {
            record('SsrfGuard: --add-header Host (IP pinning) terpasang', true);
        } else {
            record('SsrfGuard: --add-header Host TIDAK terdeteksi', false, false);
        }

        // ─── Validating forward proxy: SSRF pada SETIAP redirect hop ───
        if (strpos($tc, 'ensureDownloadProxy') !== false && strpos($tc, '--proxy') !== false) {
            record('Transcoder: yt-dlp diarahkan lewat validating proxy (--proxy)', true);
        } else {
            record('Transcoder: --proxy / ensureDownloadProxy TIDAK terdeteksi', false, false,
                'Tanpa proxy, redirect ke IP private masih bisa diikuti yt-dlp');
        }
    }

    $proxyClass = PROJECT_ROOT . '/modules/core/ValidatingProxy.php';
    if (!file_exists($proxyClass)) {
        record('modules/core/ValidatingProxy.php — FILE TIDAK DITEMUKAN!', false, false);
    } else {
        $pc = file_get_contents($proxyClass);
        if (strpos($pc, '127.0.0.1') !== false && strpos($pc, 'proc_open') !== false) {
            record('ValidatingProxy: spawn CLI + bind 127.0.0.1 (loopback-only)', true);
        } else {
            record('ValidatingProxy: bind loopback / proc_open TIDAK terdeteksi', false, false);
        }
    }

    $proxyServer = PROJECT_ROOT . '/modules/core/validating_proxy_server.php';
    if (!file_exists($proxyServer)) {
        record('modules/core/validating_proxy_server.php — FILE TIDAK DITEMUKAN!', false, false);
    } else {
        $ps = file_get_contents($proxyServer);
        foreach (['SsrfGuard', 'CONNECT', 'resolvePublicAddresses'] as $pat) {
            if (strpos($ps, $pat) === false) {
                record("validating_proxy_server: {$pat} TIDAK ditemukan", false, false);
            }
        }
        record('validating_proxy_server: SsrfGuard diterapkan per hop (CONNECT + resolve)', true);
    }

    // ─── 13b: Private Drive storage di-deny web server ───
    // primary: aturan deny ter-track di data_drive/.htaccess (berlaku untuk
    // semua deployment, termasuk saat private_admins/ adalah symlink storage).
    $parentHt = PROJECT_ROOT . '/data_drive/.htaccess';
    if (!file_exists($parentHt)) {
        record('data_drive/.htaccess — FILE TIDAK DITEMUKAN!', false, false,
            'Tanpa deny, file private bisa diambil langsung lewat web!');
    } else {
        $phc = (string) file_get_contents($parentHt);
        if (strpos($phc, 'private_admins') !== false && strpos($phc, '[F') !== false) {
            record('data_drive/.htaccess — deny subtree private_admins OK (RewriteRule [F])', true);
        } else {
            record('data_drive/.htaccess — TIDAK ada deny untuk private_admins', false, false,
                'Tambahkan RewriteRule ^private_admins/ - [F,L]');
        }
    }

    // lapisan kedua (opsional, dibuat saat deploy pada target storage):
    $nestedHt = PROJECT_ROOT . '/data_drive/private_admins/.htaccess';
    if (is_file($nestedHt)) {
        $nhc = (string) file_get_contents($nestedHt);
        if (strpos($nhc, 'Require all denied') !== false) {
            record('data_drive/private_admins/.htaccess — Require all denied OK (lapisan 2)', true);
        } else {
            record('data_drive/private_admins/.htaccess — TIDAK ada Require all denied', true, true);
        }
    } else {
        record('data_drive/private_admins/.htaccess — tidak ada (deploy-time; parent rule aktif)', true, true);
    }

    // ─── 13c: Streaming private harus lewat endpoint ber-otorisasi ───
    $streamFile = PROJECT_ROOT . '/drive/stream.php';
    if (!file_exists($streamFile)) {
        record('drive/stream.php — FILE TIDAK DITEMUKAN!', false, false);
    } else {
        $sc = file_get_contents($streamFile);
        $need = [
            'authorize()'            => 'authorize()',
            'verify_csrf_token'      => 'verify_csrf_token',
            'getFileForDownload'     => 'getFileForDownload',
            'basename (traversal)'   => 'basename(',
        ];
        foreach ($need as $label => $pat) {
            if (strpos($sc, $pat) !== false) {
                record("drive/stream.php — {$label} OK", true);
            } else {
                record("drive/stream.php — {$label} TIDAK ditemukan", false, false);
            }
        }
    }

    // ─── 13d: URL listing private diarahkan ke stream.php (bukan path web) ───
    $dsFile = PROJECT_ROOT . '/drive/DriveService.php';
    if (file_exists($dsFile)) {
        $ds = file_get_contents($dsFile);
        if (strpos($ds, "'stream.php?file='") !== false) {
            record('DriveService: listing private memakai stream.php ber-auth', true);
        } else {
            record('DriveService: listing private TIDAK memakai stream.php', false, false,
                'URL web langsung membocorkan file private');
        }
        if (strpos($ds, "fopen(\$destination, 'x')") !== false || (strpos($ds, '@fopen($destination') !== false && strpos($ds, ", 'x')") !== false)) {
            record('DriveService: reservasi nama file atomik (fopen O_EXCL)', true);
        } else {
            record('DriveService: reservasi nama file atomik TIDAK terdeteksi', false, false);
        }
        if (strpos($ds, 'flock($lockFp') !== false) {
            record('DriveService: kuota ditegakkan di dalam flock (atomik)', true);
        } else {
            record('DriveService: kuota tanpa flock — rawan race condition', false, false);
        }
    }
}

// TEST 14: FATAL-BUG REGRESSION GUARD
// Guard statis untuk dua bug fatal yang pernah terjadi:
//   1) INSERT guest ke tabel users tanpa kolom `password` (NOT NULL) →
//      Uncaught mysqli_sql_exception di MySQL/MariaDB strict mode.
//   2) Query chart "7-Day Activity" memakai v.views/m.views yang tidak
//      eksis di subquery UNION (alias t) → SQL error, admin dashboard crash.
function testFatalBugRegression(): void {
    print_header('TEST 14: Fatal-Bug Regression Guard');

    // ─── 14a: Guest INSERT di activity_logger.php harus mengisi kolom password ───
    // schema.sql: `password varchar(255) NOT NULL` — INSERT tanpa nilai langsung
    // fatal di strict mode. Password guest diisi nilai acak (tidak pernah dipakai
    // login), bukan dibiarkan kosong.
    $alFile = PROJECT_ROOT . '/modules/core/activity_logger.php';
    if (!file_exists($alFile)) {
        record('modules/core/activity_logger.php — FILE TIDAK DITEMUKAN!', false, false);
    } else {
        $al = (string) file_get_contents($alFile);

        if (strpos($al, 'INSERT INTO users (username, password, role') !== false) {
            record('activity_logger: INSERT guest menyertakan kolom password', true);
        } else {
            record('activity_logger: INSERT guest TIDAK mengisi kolom password — fatal di strict mode!', false, false,
                'Tambahkan kolom password + bind_param, isi dengan random_bytes()');
        }

        if (strpos($al, 'random_bytes') !== false && strpos($al, '$guest_pass') !== false) {
            record('activity_logger: password guest acak (random_bytes) — aman', true);
        } else {
            record('activity_logger: password guest tidak di-generate acak', false, false,
                'Kolom password NOT NULL tanpa nilai acak = error strict mode');
        }

        if (strpos($al, 'ON DUPLICATE KEY UPDATE') !== false) {
            record('activity_logger: guest upsert (ON DUPLICATE KEY UPDATE) OK', true);
        } else {
            record('activity_logger: ON DUPLICATE KEY UPDATE TIDAK terdeteksi', true, true,
                'Guest row bisa menumpuk setiap request');
        }
    }

    // ─── 14a-2: SEMUA call site INSERT INTO users wajib mengisi kolom password ───
    // Pemindaian menyeluruh (termasuk tests/) — bukan hanya activity_logger.php,
    // agar call site baru yang lupa kolom `password` (NOT NULL) langsung tertangkap.
    $insertSites = [];
    $ri = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(PROJECT_ROOT, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($ri as $f) {
        if ($f->getExtension() !== 'php') continue;
        $p = $f->getPathname();
        foreach (['/vendor/', '/node_modules/', '/.git/', '/assets/dict/', '/temp/'] as $ex) {
            if (strpos($p, $ex) !== false) continue 2;
        }
        $c = (string) file_get_contents($p);
        if (preg_match_all('/INSERT\s+INTO\s+users\s*\(([^)]*)\)/i', $c, $ms)) {
            foreach ($ms[1] as $cols) {
                $insertSites[] = [
                    str_replace(PROJECT_ROOT . '/', '', $p),
                    (bool) preg_match('/\bpassword\b/i', $cols),
                ];
            }
        }
    }

    if (empty($insertSites)) {
        record('Tidak ada INSERT INTO users ditemukan', true, true);
    } else {
        $bad = array_filter($insertSites, fn($s) => !$s[1]);
        if (empty($bad)) {
            record(count($insertSites) . ' call site INSERT INTO users — semua mengisi kolom password', true);
        } else {
            foreach ($bad as $b) {
                record("{$b[0]} — INSERT INTO users TANPA kolom password (fatal di strict mode!)", false, false,
                    'Tambahkan kolom password (nilai acak untuk guest/test)');
            }
        }
    }

    // ─── 14b: Query chart 7-Day Activity di admin_data.php ───
    // Subquery UNION video+music diberi alias t; query luar harus pakai
    // COALESCE(SUM(views), 0) — bukan v.views/m.views (kolom tidak eksis).
    $adFile = PROJECT_ROOT . '/controllers/admin/admin_data.php';
    if (!file_exists($adFile)) {
        record('controllers/admin/admin_data.php — FILE TIDAK DITEMUKAN!', false, false);
    } else {
        $ad = (string) file_get_contents($adFile);

        if (strpos($ad, 'COALESCE(SUM(views), 0)') !== false && strpos($ad, ') AS t') !== false) {
            record('admin_data: chart 7-Day pakai COALESCE(SUM(views),0) dari subquery AS t', true);
        } else {
            record('admin_data: chart 7-Day TIDAK memakai COALESCE(SUM(views),0)/AS t', false, false,
                'Query lama gagal: Unknown column v.views in field list');
        }

        if (strpos($ad, 'v.views') === false && strpos($ad, 'm.views') === false) {
            record('admin_data: tidak ada referensi v.views/m.views (pola bug lama)', true);
        } else {
            record('admin_data: masih ada referensi v.views/m.views — query rusak!', false, false,
                'Ganti jadi COALESCE(SUM(views), 0) FROM (...) AS t');
        }
    }
}

// MAIN
function run(): int {
    echo CLR_CYAN . CLR_BOLD . "\n";
    echo "  " . chr(9556) . str_repeat(chr(9552), 56) . chr(9559) . "\n";
    echo "  " . chr(9553) . "   MEeL Automated Security Test Suite v1.1" . str_repeat(' ', 19) . chr(9553) . "\n";
    echo "  " . chr(9562) . str_repeat(chr(9552), 56) . chr(9565) . "\n";
    echo CLR_RESET;
    echo CLR_GRAY . "  Path : " . PROJECT_ROOT . "\n";
    echo "  Time : " . date('Y-m-d H:i:s') . "\n" . CLR_RESET;

    testSqlInjection();
    testDisplayErrors();
    testCsrfProtection();
    testXssProtection();
    testFileUploadSecurity();
    testPathTraversal();
    testHtaccessSecurity();
    testSessionSecurity();
    testCspHeaders();
    testCommandInjection();
    testPasswordPolicy();
    testFileIntegrity();
    testSsrfAndPrivateDrive();
    testFatalBugRegression();

    // ─── SUMMARY ───
    echo "\n" . CLR_BOLD . chr(9556) . str_repeat(chr(9552), 56) . chr(9559) . "\n";
    echo chr(9553) . "                    SUMMARY REPORT" . str_repeat(' ', 23) . chr(9553) . "\n";
    echo chr(9562) . str_repeat(chr(9552), 56) . chr(9565) . CLR_RESET . "\n\n";

    $t = $GLOBALS['total_tests'];
    $p = $GLOBALS['passed'];
    $w = $GLOBALS['warnings'];
    $f = $GLOBALS['failed'];

    echo "  Total  : {$t}\n";
    echo "  " . CLR_GREEN . "Pass   : {$p}" . CLR_RESET . "\n";
    echo "  " . CLR_YELLOW . "Warn   : {$w}" . CLR_RESET . "\n";
    echo "  " . ($f > 0 ? CLR_RED : '') . "Fail   : {$f}" . CLR_RESET . "\n\n";

    $score = ($t > 0) ? round((($p + ($w * 0.5)) / $t) * 100) : 0;
    $grade = ($score >= 90) ? CLR_GREEN . 'A' : (($score >= 75) ? CLR_YELLOW . 'B' : (($score >= 50) ? CLR_YELLOW . 'C' : CLR_RED . 'D'));

    echo "  Score : {$score}/100  Grade: " . $grade . CLR_RESET . "\n\n";

    $failDetails = $GLOBALS['fail_details'];
    if (!empty($failDetails)) {
        echo CLR_RED . CLR_BOLD . "  FAILED ITEMS:\n" . CLR_RESET;
        foreach ($failDetails as $d) echo "   " . chr(8226) . " {$d}\n";
        echo "\n";
    }

    if ($w > 0) {
        echo CLR_YELLOW . "  Review warnings above for best-practice improvements.\n\n" . CLR_RESET;
    }

    $reportFile = PROJECT_ROOT . '/logs/security_report_' . date('Ymd_His') . '.log';
    $report  = "MEeL Security Report\n";
    $report .= "Date: " . date('Y-m-d H:i:s') . "\n";
    $report .= "Score: {$score}/100 ({$p} pass, {$w} warn, {$f} fail)\n\n";
    if (!empty($failDetails)) {
        $report .= "FAILED:\n";
        foreach ($failDetails as $d) $report .= "  {$d}\n";
    }
    file_put_contents($reportFile, $report);

    echo CLR_GRAY . "  Report saved to: {$reportFile}\n\n" . CLR_RESET;
    echo CLR_BOLD . "  Done.\n\n" . CLR_RESET;

    return ($f > 0) ? 2 : (($w > 0) ? 1 : 0);
}

exit(run());
