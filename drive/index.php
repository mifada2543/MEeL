<?php
require '../auth/auth.php';
require '../auth/config.php';
require '../modules/core/helpers.php';
require __DIR__ . '/DriveService.php';

$user = DriveUserContext::fromSession($_SESSION);
$user->authorize();

$storage = new DriveStorage(dirname(__DIR__) . '/data_drive', $user);
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
    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <script src="../assets/js/script.min.js"></script>
    <link rel="stylesheet" href="../assets/css/drive.css">
    <style>
        :root {
            --bg-main: #0b0f1a;
            --bg-card: #161b2a;
            --accent-blue: #3b82f6;
        }

        body {
            background-color: var(--bg-main);
            color: #f3f4f6;
            font-family: 'Inter', sans-serif;
        }

        .glass {
            background: rgba(22, 27, 42, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .nav-active {
            background: rgba(59, 130, 246, 0.1);
            border-left: 4px solid var(--accent-blue);
            color: var(--accent-blue);
        }
    </style>
</head>

<body class="antialiased">

    <div class="flex min-h-screen">
        <aside class="w-64 glass border-r border-gray-800 hidden md:flex flex-col sticky top-0 h-screen">
            <div class="p-6">
                <div class="flex items-center gap-3 mb-8" onclick="window.location.href='../index.php'" style="cursor: pointer;">
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
                <div class="flex items-center gap-3" onclick="window.location.href='../index.php'" style="cursor: pointer;" title="Kembali ke MEeL HUB">
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
                    <div class="relative">
                        <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                        <input type="text" placeholder="Cari file..." class="bg-gray-900 border border-gray-800 rounded-full py-2 pl-10 pr-4 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-full md:w-64">
                    </div>
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
                <div class="relative">
                    <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                    <input type="text" placeholder="Cari file..." class="w-full bg-gray-900 border border-gray-800 rounded-xl py-2.5 pl-10 pr-4 text-sm focus:outline-none focus:border-blue-500">
                </div>
            </div>

            <?php if ($user->isMember() && $usage !== null && $usagePercentage !== null): ?>
                <div class="glass rounded-2xl p-4 mb-8 flex items-center gap-6">
                    <div class="flex-1">
                        <div class="flex justify-between text-[11px] font-bold uppercase mb-2">
                            <span class="text-gray-400">Penyimpanan Terpakai</span>
                            <span class="<?= $usagePercentage > 80 ? 'text-red-500' : 'text-blue-500' ?>"><?= format_bytes($usage) ?> / 20 GB</span>
                        </div>
                        <div class="h-2 bg-gray-800 rounded-full overflow-hidden">
                            <div class="h-full bg-gradient-to-r from-blue-500 to-blue-500 transition-all duration-500" style="width: <?= $usagePercentage ?>%"></div>
                        </div>
                    </div>
                    <a href="index.php?scope=<?= urlencode($currentScope) ?>" class="p-2 hover:bg-gray-800 rounded-lg" title="Refresh halaman"><i data-lucide="refresh-cw" class="w-4 h-4"></i></a>
                </div>
            <?php endif; ?>

            <section class="glass rounded-2xl p-6 mb-8 border-dashed border-2 border-gray-800 hover:border-blue-500/50 transition-colors">
                <form action="upload.php" method="POST" enctype="multipart/form-data" class="flex flex-col md:flex-row items-center gap-6">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="scope" value="<?= htmlspecialchars($currentScope, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="flex-1 w-full">
                    <label for="fileInput" class="flex items-center justify-center gap-3 p-4 bg-black/30 rounded-xl cursor-pointer hover:bg-black/50 transition border border-gray-800" title="Pilih file untuk diunggah">
                            <i data-lucide="cloud-upload" class="w-6 h-6 text-blue-500"></i>
                            <span id="fileLabel" class="text-sm text-gray-400 font-medium">Tarik file atau klik untuk memilih</span>
                            <input type="file" name="file_drive" id="fileInput" class="hidden" onchange="updateFileName(this)" required>
                        </label>
                    </div>                    <button type="submit" name="submit_upload" class="w-full md:w-auto bg-blue-600 hover:bg-blue-700 text-white px-8 py-4 rounded-xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-blue-600/20" title="Unggah file yang dipilih">
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

    <script>
        lucide.createIcons();

        const counts = {
            video: document.querySelectorAll('#drive-video .glass').length,
            audio: document.querySelectorAll('#drive-audio .glass').length,
            dokumen: document.querySelectorAll('#drive-dokumen .glass').length
        };

        const accents = {
            video: {
                color: 'text-red-500',
                label: 'Video'
            },
            audio: {
                color: 'text-orange-500',
                label: 'Audio'
            },
            dokumen: {
                color: 'text-green-500',
                label: 'Dokumen'
            }
        };

        function showSection(id, btn, isMobile = false) {
            document.querySelectorAll('.drive-section').forEach(section => section.classList.add('hidden'));

            const target = document.getElementById('drive-' + id);
            if (target) {
                target.classList.remove('hidden');
            }

            document.querySelectorAll('.nav-btn-desktop').forEach(button => {
                button.classList.remove('nav-active', 'text-blue-500');
                button.classList.add('text-gray-400');
            });
            document.querySelectorAll('.nav-btn-mobile').forEach(button => {
                button.classList.remove('bg-blue-500/10', 'border-blue-500', 'text-blue-500');
                button.classList.add('bg-gray-800', 'border-transparent', 'text-gray-400');
            });

            if (btn) {
                if(isMobile) {
                    btn.classList.add('bg-blue-500/10', 'border-blue-500', 'text-blue-500');
                    btn.classList.remove('bg-gray-800', 'border-transparent', 'text-gray-400');
                    // Sync desktop button
                    const dtBtn = document.querySelector(`.nav-btn-desktop[onclick*="'${id}'"]`);
                    if(dtBtn) {
                        dtBtn.classList.add('nav-active');
                        dtBtn.classList.remove('text-gray-400');
                    }
                } else {
                    btn.classList.add('nav-active');
                    btn.classList.remove('text-gray-400');
                    // Sync mobile button
                    const mbBtn = document.querySelector(`.nav-btn-mobile[onclick*="'${id}'"]`);
                    if(mbBtn) {
                        mbBtn.classList.add('bg-blue-500/10', 'border-blue-500', 'text-blue-500');
                        mbBtn.classList.remove('bg-gray-800', 'border-transparent', 'text-gray-400');
                    }
                }
            }

            const headingAccent = document.getElementById('sectionAccent');
            if (headingAccent) {
                headingAccent.innerText = accents[id].label;
                headingAccent.className = accents[id].color;
            }
            
            const headingAccentMobile = document.getElementById('sectionAccentMobile');
            if (headingAccentMobile) {
                headingAccentMobile.innerText = accents[id].label;
                headingAccentMobile.className = accents[id].color;
            }

            const fileCount = document.getElementById('fileCount');
            if (fileCount) fileCount.innerText = `${counts[id]} file ditemukan`;
            
            const fileCountMobile = document.getElementById('fileCountMobile');
            if (fileCountMobile) fileCountMobile.innerText = `${counts[id]} file ditemukan`;
            
            lucide.createIcons();
        }

        function updateFileName(input) {
            const label = document.getElementById('fileLabel');

            if (input.files.length > 0) {
                label.innerText = 'Siap unggah: ' + input.files[0].name;
                label.classList.remove('text-gray-400');
                label.classList.add('text-blue-400', 'font-bold');
            }
        }

        function openPreview(path, type, name) {
            const modal = document.getElementById('previewModal');
            const content = document.getElementById('previewContent');
            const title = document.getElementById('previewTitle');

            title.innerText = name;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            content.innerHTML = '<div class="text-gray-500 flex flex-col items-center"><div class="animate-spin mb-2">⏳</div> Memuat pratinjau...</div>';

            let html = '';
            const ext = name.split('.').pop().toLowerCase();

            if (type === 'video') {
                html = `
            <video controls autoplay class="max-w-full max-h-[75vh] rounded-lg shadow-2xl bg-black">
                <source src="${path}" type="video/mp4">
                <source src="${path}" type="video/webm">
                Browser Anda tidak mendukung pratinjau video.
            </video>`;
            } else if (type === 'audio') {
                html = `
            <div class="bg-gray-900 p-10 rounded-2xl border border-gray-800 w-full max-w-md text-center shadow-2xl">
                <div class="mb-6 inline-block p-4 bg-orange-500/10 rounded-full">
                    <i data-lucide="music" class="w-12 h-12 text-orange-500"></i>
                </div>
                <audio controls autoplay class="w-full">
                    <source src="${path}" type="audio/mpeg">
                    <source src="${path}" type="audio/wav">
                    Browser Anda tidak mendukung pratinjau audio.
                </audio>
                <p class="text-gray-500 text-xs mt-4 truncate">${name}</p>
            </div>`;
            } else if (type === 'dokumen') {
                const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
                if (imageExtensions.includes(ext)) {
                    html = `<img src="${path}" class="max-w-full max-h-[75vh] object-contain rounded-lg shadow-2xl">`;
                } else {
                    html = `
                <div class="text-center p-10 bg-gray-900 rounded-2xl border border-gray-800">
                    <i data-lucide="file-warning" class="w-16 h-16 text-gray-600 mx-auto mb-4"></i>
                    <p class="text-gray-400 mb-4">Pratinjau tidak tersedia untuk file .${ext}</p>
                    <a href="${path}" download class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition font-bold">
                        Unduh untuk Melihat
                    </a>
                </div>`;
                }
            }

            setTimeout(() => {
                content.innerHTML = html;
                if (window.lucide) {
                    lucide.createIcons();
                }
            }, 200);
        }

        function closePreview() {
            const modal = document.getElementById('previewModal');
            const content = document.getElementById('previewContent');

            modal.classList.add('hidden');
            modal.classList.remove('flex');
            content.innerHTML = '';
        }

        document.getElementById('previewModal').addEventListener('click', event => {
            if (event.target.id === 'previewModal') {
                closePreview();
            }
        });

        document.querySelectorAll('input[placeholder="Cari file..."]').forEach(input => {
            input.addEventListener('input', event => {
                const keyword = event.target.value.toLowerCase();
                const activeSection = document.querySelector('.drive-section:not(.hidden)');

                if (!activeSection) {
                    return;
                }

                activeSection.querySelectorAll('.glass').forEach(card => {
                    const fileName = card.querySelector('h3')?.innerText.toLowerCase() ?? '';
                    card.style.display = fileName.includes(keyword) ? 'block' : 'none';
                });
                
                // Sync the other search input
                document.querySelectorAll('input[placeholder="Cari file..."]').forEach(otherInput => {
                    if (otherInput !== event.target) {
                        otherInput.value = event.target.value;
                    }
                });
            });
        });

        showSection('video', document.querySelector('.nav-btn-desktop.active'));
    </script>
    <?php include '../partials/footer.php'; ?>
</body>

</html>
