<?php
require_once '../auth/auth.php';
require_once '../auth/config.php';
// activity_logger loaded via auth/config.php
require_once '../modules/media/MediaLibrary.php';

// ── Proteksi role admin ───────────────────────────────────────────────────────
$repo    = new BookRepository($conn);
$user_id = (int)$_SESSION['user_id'];
$role    = $repo->getUserRole($user_id);

if ($role !== 'admin') {
    header("Location: index.php?error=unauthorized");
    exit();
}

// ── Handle POST upload ────────────────────────────────────────────────────────
$message  = '';
$val_title = htmlspecialchars($_GET['reup'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_book'])) {
    if (!verify_csrf()) {
        $message = 'CSRF Token tidak valid.';
    } else {
    $uploader = new BookUploader($conn, __DIR__);
    $result   = $uploader->handleUpload(
        array_merge($_POST, ['user_id' => $user_id]),
        $_FILES
    );
    $message = $result['message'];
    if (str_starts_with($message, 'Success')) {
        MediaLibrary::clearCountsCache();
        log_activity($conn, $user_id, 'upload_book', 'books');
    }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="MEeL | Upload Book">
    <meta property="og:description" content="Upload buku dan dokumen ke perpustakaan digital MEeL Books.">
    <title>MEeL | Upload Book</title>
    <?php include '../partials/link.php'; ?>
    <style>
        body {
            background-color: #05070a;
            color: white;
        }
    </style>
</head>

<body class="min-h-screen flex items-center justify-center p-6">
    <div class="w-full max-w-xl bg-[#0b0e14] border border-gray-800 rounded-[2.5rem] p-10 shadow-2xl">

        <div class="flex justify-between items-center mb-8">
            <h1 class="text-2xl font-black">Upload to Library</h1>
            <a href="index.php" class="text-gray-500 hover:text-white transition">
                <i data-lucide="x"></i>
            </a>
        </div>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-2xl text-xs font-bold
                        <?= str_starts_with($message, 'Success') ? 'bg-green-500/10 text-green-400 border border-green-500/20' : 'bg-blue-500/10 text-blue-400 border border-blue-500/20' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-gray-500 uppercase ml-1">Tipe Konten</label>
                    <select name="type" class="w-full bg-[#05070a] border border-gray-800 rounded-2xl p-3 text-sm focus:border-blue-600 outline-none">
                        <option value="manga">Manga / Komik (ZIP / CBZ)</option>
                        <option value="pdf">E-Book / Dokumen (PDF)</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-gray-500 uppercase ml-1">Kategori</label>
                    <input type="text" name="category" placeholder="Edukasi, Action, dll"
                        class="w-full bg-[#05070a] border border-gray-800 rounded-2xl p-3 text-sm focus:border-blue-600 outline-none">
                </div>
            </div>

            <div class="space-y-2">
                <label class="text-[10px] font-bold text-gray-500 uppercase ml-1">Judul Buku</label>
                <input type="text" name="title" value="<?= $val_title ?>" required
                    placeholder="Contoh: Belajar PHP Dasar"
                    class="w-full bg-[#05070a] border border-gray-800 rounded-2xl p-3 text-sm focus:border-blue-600 outline-none">
            </div>

            <div class="space-y-2">
                <label class="text-[10px] font-bold text-gray-500 uppercase ml-1">Penulis / Artist</label>
                <input type="text" name="author" placeholder="Nama Penulis"
                    class="w-full bg-[#05070a] border border-gray-800 rounded-2xl p-3 text-sm focus:border-blue-600 outline-none">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-gray-500 uppercase ml-1">File (PDF / ZIP / CBZ)</label>
                    <input type="file" name="book_file" required
                        class="w-full text-xs text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0
                                  file:text-xs file:font-bold file:bg-gray-800 file:text-gray-300">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-bold text-gray-500 uppercase ml-1">Cover (Thumbnail)</label>
                    <input type="file" name="thumbnail"
                        class="w-full text-xs text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0
                                  file:text-xs file:font-bold file:bg-gray-800 file:text-gray-300">
                </div>
            </div>

            <button type="submit" name="upload_book"
                class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-4 rounded-2xl
                           transition-all flex items-center justify-center gap-2 mt-4">
                <i data-lucide="plus-circle" class="w-5 h-5"></i>
                SIMPAN KE PERPUSTAKAAN
            </button>
        </form>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>

</html>
