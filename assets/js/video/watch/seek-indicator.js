/* ============================================================
 * seek-indicator.js — Indikator visual '-10s'/'+10s' saat double-tap seek di gestur mobile.
 * Depends on: video/watch
 * ============================================================ */
const _sisiIndicators = { rewind: null, forward: null },
  _sisiHideTimeouts = { rewind: null, forward: null },
  _sisiRippleCounts = { rewind: 0, forward: 0 };
function tampilkanSisiIndikator(e, t) {
  const n = document.querySelector(".plyr");
  if (!n) return;
  if (!_sisiIndicators[e] || !_sisiIndicators[e].parentNode) {
    const t = document.createElement("div");
    ((t.className = `meel-seek-indicator meel-seek-${e}`),
      n.appendChild(t),
      (_sisiIndicators[e] = t));
  }
  const o = _sisiIndicators[e],
    l =
      "rewind" === e
        ? '<svg class="meel-seek-icon" viewBox="0 0 24 24"><path d="M11 18V6l-8.5 6 8.5 6zm.5-6l8.5 6V6l-8.5 6z"/></svg>'
        : '<svg class="meel-seek-icon" viewBox="0 0 24 24"><path d="M4 18l8.5-6L4 6v12zm9-12v12l8.5-6L13 6z"/></svg>';
  ((o.innerHTML = `${l}<span class="meel-seek-label">${t}</span>`),
    o.classList.remove("meel-seek-active"),
    o.offsetWidth,
    o.classList.add("meel-seek-active"),
    clearTimeout(_sisiHideTimeouts[e]),
    (_sisiHideTimeouts[e] = setTimeout(() => {
      o.classList.remove("meel-seek-active");
    }, 800)));
}
function tampilkanIndikator(e) {}
