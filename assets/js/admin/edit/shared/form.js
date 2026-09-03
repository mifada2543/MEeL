
(function () {
  "use strict";
  
  window.handleSubmit = function () {
    var btn = document.getElementById("btn-save");
    if (!btn) return;
    btn.innerHTML =
      '<div style="width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:admin-spin .7s linear infinite;"></div> Menyimpan...';
    btn.style.opacity = ".6";
    btn.style.pointerEvents = "none";
  };
  
  (function () {
    var style = document.createElement("style");
    style.textContent =
      "@keyframes admin-spin { to { transform: rotate(360deg); } }";
    document.head.appendChild(style);
  })();
})();
