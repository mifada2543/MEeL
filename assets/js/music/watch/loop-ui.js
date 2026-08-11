/* loop-ui.js — Update tampilan pill/tombol 'Loop' (desktop & mini-player). */
function _setTogglePillUI(e, t) {
  e &&
    (e.classList.toggle("bg-gray-800", !t),
    e.classList.toggle("text-gray-400", !t),
    e.classList.toggle("bg-orange-500/10", t),
    e.classList.toggle("text-orange-500", t),
    e.classList.toggle("border", t),
    e.classList.toggle("border-orange-500/30", t));
}
function _applyLoopUI(e) {
  _setTogglePillUI(document.getElementById("btn-loop"), e);
  const t = document.getElementById("loop-text"),
    n = document.getElementById("mini-loop-btn");
  (t && (t.innerText = e ? "Loop On" : "Loop Off"),
    n &&
      ((n.style.color = e ? "#f97316" : ""),
      (n.style.opacity = e ? "1" : "0.5")));
}
function updateLoopUI() {
  _applyLoopUI(
    player ? player.loop : "true" === localStorage.getItem(MEEL_KEYS.GLOBAL_LOOP),
  );
}
