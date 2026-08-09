function normalizeEqValue(e) {
  const t = Number(e);
  return Number.isFinite(t) ? Math.max(-12, Math.min(12, t)) : 0;
}
function saveEqState() {
  try {
    localStorage.setItem(
      "meel_music_eq_state",
      JSON.stringify({ enabled: eqEnabled, preset: eqPreset, gains: eqGains }),
    );
  } catch (e) {
    console.warn("⚠️ Could not save EQ state:", e);
  }
}
function loadEqState() {
  try {
    const e = localStorage.getItem("meel_music_eq_state");
    if (!e) return;
    const t = JSON.parse(e);
    (t && Array.isArray(t.gains) && (eqGains = t.gains.map(normalizeEqValue)),
      "boolean" == typeof t?.enabled && (eqEnabled = t.enabled),
      "string" == typeof t?.preset && (eqPreset = t.preset));
  } catch (e) {
    console.warn("⚠️ Bad EQ state:", e);
  }
}
function applyEqToFilters() {
  // EQ (BiquadFilter chain) sekarang dipegang oleh audio-engine.js yang
  // persisten (bukan `eqFilters` lokal di sini, yang sudah tidak dipakai
  // lagi sejak refactor gapless mini<->full player). eqGains/eqEnabled di
  // sini tetap jadi source-of-truth UI & localStorage seperti sebelumnya —
  // fungsi ini cuma jembatan yang mendorong nilainya ke engine.
  const engine = window.meelGetAudioEngine && window.meelGetAudioEngine();
  if (!engine) return;
  engine.setEqEnabled(eqEnabled);
  engine.setEqGains(eqGains);
}
function getRealtimeVbrValue(e) {
  if (!e || !e.length) return 160;
  const t = e.reduce((e, t) => e + t, 0) / e.length,
    n = Math.min(1, Math.max(0, t / 255));
  return Math.round(96 + 224 * n);
}
function updateBitrateLabel(e, t) {
  t && (t.innerText = `${e}`);
}
function updateBarColors(e, t) {
  if (!t || !t.length) return;
  const n = Math.round(28 + ((e - 96) / 224) * 180);
  t.forEach((e) => {
    e.style.background = `linear-gradient(to top, hsl(${n}, 96%, 50%), hsl(${Math.min(360, n + 40)}, 96%, 72%))`;
  });
}
function updateEqUI() {
  const e = document.getElementById("btn-eq"),
    t = document.getElementById("eq-text"),
    n = document.getElementById("eq-panel"),
    a = document.getElementById("eq-container"),
    o = document.getElementById("eq-preset"),
    i =
      (document.getElementById("eq-preset-button"),
      document.getElementById("eq-preset-label")),
    l = document.getElementById("eq-preset-options");
  (_setTogglePillUI(e, eqEnabled),
    t && (t.innerText = eqEnabled ? "EQ On" : "EQ Off"),
    n && n.classList.toggle("hidden", !eqEnabled),
    a && a.classList.toggle("hidden", !eqEnabled),
    o && (o.value = eqPreset),
    i && (i.innerText = EQ_PRESET_LABELS[eqPreset] || eqPreset),
    l &&
      l.querySelectorAll("button[data-preset]").forEach((e) => {
        const t = e.dataset.preset === eqPreset;
        (e.classList.toggle("bg-white/[.06]", t),
          e.classList.toggle("text-orange-400", t));
      }),
    eqBands.forEach((e, t) => {
      const n = document.getElementById(`eq-band-${t}`),
        a = document.getElementById(`eq-band-value-${t}`);
      (n && (n.value = eqGains[t] ?? 0),
        a &&
          (a.innerText = `${normalizeEqValue(eqGains[t] ?? 0).toFixed(1)} dB`));
    }));
}
let _eqUiSaveTimer = null;
window.setEqBand = function (e, t) {
  ((eqGains[e] = normalizeEqValue(t)), eqEnabled && applyEqToFilters());
  const bandLabel = document.getElementById(`eq-band-value-${e}`);
  bandLabel &&
    (bandLabel.innerText = `${normalizeEqValue(eqGains[e] ?? 0).toFixed(1)} dB`);
  clearTimeout(_eqUiSaveTimer);
  _eqUiSaveTimer = setTimeout(() => {
    updateEqUI();
    saveEqState();
  }, 250);
};
window.setEqPreset = function (e) {
  const t = (EQ_PRESETS[e] || EQ_PRESETS.flat).map(normalizeEqValue);
  ((eqPreset = e || "flat"),
    (eqGains = t),
    eqEnabled && applyEqToFilters(),
    updateEqUI(),
    saveEqState());
};
