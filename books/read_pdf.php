<?php
require_once '../modules/core/helpers.php';

require_once '../auth/auth.php';
require_once '../auth/config.php';
require_once '../modules/media/MediaLibrary.php';

// ─── Validasi ID ───
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id < 1) {
    header("Location: ..");
    exit();
}

// ─── Ambil data buku dari database ───
$repo  = new BookRepository($conn);
$book  = $repo->getBookById($id);

if (!$book || $book['type'] !== 'pdf') {
    header("Location: ..");
    exit();
}
$pdf_path   = meel_media_base_path('books') . '/pdf/' . basename($book['path_folder']);

if (isset($_GET['raw']) && $_GET['raw'] === '1') {
    if (!file_exists($pdf_path) || !is_readable($pdf_path)) {
        http_response_code(404);
        die('File not found');
    }
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $book['title']) . '.pdf"');
    header('Content-Length: ' . filesize($pdf_path));
    header('Cache-Control: public, max-age=86400');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
    header('Pragma: public');
    header('Accept-Ranges: bytes');
    readfile($pdf_path);
    exit;
}

// ─── Ambil ukuran file ───
$pdf_size   = is_file($pdf_path) ? filesize($pdf_path) : 0;
$pdf_size_f = $pdf_size > 1048576
    ? number_format($pdf_size / 1048576, 1) . ' MB'
    : number_format($pdf_size / 1024, 1) . ' KB';

$title = htmlspecialchars($book['title']);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <?php
    $_META_TITLE = 'MEeL PDF | ' . $title;
    $_META_DESC  = 'MEeL - Baca PDF: ' . $title;
    include __DIR__ . '/../partials/link.php';
    $scripts_root = '../';
    include __DIR__ . '/../partials/scripts.php';
    ?>
    <?php foreach (require __DIR__ . '/../assets/css/books/manifest.php' as $__f): ?>
        <link rel="stylesheet" href="../assets/css/books/<?= $__f ?>?v=<?= filemtime(__DIR__ . '/../assets/css/books/' . $__f) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="../assets/css/books/read-pdf/main.css">
</head>

<body>
    <div class="pdf-wrap">
        <!-- Navigation bar -->
        <div class="pdf-nav">
            <div class="pdf-nav-left">
                <a href="<?= base_url('/books/read?id=' . (int)$id) ?>" class="pdf-nav-back" title="Kembali ke pembaca">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>
                </a>
                <div class="pdf-nav-icon">
                    <i data-lucide="file-text" class="w-3.5 h-3.5"></i>
                </div>
                <div class="min-w-0">
                    <div class="pdf-nav-title"><?= $title ?></div>
                    <div class="pdf-nav-meta">PDF &middot; <?= $pdf_size_f ?></div>
                </div>
            </div>
            <div class="pdf-nav-actions">
                <a href="../api/pdf?id=<?= $id ?>" target="_blank" rel="noopener" class="pdf-nav-btn">
                    <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                    Buka Mentah
                </a>
            </div>
        </div>
        <div class="pdf-body" id="pdfBody">
            <div class="pdf-redirect-card" id="redirectCard">
                <div class="pdf-redirect-icon">
                    <i data-lucide="file-text" class="w-10 h-10 text-purple-400"></i>
                </div>
                <h2 class="pdf-redirect-title"><?= $title ?></h2>
                <p class="pdf-redirect-meta">Dokumen PDF &middot; <?= $pdf_size_f ?></p>

                <!-- Loading spinner -->
                <div class="pdf-redirect-loader" id="redirectLoader">
                    <div class="loader-ring"></div>
                    <span class="loader-text">Membuka PDF...</span>
                </div>

                <!-- Tombol akses langsung (jika redirect tidak jalan) -->
                <a href="../api/pdf?id=<?= $id ?>"
                    target="_blank" rel="noopener"
                    class="btn" id="directBtn">
                    <i data-lucide="external-link" class="w-4 h-4"></i>
                    Buka PDF
                </a>
            </div>
        </div>
    </div>

    <script>
        /**
         * PDF Redirector — gateway mobile: redirect ke api/pdf.php.
         * Top-level navigation mengirim cookie session (iframe/embed mobile tidak).
         */
        (function() {
            var loader = document.getElementById('redirectLoader');
            var directBtn = document.getElementById('directBtn');
            var _redirected = false;
            setTimeout(function() {
                _redirected = true;
                window.location.href = '../api/pdf?id=<?= $id ?>';
            }, 1800);
            setTimeout(function() {
                if (_redirected) return;
                if (loader) loader.style.display = 'none';
                if (directBtn) directBtn.style.display = 'inline-flex';
            }, 5000);
        })();
    </script>
    <script>
        lucide.createIcons();
    </script>
</body>

</html>