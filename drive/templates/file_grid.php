<?php
// Expected vars: $files, $accent, $icon, $type, $scope, $csrfToken

if (empty($files)): ?>
    <div class="flex flex-col items-center justify-center py-20 opacity-20">
        <i data-lucide="folder-open" class="w-16 h-16 mb-4"></i>
        <p>Tidak ada file ditemukan</p>
    </div>
    <?php return; ?>
<?php endif; ?>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
    <?php foreach ($files as $file):
        $name = htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8');
        $path = htmlspecialchars($file['path'], ENT_QUOTES, 'UTF-8');
        $size = format_bytes((int) $file['size']);
        $date = date('d M Y', (int) $file['time']);
        $downloadUrl = 'download.php?file=' . rawurlencode($file['name']) . '&type=' . rawurlencode($type) . '&scope=' . rawurlencode($scope) . '&csrf_token=' . rawurlencode($csrfToken);
        $deleteFormId = 'delete-form-' . md5($file['name'] . $type . $scope);
        $safeCsrfToken = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
        $safeFileName = htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8');
    ?>
        <div class='glass p-4 rounded-2xl group hover:border-blue-500/50 transition-all duration-300 transform hover:-translate-y-1 shadow-xl hover:shadow-blue-900/10'>
            <div class='flex items-start justify-between mb-4'>
                <div class='p-3 rounded-xl bg-gray-900 group-hover:bg-blue-500/10 transition' title='Tipe: <?= $type ?>'>
                    <i data-lucide='<?= $icon ?>' class='w-6 h-6' style='color: <?= $accent ?>'></i>
                </div>

                <div class='flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity'>
                    <button onclick="openPreview('<?= $path ?>', '<?= $type ?>', '<?= $name ?>')" class='p-2 hover:bg-blue-500/20 rounded-lg text-blue-400' title='Pratinjau'>
                        <i data-lucide='eye' class='w-4 h-4'></i>
                    </button>

                    <a href='<?= $downloadUrl ?>' class='p-2 hover:bg-green-500/20 rounded-lg text-green-400' title='Unduh'>
                        <i data-lucide='download' class='w-4 h-4'></i>
                    </a>

                    <button onclick="meelConfirm({ title:'Hapus File', text:'Hapus file ini?', confirmButtonText:'HAPUS' }).then(function(ok){ if(ok) document.getElementById('<?= $deleteFormId ?>').submit(); }); return false;" class='p-2 hover:bg-red-500/20 rounded-lg text-red-400' title='Hapus'>
                        <i data-lucide='trash-2' class='w-4 h-4'></i>
                    </button>

                    <form id='<?= $deleteFormId ?>' action='delete' method='POST' style='display:none;'>
                        <input type='hidden' name='csrf_token' value='<?= $safeCsrfToken ?>'>
                        <input type='hidden' name='file' value='<?= $safeFileName ?>'>
                        <input type='hidden' name='type' value='<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>'>
                        <input type='hidden' name='scope' value='<?= htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') ?>'>
                    </form>
                </div>
            </div>

            <h3 class='text-sm font-bold truncate mb-1 text-gray-200' title='<?= $name ?>'><?= $name ?></h3>
            <div class='flex justify-between items-center text-[10px] text-gray-500 font-medium uppercase tracking-tighter'>
                <span><?= $size ?></span>
                <span><?= $date ?></span>
            </div>
        </div>
    <?php endforeach; ?>
</div>
