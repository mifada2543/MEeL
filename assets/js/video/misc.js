/* ============================================================
 * misc.js — Util kecil: toggle deskripsi video, update parameter
 * exclude pada search box navbar.
 * Fungsi komentar (toggleReply, meelConfirmHtmx, toggleCommentSection)
 * kini berada di assets/js/shared/comment.js.
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
window.toggleDescription = function () {
  const e = document.getElementById("desc-text"),
    t = document.getElementById("btn-read-more");
  if (!e || !t) return;
  const n = e.classList.toggle("line-clamp-2");
  t.textContent = n ? "Selengkapnya" : "Lebih Sedikit";
};
