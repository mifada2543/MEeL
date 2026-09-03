
let isMiniPlayerActive = !1,
  watchUrl = window.location.href,
  savedWatchScrollY = 0,
  miniShell = null,
  miniDragState = null,
  miniDragSuppressClick = !1,
  miniSnapPending = null;
const MINI_POS_KEY = MEEL_KEYS.MINI_PLAYER_POS,
  
  MINI_DRAG_SCALE = 1.02;

const MINI_ICON_EXPAND =
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M3 3h7v2H5v5H3V3zm11 0h7v7h-2V5h-5V3zM3 14h2v5h5v2H3v-7zm16 5h-5v2h7v-7h-2v5z"/></svg>',
  MINI_ICON_CLOSE =
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>',
  MINI_ICON_VOLUME =
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>',
  MINI_ICON_MUTED =
    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>';
function setNavbarSearchTarget(e) {
  (["v-search-watch", "v-search-mobile"].forEach((t) => {
    const n = document.getElementById(t);
    n && n.setAttribute("hx-target", e);
  }),
    document
      .querySelectorAll('button[hx-include="#v-search-watch"]')
      .forEach((t) => t.setAttribute("hx-target", e)));
}

function syncMiniPlayerBodyPadding() {
  if (!isMiniPlayerActive || !miniShell)
    return void (document.body.style.paddingBottom = "");
  const e = miniShell.offsetHeight;
  document.body.style.paddingBottom = e ? `${e + 32}px` : "120px";
}
function clampMiniPlayerToViewport(e) {
  cancelMiniSnap();
  const t = e.getBoundingClientRect(),
    n = e.offsetWidth,
    o = e.offsetHeight,
    l = window.innerWidth,
    a = window.innerHeight;
  if (n >= l && o >= a) return;
  const c = Math.min(Math.max(0, t.left), Math.max(0, l - n)),
    d = Math.min(Math.max(0, t.top), Math.max(0, a - o));
  (c !== t.left || d !== t.top) &&
    ((e.style.left = c + "px"),
    (e.style.top = d + "px"),
    (e.style.right = "auto"),
    (e.style.bottom = "auto"));
}
window.addEventListener("resize", () => {
  if (!isMiniPlayerActive) return;
  miniShell && !miniDragState && clampMiniPlayerToViewport(miniShell);
  syncMiniPlayerBodyPadding();
});

function saveMiniPlayerPos(e, t) {
  try {
    localStorage.setItem(MINI_POS_KEY, JSON.stringify({ left: e, top: t }));
  } catch (e) {}
}
function applyMiniPlayerPos(e) {
  let t = null;
  try {
    t = JSON.parse(localStorage.getItem(MINI_POS_KEY) || "null");
  } catch (e) {
    t = null;
  }
  if (!t || !Number.isFinite(t.left) || !Number.isFinite(t.top)) return;
  const n = e.offsetWidth || 360,
    o = e.offsetHeight || 200;
  e.style.left =
    Math.min(Math.max(0, t.left), Math.max(0, window.innerWidth - n - 8)) +
    "px";
  e.style.top =
    Math.min(Math.max(0, t.top), Math.max(0, window.innerHeight - o - 8)) +
    "px";
  (e.style.right = "auto"), (e.style.bottom = "auto");
}
function cancelMiniSnap() {
  miniSnapPending &&
    (clearTimeout(miniSnapPending.timer),
    miniSnapPending.shell.classList.remove("mini-snapping"),
    (miniSnapPending.shell.style.transform = "none"),
    (miniSnapPending.shell.style.left = miniSnapPending.left + "px"),
    (miniSnapPending.shell.style.top = miniSnapPending.top + "px"),
    (miniSnapPending.shell.style.right = "auto"),
    (miniSnapPending.shell.style.bottom = "auto"),
    (miniSnapPending = null));
}


function pickMiniCorner(left, top, w, h) {
  const vw = window.innerWidth,
    vh = window.innerHeight,
    small = vw <= 480,
    tiny = vw <= 640,
    mx = tiny ? (small ? 8 : 12) : 24,
    my = tiny ? (small ? 12 : 16) : 24;
  const targets = [
    { left: mx, top: my },
    { left: Math.max(0, vw - w - mx), top: my },
    { left: mx, top: Math.max(0, vh - h - my) },
    { left: Math.max(0, vw - w - mx), top: Math.max(0, vh - h - my) },
  ];
  const cx = left + w / 2,
    cy = top + h / 2;
  let best = targets[0],
    bd = Infinity;
  for (const t of targets) {
    const d = (t.left + w / 2 - cx) ** 2 + (t.top + h / 2 - cy) ** 2;
    d < bd && ((bd = d), (best = t));
  }
  return best;
}




function snapMiniPlayer(e, base, applied) {
  cancelMiniSnap();
  
  const curLeft = base.left + applied.x,
    curTop = base.top + applied.y,
    corner = pickMiniCorner(curLeft, curTop, base.width, base.height),
    dx = corner.left - base.left,
    dy = corner.top - base.top;
  e.classList.add("mini-snapping");
  e.style.transform = `translate3d(${dx}px, ${dy}px, 0) scale(1)`;
  miniSnapPending = {
    shell: e,
    left: corner.left,
    top: corner.top,
    timer: setTimeout(() => {
      e.classList.remove("mini-snapping");
      (e.style.transform = "none"),
        (e.style.left = corner.left + "px"),
        (e.style.top = corner.top + "px"),
        (e.style.right = "auto"),
        (e.style.bottom = "auto");
      miniSnapPending = null;
    }, 320),
  };
  saveMiniPlayerPos(corner.left, corner.top);
}
function initMiniPlayerDrag(e) {
  let rafId = null,
    captured = !1,
    appliedX = 0,
    appliedY = 0;
  const applyTransform = () => {
    rafId = null;
    const t = miniDragState;
    if (!t) return;
    
    const n = t.rect,
      o = t.curX - t.startX,
      l = t.curY - t.startY,
      a = Math.max(0, window.innerWidth - n.width),
      c = Math.max(0, window.innerHeight - n.height),
      d = Math.min(Math.max(0, n.left + o), a),
      h = Math.min(Math.max(0, n.top + l), c);
    (appliedX = d - n.left), (appliedY = h - n.top);
    e.style.transform = `translate3d(${appliedX}px, ${appliedY}px, 0) scale(${MINI_DRAG_SCALE})`;
  };
  const endDrag = () => {
    rafId !== null && (cancelAnimationFrame(rafId), (rafId = null));
    const t = miniDragState;
    if (!t) return;
    const n = t.rect,
      o = { x: appliedX, y: appliedY };
    captured &&
      (e.hasPointerCapture(t.pointerId) &&
        e.releasePointerCapture(t.pointerId),
      (captured = !1));
    miniDragState = null;
    e.classList.remove("mini-dragging");
    if (t.moved) {
      miniDragSuppressClick = !0;
      setTimeout(() => (miniDragSuppressClick = !1), 0);
      snapMiniPlayer(e, n, o);
    }
  };
  e.addEventListener("pointerdown", (t) => {
    if (0 !== t.button) return;
    if (
      t.target.closest(
        "button, a, input, select, textarea, .plyr__controls, .plyr__menu",
      )
    )
      return;
    cancelMiniSnap();
    miniDragState = {
      pointerId: t.pointerId,
      startX: t.clientX,
      startY: t.clientY,
      curX: t.clientX,
      curY: t.clientY,
      rect: e.getBoundingClientRect(),
      moved: !1,
    };
    e.classList.add("mini-dragging");
  });
  const t = (t) => {
    const n = miniDragState;
    if (!n || t.pointerId !== n.pointerId) return;
    (n.curX = t.clientX),
      (n.curY = t.clientY);
    if (
      Math.abs(n.curX - n.startX) + Math.abs(n.curY - n.startY) > 4 &&
      !n.moved
    ) {
      
      
      
      n.moved = !0;
      if (!captured)
        try {
          e.setPointerCapture(t.pointerId), (captured = !0);
        } catch (e) {}
    }
    rafId === null && (rafId = requestAnimationFrame(applyTransform));
  };
  const n = (t) => {
    const n = miniDragState;
    (!n || t.pointerId !== n.pointerId) || endDrag();
  };
  window.addEventListener("pointermove", t);
  window.addEventListener("pointerup", n);
  window.addEventListener("pointercancel", n);
  window.addEventListener("blur", endDrag);
  document.addEventListener("visibilitychange", () => {
    document.hidden && endDrag();
  });
}
document.addEventListener(
  "click",
  (e) => {
    miniDragSuppressClick &&
      (e.stopPropagation(), e.preventDefault());
  },
  !0,
);

function updateMiniMuteBtn() {
  const e = document.getElementById("mini-mute-btn");
  if (!e) return;
  const t = !!(player && (player.muted || player.volume === 0));
  (e.innerHTML = t ? MINI_ICON_MUTED : MINI_ICON_VOLUME),
    (e.title = t ? "Suarakan" : "Bisukan"),
    e.setAttribute("aria-label", e.title);
}
let _miniMuteWiredPlayer = null;
function wireMiniMuteToPlayer() {
  if (_miniMuteWiredPlayer === player) return;
  _miniMuteWiredPlayer &&
    _miniMuteWiredPlayer.off &&
    (_miniMuteWiredPlayer.off("mutedchange", updateMiniMuteBtn),
    _miniMuteWiredPlayer.off("volumechange", updateMiniMuteBtn));
  player &&
    player.on &&
    (player.on("mutedchange", updateMiniMuteBtn),
    player.on("volumechange", updateMiniMuteBtn));
  _miniMuteWiredPlayer = player || null;
}


let miniShellResizeObserver = null;
function watchMiniShellSize(e) {
  if (!window.ResizeObserver) return;
  miniShellResizeObserver ||
    (miniShellResizeObserver = new ResizeObserver(() => {
      isMiniPlayerActive &&
        !miniDragState &&
        clampMiniPlayerToViewport(e);
    }));
  miniShellResizeObserver.observe(e);
}

function buildMiniInfo() {
  const e = document.createElement("div");
  e.style.cssText = "flex:1;min-width:0;";
  const t = document.createElement("div");
  (t.id = "mini-info-title"),
    (t.textContent = videoTitle || "");
  const n = document.createElement("div");
  (n.id = "mini-info-uploader"),
    (n.textContent = videoUploader || "");
  return (e.appendChild(t), e.appendChild(n), e);
}
function getMiniShell() {
  if (miniShell) return miniShell;
  const e = document.createElement("div");
  e.id = "mini-player-shell";
  const t = document.createElement("button");
  ((t.id = "mini-expand-btn"),
    (t.type = "button"),
    (t.title = "Perlebar player"),
    (t.innerHTML = MINI_ICON_EXPAND),
    t.addEventListener("click", (e) => {
      (e.stopPropagation(), toggleMiniPlayer());
    }));
  const n = document.createElement("button");
  ((n.id = "mini-close-btn"),
    (n.type = "button"),
    (n.title = "Tutup mini player"),
    (n.innerHTML = MINI_ICON_CLOSE),
    n.addEventListener("click", (e) => {
      (e.stopPropagation(), closeMiniPlayer());
    }));
  const o = document.createElement("div");
  (o.id = "mini-player-info"),
    (o.title = "Kembali ke video");
  const l = buildMiniInfo(),
    a = document.createElement("button");
  ((a.id = "mini-mute-btn"),
    (a.type = "button"),
    (a.title = "Bisukan"),
    (a.innerHTML = MINI_ICON_VOLUME),
    a.addEventListener("click", (e) => {
      (e.stopPropagation(),
        player && (player.muted = !player.muted),
        updateMiniMuteBtn());
    }));
  (o.appendChild(l), o.appendChild(a)),
    o.addEventListener("click", () => toggleMiniPlayer()),
    e.appendChild(o),
    e.appendChild(t),
    e.appendChild(n),
    (miniShell = e),
    (e.style.display = "none"),
    document.body.appendChild(e),
    initMiniPlayerDrag(e),
    watchMiniShellSize(e),
    wireMiniMuteToPlayer(),
    updateMiniMuteBtn();
  return e;
}
function closeMiniPlayer() {
  if (!isMiniPlayerActive) return;
  isMiniPlayerActive = false;
  
  
  
  
  isRecovering = !0;
  isCheckingStatus = !1;
  stopStuckDetector();
  stopWaitingTimeout();
  stopPlaybackStartTimeout();
  
  
  try {
    player && player.pause();
  } catch (e) {}
  destroyPlayer();
  
  
  if (miniShell) {
    miniShell.remove();
    miniShell = null;
  }
  document.body.style.paddingBottom = "";
  document.body.classList.remove("meel-autonext-active");
  if (typeof glowNavbar !== "undefined" && glowNavbar) {
    glowNavbar.style.removeProperty("--navbar-glow-color");
  }
  
  
  
  const grid = document.getElementById("app-content-grid");
  grid && grid.remove();
  
  setNavbarSearchTarget("#video-container");
  const tempTitle = window.__meelTempIndexTitle;
  tempTitle && (document.title = tempTitle);
}
function updateMiniPlayerInfo(e, t) {
  const n = document.getElementById("mini-info-title"),
    o = document.getElementById("mini-info-uploader");
  n && (n.textContent = e || "");
  o && (o.textContent = t || "");
}
function attachMiniPlayerVideoCardListeners(e) {
  e &&
    e.querySelectorAll('a[href*="watch.php"], a[href*="/video/watch"]').forEach((e) => {
      e.dataset.miniIntercepted ||
        ((e.dataset.miniIntercepted = "1"),
        e.addEventListener("click", async (t) => {
          if (!isMiniPlayerActive) return;
          t.preventDefault();
          autoNextEnabled = false;
          localStorage.setItem(MEEL_KEYS.AUTONEXT_ENABLED, "false");
          const n = e.href;
          try {
            const e = await fetch(n),
              t = await e.text(),
              o = new DOMParser().parseFromString(t, "text/html");
            let l = {};
            o.querySelectorAll("script:not([src])").forEach((e) => {
              const t = e.textContent.match(
                /window\.playerConfig\s*=\s*(\{[\s\S]*?\});/,
              );
              if (t)
                try {
                  l = JSON.parse(t[1]);
                } catch (e) {
                  console.error("Gagal parse playerConfig:", e);
                }
            });
            const a = o.getElementById("main-video"),
              r = l.title || "",
              i = l.uploader || "",
              s = l.videoSrc || a?.dataset?.src || "",
              c =
                !0 === l.isHls ||
                "true" === l.isHls ||
                "true" === a?.dataset?.ishls,
              d = a?.dataset?.poster || "",
              p = l.id || new URL(n).searchParams.get("id") || (n.match(/[?&]id=(\d+)/) || [])[1] || "";
            updateSearchExcludeId(p);
            const u = l.vttSrc || "";
            if (!s) return void (window.location.href = n);
            ((storageKeyVideo = `video_pos_${p}`),
              (videoSrc = s),
              (isHls = c),
              (vttSrc = u),
              (videoId = p),
              destroyPlayer());
            const m = document.getElementById("main-video");
            (m &&
              ((m.innerHTML = ""),
              Array.from(
                a.querySelectorAll('track[kind="captions"]') || [],
              ).forEach((t) => {
                const track = document.createElement("track");
                track.kind = "captions";
                track.src = t.getAttribute("src") || "";
                track.srclang = t.getAttribute("srclang") || "und";
                track.label = t.getAttribute("label") || "";
                m.appendChild(track);
              }),
              (m.dataset.src = s),
              (m.dataset.ishls = c ? "true" : "false"),
              (m.dataset.poster = d),
              (m.poster = d),
              c ? m.removeAttribute("src") : (m.src = s),
              m.load()),
              (videoTitle = r),
              (videoUploader = i),
              (window.playerConfig = {
                videoSrc: s,
                isHls: c,
                vttSrc: u,
                id: p,
                title: r,
                uploader: i,
              }),
              initPlayer(),
              updateMiniPlayerInfo(r, i),
              (document.title = o.title),
              ["watch-details-wrapper", "recommendation-column"].forEach(
                (e) => {
                  const t = document.getElementById(e),
                    n = o.getElementById(e);
                  t && n && (t.innerHTML = n.innerHTML);
                },
              ),
              window.lucide && window.lucide.createIcons(),
              ["main-video", "watch-details-wrapper", "recommendation-column"].forEach(
                (e) => {
                  const t = document.getElementById(e);
                  t && window.htmx && htmx.process(t);
                },
              ),
              wireMiniMuteToPlayer(),
              updateMiniMuteBtn(),
              syncMiniPlayerBodyPadding(),
              clampMiniPlayerToViewport(miniShell),
              (watchUrl = n),
              window.history.pushState({ miniPlayer: !0 }, "", n));
            const y = document.getElementById("temp-index-content");
            y && attachMiniPlayerVideoCardListeners(y);
          } catch (e) {
            (console.error("Gagal ganti video di mini-player:", e),
              (window.location.href = n));
          }
        }));
    });
}
((window.toggleMiniPlayer = async function () {
  const e = document.getElementById("main-video-wrapper"),
    t = document.getElementById("watch-details-wrapper"),
    n = document.getElementById("recommendation-wrapper"),
    o = document.getElementById("app-content-grid"),
    l = document.getElementById("left-column");
  if (!e && !isMiniPlayerActive) {
    console.error("toggleMiniPlayer: main-video-wrapper tidak ditemukan");
    return;
  }
  if (isMiniPlayerActive) {
    ((isMiniPlayerActive = !1),
      setNavbarSearchTarget("#recommendation-column"));
    const e = document.getElementById("main-video-wrapper");
    if (e) {
      (e.classList.remove("mini-player-mode"),
        e.style.removeProperty("aspect-ratio"),
        e.style.removeProperty("height"),
        e.style.removeProperty("width"),
        e.style.removeProperty("position"),
        (e.style.aspectRatio = "16 / 9"),
        player?.elements?.controls &&
          Array.from(player.elements.controls.children).forEach(
            (e) => (e.style.display = ""),
          ));
      const t = document.getElementById("video-glow-container");
      if (t) {
        const n = t.querySelector("canvas");
        n ? t.insertBefore(e, n.nextSibling) : t.appendChild(e);
      } else l && l.insertBefore(e, l.firstChild);
      const n = document.getElementById("video-glow-canvas");
      n &&
        (n.style.removeProperty("display"),
        glowTargetData.fill(0),
        glowCurData.fill(0),
        glowNavbar &&
          glowNavbar.style.setProperty("--navbar-glow-color", "0,0,0"),
        videoElement?.paused ||
          videoElement?.ended ||
          !glowStartFn ||
          glowStartFn());
    }
    (miniShell && (miniShell.style.display = "none"),
      (document.body.style.paddingBottom = ""));
    const a = document.getElementById("temp-index-content");
    (a && (a.style.display = "none"),
      o && (o.style.display = ""),
      t && (t.style.display = "block"),
      n && (n.style.display = "block"),
      requestAnimationFrame(() => {
        if (
          videoElement &&
          videoElement.videoWidth &&
          videoElement.videoHeight
        ) {
          const t = videoElement.videoWidth,
            n = videoElement.videoHeight;
          e && (e.style.aspectRatio = `${t} / ${n}`);
        }
        const t = document.getElementById("desc-text"),
          n = document.getElementById("btn-read-more");
        (t &&
          n &&
          (n.classList.add("hidden"),
          t.scrollHeight > t.clientHeight && n.classList.remove("hidden")),
          window.scrollTo({
            top: savedWatchScrollY,
            left: 0,
            behavior: "instant",
          }));
      }),
      window.history.pushState({}, "", watchUrl));
  } else {
    const videoWrapper = e;
    try {
      ((isMiniPlayerActive = !0),
        setNavbarSearchTarget("#video-container"),
        (savedWatchScrollY = window.scrollY),
        window.scrollTo({ top: 0, left: 0, behavior: "instant" }),
        videoWrapper &&
          (videoWrapper.style.removeProperty("aspect-ratio"),
          videoWrapper.style.removeProperty("height")));
      const shell = getMiniShell();
      (shell.style.display = ""),
        videoWrapper.classList.add("mini-player-mode"),
        shell.insertBefore(videoWrapper, shell.firstChild),
        applyMiniPlayerPos(shell);
      const l = document.getElementById("video-glow-canvas");
      (l && ((l.style.display = "none"), l.classList.remove("glow-active")),
        glowRAF && (cancelAnimationFrame(glowRAF), (glowRAF = null)),
        glowNavbar &&
          glowNavbar.style.setProperty("--navbar-glow-color", "0,0,0"),
        document.body.appendChild(shell),
        syncMiniPlayerBodyPadding(),
        requestAnimationFrame(syncMiniPlayerBodyPadding),
        t && (t.style.display = "none"),
        n && (n.style.display = "none"),
        o && (o.style.display = "none"),
        wireMiniMuteToPlayer(),
        updateMiniMuteBtn());
      await window.meelLoadTempIndex({
        useOuterHTML: true,
        onLoad: (el) => attachMiniPlayerVideoCardListeners(el),
      });
    } catch (err) {
      console.error(
        "toggleMiniPlayer: error saat masuk mini-player mode:",
        err,
      );
      isMiniPlayerActive = !1;
      setNavbarSearchTarget("#recommendation-column");
      if (videoWrapper) {
        videoWrapper.classList.remove("mini-player-mode");
        videoWrapper.style.removeProperty("aspect-ratio");
        videoWrapper.style.removeProperty("height");
        if (miniShell && miniShell.contains(videoWrapper)) {
          const t = document.getElementById("video-glow-container");
          if (t) {
            const n = t.querySelector("canvas");
            n ? t.insertBefore(videoWrapper, n.nextSibling) : t.appendChild(videoWrapper);
          }
        }
      }
      miniShell && (miniShell.style.display = "none");
      document.body.style.paddingBottom = "";
      if (t) t.style.display = "";
      if (n) n.style.display = "";
      if (o) o.style.display = "";
      const a = document.getElementById("temp-index-content");
      if (a) a.style.display = "none";
    }
  }
}),
  window.addEventListener(
    "keydown",
    (e) => {
      if (!["INPUT", "TEXTAREA"].includes(document.activeElement.tagName))
        return isMiniPlayerActive && "f" === e.key.toLowerCase()
          ? (e.preventDefault(), void e.stopPropagation())
          : void (
              ("i" === e.key.toLowerCase() &&
                (document.getElementById("main-video-wrapper") ||
                  (console.warn(
                    "toggleMiniPlayer via keydown: main-video-wrapper tidak ditemukan",
                  ),
                  0)) &&
                toggleMiniPlayer()) ||
              
              
              
              ("n" === e.key.toLowerCase() &&
                !e.ctrlKey &&
                !e.altKey &&
                !isTransitioningNext &&
                !isRecovering &&
                document.getElementById("main-video-wrapper") &&
                (e.preventDefault(),
                e.stopPropagation(),
                window.skipToNextVideo && window.skipToNextVideo()))
            );
    },
    !0,
  ),
  window.addEventListener(
    "dblclick",
    (e) => {
      isMiniPlayerActive &&
        e.target.closest("#main-video-wrapper") &&
        (e.preventDefault(), e.stopPropagation());
    },
    !0,
  ),
  window.meelMiniPlayerPopstate({
    isActive: () => isMiniPlayerActive,
    watchUrl: () => watchUrl,
    onExit: () => toggleMiniPlayer(),
  }));
