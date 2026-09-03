function setupMeelPlayerEvents() {
  window.player = player;
  
  function applyMeelVideoAspect(wrapper, videoW, videoH) {
    if (!wrapper || !videoW || !videoH) return;
    wrapper.style.aspectRatio = `${videoW} / ${videoH}`;
    if (videoW < videoH) {
      wrapper.style.maxHeight = "80vh";
      wrapper.style.width = "auto";
      wrapper.style.marginLeft = "auto";
      wrapper.style.marginRight = "auto";
    } else {
      wrapper.style.maxHeight = "";
      wrapper.style.width = "";
      wrapper.style.marginLeft = "";
      wrapper.style.marginRight = "";
    }
  }
  function a() {
    const e = document.getElementById("main-video-wrapper"),
      t = videoElement;
    if (!(e && t && t.videoWidth && t.videoHeight)) return;
    const n = t.videoWidth,
      o = t.videoHeight,
      l = (e, t) => (0 === t ? e : l(t, e % t)),
      a = l(n, o);
    console.log(`[MEeL] Aspect ratio video: ${n / a}:${o / a} (${n}x${o})`);
    if (!isMiniPlayerActive) applyMeelVideoAspect(e, n, o);
  }
  
  const AUTONEXT_COUNTDOWN = 5;
  function showAutoNextOverlay(e) {
    return new Promise((t) => {
      try {
        const n = document.getElementById("autonext-overlay");
        n && n.remove();
        
        const o =
          e
            .querySelector(".rec-title-text, .line-clamp-2, h5")
            ?.textContent?.trim() ||
          e.querySelector('[class*="line-clamp"]')?.textContent?.trim() ||
          e.querySelector("a[title]")?.getAttribute("title")?.trim() ||
          "";
        
        const l =
          e.querySelector(".rec-thumb-img")?.src ||
          e.querySelector('img[src*="thumbnail"]')?.src ||
          e.querySelector("img")?.src ||
          "";
        
        const a =
          e
            .querySelector('[class*="text-red-500"], [class*="text-red-600"]')
            ?.textContent?.trim() || "";
        const plyr = document.querySelector(".plyr");
        const r = plyr || document.getElementById("main-video-wrapper");
        if (!r) return void t(!1);
        const i = document.createElement("div");
        i.id = "autonext-overlay";
        const thumbHtml = l
          ? '<img src="' +
            l.replace(/"/g, "&quot;") +
            '" alt="" loading="lazy" onerror="this.style.display=\'none\'">'
          : '<div style="width:100%;height:100%;background:rgba(255,255,255,0.04);display:flex;align-items:center;justify-content:center"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" opacity="0.3"><rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"/><line x1="7" y1="2" x2="7" y2="22"/><line x1="17" y1="2" x2="17" y2="22"/><line x1="2" y1="12" x2="22" y2="12"/></svg></div>';
        i.innerHTML =
          '<div class="autonext-card"><div class="autonext-header"><span class="autonext-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>Up Next</span><span class="autonext-timer"><span class="countdown-num">' +
          AUTONEXT_COUNTDOWN +
          '</span>s</span></div><div class="autonext-body"><div class="autonext-thumb">' +
          thumbHtml +
          '</div><div class="autonext-info"><div class="autonext-title">' +
          o.replace(/"/g, "&quot;") +
          '</div><div class="autonext-uploader">' +
          a.replace(/"/g, "&quot;") +
          '</div></div></div><div class="autonext-actions"><button class="autonext-cancel">Batal</button></div></div>';
        r.appendChild(i);
        document.body.classList.add("meel-autonext-active");
        requestAnimationFrame(() => {
          i.classList.add("active");
        });
        let s = AUTONEXT_COUNTDOWN;
        const c = i.querySelector(".countdown-num"),
          d = i.querySelector(".autonext-cancel"),
          cleanup = () => {
            document.body.classList.remove("meel-autonext-active");
            i.classList.remove("active");
            setTimeout(() => {
              try {
                i.remove();
              } catch (e) {}
            }, 350);
          },
          p = setInterval(() => {
            try {
              if ((s--, c && (c.textContent = Math.max(0, s)), s <= 0)) {
                clearInterval(p);
                cleanup();
                t(!0);
              }
            } catch (e) {
              clearInterval(p);
              t(!1);
            }
          }, 1e3);
        d.addEventListener("click", () => {
          clearInterval(p);
          cleanup();
          t(!1);
        });
      } catch (err) {
        console.error("[MEeL] showAutoNextOverlay error:", err);
        document.body.classList.remove("meel-autonext-active");
        t(!1);
      }
    });
  }
  
  function ensureCustomControls() {
    if (!player?.elements?.controls) return;
    const e = player.elements.controls;
    if (e.querySelector('[data-plyr="meel-miniplayer"]')) return;
    const t = e.querySelector('[data-plyr="pip"]');
    if (!t) return;
    const n = document.createElement("button");
    ((n.className = "plyr__control"),
      n.setAttribute("data-plyr", "meel-miniplayer"),
      n.setAttribute("type", "button"),
      n.setAttribute("aria-label", "Mini Player"),
      (n.title = "Mini Player"),
      (n.innerHTML =
        '<i data-lucide="shrink" style="width:18px;height:18px;"></i>'),
      n.addEventListener("click", (e) => {
        (e.stopPropagation(), window.toggleMiniPlayer());
      }),
      t.parentNode.insertBefore(n, t.nextSibling));
    const o = document.createElement("button");
    ((o.className = "plyr__control"),
      o.setAttribute("data-plyr", "meel-next"),
      o.setAttribute("type", "button"),
      o.setAttribute("aria-label", "Video Berikutnya"),
      (o.title = "Video Berikutnya (N)"),
      (o.innerHTML =
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="5 4 15 12 5 20"/><line x1="19" y1="5" x2="19" y2="19"/></svg>'),
      o.addEventListener("click", (e) => {
        (e.stopPropagation(),
          !isTransitioningNext &&
            !isRecovering &&
            window.skipToNextVideo &&
            window.skipToNextVideo());
      }),
      n.parentNode.insertBefore(o, n.nextSibling));
    window.lucide && window.lucide.createIcons();
  }
  
  window.skipToNextVideo = async function (e, isManual = !0) {
    if (window.meelHealthAlertActive) return !1;
    const t = e || document.querySelector(".rekomendasi-item");
    if (!t) return !1;
    isManual &&
      ((autoNextEnabled = !1),
      localStorage.setItem(MEEL_KEYS.AUTONEXT_ENABLED, "false"),
      window.updateAutoNextMenuUI && window.updateAutoNextMenuUI());
    ((isTransitioningNext = !0), (isRecovering = !0));
    const n = ++nextVideoTransitionId;
    localStorage.removeItem(storageKeyVideo);
    const o = player
      ? player.fullscreen.active || !!document.fullscreenElement
      : !1;
    sessionStorage.setItem(MEEL_KEYS.AUTONAV, "1");
    try {
      const l = await fetch(t.href),
        a = await l.text();
      if (n !== nextVideoTransitionId) return !1;
      const r = new DOMParser().parseFromString(a, "text/html");
      ((watchUrl = t.href),
        window.history.pushState({}, "", t.href),
        (document.title = r.title));
      const i = r.getElementById("main-video");
      if (!i) throw new Error("Video elemen tidak ditemukan");
      const s = i.getAttribute("data-src"),
        c = "true" === i.getAttribute("data-ishls"),
        d = i.getAttribute("data-poster"),
        p = i.getAttribute("data-vtt");
      
      const subTracks = Array.from(
        i.querySelectorAll('track[kind="captions"]'),
      ).map((t) => ({
        src: t.getAttribute("src") || "",
        lang: t.getAttribute("srclang") || "und",
        label: t.getAttribute("label") || "",
      }));
      let u = {};
      (r.querySelectorAll("script:not([src])").forEach((e) => {
        const t = e.textContent.match(
          /window\.playerConfig\s*=\s*(\{[\s\S]*?\});/,
        );
        if (t)
          try {
            u = JSON.parse(t[1]);
          } catch (e) {}
      }),
        (videoId =
          u.id ||
          new URL(t.href, window.location.href).searchParams.get("id") ||
          (new URL(t.href, window.location.href).href.match(
            /[?&]id=(\d+)/,
          ) || [])[1] ||
          videoId),
        (storageKeyVideo = `video_pos_${videoId}`),
        (videoTitle = u.title || ""),
        (videoUploader = u.uploader || ""),
        (window.playerConfig = {
          videoSrc: s,
          isHls: c,
          vttSrc: p,
          id: videoId,
          title: videoTitle,
          uploader: videoUploader,
        }),
        (videoSrc = s),
        (isHls = c),
        (vttSrc = p),
        isMiniPlayerActive && updateMiniPlayerInfo(videoTitle, videoUploader),
        updateSearchExcludeId(videoId),
        ["watch-details-wrapper", "recommendation-column"].forEach((e) => {
          const t = document.getElementById(e),
            n = r.getElementById(e);
          t && n && (t.innerHTML = n.innerHTML);
        }),
        window.lucide && window.lucide.createIcons(),
        window.htmx && htmx.process(document.body),
        isMiniPlayerActive ||
          requestAnimationFrame(() => {
            const e = document.getElementById("desc-text"),
              t = document.getElementById("btn-read-more");
            e &&
              t &&
              e.scrollHeight > e.clientHeight &&
              t.classList.remove("hidden");
          }),
        (player.poster = d),
        c
          ? (!hls && window.Hls && Hls.isSupported()
              ? ((hls = new Hls(HLS_CONFIG)),
                registerHlsErrorListener(hls),
                hls.attachMedia(player.media))
              : hls &&
                hls.media !== player.media &&
                (hls.detachMedia(), hls.attachMedia(player.media)),
            hls.loadSource(s),
            videoElement.addEventListener(
              "loadedmetadata",
              function () {
                var e = document.getElementById("main-video-wrapper");
                e &&
                  videoElement &&
                  videoElement.videoWidth &&
                  videoElement.videoHeight &&
                  applyMeelVideoAspect(
                    e,
                    videoElement.videoWidth,
                    videoElement.videoHeight,
                  );
              },
              { once: !0 },
            ))
          : (hls && (hls.destroy(), (hls = null)),
            (player.media.src = s),
            player.media.load(),
            videoElement.addEventListener(
              "loadedmetadata",
              function () {
                var e = document.getElementById("main-video-wrapper");
                e &&
                  videoElement &&
                  videoElement.videoWidth &&
                  videoElement.videoHeight &&
                  applyMeelVideoAspect(
                    e,
                    videoElement.videoWidth,
                    videoElement.videoHeight,
                  );
              },
              { once: !0 },
            )));
      
      if (videoElement) {
        videoElement
          .querySelectorAll('track[kind="captions"]')
          .forEach((t) => t.remove());
        subTracks.forEach((st) => {
          const track = document.createElement("track");
          track.kind = "captions";
          track.src = st.src;
          track.srclang = st.lang;
          track.label = st.label;
          videoElement.appendChild(track);
        });
      }
      if (
        player &&
        player.captions &&
        typeof player.captions.update === "function"
      ) {
        try {
          player.captions.update();
        } catch (e) {
          console.error("[MEeL] captions update error:", e);
        }
      }
      const m = player.play();
      if (
        (void 0 !== m &&
          m.catch((e) => {
            console.error("Autoplay dicegah oleh browser:", e);
          }),
        p)
      )
        setTimeout(() => refreshVttSprites(p), 300);
      else {
        player.config.previewThumbnails.enabled = !1;
        const e = document.querySelector(".plyr__preview-thumb");
        e && (e.style.display = "none");
      }
      o &&
        !player.fullscreen.active &&
        (player.fullscreen.toggle(),
        p &&
          (setTimeout(() => refreshVttSprites(p), 500),
          setTimeout(() => refreshVttSprites(p), 1500)));
      return !0;
    } catch (e) {
      return (
        console.error("Gagal transisi seamless, fallback ke reload:", e),
        (window.location.href = t.href),
        !1
      );
    } finally {
      n === nextVideoTransitionId &&
        ((isTransitioningNext = !1), (isRecovering = !1), startStuckDetector());
      ensureCustomControls();
    }
  };
  (videoElement.readyState >= 1 && videoElement.videoWidth
    ? a()
    : videoElement.addEventListener("loadedmetadata", a, { once: !0 }),
    player.on("ready", (r) => {
      if (
        ("function" == typeof window.appendCustomSettings &&
          setTimeout(window.appendCustomSettings, 0),
        videoElement && !isHls && (videoElement.preload = "metadata"),
        a(),
        vttSrc)
      )
        setTimeout(() => refreshVttSprites(vttSrc), 300);
      else {
        player.config.previewThumbnails.enabled = !1;
        const e = document.querySelector(".plyr__preview-thumb");
        e && (e.style.display = "none");
      }
      setTimeout(ensureCustomControls, 200);
      const i = localStorage.getItem(storageKeyVideo);
      if (isAutoRecovering && i) {
        const savedPos = parseFloat(i);
        isAutoRecovering = !1;
        


        function doRestore() {
          player.currentTime = savedPos;
          player.play().catch(() => {});
          startStuckDetector();
          startPlaybackStartTimeout();
        }
        if (isHls && hls) {
          let restored = !1;
          const restoreTimeout = setTimeout(() => {
            restored || ((restored = !0), doRestore());
          }, 1e4);
          hls.on(Hls.Events.FRAG_BUFFERED, function onBuf() {
            if (restored) { hls.off(Hls.Events.FRAG_BUFFERED, onBuf); return; }
            try {
              const buf = videoElement.buffered;
              if (buf.length > 0 && buf.end(0) - (videoElement.currentTime || 0) >= 5) {
                clearTimeout(restoreTimeout);
                restored = !0;
                hls.off(Hls.Events.FRAG_BUFFERED, onBuf);
                doRestore();
              }
            } catch (_) {}
          });
          return;
        }
        doRestore();
        return;
      }
      function s() {
        7;
        if (
          window.meelResumeModal({
            storageKey: storageKeyVideo,
            durationMargin: 10,
            countdownPrefix: "Otomatis ulang dari awal dalam",
            onResume: (pos) => {
              player.currentTime = pos;
              player.play();
            },
            onRestart: () => {
              localStorage.removeItem(storageKeyVideo);
              player.currentTime = 0;
              player.play();
            },
          })
        )
          return;
        player.play().catch(() => console.log("Menunggu interaksi user..."));
        startStuckDetector();
        startPlaybackStartTimeout();
      }
      if (isHls && hls) {
        let e = !1;
        const t = setTimeout(() => {
          e || ((e = !0), s());
        }, 8e3);
        hls.on(Hls.Events.FRAG_BUFFERED, function n() {
          if (e) hls.off(Hls.Events.FRAG_BUFFERED, n);
          else
            try {
              const o = videoElement.buffered;
              o.length > 0 &&
                o.end(0) - (videoElement.currentTime || 0) >= 15 &&
                (clearTimeout(t),
                (e = !0),
                hls.off(Hls.Events.FRAG_BUFFERED, n),
                s());
            } catch (e) {}
        });
      } else s();
    }),
    player.on("controlsshown", () => {}),
    player.on("play", () => {
      (stopPlaybackStartTimeout(),
        (playbackStartTimestamp = Date.now()),
        (lastTimeUpdateTimestamp = Date.now()),
        player && (lastPlayTime = player.currentTime),
        startStuckDetector());
    }),
    player.on("playing", () => {
      ((hasEverPlayed = !0),
        stopPlaybackStartTimeout(),
        (lastTimeUpdateTimestamp = Date.now()),
        player && (lastPlayTime = player.currentTime),
        startStuckDetector());
    }),
    player.on("pause", () => {
      (stopStuckDetector(),
        stopWaitingTimeout(),
        stopPlaybackStartTimeout(),
        player.currentTime > 0 &&
          (localStorage.setItem(storageKeyVideo, player.currentTime),
          (lastLocalStorageSave = Date.now())));
    }),
    player.on("seeking", () => {
      const e = Date.now();
      ((lastTimeUpdateTimestamp = e),
        (lastLocalStorageSave = e),
        player &&
          (localStorage.setItem(storageKeyVideo, player.currentTime),
          (lastPlayTime = player.currentTime)));
    }),
    player.on("seeked", () => {
      ((lastTimeUpdateTimestamp = Date.now()),
        player && (lastPlayTime = player.currentTime));
    }),
    player.on("timeupdate", () => {
      if (player.currentTime > 0) {
        const e = Date.now();
        ((lastPlayTime = player.currentTime),
          (lastTimeUpdateTimestamp = e),
          e - lastLocalStorageSave >= LOCAL_STORAGE_THROTTLE_MS &&
            (localStorage.setItem(storageKeyVideo, player.currentTime),
            (lastLocalStorageSave = e)),
          playbackStartTimestamp > 0 &&
            e - playbackStartTimestamp > 5e3 &&
            ((recoveryDelay = 5e3), (playbackStartTimestamp = 0)));
      }
      stopWaitingTimeout();
    }),
    player.on("waiting", () => {
      startWaitingTimeout();
    }),
    player.on("playing", () => {
      stopWaitingTimeout();
    }),
    player.on("canplay", () => {
      stopWaitingTimeout();
    }),
    player.on("ended", async () => {
      
      if (window.meelHealthAlertActive) return;
      if ((stopStuckDetector(), player.loop)) return;
      if (isTransitioningNext) return;
      if (!autoNextEnabled) {
        stopPlaybackStartTimeout();
        return;
      }
      ((isTransitioningNext = !0), (isRecovering = !0));
      const e = ++nextVideoTransitionId;
      localStorage.removeItem(storageKeyVideo);
      const t = document.querySelector(".rekomendasi-item");
      if (!t) return ((isTransitioningNext = !1), void (isRecovering = !1));
      
      const g = await showAutoNextOverlay(t);
      if (!g) {
        autoNextEnabled = !1;
        localStorage.setItem(MEEL_KEYS.AUTONEXT_ENABLED, "false");
        ((isTransitioningNext = !1), (isRecovering = !1));
        stopPlaybackStartTimeout();
        return;
      }
      
      await window.skipToNextVideo(t, !1);
    }),
    player.on("enterfullscreen", () => {
      (screen.orientation?.lock &&
        screen.orientation.lock("landscape").catch(() => {}),
        vttSrc && setTimeout(() => refreshVttSprites(vttSrc), 300));
      document.body.classList.add("meel-fs-active");
      glowStopFn && glowStopFn(!0);
      const e_fsWrap = document.getElementById("main-video-wrapper"),
        e_fsGlow = document.getElementById("video-glow-container");
      if (e_fsWrap) {
        e_fsWrap._meelSavedRatio = e_fsWrap.style.aspectRatio || "";
        e_fsWrap._meelSavedMaxHeight = e_fsWrap.style.maxHeight || "";
        e_fsWrap._meelSavedWidth = e_fsWrap.style.width || "";
        e_fsWrap._meelSavedMarginL = e_fsWrap.style.marginLeft || "";
        e_fsWrap._meelSavedMarginR = e_fsWrap.style.marginRight || "";
        e_fsWrap.style.setProperty("aspect-ratio", "unset", "important");
        e_fsWrap.style.setProperty("max-height", "none", "important");
        e_fsWrap.style.setProperty("height", "100vh", "important");
        e_fsWrap.style.setProperty("width", "100vw", "important");
        e_fsWrap.style.setProperty("border-radius", "0", "important");
      }
      if (e_fsGlow) {
        e_fsGlow._meelSavedHeight = e_fsGlow.style.height || "";
        e_fsGlow._meelSavedWidth = e_fsGlow.style.width || "";
        e_fsGlow.style.setProperty("height", "100vh", "important");
        e_fsGlow.style.setProperty("width", "100vw", "important");
      }
      const e = player.elements.container,
        t = e ? e.querySelector(".plyr__video-wrapper") : null;
      if (e && t && videoElement) {
        const n = t.querySelector("#video-glow-canvas-fs");
        (n && n.remove(),
          (videoElement.style.position = "relative"),
          (videoElement.style.zIndex = "2"));
        const o = document.createElement("canvas");
        ((o.id = "video-glow-canvas-fs"),
          (o.width = GLOW_W),
          (o.height = GLOW_H),
          (o.style.cssText = [
            "position:absolute",
            "top:50%",
            "left:50%",
            "transform:translate3d(-50%,-50%,0) scale(1.0)",
            "width:100%",
            "height:100%",
            "pointer-events:none",
            "z-index:1",
            "filter:blur(60px) brightness(1.8) saturate(1.15)",
            "opacity:0",
            "will-change:transform,opacity",
            "transition:opacity 0.6s ease",
          ].join(";")),
          t.insertBefore(o, t.firstChild));
        const l = o.getContext("2d"),
          a = document.createElement("canvas");
        ((a.width = GLOW_W), (a.height = GLOW_H));
        const r = a.getContext("2d", { willReadFrequently: !0 }),
          i = new Float32Array(GLOW_W * GLOW_H * 4),
          s = new Float32Array(GLOW_W * GLOW_H * 4);
        let c = null,
          d = 0;
        const p = () => {
            if (!(videoElement.readyState < 2 || document.hidden))
              try {
                r.drawImage(videoElement, 0, 0, GLOW_W, GLOW_H);
                const e = r.getImageData(0, 0, GLOW_W, GLOW_H).data;
                i.set(e);
              } catch (e) {}
          },
          u = (timestamp) => {
            if (!c) return;
            if (timestamp - d >= GLOW_SAMPLE_INTERVAL) {
              d = timestamp;
              p();
            }
            for (let e = 0; e < s.length; e++)
              s[e] += GLOW_LERP_FACTOR * (i[e] - s[e]);
            const e = l.createImageData(GLOW_W, GLOW_H);
            for (let t = 0; t < s.length; t++) e.data[t] = Math.round(s[t]);
            l.putImageData(e, 0, 0);
            c = requestAnimationFrame(u);
          },
          m = () => {
            if (!glowEnabled || c) return;
            if (document.documentElement.getAttribute("data-theme") === "light") return;
            o.style.opacity = "0.6";
            p();
            d = 0;
            c = requestAnimationFrame(u);
          },
          y = () => {
            if (c) {
              cancelAnimationFrame(c);
              c = null;
            }
            o.style.opacity = "0";
            i.fill(0);
            s.fill(0);
            l.clearRect(0, 0, GLOW_W, GLOW_H);
          },
          v = () => {
            if (c) {
              cancelAnimationFrame(c);
              c = null;
            }
          };
        ((e._fsGlowStart = m),
          (e._fsGlowStop = y),
          (e._fsGlowPause = v),
          player.on("play", m),
          player.on("playing", m),
          player.on("pause", v),
          player.on("ended", y),
          videoElement.paused || videoElement.ended || m());
      }
    }),
    player.on("exitfullscreen", () => {
      screen.orientation?.unlock && screen.orientation.unlock();
      
      document.body.classList.remove("meel-fs-active");
      const e_xsWrap = document.getElementById("main-video-wrapper"),
        e_xsGlow = document.getElementById("video-glow-container");
      if (e_xsWrap) {
        e_xsWrap.style.removeProperty("aspect-ratio");
        e_xsWrap.style.removeProperty("max-height");
        e_xsWrap.style.removeProperty("height");
        e_xsWrap.style.removeProperty("width");
        e_xsWrap.style.removeProperty("border-radius");
        if (e_xsWrap._meelSavedRatio)
          e_xsWrap.style.aspectRatio = e_xsWrap._meelSavedRatio;
        if (e_xsWrap._meelSavedMaxHeight)
          e_xsWrap.style.maxHeight = e_xsWrap._meelSavedMaxHeight;
        if (e_xsWrap._meelSavedWidth)
          e_xsWrap.style.width = e_xsWrap._meelSavedWidth;
        if (e_xsWrap._meelSavedMarginL)
          e_xsWrap.style.marginLeft = e_xsWrap._meelSavedMarginL;
        if (e_xsWrap._meelSavedMarginR)
          e_xsWrap.style.marginRight = e_xsWrap._meelSavedMarginR;
        delete e_xsWrap._meelSavedRatio;
        delete e_xsWrap._meelSavedMaxHeight;
        delete e_xsWrap._meelSavedWidth;
        delete e_xsWrap._meelSavedMarginL;
        delete e_xsWrap._meelSavedMarginR;
      }
      if (e_xsGlow) {
        e_xsGlow.style.removeProperty("height");
        e_xsGlow.style.removeProperty("width");
        delete e_xsGlow._meelSavedHeight;
        delete e_xsGlow._meelSavedWidth;
      }
      const e = player.elements.container;
      if (e) {
        (e._fsGlowStop && e._fsGlowStop(),
          e._fsGlowStart && player.off("play", e._fsGlowStart),
          e._fsGlowStart && player.off("playing", e._fsGlowStart),
          e._fsGlowPause && player.off("pause", e._fsGlowPause),
          e._fsGlowStop && player.off("ended", e._fsGlowStop),
          delete e._fsGlowStart,
          delete e._fsGlowStop,
          delete e._fsGlowPause);
        const t = e.querySelector(".plyr__video-wrapper"),
          n = t ? t.querySelector("#video-glow-canvas-fs") : null;
        (n && n.remove(),
          videoElement &&
            ((videoElement.style.position = ""),
            (videoElement.style.zIndex = "")));
      }
      glowEnabled &&
        videoElement &&
        !videoElement.paused &&
        !videoElement.ended &&
        !glowRAF &&
        glowStartFn &&
        glowStartFn();
    }));
  const r = document.getElementById("video-glow-canvas");
  if (r && videoElement) {
    const e = document.createElement("canvas");
    ((e.width = GLOW_W), (e.height = GLOW_H));
    const t = e.getContext("2d", { willReadFrequently: !0 });
    ((r.width = GLOW_W), (r.height = GLOW_H));
    const n = r.getContext("2d");
    glowNavbar = document.querySelector("nav");
    let glowLastNavbarUpdate = 0;
    const o = () => {
        if (!(videoElement.readyState < 2 || document.hidden))
          try {
            t.drawImage(videoElement, 0, 0, GLOW_W, GLOW_H);
            const e = t.getImageData(0, 0, GLOW_W, GLOW_H).data;
            glowTargetData.set(e);
          } catch (e) {}
      },
      a = (timestamp) => {
        if (!glowRAF) return;
        if (document.documentElement.getAttribute("data-theme") === "light") {
          s();
          return;
        }
        if (r.offsetParent === null) {
          glowRAF = requestAnimationFrame(a);
          return;
        }
        if (timestamp - glowLastSampleTime >= GLOW_SAMPLE_INTERVAL) {
          glowLastSampleTime = timestamp;
          o();
        }
        for (let e = 0; e < glowCurData.length; e++)
          glowCurData[e] +=
            (glowTargetData[e] - glowCurData[e]) * GLOW_LERP_FACTOR;
        const e = n.createImageData(GLOW_W, GLOW_H);
        for (let t = 0; t < glowCurData.length; t++)
          e.data[t] = Math.round(glowCurData[t]);
        n.putImageData(e, 0, 0);
        if (glowNavbar && timestamp - glowLastNavbarUpdate >= 250) {
          glowLastNavbarUpdate = timestamp;
          let e = 0,
            t = 0,
            n = 0;
          for (let o = 0; o < GLOW_W; o++) {
            const l = 4 * o;
            ((e += glowCurData[l]),
              (t += glowCurData[l + 1]),
              (n += glowCurData[l + 2]));
          }
          const o = Math.round(e / GLOW_W),
            l = Math.round(t / GLOW_W),
            a = Math.round(n / GLOW_W);
          glowNavbar.style.setProperty("--navbar-glow-color", `${o},${l},${a}`);
        }
        glowRAF = requestAnimationFrame(a);
      },
      i = () => {
        if (!glowEnabled || glowRAF) return;
        if (player && player.fullscreen && player.fullscreen.active) return;
        if (document.documentElement.getAttribute("data-theme") === "light") return;
        r.classList.add("glow-active");
        o();
        glowLastSampleTime = 0;
        glowRAF = requestAnimationFrame(a);
      },
      s = (e = !1) => {
        if (glowRAF) {
          cancelAnimationFrame(glowRAF);
          glowRAF = null;
        }
        r.classList.remove("glow-active");
        glowNavbar &&
          glowNavbar.style.setProperty("--navbar-glow-color", "0,0,0");
        e &&
          (glowTargetData.fill(0),
          glowCurData.fill(0),
          n.clearRect(0, 0, GLOW_W, GLOW_H));
      },
      c = () => {
        if (glowRAF) {
          cancelAnimationFrame(glowRAF);
          glowRAF = null;
        }
      };
    ((glowStartFn = i), (glowStopFn = s));
    const d = (e) =>
        `${e ? "On" : "Off"} <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="display:${e ? "inline-block" : "none"};vertical-align:middle;margin-left:4px"><polyline points="20 6 9 17 4 12"/></svg>`,
      p = () => {
        const e = document.getElementById("plyr-setting-glow");
        if (!e) return;
        e.setAttribute("aria-checked", glowEnabled ? "true" : "false");
        const t = e.querySelector(".plyr__menu__value");
        t && (t.innerHTML = d(glowEnabled));
      },
      u = () => {
        const e = document.getElementById("plyr-setting-loop");
        if (!e) return;
        const t = !!player && player.loop;
        e.setAttribute("aria-checked", t ? "true" : "false");
        const n = e.querySelector(".plyr__menu__value");
        n && (n.innerHTML = d(t));
      };
    ((window.updateLoopMenuUI = u), (window.updateGlowMenuUI = p));
    
    const S = (e) =>
      `${e ? "On" : "Off"} <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="display:${e ? "inline-block" : "none"};vertical-align:middle;margin-left:4px"><polyline points="20 6 9 17 4 12"/></svg>`;
    window.updateAutoNextMenuUI = () => {
      const e = document.getElementById("plyr-setting-autonext");
      if (!e) return;
      e.setAttribute("aria-checked", autoNextEnabled ? "true" : "false");
      const t = e.querySelector(".plyr__menu__value");
      t && (t.innerHTML = S(autoNextEnabled));
    };
    window.toggleAutoNext = () => {
      ((autoNextEnabled = !autoNextEnabled),
        localStorage.setItem(
          MEEL_KEYS.AUTONEXT_ENABLED,
          autoNextEnabled ? "true" : "false",
        ),
        window.updateAutoNextMenuUI(),
        autoNextEnabled &&
          player &&
          ((player.loop = false),
          window.updateLoopMenuUI && window.updateLoopMenuUI()),
        h(
          autoNextEnabled
            ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>'
            : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="2" y1="2" x2="22" y2="22"/><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>',
          autoNextEnabled ? "Auto Next On" : "Auto Next Off",
        ));
    };

    const m = () => {
      if (!player?.elements?.container) return null;
      const e = player.elements?.settings?.panels?.home;
      if (e) return e.querySelector('[role="menu"]') || e;
      const t = player.elements.container;
      return (
        t.querySelector('.plyr__menu__container [id$="-home"] [role="menu"]') ||
        t.querySelector('.plyr__menu__container [id$="-home"]') ||
        t.querySelector('.plyr__menu__container [role="menu"]') ||
        t.querySelector('.plyr__menu [role="menu"]')
      );
    };
    let y = !1;
    const v = () => {
      const e = m();
      if (!e) return;
      (e.querySelector("#plyr-setting-glow")?.remove(),
        e.querySelector("#plyr-setting-loop")?.remove(),
        e.querySelector("#plyr-setting-autonext")?.remove());
      const t = document.createElement("button");
      ((t.type = "button"),
        (t.className = "plyr__control"),
        t.setAttribute("role", "menuitemcheckbox"),
        (t.id = "plyr-setting-glow"),
        (t.innerHTML =
          '<span>Ambient Glow</span><span class="plyr__menu__value"></span>'),
        t.addEventListener("click", (e) => {
          (e.stopPropagation(), window.toggleGlow());
        }));
      const n = document.createElement("button");
      ((n.type = "button"),
        (n.className = "plyr__control"),
        n.setAttribute("role", "menuitemcheckbox"),
        (n.id = "plyr-setting-loop"),
        (n.innerHTML =
          '<span>Loop Playback</span><span class="plyr__menu__value"></span>'),
        n.addEventListener("click", (e) => {
          (e.stopPropagation(), window.toggleLoop());
        }));
      const o = document.createElement("button");
      ((o.type = "button"),
        (o.className = "plyr__control"),
        o.setAttribute("role", "menuitemcheckbox"),
        (o.id = "plyr-setting-autonext"),
        (o.innerHTML =
          '<span>Auto Next</span><span class="plyr__menu__value"></span>'),
        o.addEventListener("click", (e) => {
          (e.stopPropagation(), window.toggleAutoNext());
        }),
        e.appendChild(t),
        e.appendChild(n),
        e.appendChild(o),
        p(),
        u(),
        window.updateAutoNextMenuUI());
    };
    window.appendCustomSettings = () => {
      if (y) return;
      if (!player?.elements?.container) return;
      y = !0;
      const e = player.elements.container.querySelector(
          '[data-plyr="settings"]',
        ),
        t = () => setTimeout(v, 0);
      e &&
        (e.addEventListener("click", t),
        e.addEventListener("touchend", t, { passive: !0 }));
    };
    let g = null;
    const h = (e, t) => {
      const n = player?.elements?.container;
      if (!n) return;
      const o = n.querySelector(".meel-toggle-toast");
      (o && o.remove(), g && (clearTimeout(g), (g = null)));
      const l = document.createElement("div");
      ((l.className = "meel-toggle-toast"),
        (l.innerHTML = `${e}<span>${t}</span>`),
        n.appendChild(l),
        (g = setTimeout(() => l.remove(), 1900)));
    };
    ((window.toggleGlow = () => {
      if (
        ((glowEnabled = !glowEnabled),
        localStorage.setItem(
          MEEL_KEYS.GLOW_ENABLED,
          glowEnabled ? "true" : "false",
        ),
        p(),
        h(
          glowEnabled
            ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>'
            : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="2" y1="2" x2="22" y2="22"/><path d="M9.58 4.18A8 8 0 0 1 20 12c0 1.49-.41 2.88-1.12 4.08M6.51 6.51A8 8 0 0 0 4 12c0 4.42 3.58 8 8 8a8 8 0 0 0 5.49-2.18"/></svg>',
          glowEnabled ? "Ambient Glow On" : "Ambient Glow Off",
        ),
        glowEnabled)
      ) {
        videoElement &&
          !videoElement.paused &&
          !videoElement.ended &&
          glowStartFn &&
          glowStartFn();
        const e = player?.elements?.container;
        e &&
          e._fsGlowStart &&
          !videoElement.paused &&
          !videoElement.ended &&
          e._fsGlowStart();
      } else {
        glowStopFn && glowStopFn(!0);
        const e = player?.elements?.container;
        e && e._fsGlowStop && e._fsGlowStop();
      }
    }),
      (window.toggleLoop = () => {
        if (player) {
          ((player.loop = !player.loop),
            player.loop &&
              ((autoNextEnabled = false),
              localStorage.setItem(MEEL_KEYS.AUTONEXT_ENABLED, "false"),
              window.updateAutoNextMenuUI && window.updateAutoNextMenuUI()),
            u());
          const e = player.loop;
          h(
            e
              ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>'
              : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="2" y1="2" x2="22" y2="22"/><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h11"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>',
            e ? "Loop On" : "Loop Off",
          );
        }
      }),
      player.on("play", i),
      player.on("playing", i),
      player.on("pause", c),
      player.on("ended", () => s(!0)),
      videoElement.paused || videoElement.ended || i());
  }
  
  if (!window._meelClickRekomGuard) {
    window._meelClickRekomGuard = !0;
    document.addEventListener("click", function (e) {
      const link = e.target.closest(".rekomendasi-item");
      if (!link || !link.href) return;
      if (link.href === window.location.href) return;
      autoNextEnabled = false;
      localStorage.setItem(MEEL_KEYS.AUTONEXT_ENABLED, "false");
    });
  }

  setupMobileGestures();
}
