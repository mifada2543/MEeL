<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * partials/ui.php — MEeL Engine Overlay (ASSEMBLER)
 * ═══════════════════════════════════════════════════════════════
 *
 * Overlay progres download/transcode. File ini HANYA perakit
 * (assembler): meng-emit <link> CSS + <script> JS + shell overlay,
 * lalu meng-include fase-fase dari partials/engine/.
 *
 *   CSS  → assets/css/engine/main.css   (entry @import modul)
 *   JS   → assets/js/engine/main.js     (entry document.write sibling)
 *   Fase → partials/engine/{download,transcode,sprite,done,error}.php
 *
 * ⚠️ PENTING — file ini juga di-include oleh Transcoder::
 *    showMEeLOverlay() DI TENGAH aliran respons (setelah
 *    ob_end_clean() membuang <head>). Karena itu assembler ini
 *    WAJIB meng-emit <link>/<script src> sendiri setiap kali
 *    di-include, agar CSS/JS overlay selalu tersedia baik saat
 *    page load biasa maupun saat di-stream sebagai dokumen baru.
 *    Jangan pernah pindahkan aset engine ke <head> halaman saja.
 *
 * ═══════════════════════════════════════════════════════════════
 */

// Cache-buster versi aset engine (pola filemtime halaman lain).
// Dihitung relatif ke file ini agar sama untuk semua konteks include.
$__meel_engine_v = function (string $asset): string {
    static $cache = [];
    $path = __DIR__ . '/../' . $asset;
    if (!isset($cache[$path])) {
        $cache[$path] = @filemtime($path);
    }
    return '?v=' . $cache[$path];
};
?>
<link rel="stylesheet" href="assets/css/engine/main.css<?= $__meel_engine_v('assets/css/engine/main.css') ?>">
<link rel="manifest" href="assets/manifest.json">
<link rel="icon" type="image/png" href="assets/MEeL.png">
<div id="meel-overlay">
  <div id="meel-card">

    <!-- LOGO / WORDMARK -->
    <div style="font-size:11px;letter-spacing:.35em;color:rgba(255,255,255,.18);text-transform:uppercase;margin-bottom:28px">MEeL Engine</div>

    <?php include __DIR__ . '/engine/download.php'; ?>
    <?php include __DIR__ . '/engine/transcode.php'; ?>
    <?php include __DIR__ . '/engine/sprite.php'; ?>
    <?php include __DIR__ . '/engine/done.php'; ?>
    <?php include __DIR__ . '/engine/error.php'; ?>

  </div>
</div>
<script src="assets/js/engine/main.js<?= $__meel_engine_v('assets/js/engine/main.js') ?>"></script>
