/* state.js — State global player musik & data preset EQ. */
let player,
  audio,
  storageKeyMusic,
  watchUrl,
  isFinished = !1,
  isMiniPlayerActive = !1,
  skipResumeModalOnce = !1,
  isNavigating = !1,
  eqFilters = [],
  eqBands = [60, 170, 350, 1e3, 3500, 1e4],
  eqGains = Array(eqBands.length).fill(0),
  eqEnabled = !1,
  eqPreset = "flat";
const ZERO_GAINS = Array(eqBands.length).fill(0),
  EQ_PRESET_LABELS = {
    flat: "Flat",
    bass: "Bass Boost",
    treble: "Treble Boost",
    vocal: "Vocal Boost",
    rock: "Rock",
    classical: "Classical",
    pop: "Pop",
    jazz: "Jazz",
    electronic: "Electronic",
    acoustic: "Acoustic",
    gaming: "Gaming",
    podcast: "Podcast",
  },
  EQ_PRESETS = {
    flat: [0, 0, 0, 0, 0, 0],
    bass: [3, 4, 4, 2, 1, 0],
    treble: [0, 1, 2, 2, 3, 4],
    vocal: [2, 2, 0, 1, 2, 2],
    rock: [3, 1, 0, -1, 2, 3],
    classical: [2, 0, -1, -1, 2, 3],
    pop: [1, 2, 2, 1, 2, 1],
    jazz: [2, 3, 1, 0, 1, 2],
    electronic: [4, 2, -1, -1, 2, 4],
    acoustic: [2, 2, 1, 0, 1, 2],
    gaming: [3, 2, -1, 1, 3, 2],
    podcast: [0, -1, 2, 3, 1, -1],
  };
let miniEls = null;
// Marker sesi "datang dari mini-player" — window global karena harus dibaca
// dari module index (assets/js/music/shared/mini-player.js) yang dipisah dari
// module watch. Di-set saat meelInitWatchPlayer() melihat skip_resume_once
// (tap kartu/playlist/expand) dan dibersihkan saat user pause/close EKSPLISIT
// di index (miniPlayPauseIndex/closeMiniPlayerIndex) — jadi resume-modal
// tidak pernah menginterupsi sesi mendengarkan yang aktif, tapi tetap muncul
// di kunjungan dingin (buka watch langsung / reload / setelah pause-close).
// Reset otomatis saat full page load (halaman baru → undefined/falsy).
window.__meelResumeSessionActive = !1;
