/**
 * MEeL Admin — Media Analytics (cookies.php)
 *
 * Dependencies:
 *   - admin/main.js (shared/modal.js handles confirmDelete)
 *   - compatibilitas/sweetalert2.all.min.js
 *
 * Note: SweetAlert success/error/warning dialogs are rendered
 * inline by PHP (conditional echo). This file handles any
 * additional init needed for the cookies page.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    if (typeof lucide !== 'undefined') lucide.createIcons();
  });
})();
