
(function () {
  'use strict';
  
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof lucide !== 'undefined') lucide.createIcons();
    if (typeof setupImageDragDrop !== 'undefined') {
      setupImageDragDrop('cover-wrap', 'cover-file-hidden', 'cover-preview', 'cover-changed-badge', window.handleCoverChange);
    }
  });
})();
