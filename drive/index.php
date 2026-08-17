<?php
require '../auth/auth.php';
require '../auth/config.php';
require '../modules/core/helpers.php';
require __DIR__ . '/DriveService.php';

$user = DriveUserContext::fromSession($_SESSION);
$user->authorize();

$storage = new DriveStorage(DriveStorage::defaultBasePath(), $user);
$renderer = new DriveViewRenderer();
$currentScope = $storage->normalizeScope($_GET['scope'] ?? DriveStorage::SCOPE_PUBLIC);

$videos = $storage->listFilesByType('video', $currentScope);
$audios = $storage->listFilesByType('audio', $currentScope);
$documents = $storage->listFilesByType('dokumen', $currentScope);

$usage = null;
$usagePercentage = null;

if ($user->isMember()) {
    $usage = get_user_usage($user->username);
    $limit = 20 * 1024 * 1024 * 1024;
    $usagePercentage = min(100, ($usage / $limit) * 100);
}
?>
<!DOCTYPE html>
<html lang="id" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="MEeL Cloud | Dashboard">
    <meta property="og:description" content="MEeL Cloud Drive - Kelola dan simpan file Anda dengan aman di cloud pribadi.">
    <title>MEeL Cloud | Dashboard</title>
    <?php include '../partials/link.php'; ?>
    <script src="../assets/js/compatibilitas/sweetalert2.all.min.js"></script>
    <script src="../assets/js/compatibilitas/script.min.js"></script>
    <?php foreach (require __DIR__ . '/../assets/css/drive/manifest.php' as $__f): ?>
    <link rel="stylesheet" href="../assets/css/drive/<?= $__f ?>?v=<?= filemtime(__DIR__ . '/../assets/css/drive/' . $__f) ?>">
    <?php endforeach; ?>
</head>

<body class="antialiased">

    <div class="flex min-h-screen">
        <aside class="w-64 glass border-r border-gray-800 hidden md:flex flex-col sticky top-0 h-screen">
            <div class="p-6">
                <div class="flex items-center gap-3 mb-8" onclick="window.location.href='../'" style="cursor: pointer;">
                    <img src="../assets/MEeL.png" class="w-10 h-10 rounded-xl shadow-lg shadow-blue-500/20" alt="Logo">
                    <div>
                        <h1 class="font-bold text-lg leading-none">MEeL <span class="text-blue-500">Cloud</span></h1>
                        <p class="text-[10px] text-gray-500 tracking-widest uppercase mt-1">Storage System</p>
                    </div>
                </div>

                <nav class="space-y-1">
                    <p class="text-[10px] font-bold text-gray-600 uppercase tracking-widest px-3 mb-2">Scope</p>
                    <a href="?scope=public" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-800 transition <?= $currentScope === 'public' ? 'nav-active' : '' ?>" title="File publik yang bisa diakses semua orang">
                        <i data-lucide="globe" class="w-5 h-5"></i> Public Space
                    </a>
                    <a href="?scope=private" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-800 transition <?= $currentScope === 'private' ? 'nav-active' : '' ?>" title="File pribadi Anda">
                        <i data-lucide="shield-check" class="w-5 h-5"></i> Private Cloud
                    </a>
                </nav>

                <nav class="mt-10 space-y-1">
                    <p class="text-[10px] font-bold text-gray-600 uppercase tracking-widest px-3 mb-2">Kategori</p>
                    <button onclick="showSection('video', this)" class="w-full flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-800 transition nav-btn-desktop active" title="Tampilkan file video">
                        <i data-lucide="play-circle" class="w-5 h-5 text-red-500"></i> Video
                    </button>
                    <button onclick="showSection('audio', this)" class="w-full flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-800 transition nav-btn-desktop text-gray-400" title="Tampilkan file audio">
                        <i data-lucide="music" class="w-5 h-5 text-orange-500"></i> Audio
                    </button>
                    <button onclick="showSection('dokumen', this)" class="w-full flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-800 transition nav-btn-desktop text-gray-400" title="Tampilkan file dokumen">
                        <i data-lucide="file-text" class="w-5 h-5 text-green-500"></i> Dokumen
                    </button>
                </nav>
            </div>

            <div class="mt-auto p-4 border-t border-gray-800 bg-black/20">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center font-bold">
                        <?= strtoupper(substr($user->username, 0, 1)) ?>
                    </div>
                    <div class="overflow-hidden">
                        <p class="text-sm font-semibold truncate"><?= htmlspecialchars($user->username, ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="text-[10px] text-gray-500 uppercase"><?= htmlspecialchars($user->role, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
            </div>
        </aside>

        <main class="flex-1 p-4 md:p-10 w-full overflow-x-hidden">
            <!-- Mobile Header -->
            <div class="md:hidden flex items-center justify-between mb-6 pb-4 border-b border-gray-800">
                <div class="flex items-center gap-3" onclick="window.location.href='../'" style="cursor: pointer;" title="Kembali ke MEeL HUB">
                    <img src="../assets/MEeL.png" class="w-8 h-8 rounded-lg shadow-lg shadow-blue-500/20" alt="Logo">
                    <div>
                        <h1 class="font-bold text-base leading-none">MEeL <span class="text-blue-500">Cloud</span></h1>
                    </div>
                </div>
                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center font-bold text-xs">
                    <?= strtoupper(substr($user->username, 0, 1)) ?>
                </div>
            </div>

            <!-- Mobile Scope Toggle -->
            <div class="md:hidden flex items-center gap-2 mb-4">
                <a href="?scope=public" class="flex-1 text-center text-xs px-4 py-2 rounded-lg font-semibold transition <?= $currentScope === 'public' ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-400' ?>">Public</a>
                <a href="?scope=private" class="flex-1 text-center text-xs px-4 py-2 rounded-lg font-semibold transition <?= $currentScope === 'private' ? 'bg-blue-600 text-white' : 'bg-gray-800 text-gray-400' ?>">Private</a>
            </div>

            <!-- Mobile Category Tabs -->
            <div class="md:hidden flex overflow-x-auto gap-2 mb-6 pb-2 scrollbar-hide">
                <button onclick="showSection('video', this, true)" class="flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-500/10 border border-blue-500 text-blue-500 whitespace-nowrap nav-btn-mobile active font-medium text-xs" title="Tampilkan file video">
                    <i data-lucide="play-circle" class="w-4 h-4"></i> Video
                </button>
                <button onclick="showSection('audio', this, true)" class="flex items-center gap-2 px-4 py-2 rounded-lg bg-gray-800 border border-transparent text-gray-400 whitespace-nowrap nav-btn-mobile font-medium text-xs" title="Tampilkan file audio">
                    <i data-lucide="music" class="w-4 h-4 text-orange-500"></i> Audio
                </button>
                <button onclick="showSection('dokumen', this, true)" class="flex items-center gap-2 px-4 py-2 rounded-lg bg-gray-800 border border-transparent text-gray-400 whitespace-nowrap nav-btn-mobile font-medium text-xs" title="Tampilkan file dokumen">
                    <i data-lucide="file-text" class="w-4 h-4 text-green-500"></i> Dokumen
                </button>
            </div>

            <header class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8 hidden md:flex">
                <div>
                    <h2 id="sectionHeading" class="text-3xl font-extrabold tracking-tight">
                        Drive <span id="sectionAccent" class="text-red-500">Video</span>
                    </h2>
                    <p id="fileCount" class="text-sm text-gray-500 mt-1">Memuat file...</p>
                </div>

                <div class="flex items-center gap-3">
                    <div class="relative flex-1">
                        <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                        <input type="text" id="search-input-desktop" placeholder="Cari file..." class="bg-gray-900 border border-gray-800 rounded-full py-2 pl-10 pr-4 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-full md:w-64">
                    </div>
                    <button onclick="filterDriveFiles()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-full text-xs font-bold uppercase tracking-wider transition-all flex-shrink-0">
                        Cari
                    </button>
                </div>
            </header>

            <!-- Mobile Heading & Search (since desktop header is hidden on mobile) -->
            <div class="md:hidden flex flex-col gap-3 mb-6">
                <div>
                    <h2 class="text-xl font-extrabold tracking-tight">
                        Drive <span id="sectionAccentMobile" class="text-red-500">Video</span>
                    </h2>
                    <p id="fileCountMobile" class="text-xs text-gray-500 mt-0.5">Memuat file...</p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="relative flex-1">
                        <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                        <input type="text" id="search-input-mobile" placeholder="Cari file..." class="w-full bg-gray-900 border border-gray-800 rounded-xl py-2.5 pl-10 pr-4 text-sm focus:outline-none focus:border-blue-500">
                    </div>
                    <button onclick="filterDriveFiles()" class="px-3 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold uppercase tracking-wider transition-all flex-shrink-0">
                        Cari
                    </button>
                </div>
            </div>

            <?php if ($user->isMember() && $usage !== null && $usagePercentage !== null): ?>
                <div class="glass rounded-2xl p-4 mb-8 flex items-center gap-6">
                    <div class="flex-1">
                        <div class="flex justify-between text-[11px] font-bold uppercase mb-2">
                            <span class="text-gray-400">Penyimpanan Terpakai</span>
                            <span id="storageUsageText" class="<?= $usagePercentage > 80 ? 'text-red-500' : 'text-blue-500' ?>"><?= format_bytes($usage) ?> / 20 GB</span>
                        </div>
                        <div class="h-2 bg-gray-800 rounded-full overflow-hidden">
                            <div id="storageUsageBar" class="h-full bg-gradient-to-r from-blue-500 to-blue-500 transition-all duration-500" style="width: <?= $usagePercentage ?>%"></div>
                        </div>
                    </div>
                    <button id="refreshBtn" onclick="refreshDrive()" class="p-2 hover:bg-gray-800 rounded-lg transition-all" title="Refresh data (grid + penyimpanan)"><i data-lucide="refresh-cw" class="w-4 h-4"></i></button>
                </div>
            <?php endif; ?>
            <section class="upload-dropzone glass rounded-2xl p-6 mb-8 border-dashed border-2 border-gray-800 hover:border-blue-500/50 transition-colors" id="uploadDropzone">
                <!-- Drop hint overlay (visible saat drag) -->
                <div class="dropzone-hint" id="dropzoneHint">
                    <div class="dropzone-hint-icon">
                        <i data-lucide="cloud-upload" class="w-5 h-5"></i>
                    </div>
                    <span class="dropzone-hint-text">Lepaskan file untuk mengunggah</span>
                    <span class="dropzone-hint-sub">atau klik area ini untuk memilih file</span>
                </div>

                <form id="uploadForm" action="upload?ajax=1" method="POST" enctype="multipart/form-data" class="flex flex-col md:flex-row items-center gap-6">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="scope" value="<?= htmlspecialchars($currentScope, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="flex-1 w-full">
                    <label for="fileInput" class="flex items-center justify-center gap-3 p-4 bg-black/30 rounded-xl cursor-pointer hover:bg-black/50 transition border border-gray-800" title="Pilih file untuk diunggah">
                            <i data-lucide="cloud-upload" class="w-6 h-6 text-blue-500"></i>
                            <span id="fileLabel" class="text-sm text-gray-400 font-medium">Tarik file atau klik untuk memilih</span>
                            <input type="file" name="file_drive" id="fileInput" class="hidden" onchange="updateFileName(this)" required>
                        </label>
                    </div>                    <button type="submit" name="submit_upload" id="uploadBtn" class="w-full md:w-auto bg-blue-600 hover:bg-blue-700 text-white px-8 py-4 rounded-xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-blue-600/20" title="Unggah file yang dipilih">
                        Unggah Berkas <i data-lucide="chevron-right" class="w-4 h-4"></i>
                    </button>
                </form>
            </section>

            <div id="drive-video" class="drive-section">
                <?php $renderer->renderFileGrid($videos, '#ef4444', 'play', 'video', $currentScope); ?>
            </div>
            <div id="drive-audio" class="drive-section hidden">
                <?php $renderer->renderFileGrid($audios, '#f97316', 'music', 'audio', $currentScope); ?>
            </div>
            <div id="drive-dokumen" class="drive-section hidden">
                <?php $renderer->renderFileGrid($documents, '#10b981', 'file-text', 'dokumen', $currentScope); ?>
            </div>
        </main>
    </div>

    <!-- Floating Upload Progress Card -->
    <div id="uploadProgressCard" class="upload-prog-card hidden">
        <div class="upload-prog-header">
            <div class="upload-prog-header-title" id="uploadProgToggle" title="Klik untuk detail">
                <span class="upload-prog-toggle-icon">
                    <i data-lucide="chevron-down" class="w-3.5 h-3.5"></i>
                </span>
                <i data-lucide="cloud-upload" class="w-4 h-4 text-blue-400 flex-shrink-0 upload-prog-header-icon"></i>
                <span id="uploadProgFilename" class="upload-prog-filename">unggah file...</span>
            </div>
            <button id="uploadProgClose" class="upload-prog-close" title="Tutup">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <div class="upload-prog-body">
            <!-- Progress bar -->
            <div class="upload-prog-track">
                <div id="uploadProgBar" class="upload-prog-fill" style="width: 0%"></div>
            </div>

            <!-- Percentage + status text -->
            <div class="upload-prog-info">
                <span id="uploadProgPercent" class="upload-prog-pct">0%</span>
                <span id="uploadProgStatus" class="upload-prog-status">Mengunggah...</span>
            </div>

            <!-- Stats grid: speed, duration, size -->
            <div class="upload-prog-stats">
                <div class="upload-prog-stat">
                    <span class="upload-prog-stat-label">Kecepatan</span>
                    <span id="uploadProgSpeed" class="upload-prog-stat-val">—</span>
                </div>
                <div class="upload-prog-stat">
                    <span class="upload-prog-stat-label">Durasi</span>
                    <span id="uploadProgDuration" class="upload-prog-stat-val">—</span>
                </div>
                <div class="upload-prog-stat">
                    <span class="upload-prog-stat-label">Ukuran</span>
                    <span id="uploadProgSize" class="upload-prog-stat-val">—</span>
                </div>
            </div>

            <!-- Done / Error state -->
            <div id="uploadProgDone" class="upload-prog-result hidden">
                <div class="upload-prog-result-icon upload-prog-result-success">
                    <!-- Animated checkmark SVG -->
                    <svg class="upload-checkmark" viewBox="0 0 52 52" width="28" height="28">
                        <circle class="upload-checkmark-circle" cx="26" cy="26" r="24" fill="none" stroke="currentColor" stroke-width="3"/>
                        <path class="upload-checkmark-check" d="M14 27l7 7 16-16" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <span class="upload-prog-result-text">Unggah Selesai</span>
            </div>
            <div id="uploadProgError" class="upload-prog-result hidden">
                <div class="upload-prog-result-icon upload-prog-result-error">
                    <i data-lucide="alert-circle" class="w-5 h-5"></i>
                </div>
                <span class="upload-prog-result-text">Unggah Gagal</span>
            </div>

            <!-- Confetti container (triggered via JS on success) -->
            <div id="uploadConfetti" class="upload-confetti hidden"></div>
        </div>
    </div>

    <div id="previewModal" class="hidden fixed inset-0 z-[100] bg-black/90 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-[#161b2a] border border-gray-800 w-full max-w-4xl rounded-2xl overflow-hidden shadow-2xl relative">

            <div class="flex items-center justify-between p-4 border-bottom border-gray-800 bg-black/20">
                <div class="flex items-center gap-2">
                    <i data-lucide="file" class="w-4 h-4 text-blue-500"></i>
                    <h3 id="previewTitle" class="text-sm font-semibold truncate max-w-[200px] md:max-w-md text-gray-300">Nama File</h3>
                </div>                <button onclick="closePreview()" class="p-2 hover:bg-red-500/20 text-gray-500 hover:text-red-500 rounded-lg transition" title="Tutup pratinjau">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <div id="previewContent" class="min-h-[300px] flex items-center justify-center bg-black/40">
            </div>

        </div>
    </div>

    <script src="../assets/js/drive/navigation.js?v=<?= filemtime('../assets/js/drive/navigation.js') ?>"></script>
    <script src="../assets/js/drive/file-input.js?v=<?= filemtime('../assets/js/drive/file-input.js') ?>"></script>
    <script src="../assets/js/drive/preview.js?v=<?= filemtime('../assets/js/drive/preview.js') ?>"></script>
    <script src="../assets/js/drive/search.js?v=<?= filemtime('../assets/js/drive/search.js') ?>"></script>
    <script src="../assets/js/drive/upload.js?v=<?= filemtime('../assets/js/drive/upload.js') ?>"></script>
    <?php include '../partials/footer.php'; ?>
</body>

</html>
