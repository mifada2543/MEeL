/* mini-player.js — Mini player (Spotify-style) yang dipakai
 * di index.php. Audio sekarang dipegang oleh assets/js/shared/
 * audio-engine.js yang persisten (bukan <audio id="hidden-audio-player">
 * yang dibuat manual & dibuang tiap page-load) — supaya transisi
 * mini<->full player gapless untuk track yang sama.
 *
 * CATATAN: #mini-player-index adalah bagian dari <body> yang ikut
 * di-innerHTML-replace oleh view-router.js tiap kali user pindah
 * watch.php<->index.php. Karena itu elemen ini TIDAK BOLEH di-capture
 * sekali di top-level scope (module ini hanya di-load SEKALI seumur
 * dokumen) — selalu ambil ulang lewat getMiniPlayerIndexEl().
 */
let audioPlayer = null;
let isMiniPlayerIndexActive = false;
let currentState = null;

function getMiniPlayerIndexEl() {
  return document.getElementById("mini-player-index");
}

// ─── Helpers ───
function saveIndexState() {
  // BUG FIX: interval saveIndexState() (5s) milik view index TIDAK boleh
  // menulis state saat view aktif bukan index (mis. sudah pindah ke watch
  // via expand) — kalau tidak, dia menimpa state dengan data stale dan
  // bertabrakan dengan saveAudioState() milik watch.
  if (window.__meelCurrentView !== "index") return;
  if (!currentState || !audioPlayer) return;
  currentState.currentTime = audioPlayer.currentTime;
  currentState.isPlaying = !audioPlayer.paused;
  currentState.isLooping = isMiniLoopIndexActive;
  sessionStorage.setItem("meel_audio_state", JSON.stringify(currentState));
}

// Pasang listener play/pause/timeupdate/ended ke audio-engine SEKALI SAJA
// (guard lewat flag di elemen audio itu sendiri, BUKAN `!audioPlayer` —
// karena initMiniPlayerIndex() sudah men-set audioPlayer=engine.audio
// duluan sebelum loadAudio() sempat jalan, jadi cek `!audioPlayer` di sini
// tidak akan pernah true & listener tidak akan pernah terpasang).
function ensureIndexAudioListeners(audio) {
  if (audio.__meelIndexListenersBound) return;
  audio.__meelIndexListenersBound = true;
  audio.addEventListener("timeupdate", updateIndexProgress);
  audio.addEventListener("play", () => setPlayIcon("pause"));
  audio.addEventListener("pause", () => setPlayIcon("play"));
  audio.addEventListener("ended", () => miniNextIndex());
}

// ─── Muat track ke audio-engine persisten (ganti src HANYA kalau beda) ───
function loadAudio(state, autoplay) {
  const engine = window.meelGetAudioEngine();
  if (!audioPlayer) audioPlayer = engine.audio;
  ensureIndexAudioListeners(audioPlayer);

  const trackId = state.id || state.musicId;
  const _gLoop = localStorage.getItem("meel_global_loop") === "true";
  let loopVal;
  if (state.isLooping !== undefined && state.isLooping !== _gLoop) {
    loopVal = state.isLooping;
    localStorage.setItem("meel_global_loop", String(state.isLooping));
  } else {
    loopVal = _gLoop;
  }
  isMiniLoopIndexActive = loopVal;

  // KUNCI GAPLESS: kalau trackId sama dengan yang sedang diputar engine
  // (mis. balik dari watch.php ke index.php pada lagu yang sama), ini
  // NO-OP TOTAL — src/currentTime/playback TIDAK disentuh.
  engine.loadTrack(
    { id: trackId, streamUrl: `stream.php?id=${trackId}`, isLooping: loopVal },
    { autoplay: !!autoplay, startTime: state.currentTime || 0 },
  );

  currentState = state;
  updateMiniLoopUIIndex();
  // Sync ikon play/pause ke state audio SEBENARNYA saat ini. Penting untuk
  // kasus gapless: kalau engine.loadTrack() di atas NO-OP (track sama sudah
  // berjalan), event 'play'/'pause' TIDAK akan pernah fire, jadi setPlayIcon()
  // tidak terpanggil lewat listener event di ensureIndexAudioListeners — tanpa
  // baris ini ikon menganggur di "play" (default markup) padahal audio sedang
  // berjalan, dan klik pertama terlihat tidak mengubah apa-apa.
  setPlayIcon(audioPlayer.paused ? "play" : "pause");
}

// ─── Update seluruh UI ───
let _idxEls = null;
function _getIdxEls() {
  if (!_idxEls) {
    _idxEls = {
      fill: document.getElementById("mp-seekbar-fill-index"),
      thumb: document.getElementById("mp-seekbar-thumb-index"),
      ct: document.getElementById("mini-current-time-index"),
      dt: document.getElementById("mini-duration-index"),
      img: document.getElementById("mini-thumbnail-index"),
      title: document.getElementById("mini-title-index"),
      artist: document.getElementById("mini-artist-index"),
    };
  }
  return _idxEls;
}
// Dipanggil tiap kali landing di view index (DOM baru) supaya cache elemen
// tidak stale mengarah ke node lama yang sudah dibuang view-router.
function _resetIdxEls() {
  _idxEls = null;
}
function updateIndexProgress() {
  if (!audioPlayer) return;
  const els = _getIdxEls();
  const pct =
    audioPlayer.duration > 0
      ? (audioPlayer.currentTime / audioPlayer.duration) * 100
      : 0;

  if (els.fill) els.fill.style.width = pct + "%";
  if (els.thumb) els.thumb.style.left = pct + "%";
  if (els.ct) els.ct.textContent = formatTime(audioPlayer.currentTime);
  if (els.dt) els.dt.textContent = formatTime(audioPlayer.duration);
}
function updateIndexMeta() {
  if (!currentState) return;
  const els = _getIdxEls();
  if (els.img)
    els.img.src =
      currentState.thumbnailUrl || `upload/thumbnail/${currentState.thumbnail}`;
  if (els.title) els.title.textContent = currentState.title || "Unknown";
  if (els.artist) els.artist.textContent = currentState.artist || "Unknown";
}
function updateIndexUI() {
  if (!audioPlayer || !currentState) return;
  updateIndexProgress();
  updateIndexMeta();
}
function setPlayIcon(icon) {
  const btn = document.getElementById("mini-play-btn-index");
  if (btn) {
    btn.innerHTML = `<i data-lucide="${icon}" style="width:18px;height:18px;"></i>`;
    if (typeof lucide !== "undefined") lucide.createIcons();
  }
}

// ─── Init: dipanggil tiap kali landing di index view (idempotent, DOM baru
//     tiap kali) — mount audio-engine ke #mini-player-index & baca
//     sessionStorage. ───
function initMiniPlayerIndex() {
  window.__meelCurrentView = "index";
  _resetIdxEls();

  const engine = window.meelGetAudioEngine();
  const slot = getMiniPlayerIndexEl();
  if (slot && engine) engine.mount(slot, { compact: true });
  audioPlayer = engine.audio;
  ensureIndexAudioListeners(audioPlayer);

  const miniPlayerBar = getMiniPlayerIndexEl();
  if (miniPlayerBar && !miniPlayerBar.__meelClickBound) {
    miniPlayerBar.__meelClickBound = true;
    miniPlayerBar.style.cursor = "default";
    miniPlayerBar.addEventListener("click", (e) => {
      // BUG FIX (double-fire): tap pada cover image memicu expand DUA kali
      // — inline onclick di .mp-art (index.php/view_playlist.php) DAN
      // listener ter-delegasi ini (karena e.target.tagName === "IMG").
      // Tap di dalam .mp-art sudah ditangani inline onclick, jadi skip di sini.
      if (e.target.closest(".mp-art")) return;
      if (
        e.target.closest(".mp-thumbnail") ||
        e.target.closest("#mini-player-img") ||
        e.target.tagName === "IMG"
      ) {
        expandPlayerFromMiniPlayer();
      }
    });
  }
  isMiniLoopIndexActive = localStorage.getItem("meel_global_loop") === "true";
  updateMiniLoopUIIndex();
  if (engine.getCurrentTrackId() != null && currentState) {
    isMiniPlayerIndexActive = true;
    updateIndexUI();
    setPlayIcon(audioPlayer.paused ? "play" : "pause");
    // BUG FIX (mobile-only): browser HP (terutama iOS Safari/WebKit)
    // menghentikan <audio>, atau tetap memutar resource LAMA, saat
    // view-router memindahkannya antar-DOM (body.innerHTML = "" →
    // re-attach) di transisi watch→index. Sinkronkan ulang eksplisit:
    // (a) resource termuat ≠ lagu state → muat ulang + play;
    // (b) audio berhenti akibat detach → play() ulang.
    const wantId = String(currentState.id ?? currentState.musicId);
    const wantStream =
      currentState.streamUrl || `stream.php?id=${wantId}`;
    const haveSrc = audioPlayer.currentSrc || audioPlayer.src || "";
    let haveId = null;
    try {
      haveId = new URL(haveSrc, window.location.href).searchParams.get("id");
    } catch (e) {}
    if (haveSrc && haveId !== null && haveId !== wantId) {
      audioPlayer.src = wantStream;
      audioPlayer.load();
      if (currentState.isPlaying) {
        const onReloadReady = function () {
          if ((currentState.currentTime || 0) > 5) {
            audioPlayer.currentTime = currentState.currentTime;
          }
          audioPlayer.play().catch(function () {});
        };
        if (audioPlayer.readyState >= HTMLMediaElement.HAVE_METADATA)
          onReloadReady();
        else
          audioPlayer.addEventListener("loadedmetadata", onReloadReady, {
            once: true,
          });
      }
    } else if (currentState.isPlaying && audioPlayer.paused) {
      // Audio berhenti karena detach (bukan ganti lagu) → play() ulang.
      // Event 'play' listener akan menyinkronkan ikon.
      audioPlayer.play().catch(function () {});
    }
    const bar = getMiniPlayerIndexEl();
    if (bar) bar.classList.add("active");
    return;
  }
  const raw = sessionStorage.getItem("meel_audio_state");
  if (!raw) return;
  try {
    const state = JSON.parse(raw);
    isMiniPlayerIndexActive = true;
    const els = _getIdxEls();
    if (els.img)
      els.img.src = state.thumbnailUrl || `upload/thumbnail/${state.thumbnail}`;
    if (els.title) els.title.textContent = state.title || "Unknown";
    if (els.artist) els.artist.textContent = state.artist || "Unknown";
    // BUG FIX (race): dulu loadAudio ditunda 100ms — kalau user men-tap kartu
    // lain dalam jendela itu, loadAudio(state) yang tertunda akan ME-REVERT
    // lagu yang baru dipilih ke lagu dari state lama. Panggil langsung saja:
    // engine.loadTrack() sudah no-op untuk track yang sama, jadi aman.
    loadAudio(state, state.isPlaying);
    updateIndexUI();
    const globalLoop = localStorage.getItem("meel_global_loop") === "true";
    if (state.isLooping !== undefined) {
      isMiniLoopIndexActive = globalLoop;
      if (state.isLooping !== globalLoop) {
        isMiniLoopIndexActive = state.isLooping;
        localStorage.setItem("meel_global_loop", String(state.isLooping));
      }
    } else {
      isMiniLoopIndexActive = globalLoop;
    }
    if (audioPlayer) audioPlayer.loop = isMiniLoopIndexActive;
    updateMiniLoopUIIndex();
    const bar = getMiniPlayerIndexEl();
    if (bar) bar.classList.add("active");
    if (!window._playlistLoaded && typeof loadPlaylistById === "function") {
      var plId = state.playlistId;
      if (!plId || plId <= 0) {
        var lastPl = localStorage.getItem("meel_last_playlist_id");
        plId = lastPl ? parseInt(lastPl) : 0;
      }
      if (!plId || plId <= 0) {
        plId = parseInt(window.MEEL_INDEX_CONFIG?.playlistId || 0);
      }
      if (plId > 0) {
        window._playlistLoaded = true;
        loadPlaylistById(plId);
      }
    }
  } catch (e) {
    console.warn("Mini player init error:", e);
  }
}
// ─── Play / Pause ───
window.miniPlayPauseIndex = function () {
  if (!audioPlayer) return;

  if (window.meelHealthAlertActive && audioPlayer.paused) return;
  audioPlayer.paused ? audioPlayer.play() : audioPlayer.pause();
};
// ─── Seek ───
window.miniSeekIndex = function (event) {
  if (!audioPlayer) return;
  const rect = event.currentTarget.getBoundingClientRect();
  const pct = (event.clientX - rect.left) / rect.width;
  audioPlayer.currentTime = Math.max(
    0,
    Math.min(pct * audioPlayer.duration, audioPlayer.duration),
  );
};
// ─── Next: Cari lagu berikutnya ───
window.miniNextIndex = function () {
  if (!audioPlayer) return;

  if (window.meelHealthAlertActive) return;
  if (audioPlayer.loop) return;
  if (currentState && currentState.filename) {
    const allItems = Array.from(
      document.querySelectorAll(".music-item, .music-pl-item"),
    );
    const idx = allItems.findIndex(
      (el) => el.dataset.filename === currentState.filename,
    );
    if (idx !== -1 && idx < allItems.length - 1) {
      allItems[idx + 1].click();
      return;
    }
  }
  audioPlayer.currentTime = 0;
  audioPlayer.pause();
  const btn = document.getElementById("mini-play-btn-index");
  if (btn) {
    btn.innerHTML = `<i data-lucide="play" style="width:18px;height:18px;"></i>`;
    if (typeof lucide !== "undefined") lucide.createIcons();
  }
};
// ─── Prev: restart jika > 3 detik ───
window.miniPrevIndex = function () {
  if (!audioPlayer) return;
  if (audioPlayer.currentTime > 3) {
    audioPlayer.currentTime = 0;
    return;
  }
  if (currentState && currentState.filename) {
    const allItems = Array.from(
      document.querySelectorAll(".music-item, .music-pl-item"),
    );
    const idx = allItems.findIndex(
      (el) => el.dataset.filename === currentState.filename,
    );
    if (idx > 0) {
      allItems[idx - 1].click();
      return;
    }
  }
  audioPlayer.currentTime = 0;
};
// Tambahkan playlist_id ke URL bila belum ada — konteks playlist TIDAK boleh
// hilang saat kembali ke full player dari mini-player index.php.
function withPlaylistParam(url, playlistId) {
  if (!url || !playlistId || playlistId <= 0) return url;
  if (url.indexOf("playlist_id=") !== -1) return url;
  // Sisipkan parameter sebelum fragment (#...), supaya URL tetap valid
  const hashIdx = url.indexOf("#");
  const base = hashIdx === -1 ? url : url.substring(0, hashIdx);
  const hash = hashIdx === -1 ? "" : url.substring(hashIdx);
  return (
    base +
    (base.indexOf("?") === -1 ? "?" : "&") +
    "playlist_id=" +
    playlistId +
    hash
  );
}
// BUG FIX (double-fire): guard re-entrancy. Tap cover pada HP memicu
// expandPlayerFromMiniPlayer() dua kali (inline onclick + delegated
// listener) → dua navigasi AJAX bersamaan yang saling menimpa DOM dan
// membaca sessionStorage di waktu berbeda. Flag ini membuat hanya satu
// navigasi yang efektif; di-reset setelah meelNavigateView() selesai.
let _expandInFlight = false;
function expandPlayerFromMiniPlayer() {
  if (_expandInFlight) return;
  _expandInFlight = true;
  saveIndexState();
  sessionStorage.setItem("skip_resume_once", "true");
  const savedState = sessionStorage.getItem("meel_audio_state");
  if (!savedState) {
    _expandInFlight = false;
    return;
  }
  try {
    const state = JSON.parse(savedState);
    let target = "";
    if (state.watchUrl) {
      target = withPlaylistParam(state.watchUrl, state.playlistId);
    } else if (state.id) {
      target = withPlaylistParam(`watch.php?id=${state.id}`, state.playlistId);
    } else if (state.musicId) {
      target = withPlaylistParam(
        `watch.php?id=${state.musicId}`,
        state.playlistId,
      );
    } else if (state.filename) {
      const fallbackItem = document.querySelector(
        `[data-filename="${state.filename}"]`,
      );
      const href =
        fallbackItem && fallbackItem.closest("a")
          ? fallbackItem.closest("a").getAttribute("href")
          : "";
      target = withPlaylistParam(href, state.playlistId);
    }
    if (!target) {
      _expandInFlight = false;
      return;
    }
    // Keluar dari view index — hentikan interval saveIndexState() (5s)
    // yang kalau dibiarkan akan menulis state stale di view watch.
    isMiniPlayerIndexActive = false;
    if (window.meelNavigateView) {
      // AJAX partial-swap: audio-engine (dgn <audio> yg sedang berjalan)
      // TIDAK disentuh di sini — cuma direparent oleh engine.mount()
      // setelah DOM watch.php siap. Gapless untuk track yang sama.
      // Promise.resolve(): jaga-jaga kalau versi view-router.js dari cache
      // browser (Cache-Control immutable 1 tahun) bukan async — .then()
      // tetap aman untuk nilai non-thenable.
      Promise.resolve(
        window.meelNavigateView(target, "watch", {
          onAfterSwap: function () {
            const engine = window.meelGetAudioEngine();
            const slot = document.getElementById("player-audio-slot");
            if (slot && engine) engine.mount(slot, { compact: false });
            if (typeof window.meelInitWatchPlayer === "function") {
              window.meelInitWatchPlayer();
            }
          },
        }),
      ).then(
          function () {
            _expandInFlight = false;
          },
          function () {
            _expandInFlight = false;
          },
        );
    } else {
      // Fallback kalau view-router.js entah kenapa gagal dimuat.
      _expandInFlight = false;
      window.location.href = target;
    }
  } catch (err) {
    _expandInFlight = false;
    console.warn("Mini player expand error:", err);
  }
}
// ─── Loop toggle untuk mini player ───
let isMiniLoopIndexActive = localStorage.getItem("meel_global_loop") === "true";
window.toggleMiniLoopIndex = function () {
  isMiniLoopIndexActive = !isMiniLoopIndexActive;
  localStorage.setItem("meel_global_loop", String(isMiniLoopIndexActive));
  if (audioPlayer) audioPlayer.loop = isMiniLoopIndexActive;
  updateMiniLoopUIIndex();
  saveIndexState();
};
function updateMiniLoopUIIndex() {
  const btn = document.getElementById("mini-loop-btn-index");
  if (!btn) return;
  if (isMiniLoopIndexActive) {
    btn.style.color = "#f97316";
    btn.style.opacity = "1";
  } else {
    btn.style.color = "";
    btn.style.opacity = "0.5";
  }
}
// ─── Tutup ───
window.closeMiniPlayerIndex = function () {
  if (audioPlayer) audioPlayer.pause();
  const bar = getMiniPlayerIndexEl();
  if (bar) bar.classList.remove("active");
  sessionStorage.removeItem("meel_audio_state");
  isMiniPlayerIndexActive = false;
  currentState = null;
};
// ─── Setup playlist items (dipakai index & view_playlist) ───
function setupPlaylistItemClicks() {
  document.querySelectorAll(".music-pl-item").forEach(function (item) {
    if (item.dataset.plListenerAdded) return;
    item.dataset.plListenerAdded = "true";
    item.addEventListener("click", function (e) {
      if (e.target.closest("form") || e.target.closest("a")) return;
      e.preventDefault();
      sessionStorage.setItem("skip_resume_once", "true");
      var allItems = Array.from(document.querySelectorAll(".music-pl-item"));
      var idx = allItems.indexOf(this);
      var nextSongUrl = "";
      if (idx >= 0 && idx < allItems.length - 1) {
        nextSongUrl = allItems[idx + 1].dataset.watchUrl || "";
      }
      var state = {
        id: this.dataset.id,
        musicId: this.dataset.id,
        title: this.dataset.title,
        artist: this.dataset.artist,
        thumbnail: this.dataset.thumbnail,
        thumbnailUrl:
          this.dataset.thumbnailUrl ||
          `upload/thumbnail/${this.dataset.thumbnail}`,
        filename: this.dataset.filename,
        watchUrl:
          this.dataset.watchUrl ||
          `watch.php?id=${this.dataset.id}&playlist_id=${this.dataset.playlistId}`,
        nextSongUrl: nextSongUrl,
        playlistId: this.dataset.playlistId,
        currentTime: 0,
        isPlaying: true,
      };
      loadAudio(state, true);
      updateIndexUI();
      sessionStorage.setItem("meel_audio_state", JSON.stringify(state));
      // Jaga fallback meel_last_playlist_id tetap sinkron dengan playlist yang
      // sedang diputar, supaya initMiniPlayerIndex() tidak memakai nilai stale.
      var plIdNow = parseInt(this.dataset.playlistId || "0", 10);
      if (plIdNow > 0) {
        localStorage.setItem("meel_last_playlist_id", String(plIdNow));
      } else {
        localStorage.removeItem("meel_last_playlist_id");
      }
      isMiniPlayerIndexActive = true;
      const bar = getMiniPlayerIndexEl();
      if (bar) bar.classList.add("active");
    });

    var playBtn = item.querySelector(".pl-play-btn");
    if (playBtn) {
      playBtn.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        item.click();
      });
    }
  });
}
document.addEventListener("keydown", (e) => {
  if (window.meelKeyShortcutIgnored?.(e)) return;
  if (window.__meelCurrentView !== "index") return;
  const key = e.key.toLowerCase();
  // Keyboard 'i' → Pindah kembali ke full player (watch.php)
  if (key === "i") {
    e.preventDefault();
    expandPlayerFromMiniPlayer();
  }
  // Keyboard 'l' → Toggle loop mini player
  if (key === "l") {
    e.preventDefault();
    window.toggleMiniLoopIndex();
  }
});
// Auto-save tiap 5 detik
setInterval(() => {
  if (isMiniPlayerIndexActive) saveIndexState();
}, 5000);
