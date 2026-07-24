<?php
http_response_code(503);
session_name('meel');
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$username  = htmlspecialchars($_SESSION['username'] ?? 'Guest');
$back_url  = $_SERVER['HTTP_REFERER'] ?? '../index.php';

if (strpos($back_url, 'maintance.php') !== false) {
    $back_url = '../index.php';
}

// Pesan Berdasarkan Role
if ($user_role === 'admin') {
    $title     = "CRITICAL_ERROR: HDD_NOT_FOUND";
    $desc      = "$username, Terdeteksi err, koneksi ke media storage terputus. <br> Periksa mounting HDD di <code>/media/</code> sekarang!";
    $status    = "OFFLINE";
    $action    = "SUDO REPAIR REQUIRED";
} else {
    $title     = "SYSTEM_PAUSED";
    $desc      = "Halo $username, server MEeL sedang melakukan perbaikan. <br> Mohon hubungi admin untuk dilakukannya perbaikan.";
    $status    = "MAINTENANCE";
    $action    = "PLEASE_WAIT";
}

// ── Path dari config.php (dideklarasikan di sini agar scope global) ──────────
$hdd_base_path = defined('MEEL_HDD_BASE') ? MEEL_HDD_BASE : '/path/to/your/media';
$hdd_dir       = dirname($hdd_base_path);
$media_root    = dirname($hdd_dir);

// ── DEBUG DATA (admin only) ─────────────────────────────────────────────────
$debug = [];
if ($user_role === 'admin') {
    // PHP process user
    $debug['php_user']        = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') : get_current_user();
    $debug['php_self']        = $_SERVER['PHP_SELF'] ?? 'N/A';
    $debug['referer']         = $_SERVER['HTTP_REFERER'] ?? '(tidak ada referer)';

    // Paths to check (dari config.php terpusat)
    
    $paths = [
        '/media',
        $media_root,
        $hdd_dir,
        $hdd_base_path,
        $hdd_base_path . '/video',
        $hdd_base_path . '/music',
        $hdd_base_path . '/books',
        $hdd_base_path . '/drive',
    ];

    $debug['paths'] = [];
    foreach ($paths as $p) {
        $entry = [
            'path'       => $p,
            'exists'     => file_exists($p),
            'is_dir'     => is_dir($p),
            'readable'   => is_readable($p),
            'executable' => is_executable($p),
            'perms'      => file_exists($p) ? substr(sprintf('%o', fileperms($p)), -4) : 'N/A',
            'owner'      => '',
            'acl'        => '',
        ];

        if (file_exists($p)) {
            $stat          = stat($p);
            $pw            = function_exists('posix_getpwuid') ? posix_getpwuid($stat['uid']) : null;
            $gr            = function_exists('posix_getgrgid') ? posix_getgrgid($stat['gid']) : null;
            $entry['owner'] = ($pw['name'] ?? $stat['uid']) . ':' . ($gr['name'] ?? $stat['gid']);

            // getfacl output
            $acl_raw = shell_exec('getfacl ' . escapeshellarg($p) . ' 2>&1');
            $entry['acl'] = $acl_raw ?: '(getfacl tidak tersedia)';
        }

        $debug['paths'][] = $entry;
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="MEeL | System Health">
    <meta property="og:description" content="Server MEeL sedang dalam masa pemeliharaan. Mohon tunggu beberapa saat.">
    <title>MEeL | System Health</title>
    <?php include '../partials/link.php'; ?>
    <style>
        @import url('../assets/css/font.css');

        body {
            font-family: 'JetBrains Mono', monospace;
        }

        .bg-grid {
            background-image: radial-gradient(circle at 2px 2px, rgba(59, 130, 246, 0.05) 1px, transparent 0);
            background-size: 40px 40px;
        }

        .glitch {
            text-shadow: 2px 0 #ef4444, -2px 0 #06b6d4;
            animation: glitch-anim 2s infinite linear alternate-reverse;
        }

        @keyframes glitch-anim {
            0% {
                transform: skew(0deg);
            }

            20% {
                transform: skew(-1deg);
            }

            40% {
                transform: skew(1deg);
            }

            100% {
                transform: skew(0deg);
            }
        }

        .scanline {
            width: 100%;
            height: 2px;
            background: rgba(59, 130, 246, 0.1);
            position: absolute;
            top: 0;
            left: 0;
            animation: scan 4s linear infinite;
        }

        @keyframes scan {
            from {
                top: 0%;
            }

            to {
                top: 100%;
            }
        }
    </style>
</head>

<body class="bg-[#0b0e14] text-gray-300 h-screen flex flex-col items-center justify-between bg-grid overflow-hidden">

    <div class="scanline"></div>

    <div class="absolute inset-0 opacity-20 pointer-events-none">
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-blue-600/30 rounded-full blur-[160px]"></div>
    </div>

    <div class="relative z-10 w-full max-w-xl p-8 mx-4 my-auto">
        <div class="bg-gray-900/80 border border-white/10 rounded-t-2xl p-3 flex items-center justify-between backdrop-blur-md">
            <div class="flex gap-1.5 ml-2">
                <div class="w-2.5 h-2.5 rounded-full bg-red-500/50"></div>
                <div class="w-2.5 h-2.5 rounded-full bg-orange-500/50"></div>
                <div class="w-2.5 h-2.5 rounded-full bg-green-500/50"></div>
            </div>
            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-widest">Console :: MEeL_Diagnostic</span>
            <div class="w-10"></div>
        </div>

        <div class="bg-gray-900/40 border-x border-b border-white/10 rounded-b-2xl p-8 backdrop-blur-xl shadow-2xl">
            <div class="flex flex-col items-center text-center">
                <div class="p-5 bg-blue-500/10 rounded-full mb-6 border border-blue-500/20 relative">
                    <i data-lucide="hard-drive" class="w-10 h-10 text-blue-400 animate-pulse"></i>
                    <div class="absolute -top-1 -right-1 bg-red-600 rounded-full p-1 animate-bounce">
                        <i data-lucide="zap-off" class="w-3 h-3 text-white"></i>
                    </div>
                </div>

                <h1 class="text-2xl font-black mb-2 tracking-tighter text-white glitch uppercase">
                    <?= $title ?>
                </h1>

                <p class="text-sm text-gray-400 mb-8 leading-relaxed">
                    <?= $desc ?>
                </p>

                <div class="w-full space-y-4 mb-8">
                    <div class="space-y-1.5">
                        <div class="flex justify-between text-[10px] font-bold uppercase tracking-widest">
                            <span class="text-blue-400">Recovery Status</span>
                            <span class="text-gray-500">Scanning...</span>
                        </div>
                        <div class="w-full bg-gray-800 h-1.5 rounded-full overflow-hidden">
                            <div class="bg-blue-500 h-full w-[45%] animate-[pulse_2s_infinite]"></div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-3 w-full pt-6 border-t border-white/5 gap-4">
                    <div class="text-left">
                        <span class="block text-[9px] text-gray-500 uppercase font-black">Status</span>
                        <span class="text-[11px] text-orange-500 font-bold"><?= $status ?></span>
                    </div>
                    <div class="text-left">
                        <span class="block text-[9px] text-gray-500 uppercase font-black">Action</span>
                        <span class="text-[11px] text-blue-400 font-bold"><?= $action ?></span>
                    </div>
                    <div class="text-right">
                        <a href="<?= $back_url ?>" class="inline-flex items-center gap-1 text-[11px] text-gray-400 hover:text-white transition group">
                            <i data-lucide="terminal" class="w-3 h-3 group-hover:text-blue-400"></i> Back
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <p class="mt-4 text-[9px] text-gray-600 text-center uppercase tracking-widest">
            Log: Process [MEeL_Core] terminated due to I/O Exception :: MEeL 2025
        </p>
    </div>

    <div class="w-full text-center pb-4 relative z-20">
        <?php include '../partials/footer.php'; ?>
    </div>

    <?php if ($user_role === 'admin' && !empty($debug)): ?>
        <div class="fixed bottom-0 left-0 right-0 z-50 max-h-[40vh] overflow-y-auto" style="background:#0a0c10;border-top:1px solid #1e3a5f;">
            <div class="p-4 flex items-center justify-between border-b" style="border-color:#1e3a5f;">
                <div class="flex items-center gap-2">
                    <i data-lucide="terminal" class="w-4 h-4 text-blue-400"></i>
                    <span class="text-[11px] font-black text-blue-400 uppercase tracking-widest">🔍 MEeL Debug Console</span>
                </div>
                <div class="flex gap-4 text-[10px] font-mono">
                    <span class="text-gray-500">PHP User: <span class="text-yellow-400"><?= htmlspecialchars($debug['php_user']) ?></span></span>
                    <span class="text-gray-500">Self: <span class="text-cyan-400"><?= htmlspecialchars($debug['php_self']) ?></span></span>
                    <span class="text-gray-500">Referer: <span class="text-orange-400"><?= htmlspecialchars($debug['referer']) ?></span></span>
                </div>
            </div>

            <div class="p-4">
                <p class="text-[9px] text-gray-600 uppercase font-black tracking-widest mb-3">Path Access Check — semua path ini harus ✅ agar MEeL berjalan normal</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-[10px] font-mono border-collapse">
                        <thead>
                            <tr style="color:#4a5568;border-bottom:1px solid #1e3a5f;">
                                <th class="text-left py-1.5 pr-4">Path</th>
                                <th class="text-center px-2">exists</th>
                                <th class="text-center px-2">is_dir</th>
                                <th class="text-center px-2">readable</th>
                                <th class="text-center px-2">exec</th>
                                <th class="text-center px-2">perms</th>
                                <th class="text-left px-4">owner</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($debug['paths'] as $entry):
                                $allOk = $entry['exists'] && $entry['is_dir'] && $entry['readable'] && $entry['executable'];
                                $rowColor = $allOk ? '#0d2b1a' : '#2b0d0d';
                                $icon = fn($v) => $v ? '<span style="color:#22c55e">✅</span>' : '<span style="color:#ef4444">❌</span>';
                            ?>
                                <tr style="background:<?= $rowColor ?>;border-bottom:1px solid #111827;">
                                    <td class="py-2 pr-4" style="color:<?= $allOk ? '#86efac' : '#fca5a5' ?>"><?= htmlspecialchars($entry['path']) ?></td>
                                    <td class="text-center px-2"><?= $icon($entry['exists']) ?></td>
                                    <td class="text-center px-2"><?= $icon($entry['is_dir']) ?></td>
                                    <td class="text-center px-2"><?= $icon($entry['readable']) ?></td>
                                    <td class="text-center px-2"><?= $icon($entry['executable']) ?></td>
                                    <td class="text-center px-2" style="color:#60a5fa"><?= $entry['perms'] ?></td>
                                    <td class="px-4" style="color:#94a3b8"><?= htmlspecialchars($entry['owner']) ?></td>
                                </tr>
                                <?php if (!$allOk && !empty($entry['acl'])): ?>
                                    <tr style="background:#1a0f00;border-bottom:1px solid #111827;">
                                        <td colspan="7" class="px-4 py-2">
                                            <p class="text-yellow-500 font-black uppercase text-[9px] mb-1">ACL for <?= htmlspecialchars($entry['path']) ?>:</p>
                                            <pre style="color:#6b7280;font-size:9px;white-space:pre-wrap;"><?= htmlspecialchars($entry['acl']) ?></pre>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 p-3 rounded-lg" style="background:#111827;border:1px solid #1e3a5f;">
                    <p class="text-[9px] text-gray-500 font-black uppercase tracking-widest mb-2">Cara Fix — Jalankan di terminal:</p>
                    <pre class="text-green-400 text-[10px]">sudo setfacl -m u:daemon:rx <?= htmlspecialchars($media_root) ?>
sudo setfacl -m u:daemon:rx <?= htmlspecialchars($hdd_dir) ?>
sudo setfacl -R -m u:daemon:rx <?= htmlspecialchars($hdd_base_path) ?></pre>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        lucide.createIcons();
    </script>
</body>

</html>