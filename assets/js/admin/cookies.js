/**
 * MEeL Admin — Media Analytics (cookies.php)
 *
 * Dependencies:
 *   - admin/main.js (shared/modal.js handles confirmDelete)
 *   - compatibilitas/sweetalert2.all.min.js
 *
 * Note: SweetAlert success/error/warning dialogs are rendered
 * inline by PHP (conditional echo). This file handles any
 * additional init needed for the cookies page.
 *
 * HTMX: sort tabel memakai hx-select + hx-swap="outerHTML" tanpa
 * reload halaman. Setelah swap, ikon lucide di dalam #analytics-panel
 * perlu di-render ulang. Penting: pakai referensi FRESH dari DOM
 * (getElementById) — bukan detail.target dari event htmx, karena pada
 * hx-swap="outerHTML" detail.target menunjuk elemen LAMA yang sudah
 * disconnected (stale reference), sehingga createIcons tidak berefek.
 */
(function () {
  'use strict';

  function initIcons() {
    if (typeof lucide !== 'undefined') lucide.createIcons();
  }

  function reinitPanelIcons() {
    // createIcons idempotent: sudah-SVG tidak diubah lagi, jadi aman
    // dipanggil di dua event (afterSwap + afterSettle) per swap.
    var el = document.getElementById('analytics-panel');
    if (window.htmx && el && typeof lucide !== 'undefined') lucide.createIcons({}, el);
  }

  document.addEventListener('DOMContentLoaded', initIcons);

  // Re-init ikon di panel setelah swap htmx (sort tanpa reload).
  // htmx:afterSwap cukup: DOM panel baru sudah tersedia saat event ini
  // (terbukti di browser). Cek window.htmx di dalam handler (lebih kokoh
  // daripada di registrasi) — tetap bekerja jika htmx dimuat belakangan.
  document.body.addEventListener('htmx:afterSwap', reinitPanelIcons);
})();
