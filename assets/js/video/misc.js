/* ============================================================
 * misc.js — Util kecil: toggle deskripsi video, update parameter
 * exclude pada search box navbar, dan shortcut keyboard global
 * (L = toggle loop, A = toggle auto-next — sama seperti klik
 * item Settings → Loop Playback / Auto Next).
 * Fungsi komentar (toggleReply, meelConfirmHtmx, toggleCommentSection)
 * kini berada di assets/js/shared/comment.js.
 * Depends on: state.js, player-events.js (window.toggleLoop / toggleAutoNext)
 * ============================================================ */

document.addEventListener("keydown", (e) => {
  // Abaikan saat fokus di kolom input/textarea (mis. form pencarian)
  const t = (e.target?.tagName || "").toLowerCase();
  if ("input" === t || "textarea" === t) return;
  // Abaikan kombinasi dengan modifier (Ctrl/Alt/Meta)
  if (e.ctrlKey || e.altKey || e.metaKey) return;
  // Abaikan auto-repeat (tombol ditahan) agar loop tidak toggle berulang
  if (e.repeat) return;
  const n = e.key.toLowerCase();
  // L = toggle loop — sinkron dengan toggleLoop di player-events.js
  "l" === n &&
    (e.preventDefault(), e.stopPropagation(), window.toggleLoop?.());
  // A = toggle auto-next — sinkron dengan toggleAutoNext di player-events.js
  "a" === n &&
    (e.preventDefault(), e.stopPropagation(), window.toggleAutoNext?.());
});

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
