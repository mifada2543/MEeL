/**
 * MEeL Admin — Edit Music (edit-music.php) Entry Point
 * Dependencies (loaded statically via <script> tags in edit-music.php):
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
      setupImageDragDrop('cover-wrap', 'cover-file-hidden', 'cover-preview', 'cover-changed-badge', window.handleCoverChange);
    }
  });
})();
