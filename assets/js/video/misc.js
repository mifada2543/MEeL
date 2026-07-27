/* ============================================================
 * misc.js — Util kecil: toggle deskripsi video, toggle balasan
 * komentar, update parameter exclude pada search box navbar.
 * Depends on: -
 * ============================================================ */

function updateSearchExcludeId(e) {
  (["v-search-watch", "v-search-mobile"].forEach((t) => {
    const n = document.getElementById(t);
    n &&
      (n.setAttribute("hx-get", `search_video.php?exclude=${e}`),
      window.htmx && htmx.process(n));
  }),
    document
      .querySelectorAll('button[hx-include="#v-search-watch"]')
      .forEach((t) => {
        (t.setAttribute("hx-get", `search_video.php?exclude=${e}`),
          window.htmx && htmx.process(t));
      }));
}
((window.toggleDescription = function () {
  const e = document.getElementById("desc-text"),
    t = document.getElementById("btn-read-more");
  if (!e || !t) return;
  const n = e.classList.toggle("line-clamp-5");
  t.textContent = n ? "Selengkapnya" : "Lebih Sedikit";
}),
  (window.toggleReply = function (e) {
    const t = document.getElementById(e);
    if (t && (t.classList.toggle("hidden"), !t.classList.contains("hidden"))) {
      const e = t.querySelector('input[type="text"]');
      e && e.focus();
    }
  }));
