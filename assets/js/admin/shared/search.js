/**
 * MEeL Admin — Shared: Search Input with Debounce
 * Auto-submits search form when user stops typing.
 *
 * Catatan HTMX: cookies.php melakukan swap kontainer tabel via htmx
 * (sort tanpa reload halaman). Elemen input search hasil swap perlu
 * di-attach listener lagi setelah swap — dilakukan lewat initSearch()
 * yang dipanggil ulang pada event htmx:afterSwap.
 */
(function () {
  'use strict';

  function initSearch() {
    var searchTimeout;
    var searchInput = document.querySelector('input[name="search"]');

    if (searchInput) {
      searchInput.addEventListener('input', function () {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function () {
          var form = document.getElementById('search-form');
          if (form) form.submit();
        }, 400);
      });
    }
  }

  document.addEventListener('DOMContentLoaded', initSearch);

  // Re-init setelah konten di-swap oleh htmx (mis. sort di cookies.php)
  if (window.htmx) {
    document.body.addEventListener('htmx:afterSwap', initSearch);
  }
})();
