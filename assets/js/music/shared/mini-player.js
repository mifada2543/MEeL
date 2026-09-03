











let audioPlayer = null;
let isMiniPlayerIndexActive = false;
let currentState = null;

function getMiniPlayerIndexEl() {
  return document.getElementById("mini-player-index");
}


function saveIndexState() {
  
  
  if (window.__meelCurrentView !== "index") return;
  if (!currentState || !audioPlayer) return;
  currentState.currentTime = audioPlayer.currentTime;
  currentState.isPlaying = !audioPlayer.paused;
  currentState.isLooping = isMiniLoopIndexActive;
  sessionStorage.setItem(MEEL_KEYS.AUDIO_STATE, JSON.stringify(currentState));
}



function ensureIndexAudioListeners(audio) {
  if (audio.__meelIndexListenersBound) return;
  audio.__meelIndexListenersBound = true;
  audio.addEventListener("timeupdate", updateIndexProgress);
  audio.addEventListener("play", () => setPlayIcon("pause"));
  audio.addEventListener("pause", () => setPlayIcon("play"));
  audio.addEventListener("ended", () => miniNextIndex());
}


function loadAudio(state, autoplay) {
  const engine = window.meelGetAudioEngine();
  if (!audioPlayer) audioPlayer = engine.audio;
  ensureIndexAudioListeners(audioPlayer);

  const trackId = state.id || state.musicId;
  const _gLoop = localStorage.getItem(MEEL_KEYS.GLOBAL_LOOP) === "true";
  let loopVal;
  if (state.isLooping !== undefined && state.isLooping !== _gLoop) {
    loopVal = state.isLooping;
    localStorage.setItem(MEEL_KEYS.GLOBAL_LOOP, String(state.isLooping));
  } else {
    loopVal = _gLoop;
  }
  isMiniLoopIndexActive = loopVal;
  
  engine.setLoop(loopVal);

  
  const didLoad = engine.loadTrack(
    { id: trackId, streamUrl: `stream?id=${trackId}`, isLooping: loopVal },
    { autoplay: !!autoplay, startTime: state.currentTime || 0 },
  );
  
  
  
  
  if (!didLoad && autoplay) {
    const want = state.currentTime || 0;
    if (Math.abs(audioPlayer.currentTime - want) > 1.5) {
      audioPlayer.currentTime = want;
    }
    if (audioPlayer.paused) audioPlayer.play().catch(function () {});
  }

  currentState = state;
  updateMiniLoopUIIndex();
  
  
  setPlayIcon(audioPlayer.paused ? "play" : "pause");
}


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




function initMiniPlayerIndex() {
  window.__meelCurrentView = "index";
  _resetIdxEls();

  const engine = window.meelGetAudioEngine();
  const slot = getMiniPlayerIndexEl();
  if (slot && engine) engine.mount(slot, { compact: true });
  audioPlayer = engine.audio;
  ensureIndexAudioListeners(audioPlayer);

  
  
  const _engIdNow = engine.getCurrentTrackId();
  if (_engIdNow != null) {
    const _sRaw = sessionStorage.getItem(MEEL_KEYS.AUDIO_STATE);
    if (_sRaw) {
      try {
        const _s = JSON.parse(_sRaw);
        if (
          _s &&
          (_s.musicId ?? _s.id) != null &&
          String(_s.musicId ?? _s.id) === String(_engIdNow)
        ) {
          currentState = _s;
        }
      } catch (e) {}
    }
  }

  const miniPlayerBar = getMiniPlayerIndexEl();
  if (miniPlayerBar && !miniPlayerBar.__meelClickBound) {
    miniPlayerBar.__meelClickBound = true;
    miniPlayerBar.style.cursor = "default";
    miniPlayerBar.addEventListener("click", (e) => {
      
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
  isMiniLoopIndexActive = localStorage.getItem(MEEL_KEYS.GLOBAL_LOOP) === "true";
  updateMiniLoopUIIndex();
  
  engine.setLoop(isMiniLoopIndexActive);
  if (engine.getCurrentTrackId() != null && currentState) {
    isMiniPlayerIndexActive = true;
    updateIndexUI();
    setPlayIcon(audioPlayer.paused ? "play" : "pause");
    
    
    const wantId = String(currentState.id ?? currentState.musicId);
    const wantStream =
      currentState.streamUrl || `stream?id=${wantId}`;
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
      
      audioPlayer.play().catch(function () {});
    }
    const bar = getMiniPlayerIndexEl();
    if (bar) bar.classList.add("active");
    return;
  }
  const raw = sessionStorage.getItem(MEEL_KEYS.AUDIO_STATE);
  if (!raw) return;
  try {
    const state = JSON.parse(raw);
    isMiniPlayerIndexActive = true;
    const els = _getIdxEls();
    if (els.img)
      els.img.src = state.thumbnailUrl || `upload/thumbnail/${state.thumbnail}`;
    if (els.title) els.title.textContent = state.title || "Unknown";
    if (els.artist) els.artist.textContent = state.artist || "Unknown";
    
    
    loadAudio(state, state.isPlaying);
    updateIndexUI();
    const globalLoop = localStorage.getItem(MEEL_KEYS.GLOBAL_LOOP) === "true";
    if (state.isLooping !== undefined) {
      isMiniLoopIndexActive = globalLoop;
      if (state.isLooping !== globalLoop) {
        isMiniLoopIndexActive = state.isLooping;
        localStorage.setItem(MEEL_KEYS.GLOBAL_LOOP, String(state.isLooping));
      }
    } else {
      isMiniLoopIndexActive = globalLoop;
    }
    
    engine.setLoop(isMiniLoopIndexActive);
    updateMiniLoopUIIndex();
    const bar = getMiniPlayerIndexEl();
    if (bar) bar.classList.add("active");
    if (!window._playlistLoaded && typeof loadPlaylistById === "function") {
      var plId = state.playlistId;
      if (!plId || plId <= 0) {
        var lastPl = localStorage.getItem(MEEL_KEYS.LAST_PLAYLIST_ID);
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

window.miniPlayPauseIndex = function () {
  if (!audioPlayer) return;

  if (window.meelHealthAlertActive && audioPlayer.paused) return;
  if (audioPlayer.paused) {
    audioPlayer.play();
  } else {
    
    
    sessionStorage.removeItem(MEEL_KEYS.SKIP_RESUME_ONCE);
    window.__meelResumeSessionActive = false;
    audioPlayer.pause();
  }
};

window.miniSeekIndex = function (event) {
  if (!audioPlayer) return;
  const rect = event.currentTarget.getBoundingClientRect();
  const pct = (event.clientX - rect.left) / rect.width;
  audioPlayer.currentTime = Math.max(
    0,
    Math.min(pct * audioPlayer.duration, audioPlayer.duration),
  );
};

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

function withPlaylistParam(url, playlistId) {
  if (!url || !playlistId || playlistId <= 0) return url;
  
  if (url.indexOf("playlist_id=") !== -1 || /\/playlist\/\d+/.test(url)) return url;
  
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


let _expandInFlight = false;
function expandPlayerFromMiniPlayer() {
  if (_expandInFlight) return;
  _expandInFlight = true;
  saveIndexState();
  sessionStorage.setItem(MEEL_KEYS.SKIP_RESUME_ONCE, "true");
  const savedState = sessionStorage.getItem(MEEL_KEYS.AUDIO_STATE);
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
      target = withPlaylistParam(`watch?id=${state.id}`, state.playlistId);
    } else if (state.musicId) {
      target = withPlaylistParam(
        `watch?id=${state.musicId}`,
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
    
    isMiniPlayerIndexActive = false;
    if (window.meelNavigateView) {
      
      
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
      
      _expandInFlight = false;
      window.location.href = target;
    }
  } catch (err) {
    _expandInFlight = false;
    console.warn("Mini player expand error:", err);
  }
}

let isMiniLoopIndexActive = localStorage.getItem(MEEL_KEYS.GLOBAL_LOOP) === "true";
window.toggleMiniLoopIndex = function () {
  isMiniLoopIndexActive = !isMiniLoopIndexActive;
  
  const engine = window.meelGetAudioEngine ? window.meelGetAudioEngine() : null;
  if (engine) engine.setLoop(isMiniLoopIndexActive);
  else localStorage.setItem(MEEL_KEYS.GLOBAL_LOOP, String(isMiniLoopIndexActive));
  updateMiniLoopUIIndex();
  saveIndexState();
};
function updateMiniLoopUIIndex() {
  const btn = document.getElementById("mini-loop-btn-index");
  if (!btn) return;
  if (isMiniLoopIndexActive) {
    
    
    btn.classList.add("mp-loop-active");
    btn.style.color = "#f97316";
    btn.style.opacity = "1";
  } else {
    btn.classList.remove("mp-loop-active");
    btn.style.color = "";
    btn.style.opacity = "0.5";
  }
}

window.closeMiniPlayerIndex = function () {
  if (audioPlayer) audioPlayer.pause();
  const bar = getMiniPlayerIndexEl();
  if (bar) bar.classList.remove("active");
  sessionStorage.removeItem(MEEL_KEYS.AUDIO_STATE);
  
  
  sessionStorage.removeItem(MEEL_KEYS.SKIP_RESUME_ONCE);
  window.__meelResumeSessionActive = false;
  isMiniPlayerIndexActive = false;
  currentState = null;
};



function resumeTimeForClicked(id) {
  const engine = window.meelGetAudioEngine ? window.meelGetAudioEngine() : null;
  if (engine && String(engine.getCurrentTrackId()) === String(id)) {
    return engine.audio.currentTime || 0;
  }
  return 0;
}

function setupPlaylistItemClicks() {
  document.querySelectorAll(".music-pl-item").forEach(function (item) {
    if (item.dataset.plListenerAdded) return;
    item.dataset.plListenerAdded = "true";
    item.addEventListener("click", function (e) {
      if (e.target.closest("form") || e.target.closest("a")) return;
      e.preventDefault();
      sessionStorage.setItem(MEEL_KEYS.SKIP_RESUME_ONCE, "true");
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
          `watch?id=${this.dataset.id}&playlist_id=${this.dataset.playlistId}`,
        nextSongUrl: nextSongUrl,
        playlistId: this.dataset.playlistId,
        currentTime: resumeTimeForClicked(this.dataset.id),
        isPlaying: true,
      };
      loadAudio(state, true);
      updateIndexUI();
      sessionStorage.setItem(MEEL_KEYS.AUDIO_STATE, JSON.stringify(state));
      
      var plIdNow = parseInt(this.dataset.playlistId || "0", 10);
      if (plIdNow > 0) {
        localStorage.setItem(MEEL_KEYS.LAST_PLAYLIST_ID, String(plIdNow));
      } else {
        localStorage.removeItem(MEEL_KEYS.LAST_PLAYLIST_ID);
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
  
  if (key === "i") {
    e.preventDefault();
    expandPlayerFromMiniPlayer();
  }
  
  if (key === "l") {
    e.preventDefault();
    window.toggleMiniLoopIndex();
  }
});

setInterval(() => {
  if (isMiniPlayerIndexActive) saveIndexState();
}, 5000);



const meelLibTitle =
  document.title.indexOf("| Library") !== -1
    ? document.title
    : "MEeL Music | Library";
window.meelSyncViewTitle = function () {
  const main = document.querySelector("main");
  if (!main) return;
  const h1 = main.querySelector("h1");
  const name = h1 ? (h1.textContent || "").trim() : "";
  if (name) {
    document.title = name + " — MEeL Playlist";
  } else if (main.querySelector(".section-title")) {
    document.title = meelLibTitle;
  }
};
document.addEventListener("htmx:afterSwap", function () {
  if (typeof window.meelSyncViewTitle === "function") {
    window.meelSyncViewTitle();
  }
});
