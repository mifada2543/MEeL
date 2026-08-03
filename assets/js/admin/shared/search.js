/** MEeL Admin — Shared: Search Input with Debounce
 * Auto-submits search form when user stops typing. **/
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
  if (window.htmx) {
    document.body.addEventListener('htmx:afterSwap', initSearch);
  }
})();
