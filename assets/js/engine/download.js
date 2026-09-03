



window.meelDlPct = function (pct, eta, speed, size, frag) {
  var b = document.getElementById("meel-dl-bar");
  var t = document.getElementById("meel-dl-pct");
  var e = document.getElementById("meel-dl-eta");
  var sp = document.getElementById("meel-dl-speed");
  var sz = document.getElementById("meel-dl-size");
  var fr = document.getElementById("meel-dl-frag");
  if (b) b.style.width = pct + "%";
  if (t) t.textContent = pct + "%";
  if (e && eta) e.textContent = eta;
  if (sp && speed) sp.textContent = speed;
  if (sz && size) sz.textContent = size;
  if (fr && frag) fr.textContent = frag;
};
window.meelDlInfo = function (url) {
  var el = document.getElementById("meel-dl-url");
  if (el && url) {
    try {
      var u = new URL(url);
      el.textContent = u.hostname + u.pathname.slice(0, 50);
    } catch (e) {
      el.textContent = url.slice(0, 60);
    }
  }
};
