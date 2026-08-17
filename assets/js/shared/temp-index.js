/** MEeL - Media Hub Platform
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 */

window.meelLoadTempIndex = async function (options) {
  const opts = options || {};
  const container = opts.container || null;
  const useOuterHTML = !!opts.useOuterHTML;
  const onLoad = opts.onLoad || null;
  let el = document.getElementById("temp-index-content");
  if (el) {
    el.style.display = "block";
    window.history.pushState({ miniPlayer: true }, "", "beranda");
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
    // Route bersih: dari /music/watch atau /video/watch, 'beranda' resolve
    // ke /music/beranda & /video/beranda. '..' naik ke root → hub (salah
    // sejak routing bersih — mini-player malah menampilkan hub).
    const res = await fetch("beranda");
    const html = await res.text();
    const main = new DOMParser()
      .parseFromString(html, "text/html")
      .querySelector("main");
    if (main) {
      el.innerHTML = useOuterHTML ? main.outerHTML : main.innerHTML;
      window.history.pushState({ miniPlayer: true }, "", "beranda");
      window.lucide && window.lucide.createIcons();
      window.htmx && htmx.process(el);
      onLoad && onLoad(el);
    }
  } catch (e) {
    console.error("Gagal memuat index:", e);
  }
  return el;
};
