/**
 * MEeL Admin — Edit Video (edit-video.php) Entry Point
 * Dependencies (loaded statically via <script> tags in edit-video.php):
 *   - edit/shared/form.js
 *   - edit/shared/thumbnail.js
 *   - edit/shared/dragdrop.js
 *   - admin/main.js (shared modules)
 *   - compatibilitas/lucide.js
 *   - compatibilitas/sweetalert2.all.min.js
 */
(function () {
  'use strict';

  // ── Page init ──
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof lucide !== 'undefined') lucide.createIcons();
    if (typeof setupImageDragDrop !== 'undefined') {
      setupImageDragDrop('thumb-wrap', 'thumb-file-hidden', 'thumb-preview', 'thumb-changed-badge', window.handleThumbChange);
    }
  });
})();
