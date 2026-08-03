/** MEeL Admin — Chess (catur.php)
 * Auto-cleanup countdown timer
 * Dependencies:
 *   - compatibilitas/lucide.js
 *   - partials/scripts.php (sweetalert2 + script.min.js — dipakai via meelConfirmForm di catur.php) **/
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof lucide !== 'undefined') lucide.createIcons();
    // ── Countdown & Auto-cleanup ──
    var INTERVAL_MS = 10 * 60 * 1000; // 10 menit
    var remaining = INTERVAL_MS / 1000;
    var countdownEl = document.getElementById('countdown');
    var liveLog = document.getElementById('live-log');
    function formatTime(s) {
      var m = Math.floor(s / 60).toString().padStart(2, '0');
      var sec = (s % 60).toString().padStart(2, '0');
      return m + ':' + sec;
    }
    function tick() {
      remaining--;
      if (remaining <= 0) {
        remaining = INTERVAL_MS / 1000;
        runCleanup();
      }
      if (countdownEl) countdownEl.textContent = formatTime(remaining);
    }
    async function runCleanup() {
      try {
        var res = await fetch('catur.php?auto_cleanup=1');
        var data = await res.json();
        if (data.success) {
          if (liveLog) {
            var entry = document.createElement('p');
            entry.className = 'log-entry';
            entry.textContent = '[' + data.time + '] AUTO-CLEANUP: ' + data.rooms + ' rooms, ' + data.moves + ' moves deleted';
            liveLog.prepend(entry);
          }
          setTimeout(function () { location.reload(); }, 1500);
        }
      } catch (e) {
        console.warn('[MEeL Admin] Cleanup error:', e);
      }
    }
    if (countdownEl) {
      setInterval(tick, 1000);
    }
  });
})();
