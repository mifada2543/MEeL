/* MEeL Admin — Media Analytics (cookies.php) */
(function () {
  "use strict";
  function initIcons() {
    if (typeof lucide !== "undefined") lucide.createIcons();
  }
  function reinitPanelIcons() {
    var el = document.getElementById("analytics-panel");
    if (window.htmx && el && typeof lucide !== "undefined")
      lucide.createIcons({}, el);
  }
  document.addEventListener("DOMContentLoaded", initIcons);
  document.body.addEventListener("htmx:afterSwap", reinitPanelIcons);
})();
