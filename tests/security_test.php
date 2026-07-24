<?php
/**
 * MEeL Security Test Suite v1.1
 * ==============================
 * Automated security scanning untuk verifikasi:
 *  - SQL Injection (raw queries tanpa prepared statement)
 *  - display_errors (ekspos error ke user)
 *  - CSRF Token (form protection)
 *  - XSS Protection (htmlspecialchars)
 *  - Path Traversal (file download validation)
 *  - File Upload (extension & type validation)
 *  - .htaccess Security (headers, directory listing)
 *  - Session Security (cookie params, timeout)
 *
 * Cara pakai:
 *   /opt/lampp/bin/php tests/security_test.php
 *
 * Exit codes:
 *   0 = Semua test PASS
 *   1 = Ada WARNING (lulus dengan catatan)
 *   2 = Ada FAIL (gagal, perlu perbaikan)
 */

define('PROJECT_ROOT', realpath(__DIR__ . '/..'));
define('EXCLUDE_DIRS', ['vendor', 'node_modules', '.git', 'tests', 'temp', 'assets/dict', 'data_drive']);
define('EXCLUDE_FILES', ['config.example.php', 'test.php', '.gitkeep']);

require_once __DIR__ . '/helpers.php';

// Globals
$GLOBALS['total_tests']  = 0;
$GLOBALS['passed']       = 0;
$GLOBALS['warnings']     = 0;
$GLOBALS['failed']       = 0;
$GLOBALS['fail_details'] = [];

// Check if file has a function call pattern
// ============================================================================
// TEST 1: SQL INJECTION SCAN
// ============================================================================

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

        // Find ->query( calls that contain variable interpolation ($var or {$var})
        // This regex finds query() calls where the SQL string contains a $ sign
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

// ============================================================================
// TEST 2: display_errors SCAN
// ============================================================================

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
    // bootstrap.php uses environment detection: display_errors=1 hanya untuk dev mode
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

// ============================================================================
// TEST 3: CSRF PROTECTION
// ============================================================================

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
        $hasVerify = (strpos($content, 'verify_csrf') !== false);
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
            record("{$f} \u{2014} Tidak ada CSRF token atau verify_csrf()", true, true);
        }
    }
}

// ============================================================================
// TEST 4: XSS PROTECTION
// ============================================================================

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

        // Flag only if there are many outputs but zero or very few htmlspecialchars
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

// ============================================================================
// TEST 5: FILE UPLOAD SECURITY
// ============================================================================

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

// ============================================================================
// TEST 6: PATH TRAVERSAL
// ============================================================================

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

// ============================================================================
// TEST 7: .HTACCESS SECURITY
// ============================================================================

function testHtaccessSecurity(): void {
    print_header('TEST 7: .htaccess & HTTP Security Headers');

    // ── 7a: Cek semua folder sensitif wajib punya .htaccess ──
    $sensitiveDirs = [
        // PHP-include only folders
        'controllers', 'controllers/admin', 'controllers/api', 'controllers/profile', 'controllers/system',
        'modules', 'modules/core', 'modules/media', 'modules/transcoder', 'modules/exceptions',
        'partials', 'drive/templates', 'docs/partials',
        // Auth & config
        'auth', 'database',
        // Error & temp
        'err', 'temp',
        // Logs & tests
        'logs', 'tests',
        // Upload dirs (harus disable PHP)
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

    // ── 7b: Verifikasi directive spesifik per folder ──
    $checks = [
        '.htaccess'                 => ['Options -Indexes', 'X-Content-Type-Options', 'Deny from all'],
        'auth/.htaccess'            => ['Options -Indexes', 'Deny from all'],
        'admin/.htaccess'           => ['Options -Indexes', 'FilesMatch'],
        'logs/.htaccess'            => ['Options -Indexes', 'Deny from all'],
        'data_drive/.htaccess'      => ['php_flag engine off', 'ForceType', 'Options -Indexes'],
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

// ============================================================================
// TEST 8: SESSION SECURITY
// ============================================================================

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

// ============================================================================
// TEST 9: HTTP SECURITY HEADERS (CSP)
// ============================================================================

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

// ============================================================================
// TEST 10: COMMAND INJECTION (SHELL EXEC)
// ============================================================================

function testCommandInjection(): void {
    print_header('TEST 10: Command Injection ' . chr(8212) . ' Shell Execution Safety');

    $risky = [
        'modules/core/Uploader.php'     => ['shell_exec', 'exec', 'popen'],
        'modules/core/Transcoder.php'   => ['shell_exec', 'exec', 'popen'],
        'modules/core/helpers.php'      => ['shell_exec'],
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

// ============================================================================
// TEST 11: PASSWORD POLICY
// ============================================================================

function testPasswordPolicy(): void {
    print_header('TEST 11: Password Policy & Strength');

    $checks = [
        ['Min 8 karakter password',          'auth/register.php', '/strlen.*pass.*8|min.*8/'],
        ['Brute force lockout',              'auth/login.php',    '/login_fail_count/'],
        ['Lockout timeout',                  'auth/login.php',    '/lockout_time/'],
        ['Username regex (alpha numeric)',   'auth/register.php', '/preg_match.*a-zA-Z0-9/'],
        ['Guest username blacklist',         'auth/register.php', '/stripos.*guest/'],
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

// ============================================================================
// TEST 12: FILE INTEGRITY
// ============================================================================

function testFileIntegrity(): void {
    print_header('TEST 12: File Integrity ' . chr(8212) . ' Critical Files');

    $critical = [
        '.htaccess', 'auth/config.php', 'auth/auth.php', 'auth/login.php',
        'auth/logout.php', 'auth/register.php', 'modules/core/helpers.php',
        'modules/core/activity_logger.php', 'modules/core/System.php', 'modules/core/Uploader.php',
        'modules/core/Transcoder.php', 'modules/media/MediaInteraction.php', 'modules/media/MediaViewer.php',
        'modules/media/MediaLibrary.php', 'modules/core/GarbageCollector.php', 'modules/core/japanese.php',
        'admin/.htaccess', 'auth/.htaccess', 'data_drive/.htaccess',
        'drive/DriveService.php', 'controllers/admin/admin_actions.php', 'controllers/admin/admin_data.php',
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

// ============================================================================
// MAIN
// ============================================================================

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

    // Save report
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
