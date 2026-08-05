/* ============================================================
 * misc.js — Util kecil: toggle deskripsi video, update parameter
 * exclude pada search box navbar, dan shortcut keyboard global (L = toggle loop, A = toggle auto-next — sama seperti klik item Settings → Loop Playback / Auto Next).
 * Depends on: state.js, player-events.js (window.toggleLoop / toggleAutoNext), shared/keyboard.js
 * ============================================================ */
document.addEventListener("keydown", (e) => {
  if (window.meelKeyShortcutIgnored?.(e)) return;
  const n = e.key.toLowerCase();
  "l" === n && (e.preventDefault(), e.stopPropagation(), window.toggleLoop?.());
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
