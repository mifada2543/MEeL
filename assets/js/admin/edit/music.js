/**
 * MEeL Admin — Edit Music (edit-music.php) Entry Point
 * Loads shared edit modules, then initializes music-specific features.
 *
 * Dependencies:
 *   - admin/main.js (shared modules)
 *   - compatibilitas/lucide.js
 *   - compatibilitas/sweetalert2.all.min.js
 */
(function () {
  'use strict';

  var files = [
    '../edit/shared/form.js',
    '../edit/shared/thumbnail.js',
    '../edit/shared/dragdrop.js',
  ];

  var base = (function () {
    var scripts = document.getElementsByTagName('script');
    var src = scripts[scripts.length - 1].src;
    return src.substring(0, src.lastIndexOf('/') + 1);
  })();

  var cacheTs = Date.now();

  function loadScript(src, callback) {
    var s = document.createElement('script');
    s.src = src + '?v=' + cacheTs;
    s.async = false;
    s.onload = s.onreadystatechange = function () {
      if (!s.readyState || s.readyState === 'loaded' || s.readyState === 'complete') {
        s.onload = s.onreadystatechange = null;
        if (callback) callback();
      }
    };
    s.onerror = function () {
      console.warn('[MEeL Admin] Gagal memuat:', src);
      if (callback) callback();
    };
    document.body.appendChild(s);
  }

  function loadNext(index) {
    if (index >= files.length) return;
    loadScript(base + files[index], function () {
      loadNext(index + 1);
    });
  }

  loadNext(0);

  // ── Page init ──
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof lucide !== 'undefined') lucide.createIcons();
    if (typeof setupImageDragDrop !== 'undefined') {
      setupImageDragDrop('cover-wrap', 'cover-file-hidden', 'cover-preview', 'cover-changed-badge', window.handleCoverChange);
    }
  });
})();
