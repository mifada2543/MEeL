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
  const n = e.classList.toggle("line-clamp-2");
  t.textContent = n ? "Selengkapnya" : "Lebih Sedikit";
}),
  (window.toggleReply = function (e) {
    const t = document.getElementById(e);
    if (t && (t.classList.toggle("hidden"), !t.classList.contains("hidden"))) {
      const e = t.querySelector('input[type="text"]');
      e && e.focus();
    }
  }),
  // Konfirmasi SweetAlert2 untuk hx-confirm (mis. hapus komentar).
  // Intercept event htmx:confirm; jika elemen punya data-meel-confirm,
  // tampilkan meelConfirm() dulu, lalu issueRequest(true) jika disetujui.
  (window.meelConfirmHtmx = function (e) {
    const el = e && e.detail && e.detail.elt;
    const cfg = el && el.getAttribute("data-meel-confirm");
    if (!cfg) return;
    e.preventDefault();
    let opts = {};
    try { opts = JSON.parse(cfg); } catch (err) {}
    meelConfirm(opts).then((ok) => {
      if (ok && e.detail.issueRequest) e.detail.issueRequest(true);
    });
  }),
  (function () {
    if (!window.htmx) return;
    document.body.addEventListener("htmx:confirm", window.meelConfirmHtmx);
  })());
