/**
 * MEeL Admin — Activity Log (activity_log.php)
 * Action dropdown toggle, filter submission, pill buttons
 *
 * Dependencies:
 *   - admin/main.js
 *   - compatibilitas/lucide.js
 *   - compatibilitas/sweetalert2.all.min.js
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    if (typeof lucide !== 'undefined') lucide.createIcons();

    // ── Action Dropdown ──
    var trigger = document.getElementById('action-dropdown-trigger');
    var panel = document.getElementById('action-dropdown-panel');
    var actionInput = document.getElementById('action-input');
    var actionLabel = document.getElementById('action-dropdown-label');

    if (trigger && panel) {
      trigger.addEventListener('click', function (e) {
        e.stopPropagation();
        panel.classList.toggle('hidden');
      });

      // Option clicks
      panel.querySelectorAll('.action-dropdown-option').forEach(function (opt) {
        opt.addEventListener('click', function () {
          var val = this.dataset.value || '';
          if (actionInput) actionInput.value = val;
          if (actionLabel) actionLabel.textContent = val || 'Semua Aksi';
          panel.querySelectorAll('.action-dropdown-option').forEach(function (o) {
            o.classList.toggle('active', o.dataset.value === val);
          });
          panel.classList.add('hidden');
        });
      });

      // Close on outside click
      document.addEventListener('click', function () {
        if (!panel.classList.contains('hidden')) {
          panel.classList.add('hidden');
        }
      });
    }

    // ── Search Enter key ──
    var searchInput = document.getElementById('search-input');
    if (searchInput) {
      searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          submitFilters();
        }
      });
    }
  });

  // ── Days Pill Buttons ──
  window.selectDays = function (val) {
    var input = document.getElementById('days-input');
    if (input) input.value = val;
    document.querySelectorAll('.pill-btn[data-days]').forEach(function (btn) {
      btn.classList.toggle('active-blue', parseInt(btn.dataset.days) === val);
    });
  };

  // ── Clear Days Pill Buttons (Maintenance) ──
  window.selectClearDays = function (val) {
    var input = document.getElementById('clear-days-input');
    if (input) input.value = val;
    document.querySelectorAll('[data-clear-days]').forEach(function (btn) {
      btn.classList.toggle('active-red', parseInt(btn.dataset.clearDays) === val);
    });
  };

  // ── Submit Filters ──
  window.submitFilters = function () {
    var action = document.getElementById('action-input');
    var q = document.getElementById('search-input');
    var days = document.getElementById('days-input');

    var params = new URLSearchParams();
    if (action && action.value) params.set('action', action.value);
    if (q && q.value.trim()) params.set('q', q.value.trim());
    if (days && days.value) params.set('days', days.value);

    window.location.href = 'activity_log.php?' + params.toString();
  };
})();
