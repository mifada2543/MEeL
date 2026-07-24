<?php
require_once '../modules/core/helpers.php';
require_once '../auth/auth.php';
require_once '../auth/config.php';
// activity_logger loaded via auth/config.php
require_once '../modules/media/MediaLibrary.php';

// ── Validasi ID ──────────────────────────────────────────────────────────────
if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$repo = new BookRepository($conn);
$book = $repo->getBookById((int)$_GET['id']);

if (!$book) {
    http_response_code(404);
    include '../err/not_found.php';
    exit;
}

// ── Sanitasi chapter — cegah path traversal ─────────────────────────────────
// Gunakan basename() yang secara alami mencegah path traversal
// dan tetap mempertahankan semua karakter valid dalam nama folder (termasuk
// tanda kurung, titik dua, kurung siku, karakter multi-byte, dll.)
$raw_chapter     = $_GET['ch'] ?? '';
$current_chapter = basename($raw_chapter);
// basename('..') returns '..' on Linux — cegah directory traversal
if ($current_chapter === '..') {
    $current_chapter = '';
}

$book_id = (int)$book['id'];

// ── Hitung total halaman (manga mode) ──
$total_pages = 0;
if ($book['type'] !== 'pdf') {
    $ch_base = "upload/manga/" . $book['path_folder'];
    $target_path = $ch_base;

    if ($book['has_chapters'] == 1 && !empty($current_chapter)) {
        $target_path .= '/' . $current_chapter;
    }

    if ($book['has_chapters'] == 0 || ($book['has_chapters'] == 1 && !empty($current_chapter))) {
        if (is_dir($target_path)) {
            $images = _scanImages($target_path);
            $total_pages = count($images);
        }
    }
}

// ── Helper: scan direktori untuk file gambar ─────────────────────────────────
// Menggantikan glob() karena glob() menginterpretasi karakter spesial
// seperti [], {}, * sebagai pattern glob, bukan literal path.
function _scanImages(string $dir): array {
    if (!is_dir($dir)) return [];
    $extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'JPG', 'PNG'];
    $files = scandir($dir);
    if ($files === false) return [];
    $images = [];
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, $extensions, true)) {
            $images[] = $dir . '/' . $f;
        }
    }
    return $images;
}

function _scanSubdirs(string $dir): array {
    if (!is_dir($dir)) return [];
    $items = scandir($dir);
    if ($items === false) return [];
    $dirs = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $dirs[] = $path;
        }
    }
    return $dirs;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="<?= htmlspecialchars($book['title']) ?> — MEeL Read">
    <meta property="og:description" content="Baca <?= htmlspecialchars($book['title']) ?> di MEeL Books - Platform Media Hub Pribadi.">
    <title>MEeL Read | <?= htmlspecialchars($book['title']) ?></title>
    <?php include '../partials/link.php'; ?>
    <link rel="stylesheet" href="../assets/css/books.css">
    <style>
        body {
            background-color: #080a0f;
            color: white;
        }

        .manga-img {
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
        }

        .manga-img:last-child {
            border-bottom: none;
        }

        /* Image loading shimmer */
        .manga-img.lazy {
            background: linear-gradient(110deg, #0f1318 30%, #161b24 50%, #0f1318 70%);
            background-size: 200% 100%;
            animation: shimmer 1.5s ease-in-out infinite;
        }

        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* ── Chapter transition animations ── */
        #manga-container {
            opacity: 0;
            transform: translateY(16px);
            transition: opacity 0.4s cubic-bezier(0.4, 0, 0.2, 1),
                        transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        #manga-container.chapter-visible {
            opacity: 1;
            transform: translateY(0);
        }
        #manga-container.chapter-exit {
            opacity: 0;
            transform: translateY(-12px);
            transition-duration: 0.25s;
        }

        /* Page counter pill */
        .page-counter {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(13, 16, 23, 0.9);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            padding: 6px 16px;
            border-radius: 99px;
            font-size: 10px;
            font-weight: 700;
            color: #6b7280;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            z-index: 40;
            pointer-events: none;
            transition: opacity 0.4s ease;
            opacity: 0;
        }

        .page-counter.visible {
            opacity: 1;
        }

        .page-counter .current {
            color: #e5e7eb;
            display: inline-block;
            transition: color 0.2s ease;
            min-width: 1.2em;
            text-align: center;
        }

        /* Smooth pop animation saat angka berganti */
        @keyframes counter-pop {
            0% {
                transform: scale(1);
                color: #e5e7eb;
            }
            35% {
                transform: scale(1.35);
                color: #22c55e;
            }
            70% {
                transform: scale(0.95);
                color: #22c55e;
            }
            100% {
                transform: scale(1);
                color: #e5e7eb;
            }
        }

        .page-counter .current.pop {
            animation: counter-pop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        /* Navbar page indicator */
        #nav-current-page {
            display: inline-block;
            min-width: 1.2em;
            text-align: center;
            transition: color 0.2s ease;
        }

        #nav-current-page.pop {
            animation: counter-pop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        /* Scroll to top button */
        #scroll-top-btn {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(13, 16, 23, 0.9);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #6b7280;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 40;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.3s ease;
            pointer-events: none;
        }

        #scroll-top-btn.visible {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }

        #scroll-top-btn:hover {
            background: rgba(34, 197, 94, 0.2);
            border-color: rgba(34, 197, 94, 0.3);
            color: #22c55e;
        }

        /* Navbar title glow on scroll */
        .reader-nav.scrolled {
            background: rgba(11, 14, 20, 0.97);
        }

        /* ── PDF viewer: iframe (desktop) vs redirect card (mobile) ── */
        .pdf-body {
            position: relative;
            width: 100%;
            height: 100%;
        }
        /* Desktop: iframe */
        .pdf-iframe-wrap {
            display: block;
            width: 100%;
            height: 100%;
        }
        /* Mobile: redirect card (hidden on desktop) */
        .pdf-mobile-card {
            display: none;
            width: 100%;
            height: 100%;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            background: #0f1318;
        }
        .pdf-card-inner {
            text-align: center;
            max-width: 400px;
            width: 100%;
        }
        .pdf-card-icon {
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
        .pdf-card-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #f0f2f7;
            margin-bottom: 0.3rem;
            line-height: 1.3;
        }
        .pdf-card-meta {
            font-size: 0.65rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            margin-bottom: 2rem;
        }
        .pdf-card-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            width: 100%;
            padding: 0.9rem 1.8rem;
            background: #7c3aed;
            color: #fff;
            border-radius: 14px;
            font-size: 0.85rem;
            font-weight: 700;
            text-decoration: none;
            box-shadow: 0 8px 24px rgba(124,58,237,.3);
            transition: all 0.25s;
        }
        .pdf-card-btn:hover {
            background: #6d28d9;
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(124,58,237,.4);
        }
        .pdf-card-hint {
            font-size: 0.6rem;
            color: #4b5563;
            margin-top: 1rem;
        }
        @media (max-width: 639px) {
            .pdf-iframe-wrap { display: none; }
            .pdf-mobile-card { display: flex; }
            .pdf-info-bar { display: none; }
        }
    </style>
</head>

<body class="flex flex-col min-h-screen">

    <!-- READER NAVBAR -->
    <div class="reader-nav sticky top-0 z-50 px-3 sm:px-6 h-14 flex items-center justify-between transition-all duration-300" id="reader-navbar">
        <div class="flex items-center gap-3 min-w-0">
            <a href="index.php" class="p-2 hover:bg-white/[.06] rounded-xl transition-all flex-shrink-0 group">
                <i data-lucide="arrow-left" class="w-4 h-4 text-gray-500 group-hover:text-green-500 transition-colors"></i>
            </a>
            <div class="min-w-0">
                <h1 class="text-sm font-bold truncate max-w-[180px] sm:max-w-md text-white/90" title="<?= htmlspecialchars($book['title']) ?>">
                    <?= htmlspecialchars($book['title']) ?>
                </h1>
                <div class="flex items-center gap-2 mt-0.5">
                    <span class="text-[9px] text-gray-600 uppercase font-black tracking-widest">
                        <?= htmlspecialchars($book['type']) ?>
                    </span>
                    <?php if ($book['has_chapters'] == 1 && !empty($current_chapter)): ?>
                        <span class="text-[9px] text-gray-700">•</span>
                        <span class="text-[9px] text-green-500/60 uppercase font-bold tracking-wider">
                            <?= htmlspecialchars($current_chapter) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($total_pages > 0): ?>
                        <span class="text-[9px] text-gray-700">•</span>
                        <span class="text-[9px] text-gray-600 uppercase tracking-wider">
                            <?= $total_pages ?> halaman
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <?php if ($book['type'] !== 'pdf'): ?>
                <span class="text-[9px] text-gray-700 uppercase tracking-widest hidden sm:block" id="page-indicator-nav">
                    Halaman <span id="nav-current-page">-</span>
                </span>
            <?php endif; ?>
            <button onclick="window.location.reload()"
                    class="p-2 hover:bg-white/[.06] rounded-xl transition-all group">
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5 text-gray-600 group-hover:text-green-500 transition-colors"></i>
            </button>
        </div>
    </div>

    <!-- Overlay blur -->
    <div class="ch-overlay" id="chOverlay"></div>

    <!-- KONTEN -->
    <div class="flex-grow overflow-y-auto" id="scroll-container">
        <?php if ($book['type'] === 'pdf'): ?>
            <!-- ═══════════════ MODE PDF ═══════════════ -->
            <?php
            // Ambil ukuran file untuk ditampilkan
            $pdf_path   = __DIR__ . '/upload/pdf/' . basename($book['path_folder']);
            $pdf_size   = is_file($pdf_path) ? filesize($pdf_path) : 0;
            $pdf_size_f = $pdf_size > 1048576
                ? number_format($pdf_size / 1048576, 1) . ' MB'
                : number_format($pdf_size / 1024, 1) . ' KB';
            ?>
            <div class="pdf-view">
                <!-- ═════ DESKTOP: iframe PDF viewer ═════ -->
                <div class="pdf-body pdf-iframe-wrap" id="readPdfBody">
                    <iframe src="read_pdf.php?id=<?= (int)$book['id'] ?>&raw=1"
                            id="pdfFrame"
                            title="PDF Viewer"
                            style="width:100%;height:100%;border:none;display:block;"></iframe>
                </div>

                <!-- ═════ MOBILE: card redirect ke read_pdf.php → api/pdf.php ═════ -->
                <div class="pdf-body pdf-mobile-card">
                    <div class="pdf-card-inner">
                        <div class="pdf-card-icon">
                            <i data-lucide="file-text" class="w-10 h-10 text-purple-400"></i>
                        </div>
                        <h2 class="pdf-card-title"><?= htmlspecialchars($book['title']) ?></h2>
                        <p class="pdf-card-meta">Dokumen PDF &middot; <?= $pdf_size_f ?></p>
                        <a href="read_pdf.php?id=<?= (int)$book['id'] ?>"
                           class="pdf-card-btn">
                            <i data-lucide="external-link" class="w-4 h-4"></i>
                            Buka PDF
                        </a>
                        <p class="pdf-card-hint">Akan dialihkan ke pembaca PDF</p>
                    </div>
                </div>

                <!-- Bottom info bar -->
                <div class="pdf-info-bar">
                    <div class="pdf-info-left">
                        <span class="pdf-info-title"><?= htmlspecialchars($book['title']) ?></span>
                        <span class="pdf-info-meta">PDF &middot; <?= $pdf_size_f ?></span>
                    </div>
                    <a href="read_pdf.php?id=<?= (int)$book['id'] ?>"
                       target="_blank" rel="noopener"
                       class="pdf-info-btn">
                        <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                        Buka di Tab Baru
                    </a>
                </div>
            </div>

            <script>
            // Desktop fallback: jika iframe gagal, alihkan ke read_pdf.php
            (function() {
                var frame = document.getElementById('pdfFrame');
                if (!frame) return;
                // Jika lewat 10 detik iframe masih kosong, redirect ke read_pdf.php
                var timeout = setTimeout(function() {
                    window.location.href = 'read_pdf.php?id=<?= (int)$book['id'] ?>';
                }, 10000);
                frame.addEventListener('load', function() { clearTimeout(timeout); });
                frame.addEventListener('error', function() { clearTimeout(timeout); });
            })();
            </script>

        <?php else: ?>
            <!-- ═══════════════ MODE MANGA ═══════════════ -->
            <div class="py-0 space-y-0" id="manga-container">
                <?php
                $ch_base   = "upload/manga/" . $book['path_folder'];

                if ($book['has_chapters'] == 1):
                    $chapters = _scanSubdirs($ch_base);
                    natsort($chapters);
                    // Hitung variabel navigasi chapter
                    $ch_list = array_values(array_map('basename', $chapters));
                    $current_idx = array_search($current_chapter, $ch_list);
                    $prev_ch = ($current_idx !== false && $current_idx > 0) ? $ch_list[$current_idx - 1] : null;
                    $next_ch = ($current_idx !== false && $current_idx < count($ch_list) - 1) ? $ch_list[$current_idx + 1] : null;
                ?>
                    <?php if ($total_pages > 0 && !empty($current_chapter)): ?>
                    <!-- Chapter navigation (atas) — di ATAS dropdown agar tidak ketimpa -->
                    <div class="max-w-4xl mx-auto px-4 mb-2 flex items-center justify-between gap-2">
                        <?php if ($prev_ch): ?>
                            <a href="?id=<?= $book_id ?>&ch=<?= urlencode($prev_ch) ?>"
                                class="flex items-center gap-2 px-4 py-2.5 bg-white/[.03] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-500 hover:text-green-500 hover:border-green-500/30 transition-all group">
                                <i data-lucide="chevron-left" class="w-3.5 h-3.5 group-hover:-translate-x-0.5 transition-transform"></i>
                                Sebelumnya
                            </a>
                        <?php else: ?>
                            <div></div>
                        <?php endif; ?>

                        <span class="text-[9px] text-gray-700 uppercase tracking-widest">
                            <?= $current_idx + 1 ?> / <?= count($ch_list) ?>
                        </span>

                        <?php if ($next_ch): ?>
                            <a href="?id=<?= $book_id ?>&ch=<?= urlencode($next_ch) ?>"
                                class="flex items-center gap-2 px-4 py-2.5 bg-white/[.03] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-500 hover:text-green-500 hover:border-green-500/30 transition-all group">
                                Selanjutnya
                                <i data-lucide="chevron-right" class="w-3.5 h-3.5 group-hover:translate-x-0.5 transition-transform"></i>
                            </a>
                        <?php else: ?>
                            <div></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Chapter selector (atas) — custom dropdown -->
                    <div class="sticky top-14 z-30 py-3 px-4 bg-gradient-to-b from-[#080a0f] to-transparent">
                        <div class="max-w-4xl mx-auto ch-dropdown" id="ch-dropdown-top">
                            <button type="button"
                                onclick="toggleChDropdown('top')"
                                class="ch-trigger">
                                <span class="truncate" title="<?= $current_chapter ? htmlspecialchars($current_chapter) : 'Pilih chapter' ?>"><?= $current_chapter ? htmlspecialchars($current_chapter) : '— Pilih Chapter —' ?></span>
                                <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-gray-500 flex-shrink-0"></i>
                            </button>
                            <div id="ch-options-top" class="ch-options hidden">
                                <button onclick="goToChapter('')"
                                    class="ch-option <?= empty($current_chapter) ? 'active' : '' ?>">
                                    — Pilih Chapter —
                                </button>
                                <?php foreach ($chapters as $ch):
                                    $ch_name  = basename($ch);
                                    $active   = ($current_chapter === $ch_name) ? 'active' : '';
                                    $enc_name = htmlspecialchars($ch_name, ENT_QUOTES);
                                ?>
                                    <button onclick="goToChapter('<?= $enc_name ?>')"
                                        class="ch-option <?= $active ?>">
                                        <?= htmlspecialchars($ch_name) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php
                // ── Tentukan path gambar ─────────────────────────────────────
                $target_path = $ch_base;

                if ($book['has_chapters'] == 1) {
                    if (empty($current_chapter)) {
                        // Belum pilih chapter — tampilkan prompt dengan cover
                        echo '<div class="max-w-4xl mx-auto px-4 py-16">
                                <div class="glass rounded-3xl p-12 sm:p-16 text-center border border-dashed border-white/[.06]">
                                    <div class="w-20 h-20 mx-auto mb-6 rounded-2xl bg-white/[.03] border border-white/[.06] flex items-center justify-center">
                                        <i data-lucide="book-open" class="w-9 h-9 text-gray-700"></i>
                                    </div>
                                    <p class="text-gray-500 font-bold uppercase tracking-widest text-xs mb-2">
                                        Silakan pilih chapter untuk mulai membaca
                                    </p>
                                    <p class="text-[10px] text-gray-700 uppercase tracking-widest">
                                        ' . htmlspecialchars($book['title']) . '
                                    </p>
                                </div>
                              </div>';
                        $target_path = null;
                    } else {
                        $target_path .= '/' . $current_chapter;
                    }
                }

                // ── Render gambar dengan Intersection Observer ──────────────
                if ($target_path !== null && is_dir($target_path)):
                    $images = _scanImages($target_path);
                    natsort($images);

                    if ($images && count($images) > 0):
                        // Chapter navigation buttons sudah di-render di ATAS dropdown
                        // (tidak perlu diulang di sini)

                        $page_num = 0;
                        foreach ($images as $img):
                            $page_num++;
                            $safe_src = htmlspecialchars($img);
                            $is_first = ($img === reset($images));
                        ?>
                            <?php if ($is_first): ?>
                                <img src="<?= $safe_src ?>"
                                    class="manga-img loaded"
                                    alt="Halaman <?= $page_num ?>"
                                    data-page="<?= $page_num ?>"
                                    decoding="async">
                            <?php else: ?>
                                <img data-src="<?= $safe_src ?>"
                                    src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
                                    class="manga-img lazy"
                                    alt="Halaman <?= $page_num ?>"
                                    data-page="<?= $page_num ?>"
                                    decoding="async">
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <!-- Chapter navigation bawah -->
                        <?php if ($book['has_chapters'] == 1 && !empty($chapters)): ?>
                            <div class="max-w-4xl mx-auto px-4 mt-4 mb-8 flex items-center justify-between gap-2">
                                <?php if ($prev_ch): ?>
                                    <a href="?id=<?= $book_id ?>&ch=<?= urlencode($prev_ch) ?>"
                                        class="flex items-center gap-2 px-4 py-2.5 bg-white/[.03] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-500 hover:text-green-500 hover:border-green-500/30 transition-all group">
                                        <i data-lucide="chevron-left" class="w-3.5 h-3.5 group-hover:-translate-x-0.5 transition-transform"></i>
                                        Sebelumnya
                                    </a>
                                <?php else: ?>
                                    <div></div>
                                <?php endif; ?>

                                <a href="index.php"
                                    class="text-[9px] text-gray-700 hover:text-green-500 uppercase tracking-widest transition-colors">
                                    Kembali ke Library
                                </a>

                                <?php if ($next_ch): ?>
                                    <a href="?id=<?= $book_id ?>&ch=<?= urlencode($next_ch) ?>"
                                        class="flex items-center gap-2 px-4 py-2.5 bg-white/[.03] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-500 hover:text-green-500 hover:border-green-500/30 transition-all group">
                                        Selanjutnya
                                        <i data-lucide="chevron-right" class="w-3.5 h-3.5 group-hover:translate-x-0.5 transition-transform"></i>
                                    </a>
                                <?php else: ?>
                                    <div></div>
                                <?php endif; ?>
                            </div>

                            <!-- Chapter selector (bawah) — custom dropdown -->
                            <div class="max-w-4xl mx-auto px-4 mb-8">
                                <div class="ch-dropdown" id="ch-dropdown-bottom">
                                    <button type="button"
                                        onclick="toggleChDropdown('bottom')"
                                        class="ch-trigger">
                                        <span class="truncate" title="<?= $current_chapter ? htmlspecialchars($current_chapter) : 'Pilih chapter' ?>"><?= $current_chapter ? htmlspecialchars($current_chapter) : '— Pilih Chapter —' ?></span>
                                        <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-gray-500 flex-shrink-0"></i>
                                    </button>
                                    <div id="ch-options-bottom" class="ch-options hidden">
                                        <button onclick="goToChapter('')"
                                            class="ch-option <?= empty($current_chapter) ? 'active' : '' ?>">
                                            — Pilih Chapter —
                                        </button>
                                        <?php foreach ($chapters as $ch):
                                            $ch_name  = basename($ch);
                                            $active   = ($current_chapter === $ch_name) ? 'active' : '';
                                            $enc_name = htmlspecialchars($ch_name, ENT_QUOTES);
                                        ?>
                                            <button onclick="goToChapter('<?= $enc_name ?>')"
                                                class="ch-option <?= $active ?>">
                                                <?= htmlspecialchars($ch_name) ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="max-w-4xl mx-auto px-4 py-20 text-center">
                            <div class="w-14 h-14 mx-auto mb-4 rounded-2xl bg-white/[.03] border border-white/[.06] flex items-center justify-center">
                                <i data-lucide="image-off" class="w-6 h-6 text-gray-700"></i>
                            </div>
                            <p class="text-gray-600 font-bold uppercase tracking-widest text-xs">
                                Tidak ada gambar
                            </p>
                            <p class="text-[10px] text-gray-700 mt-1 uppercase tracking-widest">
                                <?= htmlspecialchars($current_chapter ?: 'Folder Utama') ?>
                            </p>
                        </div>
                    <?php endif; ?>
                <?php elseif ($target_path === null): ?>
                    <!-- Already showed prompt above -->
                <?php else: ?>
                    <div class="max-w-4xl mx-auto px-4 py-20 text-center">
                        <div class="w-14 h-14 mx-auto mb-4 rounded-2xl bg-white/[.03] border border-white/[.06] flex items-center justify-center">
                            <i data-lucide="folder-open" class="w-6 h-6 text-gray-700"></i>
                        </div>
                        <p class="text-gray-600 font-bold uppercase tracking-widest text-xs">
                            Folder tidak ditemukan
                        </p>
                    </div>
                <?php endif; ?>

            </div><!-- /manga-container -->
        <?php endif; ?>

        <?php include '../partials/footer.php'; ?>
    </div><!-- /scroll-container -->

    <!-- Page counter (floating pill) -->
    <?php if ($total_pages > 0): ?>
    <div class="page-counter <?= $total_pages > 1 ? 'visible' : '' ?>" id="page-counter">
        Halaman <span class="current" id="current-page-display">1</span> / <?= $total_pages ?>
    </div>
    <?php endif; ?>

    <!-- Scroll to top button -->
    <button id="scroll-top-btn" onclick="scrollToTop()" title="Ke atas">
        <i data-lucide="chevron-up" class="w-4 h-4"></i>
    </button>

    <script>
        lucide.createIcons();

        // ── Intersection Observer — lazy load gambar manga ───────────────────
        (function() {
            const lazyImages = document.querySelectorAll('img.manga-img.lazy');
            if (!lazyImages.length) return;

            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (!entry.isIntersecting) return;
                    const img = entry.target;
                    const src = img.dataset.src;
                    if (!src) return;
                    img.src = src;
                    img.onload = function() {
                        img.classList.add('loaded');
                        img.classList.remove('lazy');
                    };
                    img.onerror = function() {
                        img.classList.add('loaded');
                        img.classList.remove('lazy');
                        img.style.background = '#0f1318';
                        img.style.minHeight = '100px';
                    };
                    observer.unobserve(img);
                });
            }, {
                rootMargin: '400px 0px',
                threshold: 0
            });

            lazyImages.forEach(function(img) {
                observer.observe(img);
            });
        })();

        // ── Track scroll position for page counter & nav indicators ─────────
        (function() {
            const pageDisplay = document.getElementById('current-page-display');
            const navPage = document.getElementById('nav-current-page');
            const scrollTopBtn = document.getElementById('scroll-top-btn');
            const navbar = document.getElementById('reader-navbar');
            const scrollEl = document.getElementById('scroll-container');
            const images = document.querySelectorAll('img.manga-img');
            let ticking = false;

            // Deteksi elemen mana yang benar-benar di-scroll
            function getScrollState() {
                if (scrollEl && scrollEl.scrollHeight > scrollEl.clientHeight) {
                    // Scroll terjadi di dalam scroll-container
                    return {
                        scrollTop: scrollEl.scrollTop,
                        clientHeight: scrollEl.clientHeight
                    };
                }
                // Scroll terjadi di body/window
                return {
                    scrollTop: window.scrollY || document.documentElement.scrollTop,
                    clientHeight: window.innerHeight
                };
            }

            function animatePop(el) {
                if (!el) return;
                el.classList.remove('pop');
                void el.offsetWidth;
                el.classList.add('pop');
            }

            function updateScrollState() {
                const { scrollTop, clientHeight } = getScrollState();

                // Navbar scroll state
                if (navbar) {
                    navbar.classList.toggle('scrolled', scrollTop > 10);
                }

                // Scroll to top button
                if (scrollTopBtn) {
                    scrollTopBtn.classList.toggle('visible', scrollTop > clientHeight * 0.5);
                }

                // Page counter — cari gambar dengan top terdekat dari navbar
                if (images.length > 0 && pageDisplay) {
                    let currentPage = 1;
                    let minDist = Infinity;
                    const navbarH = 56;

                    images.forEach((img) => {
                        const rect = img.getBoundingClientRect();
                        const dist = Math.abs(rect.top - navbarH);
                        if (dist < minDist) {
                            minDist = dist;
                            const p = parseInt(img.dataset.page);
                            if (p) currentPage = p;
                        }
                    });

                    // Trigger animasi pop kalau angkanya berubah
                    if (pageDisplay.textContent !== String(currentPage)) {
                        pageDisplay.textContent = currentPage;
                        animatePop(pageDisplay);
                    }
                    if (navPage && navPage.textContent !== String(currentPage)) {
                        navPage.textContent = currentPage;
                        animatePop(navPage);
                    }
                }

                ticking = false;
            }

            function onScroll() {
                if (!ticking) {
                    requestAnimationFrame(updateScrollState);
                    ticking = true;
                }
            }

            // Listen scroll di BOTH — karena scroll bisa di container atau body
            if (scrollEl) {
                scrollEl.addEventListener('scroll', onScroll, { passive: true });
            }
            window.addEventListener('scroll', onScroll, { passive: true });

            // Update ulang setelah gambar lazy selesai dimuat (ukuran elemen berubah)
            images.forEach(function(img) {
                img.addEventListener('load', function() {
                    if (!ticking) {
                        requestAnimationFrame(updateScrollState);
                        ticking = true;
                    }
                });
            });

            // Initial update
            setTimeout(updateScrollState, 300);
        })();

        // ── Scroll to top function ───────────────────────────────────────────
        function scrollToTop() {
            const el = document.getElementById('scroll-container');
            if (el && el.scrollHeight > el.clientHeight) {
                el.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }

        // ── Chapter Custom Dropdown ────────────────────────────────────────
        (function() {
            var activeDropdown = null;

            window.toggleChDropdown = function(which) {
                var options = document.getElementById('ch-options-' + which);
                var isHidden = options.classList.contains('hidden');

                // Tutup semua dropdown dulu
                document.querySelectorAll('.ch-options').forEach(function(el) {
                    el.classList.add('hidden');
                });
                document.body.classList.remove('ch-dropdown-open');

                if (isHidden) {
                    options.classList.remove('hidden');
                    document.body.classList.add('ch-dropdown-open');
                    activeDropdown = which;

                    // Auto-scroll ke item aktif
                    var active = options.querySelector('.ch-option.active');
                    if (active) {
                        setTimeout(function() {
                            active.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                        }, 50);
                    }
                } else {
                    activeDropdown = null;
                }
            };

            window.goToChapter = function(ch) {
                var url = '?id=<?= $book_id ?>';
                if (ch) url += '&ch=' + encodeURIComponent(ch);
                window.navigateChapter(url);
            };

            // Tutup dropdown saat klik di luar
            document.addEventListener('click', function(e) {
                if (!activeDropdown) return;
                var dropdown = document.getElementById('ch-dropdown-' + activeDropdown);
                if (dropdown && !dropdown.contains(e.target)) {
                    document.querySelectorAll('.ch-options').forEach(function(el) {
                        el.classList.add('hidden');
                    });
                    document.body.classList.remove('ch-dropdown-open');
                    activeDropdown = null;
                }
            });
        })();

        // ── Smooth chapter transition ──────────────────────────────────────
        (function() {
            var isTransitioning = false;

            window.navigateChapter = function(url) {
                if (isTransitioning) return;
                isTransitioning = true;

                var container = document.getElementById('manga-container');
                if (container) {
                    container.classList.remove('chapter-visible');
                    container.classList.add('chapter-exit');
                }

                // Navigasi setelah animasi exit selesai
                setTimeout(function() {
                    window.location.href = url;
                }, 250);
            };

            // Page-load enter animation
            var mangaContainer = document.getElementById('manga-container');
            if (mangaContainer) {
                requestAnimationFrame(function() {
                    mangaContainer.classList.add('chapter-visible');
                });
            }

            // Intercept clicks on prev/next chapter links
            document.addEventListener('click', function(e) {
                var link = e.target.closest('a');
                if (!link) return;
                var href = link.getAttribute('href');
                if (!href || !href.includes('ch=')) return;
                var text = link.textContent.trim();
                if (!text.includes('Selanjutnya') && !text.includes('Sebelumnya')) return;

                e.preventDefault();
                window.navigateChapter(link.href);
            });
        })();

        // ── Fungsi simpan progress ke localStorage (throttled) ───────────────
        var _saveTimer = null;
        function saveProgress(extra) {
            if (_saveTimer) clearTimeout(_saveTimer);
            _saveTimer = setTimeout(function() {
                try {
                    var pageEl = document.getElementById('current-page-display');
                    var data = {
                        id: <?= json_encode((int)$book['id']) ?>,
                        title: <?= json_encode($book['title'], JSON_HEX_TAG | JSON_HEX_APOS) ?>,
                        type: <?= json_encode($book['type'], JSON_HEX_TAG) ?>,
                        ch: <?= json_encode($current_chapter ?: '', JSON_HEX_TAG) ?>,
                        page: pageEl ? parseInt(pageEl.textContent) || 1 : 1,
                        total: <?= json_encode($total_pages ?: 0) ?>,
                        timestamp: Date.now()
                    };
                    if (extra) Object.assign(data, extra);
                    localStorage.setItem('meel_book_progress', JSON.stringify(data));
                } catch(e) {}
            }, 3000); // throttle 3 detik
        }
        // Simpan saat pertama kali halaman dimuat
        saveProgress();
        // Juga simpan saat user meninggalkan halaman
        window.addEventListener('beforeunload', function() {
            if (_saveTimer) clearTimeout(_saveTimer);
            try {
                var pageEl = document.getElementById('current-page-display');
                var data = {
                    id: <?= json_encode((int)$book['id']) ?>,
                    title: <?= json_encode($book['title'], JSON_HEX_TAG | JSON_HEX_APOS) ?>,
                    type: <?= json_encode($book['type'], JSON_HEX_TAG) ?>,
                    ch: <?= json_encode($current_chapter ?: '', JSON_HEX_TAG) ?>,
                    page: pageEl ? parseInt(pageEl.textContent) || 1 : 1,
                    total: <?= json_encode($total_pages ?: 0) ?>,
                    timestamp: Date.now()
                };
                localStorage.setItem('meel_book_progress', JSON.stringify(data));
            } catch(e) {}
        });

        // ── Auto-scroll ke halaman terakhir (dari localStorage) ────────────
        (function() {
            try {
                var raw = localStorage.getItem('meel_book_progress');
                if (!raw) return;
                var saved = JSON.parse(raw);
                // Cuma untuk buku yang sama
                if (!saved || saved.id != <?= json_encode((int)$book['id']) ?>) return;
                // Cuma untuk manga, bukan PDF
                if (!saved.page || saved.page < 2) return;
                // Cek kesamaan chapter — kalau beda (misal klik Selanjutnya),
                // jangan auto-scroll, biarkan mulai dari atas
                var currentCh = <?= json_encode($current_chapter ?: '', JSON_HEX_TAG) ?>;
                if (saved.ch !== currentCh) return;

                var retries = 0;
                var maxRetries = 20;
                var targetPage = saved.page;

                function tryScroll() {
                    if (retries >= maxRetries) return;
                    retries++;

                    var img = document.querySelector('img.manga-img[data-page="' + targetPage + '"]');
                    if (!img) {
                        // Gambar belum di-render, coba lagi nanti
                        setTimeout(tryScroll, 500);
                        return;
                    }

                    var scrollEl = document.getElementById('scroll-container');
                    var top = img.offsetTop - 56; // offset navbar
                    if (scrollEl && scrollEl.scrollHeight > scrollEl.clientHeight) {
                        scrollEl.scrollTo({ top: top, behavior: 'smooth' });
                    } else {
                        window.scrollTo({ top: top, behavior: 'smooth' });
                    }
                }

                // Mulai coba setelah render awal
                setTimeout(tryScroll, 600);
            } catch(e) {}
        })();

        // ── Keyboard shortcuts ──────────────────────────────────────────────
        document.addEventListener('keydown', function(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') return;

            const key = e.key.toLowerCase();

            if (key === 'arrowleft' || key === 'a') {
                // Previous chapter
                const prevLink = document.querySelector('a[href*="ch="]:first-child');
                if (prevLink && prevLink.textContent.includes('Sebelumnya')) {
                    e.preventDefault();
                    window.navigateChapter(prevLink.href);
                }
            }

            if (key === 'arrowright' || key === 'd') {
                // Next chapter
                const links = document.querySelectorAll('a[href*="ch="]');
                const nextLink = Array.from(links).find(el => el.textContent.includes('Selanjutnya'));
                if (nextLink) {
                    e.preventDefault();
                    window.navigateChapter(nextLink.href);
                }
            }

            if (key === 'escape') {
                window.location.href = 'index.php';
            }
        });

        // Re-init after HTMX
        document.body.addEventListener('htmx:afterOnLoad', function() {
            lucide.createIcons();
        });
    </script>
</body>

</html>
