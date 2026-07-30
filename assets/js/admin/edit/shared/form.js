/**
 * MEeL Admin — Edit Shared: Form Submit Handler (Spinner)
 * Digunakan oleh edit-video.php dan edit-music.php
 */
(function () {
  'use strict';

  /**
   * Handles form submit — shows spinner on save button
   */
  window.handleSubmit = function () {
    var btn = document.getElementById('btn-save');
    if (!btn) return;

    btn.innerHTML = '<div style="width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:admin-spin .7s linear infinite;"></div> Menyimpan...';
    btn.style.opacity = '.6';
    btn.style.pointerEvents = 'none';
  };

  // Inject keyframe for spin animation
  (function () {
    var style = document.createElement('style');
    style.textContent = '@keyframes admin-spin { to { transform: rotate(360deg); } }';
    document.head.appendChild(style);
  })();
})();
