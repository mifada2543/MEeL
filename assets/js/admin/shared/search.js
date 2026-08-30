/* MEeL Admin — Shared: Search Input (Enter-only) */
(function () {
  'use strict';
  function initSearch() {
    var searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
      searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          var form = document.getElementById('search-form');
          if (form) form.submit();
        }
      });
    }
  }
  document.addEventListener('DOMContentLoaded', initSearch);
  if (window.htmx) {
    document.body.addEventListener('htmx:afterSwap', initSearch);
  }
})();
