<?php
// ─── Open Graph & Twitter Card (Legacy) ───
$_META_TITLE = $_META_TITLE ?? 'MEeL | Media Hub';
$_META_DESC  = $_META_DESC  ?? '';
include __DIR__ . '/head.php';

require_once __DIR__ . '/../modules/core/base_url.php';
$__link_base = meel_base_url_path();
?>
<link href="<?= $__link_base ?>/assets/css/tailwind.min.css" rel="stylesheet">
<script src="<?= $__link_base ?>/assets/js/compatibilitas/lucide.js"></script>
<script>/* Suppress JQMIGRATE warnings from bundled third-party libs */
(function(){var ow=console.warn;console.warn=function(){if(arguments[0]&&typeof arguments[0]==='string'&&arguments[0].startsWith('JQMIGRATE'))return;return ow.apply(console,arguments)};})();
</script>
<?php unset($__link_base); ?>
