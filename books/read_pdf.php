<?php
require_once '../modules/core/helpers.php';

require_once '../auth/auth.php';
require_once '../auth/config.php';
require_once '../modules/media/MediaLibrary.php';

// ─── Validasi ID ───
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id < 1) {
    header("Location: index.php");
    exit();
}

// ─── Ambil data buku dari database ───
$repo  = new BookRepository($conn);
$book  = $repo->getBookById($id);

if (!$book || $book['type'] !== 'pdf') {
    header("Location: index.php");
    exit();
}

// ─── RAW MODE: Serve PDF langsung untuk <iframe> ───
if (isset($_GET['raw']) && $_GET['raw'] === '1') {
    $pdf_path = __DIR__ . '/upload/pdf/' . basename($book['path_folder']);
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
$pdf_path   = __DIR__ . '/upload/pdf/' . basename($book['path_folder']);
$pdf_size   = is_file($pdf_path) ? filesize($pdf_path) : 0;
$pdf_size_f = $pdf_size > 1048576
    ? number_format($pdf_size / 1048576, 1) . ' MB'
    : number_format($pdf_size / 1024, 1) . ' KB';

$title = htmlspecialchars($book['title']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Baca PDF: <?= $title ?>">
    <meta property="og:title" content="<?= $title ?> — MEeL PDF">
    <meta property="og:description" content="Baca dokumen PDF <?= $title ?> di MEeL Books.">
    <meta property="og:image" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/assets/MEeL.png">
    <meta property="og:url" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <title>MEeL PDF | <?= $title ?></title>
    <link rel="manifest" href="../assets/manifest.json">
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <link href="../assets/css/tailwind.min.css" rel="stylesheet">
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
                <a href="read.php?id=<?= $id ?>" class="pdf-nav-back" title="Kembali ke pembaca">
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
                <a href="../controllers/api/pdf.php?id=<?= $id ?>" target="_blank" rel="noopener" class="pdf-nav-btn">
                    <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                    Buka Mentah
                </a>
            </div>
        </div>

        <!-- PDF content: MEeL branding + auto-redirect ke api/pdf.php -->
        <!-- Untuk mobile: redirect langsung ke api/pdf.php (work di semua browser) -->
        <!-- Untuk desktop: read.php menggunakan ?raw=1 via iframe -->
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
                <a href="../controllers/api/pdf.php?id=<?= $id ?>"
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

        // Redirect ke api/pdf.php setelah 1.8 detik
        // Top-level navigation → cookie session terkirim!
        setTimeout(function() {
            _redirected = true;
            window.location.href = '../controllers/api/pdf.php?id=<?= $id ?>';
        }, 1800);

        // Backup: jika redirect gagal/tidak terjadi (5 detik), munculkan tombol
        setTimeout(function() {
            if (_redirected) return; // sudah redirect, skip
            if (loader) loader.style.display = 'none';
            if (directBtn) directBtn.style.display = 'inline-flex';
        }, 5000);
    })();
    </script>
    <script src="../assets/js/compatibilitas/lucide.js"></script>
    <script>lucide.createIcons();
</script>
</body>
</html>
