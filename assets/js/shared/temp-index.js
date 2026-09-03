



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
    
    
    
    const res = await fetch("beranda");
    const html = await res.text();
    const parsed = new DOMParser().parseFromString(html, "text/html");
    
    
    window.__meelTempIndexTitle = parsed.title;
    const main = parsed.querySelector("main");
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
