<?php
$__meel_engine_v = function (string $asset): string {
    static $cache = [];
    $path = __DIR__ . '/../' . $asset;
    if (!isset($cache[$path])) {
        $cache[$path] = @filemtime($path);
    }
    return '?v=' . $cache[$path];
};

$__meel_css_bundle = function (string $dir, string $baseUrl) use ($__meel_engine_v) {
    $manifest = __DIR__ . '/../' . $dir . '/manifest.php';
    if (!file_exists($manifest)) return;
    foreach (require $manifest as $file) {
        $rel = $dir . '/' . $file;
        echo '<link rel="stylesheet" href="' . $baseUrl . $file
            . $__meel_engine_v($rel) . '">' . "\n";
    }
};
$__meel_css_bundle('assets/css/engine', 'assets/css/engine/');

// Hitung base URL untuk favicon & manifest (head.php sudah di-buang oleh ob_end_clean)
$__ui_proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$__ui_host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
$__ui_base  = $__ui_proto . '://' . $__ui_host . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
?>
<link rel="manifest" href="<?= $__ui_base ?>/assets/manifest.json">
<link rel="icon" type="image/png" sizes="32x32" href="<?= $__ui_base ?>/assets/MEeL.png">
<link rel="icon" type="image/png" sizes="16x16" href="<?= $__ui_base ?>/assets/MEeL.png">
<link rel="apple-touch-icon" sizes="180x180" href="<?= $__ui_base ?>/assets/MEeL-180.png">
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
