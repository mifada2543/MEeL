<?php
define('PROJECT_ROOT', realpath(__DIR__ . '/..'));
define('EXCLUDE_DIRS', ['vendor', 'node_modules', '.git', 'tests', 'temp', 'assets/dict', 'data_drive']);
define('EXCLUDE_FILES', ['config.example.php', 'settings.example.php', 'test.php', '.gitkeep']);

require_once __DIR__ . '/helpers.php';


$GLOBALS['total_tests']  = 0;
$GLOBALS['passed']       = 0;
$GLOBALS['warnings']     = 0;
$GLOBALS['failed']       = 0;
$GLOBALS['fail_details'] = [];



function testSqlInjection(): void {
    print_header('TEST 1: SQL Injection ' . chr(8212) . ' Prepared Statement Analysis');

    $files = getPhpFiles();
    $issues   = [];
    $clean    = 0;
    $examined = 0;

    
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

        
        $risky = [];
        foreach ($rawWithVars as $qry) {
            $isStatic = false;
            foreach ($staticPatterns as $sp) {
                if (preg_match($sp, $qry)) { $isStatic = true; break; }
            }
            
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


function testXssProtection(): void {
    print_header('TEST 4: XSS Protection ' . chr(8212) . ' htmlspecialchars Usage');

    $files  = getPhpFiles();
    $issues = [];
    $totalOut = 0;
    $totalHs  = 0;

    foreach ($files as $path) {
        $rel     = str_replace(PROJECT_ROOT . '/', '', $path);
        $content = file_get_contents($path);

        
        $outPatterns = [
            '/\<\?\=\s*\$/',           
            '/echo\s+\$/',             
            '/print\s+\$/',            
            '/\<\?\=\s*htmlspecialchars/', 
        ];

        $rawOut = 0;
        foreach ($outPatterns as $i => $pat) {
            if ($i === 3) continue; 
            preg_match_all($pat, $content, $m);
            $rawOut += count($m[0]);
        }

        
        $hsCount = countInFile($path, '/htmlspecialchars\s*\(/');

        $totalOut += $rawOut;
        $totalHs  += $hsCount;

        if ($rawOut > 10 && $hsCount === 0) {
            $issues[] = ['file' => $rel, 'out' => $rawOut, 'hs' => $hsCount];
        }
        
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


function testHtaccessSecurity(): void {
    print_header('TEST 7: .htaccess & HTTP Security Headers');

    
    $sensitiveDirs = [
        
        'controllers', 'controllers/admin', 'controllers/api', 'controllers/profile', 'controllers/system',
        'modules', 'modules/core', 'modules/core/helpers', 'modules/media', 'modules/transcoder', 'modules/exceptions',
        
        'modules/auth', 'modules/auth/helpers',
        'partials', 'drive/templates', 'docs/partials',
        
        'auth', 'database',
        
        'err', 'temp',
        
        'logs', 'tests',
        
        
        
        
        
        'data_drive', 'books/upload', 'music/upload', 'video/upload',
        
        'profile/upload',
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
        'modules/core/helpers/.htaccess' => ['Deny from all'],
        'modules/auth/.htaccess'    => ['Deny from all'],
        'modules/auth/helpers/.htaccess' => ['Deny from all'],
        'modules/exceptions/.htaccess' => ['Deny from all'],
        'profile/upload/.htaccess'  => ['php_flag engine off', 'ForceType', 'Options -Indexes'],
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


function testSessionSecurity(): void {
    print_header('TEST 8: Session & Authentication Security');

    $checks = [
        'Session name unik (meel)'        => ['modules/auth/helpers/session.php', '/session_name.*meel/'],
        'Session timeout (gc_maxlifetime)' => ['modules/auth/helpers/session.php', '/session\.gc_maxlifetime/'],
        'HTTP-only cookie params'          => ['modules/auth/helpers/session.php', '/session_set_cookie_params/'],
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


function testCommandInjection(): void {
    print_header('TEST 10: Command Injection ' . chr(8212) . ' Shell Execution Safety');

    $risky = [
        'modules/core/Uploader.php'     => ['shell_exec', 'exec', 'popen'],
        'modules/core/Transcoder.php'   => ['shell_exec', 'exec', 'popen'],
        'modules/core/helpers/storage.php' => ['shell_exec'], 
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


function testFileIntegrity(): void {
    print_header('TEST 12: File Integrity ' . chr(8212) . ' Critical Files');

    $critical = [
        '.htaccess', 'auth/config.php', 'auth/auth.php', 'auth/login.php',
        'auth/logout.php', 'auth/register.php', 'auth/auth_helpers.php',
        'modules/core/helpers.php',
        'modules/core/helpers/main.php', 'modules/core/helpers/storage.php',
        'modules/core/activity_logger.php', 'modules/core/System.php', 'modules/core/Uploader.php',
        'modules/core/Transcoder.php', 'modules/auth/SsrfGuard.php', 'modules/auth/ValidatingProxy.php',
        'modules/auth/validating_proxy_server.php', 'modules/media/MediaInteraction.php',
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


function testSsrfAndPrivateDrive(): void {
    print_header('TEST 13: SSRF Guard & Private Drive Hardening');

    
    $guardFile = PROJECT_ROOT . '/modules/auth/SsrfGuard.php';
    if (!file_exists($guardFile)) {
        record('modules/auth/SsrfGuard.php — FILE TIDAK DITEMUKAN!', false, false);
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

        
        if (strpos($tc, 'ensureDownloadProxy') !== false && strpos($tc, '--proxy') !== false) {
            record('Transcoder: yt-dlp diarahkan lewat validating proxy (--proxy)', true);
        } else {
            record('Transcoder: --proxy / ensureDownloadProxy TIDAK terdeteksi', false, false,
                'Tanpa proxy, redirect ke IP private masih bisa diikuti yt-dlp');
        }
    }

    $proxyClass = PROJECT_ROOT . '/modules/auth/ValidatingProxy.php';
    if (!file_exists($proxyClass)) {
        record('modules/auth/ValidatingProxy.php — FILE TIDAK DITEMUKAN!', false, false);
    } else {
        $pc = file_get_contents($proxyClass);
        if (strpos($pc, '127.0.0.1') !== false && strpos($pc, 'proc_open') !== false) {
            record('ValidatingProxy: spawn CLI + bind 127.0.0.1 (loopback-only)', true);
        } else {
            record('ValidatingProxy: bind loopback / proc_open TIDAK terdeteksi', false, false);
        }
    }

    $proxyServer = PROJECT_ROOT . '/modules/auth/validating_proxy_server.php';
    if (!file_exists($proxyServer)) {
        record('modules/auth/validating_proxy_server.php — FILE TIDAK DITEMUKAN!', false, false);
    } else {
        $ps = file_get_contents($proxyServer);
        foreach (['SsrfGuard', 'CONNECT', 'resolvePublicAddresses'] as $pat) {
            if (strpos($ps, $pat) === false) {
                record("validating_proxy_server: {$pat} TIDAK ditemukan", false, false);
            }
        }
        record('validating_proxy_server: SsrfGuard diterapkan per hop (CONNECT + resolve)', true);
    }

    
    
    
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

    
    $dsFile = PROJECT_ROOT . '/drive/DriveService.php';
    if (file_exists($dsFile)) {
        $ds = file_get_contents($dsFile);
        
        
        
        $usesAuthStream =
            strpos($ds, "'stream?file='") !== false ||
            strpos($ds, "'stream.php?file='") !== false;
        if ($usesAuthStream) {
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







function testFatalBugRegression(): void {
    print_header('TEST 14: Fatal-Bug Regression Guard');

    
    
    
    
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

    
    
    
    
    $adFile = PROJECT_ROOT . '/controllers/admin/admin_data.php';
    if (!file_exists($adFile)) {
        record('controllers/admin/admin_data.php — FILE TIDAK DITEMUKAN!', false, false);
    } else {
        $ad = (string) file_get_contents($adFile);

        if (strpos($ad, 'COALESCE(SUM(views), 0)') !== false && strpos($ad, 'GROUP BY DATE(upload_date)') !== false) {
            record('admin_data: chart 7-Day pakai COALESCE(SUM(views),0) + GROUP BY tanggal', true);
        } else {
            record('admin_data: chart 7-Day TIDAK memakai COALESCE(SUM(views),0)/GROUP BY', false, false,
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




function testAdminContextAndPipelineHardening(): void {
    print_header('TEST 15: Admin Context Guards & Media Pipeline Hardening');

    
    $adminFiles = [
        'admin/catur.php' => [
            'Role check admin (require_admin)' => '/require_admin\s*\(/',
        ],
        'controllers/admin/admin_actions.php' => [
            'Role check via is_admin()'        => '/is_admin\s*\(\s*\$conn\s*\)/',
            'Guard direct access (MEEL_ADMIN_CONTEXT)' => "/defined\('MEEL_ADMIN_CONTEXT'\)/",
        ],
        'controllers/admin/admin_data.php' => [
            'Guard direct access (MEEL_ADMIN_CONTEXT)' => "/defined\('MEEL_ADMIN_CONTEXT'\)/",
        ],
        'admin/index.php' => [
            'Define MEEL_ADMIN_CONTEXT sebelum include' => "/define\('MEEL_ADMIN_CONTEXT'/",
        ],
        'admin/mfa_reset.php' => [
            'Define MEEL_ADMIN_CONTEXT sebelum include' => "/define\('MEEL_ADMIN_CONTEXT'/",
        ],
    ];

    foreach ($adminFiles as $file => $pats) {
        $full = PROJECT_ROOT . '/' . $file;
        if (!file_exists($full)) {
            record("{$file} — tidak ditemukan", true, true);
            continue;
        }
        $content = file_get_contents($full);
        foreach ($pats as $label => $pat) {
            if (preg_match($pat, $content)) {
                record("{$file}: {$label} ✓", true);
            } else {
                record("{$file}: {$label} ✗ — PATCH MISSING!", false, false);
            }
        }
    }

    
    $uploaderFile = PROJECT_ROOT . '/modules/core/Uploader.php';
    if (!file_exists($uploaderFile)) {
        record('modules/core/Uploader.php — FILE TIDAK DITEMUKAN!', false, false);
    } else {
        $uc = (string) file_get_contents($uploaderFile);
        $uploaderChecks = [
            'checkActiveUploadLimit() method'          => '/function checkActiveUploadLimit/',
            'flock() untuk serialisasi'                => '/flock\(/',
            'TTL auto-reset 5 menit'                   => '/300\)/',
            'Max 3 simultaneous uploads'               => '/current >= 3/',
            'register_shutdown_function decrement'     => '/register_shutdown_function/',
            'Dipanggil di processMusic()'              => '/\$this->checkActiveUploadLimit\(\)/',
            'Dipanggil di processVideo()'              => '/\$this->checkActiveUploadLimit\(\)/',
            'flock untuk penamaan folder video'        => '/meel_upload_video\.lock/',
            'flock music upload'                       => '/meel_music_upload\.lock/',
            'flock music transcode'                    => '/meel_music_transcode\.lock/',
            'flock HDD move'                           => '/meel_move_hdd\.lock/',
            'FFprobe failure handling'                 => '/duration.*<=.*0/',
            'try-finally untuk unlock'                 => '/finally \{.*flock\(\$lock_fp, LOCK_UN\)/s',
        ];

        foreach ($uploaderChecks as $name => $pat) {
            if (preg_match($pat, $uc)) {
                record("Uploader: {$name} ✓", true);
            } else {
                record("Uploader: {$name} ✗ — pattern tidak ditemukan", false, false);
            }
        }
    }

    
    $transcoderFile = PROJECT_ROOT . '/modules/core/Transcoder.php';
    if (!file_exists($transcoderFile)) {
        record('modules/core/Transcoder.php — FILE TIDAK DITEMUKAN!', false, false);
    } else {
        $tc = (string) file_get_contents($transcoderFile);
        $tcChecks = [
            'proc_open array (finalizeVideo)'   => '/proc_open\(\$hls_cmd/',
            'proc_open array (transcodeVideo)'  => '/proc_open\(\$tc_cmd/',
            'env vars via $env (LD_LIBRARY_PATH)' => "/'LD_LIBRARY_PATH'/",
            'env vars via $env (PATH)'          => "/'PATH'/",
            'putenv() untuk processDownload'    => "/putenv\('PATH/",
            'marker file anti-duplikat transcode' => '/marker_file/',
            'folder naming lock'                => '/meel_transcode_folder\.lock/',
            'stderr pipe untuk progress FFmpeg' => '/\$hls_pipes\[2\]/',
        ];

        foreach ($tcChecks as $name => $pat) {
            if (preg_match($pat, $tc)) {
                record("Transcoder: {$name} ✓", true);
            } else {
                record("Transcoder: {$name} ✗ — pattern tidak ditemukan", false, false);
            }
        }
    }
}








function testOpenRedirectHardening(): void {
    print_header('TEST 16: Open-Redirect & Redirect-Hardening Guard');

    
    $dcFile = PROJECT_ROOT . '/controllers/api/delete_comment.php';
    if (!file_exists($dcFile)) {
        record('controllers/api/delete_comment.php — FILE TIDAK DITEMUKAN!', false, false);
    } else {
        $dc = (string) file_get_contents($dcFile);

        
        preg_match_all('/header\s*\(\s*["\']Location:/i', $dc, $locMs);
        $locCount = count($locMs[0]);
        preg_match_all('/header\s*\(\s*["\']Location:\s*["\']\s*\.\s*safe_comment_back_url\(\)/i', $dc, $safeMs);
        $safeCount = count($safeMs[0]);
        if ($locCount > 0 && $locCount === $safeCount) {
            record("delete_comment: {$locCount} Location header — semua via safe_comment_back_url()", true);
        } else {
            record("delete_comment: {$locCount} Location, hanya {$safeCount} via helper tervalidasi — open redirect!", false, false,
                'Semua redirect harus lewat safe_comment_back_url() (validasi host referer)');
        }

        
        if (preg_match('/header\s*\(\s*["\']Location:\s*["\']\s*\.\s*\$_SERVER\[\'HTTP_REFERER\'\]/i', $dc)) {
            record('delete_comment: redirect ke HTTP_REFERER MENTAH — open redirect!', false, false,
                'Redirect referer harus lewat safe_comment_back_url()');
        } else {
            record('delete_comment: tidak ada redirect ke HTTP_REFERER mentah', true);
        }

        
        
        
        
        foreach ([
            'controllers/api/delete_comment.php',
            'controllers/api/comment.php',
            'controllers/api/like.php',
        ] as $rateFile) {
            $rfContent = (string) file_get_contents(PROJECT_ROOT . '/' . $rateFile);
            if (preg_match('/include[^;]*RateLimiter\.php/i', $rfContent)) {
                record("{$rateFile}: include RateLimiter.php tersisa — fatal Cannot declare class!", false, false,
                    'Hapus include; loader.php sudah require otomatis');
            } else {
                record("{$rateFile}: tanpa include RateLimiter.php (loader.php sudah require)", true);
            }
        }
    }

    
    $paFile = PROJECT_ROOT . '/music/playlist_action.php';
    if (!file_exists($paFile)) {
        record('music/playlist_action.php — FILE TIDAK DITEMUKAN!', false, false);
    } else {
        $pa = (string) file_get_contents($paFile);

        if (strpos($pa, "str_starts_with(\$url, '//')") !== false) {
            record('playlist_action: redirect() tolak //host (protocol-relative)', true);
        } else {
            record('playlist_action: redirect() TIDAK menolak //host — open redirect!', false, false,
                'Tambah cek str_starts_with($url, \'//\') sebelum menerima URL root-relative');
        }

        if (strpos($pa, "str_contains(\$url, '://')") !== false) {
            record('playlist_action: redirect() tolak URL dengan skema (://)', true);
        } else {
            record('playlist_action: redirect() TIDAK menolak skema (://)', false, false,
                'Tambah cek str_contains($url, \'://\')');
        }

        
        $hasClean = strpos($pa, "'beranda'") !== false
            && strpos($pa, "'playlist'") !== false
            && strpos($pa, "'watch'") !== false
            && strpos($pa, "'music/'" ) !== false;
        if ($hasClean) {
            record('playlist_action: allowlist redirect mencakup rute bersih (beranda/playlist/watch/music/)', true);
        } else {
            record('playlist_action: allowlist redirect TIDAK lengkap (rute bersih hilang)', false, false,
                'Prefix harus mencakup beranda, playlist, watch, music/');
        }
    }
}


function run(): int {
    echo CLR_CYAN . CLR_BOLD . "\n";
    echo "  " . chr(9556) . str_repeat(chr(9552), 56) . chr(9559) . "\n";
    echo "  " . chr(9553) . "   MEeL Automated Security Test Suite v1.2" . str_repeat(' ', 19) . chr(9553) . "\n";
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
    testAdminContextAndPipelineHardening();
    testOpenRedirectHardening();

    
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
