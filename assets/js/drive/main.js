/**
 * MEeL Drive — Entry Point
 * Loads all drive JS modules in dependency order.
 *
 * Order:
 *   1. navigation.js  — showSection, counts, accents, lucide.createIcons()
 *   2. file-input.js  — updateFileName()
 *   3. preview.js     — openPreview(), closePreview()
 *   4. search.js      — filterDriveFiles()
 *   5. upload.js      — IIFE upload progress (XHR, drag/drop, confetti, etc.)
 */
(function () {
  'use strict';

  var files = [
    'navigation.js',
    'file-input.js',
    'preview.js',
    'search.js',
    'upload.js',
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
      console.warn('[MEeL Drive] Gagal memuat:', src, '- melanjutkan ke script berikutnya');
      if (callback) callback(); // tetap lanjut meskipun satu file gagal
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
