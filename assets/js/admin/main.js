/**
 * MEeL Admin — Entry Point
 * Loads shared admin JS modules in dependency order.
 *
 * Shared modules (loaded on ALL admin pages):
 *   1. modal.js       — confirmDelete(), closeDeleteModal()
 *   2. hover-effects.js — Table row & action button hover
 *   3. search.js      — Search input debounce
 *
 * Page-specific modules should be loaded separately after main.js.
 */
(function () {
  'use strict';

  var files = [
    'shared/modal.js',
    'shared/hover-effects.js',
    'shared/search.js',
  ];

  var base = (function () {
    var scripts = document.getElementsByTagName('script');
    var src = scripts[scripts.length - 1].src;
    // main.js is at assets/js/admin/main.js, so base = assets/js/admin/
    return src.substring(0, src.lastIndexOf('/') + 1);
  })();

  var cacheTs = Date.now();

  function loadScript(src, callback) {
    var s = document.createElement('script');
    s.src = src + '?v=' + cacheTs;
    s.async = false;
    s.onload = s.onreadystatechange = function () {
      if (
        !s.readyState ||
        s.readyState === 'loaded' ||
        s.readyState === 'complete'
      ) {
        s.onload = s.onreadystatechange = null;
        if (callback) callback();
      }
    };
    s.onerror = function () {
      console.warn('[MEeL Admin] Gagal memuat:', src, '- melanjutkan');
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
})();
