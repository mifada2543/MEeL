<?php
// Open Graph & Twitter Card (Legacy)
$_META_TITLE = $_META_TITLE ?? 'MEeL | Media Hub';
$_META_DESC  = $_META_DESC  ?? '';
include __DIR__ . '/head.php';

require_once __DIR__ . '/../modules/core/base_url.php';
$__link_base = meel_base_url_path();

// Deteksi halaman arcade & admin — exclude dari theme system
$__meel_self = $_SERVER['PHP_SELF'] ?? '';
$__meel_is_arcade = str_contains($__meel_self, '/arcade/');
$__meel_is_admin  = str_contains($__meel_self, '/admin/');
$__meel_theme_ok  = !$__meel_is_arcade && !$__meel_is_admin;
?>
<link href="<?= $__link_base ?>/assets/css/tailwind.min.css" rel="stylesheet">
<?php if ($__meel_theme_ok): ?>
<link rel="stylesheet" href="<?= $__link_base ?>/assets/css/shared/theme-tokens.css?v=<?= @filemtime(__DIR__ . '/../assets/css/shared/theme-tokens.css') ?>">
<?php endif; ?>
<script src="<?= $__link_base ?>/assets/js/compatibilitas/lucide.js"></script>
<?php if ($__meel_theme_ok): ?>
<script src="<?= $__link_base ?>/assets/js/shared/theme.js?v=<?= @filemtime(__DIR__ . '/../assets/js/shared/theme.js') ?>"></script>
<?php endif; ?>
<?php unset($__link_base, $__meel_self, $__meel_is_arcade, $__meel_is_admin, $__meel_theme_ok); ?>
<script>/* Suppress JQMIGRATE warnings from bundled third-party libs */
(function(){var ow=console.warn;console.warn=function(){if(arguments[0]&&typeof arguments[0]==='string'&&arguments[0].startsWith('JQMIGRATE'))return;return ow.apply(console,arguments)};})();
</script>
<?php unset($__link_base); ?>
