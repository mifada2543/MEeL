



window.meelTcPct = function (pct, label) {
  var b = document.getElementById("meel-tc-bar");
  var t = document.getElementById("meel-tc-pct");
  if (b) b.style.width = pct + "%";
  if (t) t.textContent = label || pct + "% — segmen TS";
  var done = Math.floor(pct / 6.5);
  for (var i = 0; i < done && i < 16; i++) {
    var s = document.getElementById("mseg" + i);
    if (s) s.classList.add("done");
  }
};

window.meelSpPct = function (pct, label) {
  var b = document.getElementById("meel-sp-bar");
  var t = document.getElementById("meel-sp-pct");
  var numericPct = parseInt(pct);
  if (numericPct === 100) {
    clearInterval(meelSpriteTimer);
    if (b) b.style.width = "100%";
    if (t) t.textContent = label || "100% — Selesai";
  } else if (numericPct > meelSpriteCurrentPct) {
    meelSpriteCurrentPct = numericPct;
    if (b) b.style.width = meelSpriteCurrentPct + "%";
    if (t) t.textContent = label || meelSpriteCurrentPct + "%";
  }
};
