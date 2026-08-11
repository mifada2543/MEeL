/* mini-player.js — Mode mini-player */
let isMiniPlayerActive = !1,
  watchUrl = window.location.href,
  savedWatchScrollY = 0,
  miniShell = null;
function setNavbarSearchTarget(e) {
  (["v-search-watch", "v-search-mobile"].forEach((t) => {
    const n = document.getElementById(t);
    n && n.setAttribute("hx-target", e);
  }),
    document
      .querySelectorAll('button[hx-include="#v-search-watch"]')
      .forEach((t) => t.setAttribute("hx-target", e)));
}
function buildMiniShell(e) {
  if (!e) {
    console.error("buildMiniShell: main-video-wrapper tidak ditemukan");
    return null;
  }
  const t = document.createElement("div");
  t.id = "mini-player-shell";
  const n = document.createElement("button");
  ((n.id = "mini-expand-btn"),
    (n.title = "Perlebar player"),
    (n.innerHTML =
      '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M3 3h7v2H5v5H3V3zm11 0h7v7h-2V5h-5V3zM3 14h2v5h5v2H3v-7zm16 5h-5v2h7v-7h-2v5z"/></svg>'),
    n.addEventListener("click", (e) => {
      (e.stopPropagation(), toggleMiniPlayer());
    }));
  const o = document.createElement("button");
  ((o.id = "mini-close-btn"),
    (o.title = "Tutup mini player"),
    (o.innerHTML =
      '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>'),
    o.addEventListener("click", (e) => {
      (e.stopPropagation(), closeMiniPlayer());
    }),
    t.appendChild(e),
    t.appendChild(n),
    t.appendChild(o));
  const l = videoTitle || "",
    a = videoUploader || "",
    r = document.createElement("div");
  return (
    (r.id = "mini-player-info"),
    (r.title = "Kembali ke video"),
    (r.innerHTML = `\n    <div style="flex:1;min-width:0;">\n      <div id="mini-info-title">${l}</div>\n      <div id="mini-info-uploader">${a}</div>\n    </div>\n  `),
    r.addEventListener("click", () => toggleMiniPlayer()),
    t.appendChild(r),
    t
  );
}
function closeMiniPlayer() {
  isMiniPlayerActive &&
    (player && player.pause(), (window.location.href = "index.php"));
}
function updateMiniPlayerInfo(e, t) {
  let n = document.getElementById("mini-info-title"),
    o = document.getElementById("mini-info-uploader");
  if (!n || !o) {
    const l = document.getElementById("mini-player-info");
    if (l) {
      l.innerHTML = `\n    <div style="flex:1;min-width:0;">\n      <div id="mini-info-title">${e || ""}</div>\n      <div id="mini-info-uploader">${t || ""}</div>\n    </div>\n  `;
      return;
    }
    return;
  }
  ((n.textContent = e || ""), (o.textContent = t || ""));
}
function attachMiniPlayerVideoCardListeners(e) {
  e &&
    e.querySelectorAll('a[href*="watch.php"]').forEach((e) => {
      e.dataset.miniIntercepted ||
        ((e.dataset.miniIntercepted = "1"),
        e.addEventListener("click", async (t) => {
          if (!isMiniPlayerActive) return;
          t.preventDefault();
          autoNextEnabled = false;
          localStorage.setItem("meel_autonext_enabled", "false");
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
              p = l.id || new URL(n).searchParams.get("id") || "";
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
              window.htmx && htmx.process(document.body),
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
    (miniShell && (miniShell.remove(), (miniShell = null)),
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
          videoWrapper.style.removeProperty("height")),
        (miniShell = buildMiniShell(videoWrapper)),
        videoWrapper.classList.add("mini-player-mode"));
      const l = document.getElementById("video-glow-canvas");
      (l && ((l.style.display = "none"), l.classList.remove("glow-active")),
        glowRAF && (cancelAnimationFrame(glowRAF), (glowRAF = null)),
        glowNavbar &&
          glowNavbar.style.setProperty("--navbar-glow-color", "0,0,0"),
        document.body.appendChild(miniShell),
        (document.body.style.paddingBottom = "120px"),
        t && (t.style.display = "none"),
        n && (n.style.display = "none"),
        o && (o.style.display = "none"));
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
      miniShell = null;
      setNavbarSearchTarget("#recommendation-column");
      if (videoWrapper) {
        videoWrapper.classList.remove("mini-player-mode");
        videoWrapper.style.removeProperty("aspect-ratio");
        videoWrapper.style.removeProperty("height");
      }
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
