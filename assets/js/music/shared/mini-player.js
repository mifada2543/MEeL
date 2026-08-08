/* mini-player.js — Mini player (Spotify-style) yang dipakai */
const miniPlayerIndex = document.getElementById("mini-player-index");
let audioPlayer = null;
let isMiniPlayerIndexActive = false;
let currentState = null;
// ─── Helpers ───
function saveIndexState() {
  if (!currentState || !audioPlayer) return;
  currentState.currentTime = audioPlayer.currentTime;
  currentState.isPlaying = !audioPlayer.paused;
  currentState.isLooping = isMiniLoopIndexActive;
  sessionStorage.setItem("meel_audio_state", JSON.stringify(currentState));
}
// ─── Buat / ganti audio element ───
function loadAudio(state, autoplay) {
  if (!audioPlayer) {
    audioPlayer = document.createElement("audio");
    audioPlayer.id = "hidden-audio-player";
    // preload=none untuk FLAC
    audioPlayer.preload = "none";
    document.body.appendChild(audioPlayer);
    audioPlayer.addEventListener("timeupdate", updateIndexProgress);
    audioPlayer.addEventListener("play", () => setPlayIcon("pause"));
    audioPlayer.addEventListener("pause", () => setPlayIcon("play"));
    audioPlayer.addEventListener("ended", () => miniNextIndex());
  }
  if (currentState && currentState.filename === state.filename) {
    return;
  }
  currentState = state;
  audioPlayer.src = `stream.php?id=${state.id}`;
  const _gLoop = localStorage.getItem("meel_global_loop") === "true";
  if (state.isLooping !== undefined && state.isLooping !== _gLoop) {
    isMiniLoopIndexActive = state.isLooping;
    localStorage.setItem("meel_global_loop", String(state.isLooping));
  } else {
    isMiniLoopIndexActive = _gLoop;
  }
  audioPlayer.loop = isMiniLoopIndexActive;
  updateMiniLoopUIIndex();
  if (autoplay) {
    audioPlayer.currentTime = state.currentTime || 0;
    audioPlayer.play().catch(() => {});
  }
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
    lucide.createIcons();
  }
}
// ─── Init: baca sessionStorage ───
function initMiniPlayerIndex() {
  const miniPlayerBar = document.getElementById("mini-player-index");
  if (miniPlayerBar) {
    miniPlayerBar.style.cursor = "default";
    miniPlayerBar.addEventListener("click", (e) => {
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
    setTimeout(() => {
      loadAudio(state, state.isPlaying);
      updateIndexUI();
    }, 100);
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
    miniPlayerIndex.classList.add("active");
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
function expandPlayerFromMiniPlayer() {
  saveIndexState();
  sessionStorage.setItem("skip_resume_once", "true");
  const savedState = sessionStorage.getItem("meel_audio_state");
  if (savedState) {
    const state = JSON.parse(savedState);
    if (state.watchUrl) {
      window.location.href = state.watchUrl;
    } else if (state.id) {
      window.location.href = `watch.php?id=${state.id}`;
    } else if (state.musicId) {
      window.location.href = `watch.php?id=${state.musicId}`;
    } else {
      const fallbackItem = document.querySelector(
        `[data-filename="${state.filename}"]`,
      );
      if (fallbackItem && fallbackItem.closest("a")) {
        window.location.href = fallbackItem.closest("a").getAttribute("href");
      }
    }
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
  miniPlayerIndex.classList.remove("active");
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
      isMiniPlayerIndexActive = true;
      miniPlayerIndex.classList.add("active");
    });
    // Tombol play
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
// Keyboard shortcuts mini player
document.addEventListener("keydown", (e) => {
  if (window.meelKeyShortcutIgnored?.(e)) return;
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
