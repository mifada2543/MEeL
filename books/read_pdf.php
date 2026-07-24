<?php
require_once '../modules/core/helpers.php';
/**
 * read_pdf.php — PDF viewer dengan PWA support
 * 
 * Menyajikan file PDF dalam halaman HTML yang menyertakan manifest, logo,
 * dan meta tags untuk PWA. Cocok untuk akses langsung di HP (tab baru).
 * 
 * Dua mode:
 *  - Normal (?id=X):      Tampilkan halaman HTML dengan navbar + iframe PDF
 *  - Raw    (?id=X&raw=1): Serve file PDF langsung (digunakan oleh <iframe>)
 */

require_once '../auth/auth.php';
require_once '../auth/config.php';
require_once '../modules/media/MediaLibrary.php';

// ── Validasi ID ──────────────────────────────────────────────────────────────
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id < 1) {
    header("Location: index.php");
    exit();
}

// ── Ambil data buku dari database ────────────────────────────────────────────
$repo  = new BookRepository($conn);
$book  = $repo->getBookById($id);

if (!$book || $book['type'] !== 'pdf') {
    header("Location: index.php");
    exit();
}

// ── RAW MODE: Serve PDF langsung untuk <iframe> ─────────────────────────────
// Keuntungan:
//   - Request via <iframe> adalah navigation request (bukan subresource),
//     sehingga mobile browser tetap mengirim cookie session.
//   - URL same-origin langsung (bukan blob:) → didukung PDF viewer mobile.
//   - Tidak perlu fetch JavaScript + blob URL yang bermasalah di HP.
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

// ── Ambil ukuran file ────────────────────────────────────────────────────────
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
    <link rel="stylesheet" href="../assets/css/books.css">
    <style>
        body {
            background-color: #080a0f;
            color: white;
            margin: 0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
        }
        .pdf-wrap {
            width: 100%;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .pdf-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.6rem 1rem;
            background: rgba(8,10,15,.92);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255,255,255,.05);
            gap: 1rem;
            flex-shrink: 0;
        }
        .pdf-nav-left {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            min-width: 0;
        }
        .pdf-nav-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: #7c3aed;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .pdf-nav-icon i {
            color: white;
        }
        .pdf-nav-title {
            font-size: 0.8rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #e5e7eb;
        }
        .pdf-nav-meta {
            font-size: 0.6rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        .pdf-nav-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-shrink: 0;
        }
        .pdf-nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.85rem;
            background: #7c3aed;
            color: #fff;
            border-radius: 10px;
            font-size: 0.65rem;
            font-weight: 700;
            transition: all 0.2s;
            text-decoration: none;
            white-space: nowrap;
        }
        .pdf-nav-btn:hover {
            background: #6d28d9;
        }
        .pdf-nav-back {
            padding: 0.4rem;
            color: #6b7280;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.2s;
            display: flex;
        }
        .pdf-nav-back:hover {
            color: #22c55e;
            background: rgba(255,255,255,.04);
        }
        .pdf-body {
            flex: 1;
            min-height: 0;
            position: relative;
            background: #0f1318;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        /* ── Branded redirect card ── */
        .pdf-redirect-card {
            text-align: center;
            max-width: 420px;
            width: 100%;
        }
        .pdf-redirect-card .pdf-redirect-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 1.5rem;
            border-radius: 20px;
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(255,255,255,.08);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pdf-redirect-card .pdf-redirect-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #f0f2f7;
            margin-bottom: .4rem;
            line-height: 1.3;
        }
        .pdf-redirect-card .pdf-redirect-meta {
            font-size: .7rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .2em;
            margin-bottom: 2rem;
        }
        /* Loading spinner */
        .pdf-redirect-loader {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .loader-ring {
            width: 32px;
            height: 32px;
            border: 3px solid rgba(124,58,237,.15);
            border-top-color: #7c3aed;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .loader-text {
            font-size: .7rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .15em;
            font-weight: 600;
        }
        /* Tombol akses langsung (muncul jika redirect gagal) */
        .pdf-redirect-card .btn {
            display: none;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.8rem;
            background: #7c3aed;
            color: #fff;
            border-radius: 14px;
            font-size: 0.8rem;
            font-weight: 700;
            text-decoration: none;
            box-shadow: 0 8px 24px rgba(124,58,237,.3);
            transition: all 0.25s;
        }
        .pdf-redirect-card .btn:hover {
            background: #6d28d9;
            transform: translateY(-2px);
        }
    </style>
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
     * PDF Redirector — untuk akses mobile via HP
     *
     * read.php sudah menangani PDF via iframe (?raw=1) untuk desktop.
     * read_pdf.php (mode normal) dipakai sebagai MEeL-branded gateway
     * yang auto-redirect ke controllers/api/pdf.php.
     *
     * Kenapa redirect? Karena iframe/embed untuk PDF di browser mobile
     * bermasalah dengan cookie session (subresource tidak kirim cookie).
     * Redirect ke api/pdf.php sebagai top-level navigation = cookie terkirim.
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
    <script src="../assets/js/lucide.js"></script>
    <script>lucide.createIcons();</script>
</body>
</html>
