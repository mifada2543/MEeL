/**
 * MEeL Admin — Shared: Search Input with Debounce
 * Auto-submits search form when user stops typing.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
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
  });
})();
