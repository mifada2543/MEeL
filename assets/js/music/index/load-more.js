/* ============================================================
 * load-more.js — Observasi .lm-meta di <main> untuk update URL tombol "Load More" + auto-scroll ke bawah saat konten playlist ditambahkan via load_more_music.php.
 * ============================================================ */
(function () {
  var _main = document.querySelector("main");
  if (!_main) return;
  var _obs = new MutationObserver(function (muts) {
    for (var i = 0; i < muts.length; i++) {
      var added = muts[i].addedNodes;
      for (var j = 0; j < added.length; j++) {
        var n = added[j];
        if (
          n.nodeType !== 1 ||
          !n.classList ||
          !n.classList.contains("lm-meta")
        )
          continue;
        var nextUrl = n.getAttribute("data-next-url");
        var isEnd = n.getAttribute("data-end");
        if (n.parentNode) n.parentNode.removeChild(n);
        var btn = document.getElementById("load-more-btn");
        var ld = document.getElementById("load-more-music");
        if (nextUrl && btn) {
          btn.setAttribute("hx-get", nextUrl);
          var pg = n.getAttribute("data-page") || "";
          var tt = n.getAttribute("data-total") || "";
          if (pg && tt) {
            var btnText = btn.querySelector("span") || btn;
            btnText.textContent = "Load More \u00b7 " + pg + "/" + tt;
          }
          if (typeof htmx !== "undefined") htmx.process(btn);
          if (ld) {
            requestAnimationFrame(function () {
              var _r2 = ld.getBoundingClientRect();
              if (_r2.bottom > window.innerHeight) {
                window.scrollBy({
                  top: _r2.bottom - window.innerHeight + 20,
                  behavior: "smooth",
                });
              }
            });
          }
        } else if (isEnd && ld) {
          ld.outerHTML =
            '<div class="py-10 text-center text-[9px] text-gray-800 uppercase tracking-[.4em]">End of Collection</div>';
        }
        return;
      }
    }
  });
  _obs.observe(_main, { childList: true, subtree: true });
})();
