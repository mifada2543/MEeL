/* MEeL Admin — Edit Music (edit-music.php) Entry Point */
(function () {
  'use strict';
  // Page init
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof lucide !== 'undefined') lucide.createIcons();
    if (typeof setupImageDragDrop !== 'undefined') {
      setupImageDragDrop('cover-wrap', 'cover-file-hidden', 'cover-preview', 'cover-changed-badge', window.handleCoverChange);
    }
  });
})();
