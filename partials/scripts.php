<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * GLOBAL SCRIPT LOADER — SweetAlert2 + helper MEeL (script.min.js)
 * ═══════════════════════════════════════════════════════════════
 *
 * Partial ini adalah SATU-SATUNYA tempat memuat:
 *   - sweetalert2.all.min.js      (library SweetAlert2)
 *   - script.min.js               (helper meelAlert / meelConfirm /
 *                                  meelConfirmLink / meelConfirmForm)
 *   - shared/health-reminder.js   (fitur Mode Sehat 20-20-20, sumber
 *                                  logika kesehatan terpisah dari
 *                                  script.min.js)
 *
 * Semua halaman cukup include partial ini SEBELUM </body>:
 *
 *   <?php include 'partials/scripts.php'; ?>            // root
 *   <?php $scripts_root = '../'; include '../partials/scripts.php'; ?> // subfolder
 *
 * Atur $scripts_root (default '') untuk prefix path relatif.
 * ═══════════════════════════════════════════════════════════════
 */
$scripts_root = $scripts_root ?? '';
?>
<script src="<?= $scripts_root ?>assets/js/compatibilitas/sweetalert2.all.min.js"></script>
<script src="<?= $scripts_root ?>assets/js/compatibilitas/script.min.js"></script>
<script src="<?= $scripts_root ?>assets/js/shared/health-reminder.js?v=<?= filemtime(__DIR__ . '/../assets/js/shared/health-reminder.js') ?>"></script>