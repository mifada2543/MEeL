



(function () {
  "use strict";
  var urlInput = document.getElementById("url-input");
  var urlPreview = document.getElementById("url-preview");
  if (!urlInput || !urlPreview) return;
  urlInput.addEventListener("input", function () {
    var val = this.value.trim();
    if (val.length > 10 && val.startsWith("http")) {
      try {
        var u = new URL(val);
        urlPreview.textContent =
          u.hostname +
          u.pathname.slice(0, 60) +
          (u.pathname.length > 60 ? "…" : "");
        urlPreview.style.display = "block";
      } catch (e) {
        urlPreview.style.display = "none";
      }
    } else {
      urlPreview.style.display = "none";
    }
  });
})();
