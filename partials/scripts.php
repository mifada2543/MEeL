<?php
$scripts_root = $scripts_root ?? '';
?>
<script src="<?= $scripts_root ?>assets/js/compatibilitas/sweetalert2.all.min.js"></script>
<script src="<?= $scripts_root ?>assets/js/compatibilitas/script.min.js"></script>
<script src="<?= $scripts_root ?>assets/js/shared/health-reminder.js?v=<?= filemtime(__DIR__ . '/../assets/js/shared/health-reminder.js') ?>"></script>
