/** MEeL - Media Hub Platform
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 */
/* ────────────────────────────────────────────────────────────────
 * shared/temp-index.js — Helper memuat konten index.php ke elemen #temp-index-content TANPA reload (pola mini-player).
 * Dipakai BERSAMA oleh modul music (watch/player-core.js) & video (watch/mini-player.js) — sebelumnya pola fetch → DOMParser → querySelector('main') → pushState → createIcons → htmx.process diduplikasi di kedua file. window.meelLoadTempIndex(options) → Promise<Element|null>
 *   options.container    : elemen induk tempat append div baru. Jika null,
 *                          default: insert sebelum <footer> / elemen body terakhir.
 *   options.useOuterHTML : true → pakai main.outerHTML (video); false (default)
 *                          → main.innerHTML (music).
 *   options.onLoad       : callback(el) setelah konten berhasil dimuat — juga
 *                          dipanggil jika elemen sudah ada (cabang display-only).
 * ──────────────────────────────────────────────────────────────── */
window.meelLoadTempIndex = async function (options) {
  const opts = options || {};
  const container = opts.container || null;
  const useOuterHTML = !!opts.useOuterHTML;
  const onLoad = opts.onLoad || null;
  let el = document.getElementById("temp-index-content");
  if (el) {
    el.style.display = "block";
    window.history.pushState({ miniPlayer: true }, "", "index.php");
    onLoad && onLoad(el);
    return el;
  }
  el = document.createElement("div");
  el.id = "temp-index-content";
  el.className = "w-full";
  if (container) {
    container.appendChild(el);
  } else {
    const ref =
      document.querySelector("footer") ?? document.body.lastElementChild;
    document.body.insertBefore(el, ref);
  }
  try {
    const res = await fetch("index.php");
    const html = await res.text();
    const main = new DOMParser()
      .parseFromString(html, "text/html")
      .querySelector("main");
    if (main) {
      el.innerHTML = useOuterHTML ? main.outerHTML : main.innerHTML;
      window.history.pushState({ miniPlayer: true }, "", "index.php");
      window.lucide && window.lucide.createIcons();
      window.htmx && htmx.process(el);
      onLoad && onLoad(el);
    }
  } catch (e) {
    console.error("Gagal memuat index:", e);
  }
  return el;
};
