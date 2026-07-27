/* ============================================================
 * player-events.js — Orkestrasi event Plyr (play/pause/ended/dll),
 * resume-modal, ambient glow (navbar + fullscreen), auto-next video,
 * dan menu setting kustom (glow/loop toggle).
 *
 * CATATAN: fungsi ini sengaja TIDAK dipecah lebih jauh karena bagian
 * ambient-glow, resume-modal, dan auto-next-video saling berbagi
 * closure/variabel lokal (mis. glowStartFn/glowStopFn dipakai juga
 * oleh handler fullscreen). Memisahkannya paksa berisiko merusak
 * referensi closure tsb. Sudah diberi komentar penanda tiap bagian.
 * Depends on: state.js, recovery.js, vtt-sprites.js, gestures.js
 * ============================================================ */

function setupMeelPlayerEvents() {
  window.player = player;
  const e = document.getElementById("resume-modal"),
    t = document.getElementById("btn-resume"),
    n = document.getElementById("btn-restart"),
    o = document.getElementById("resume-time"),
    l = document.getElementById("resume-countdown");
  function a() {
    const e = document.getElementById("main-video-wrapper"),
      t = videoElement;
    if (!(e && t && t.videoWidth && t.videoHeight)) return;
    const n = t.videoWidth,
      o = t.videoHeight,
      l = (e, t) => (0 === t ? e : l(t, e % t)),
      a = l(n, o);
    (console.log(`[MEeL] Aspect ratio video: ${n / a}:${o / a} (${n}x${o})`),
      isMiniPlayerActive || (e.style.aspectRatio = `${n} / ${o}`));
  }
  (videoElement.readyState >= 1 && videoElement.videoWidth
    ? a()
    : videoElement.addEventListener("loadedmetadata", a, { once: !0 }),
    player.on("ready", (r) => {
      if (
        ("function" == typeof window.appendCustomSettings &&
          setTimeout(window.appendCustomSettings, 0),
        videoElement && !isHls && (videoElement.preload = "auto"),
        a(),
        vttSrc)
      )
        setTimeout(() => refreshVttSprites(vttSrc), 300);
      else {
        player.config.previewThumbnails.enabled = !1;
        const e = document.querySelector(".plyr__preview-thumb");
        e && (e.style.display = "none");
      }
      setTimeout(() => {
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
          t.parentNode.insertBefore(n, t.nextSibling),
          window.lucide && lucide.createIcons());
      }, 200);
      const i = localStorage.getItem(storageKeyVideo);
      if (isAutoRecovering && i)
        return (
          (isAutoRecovering = !1),
          (player.currentTime = parseFloat(i)),
          player.play().catch(() => {}),
          startStuckDetector(),
          void startPlaybackStartTimeout()
        );
      function s() {
        if (
          i &&
          parseFloat(i) > 10 &&
          (!player.duration || parseFloat(i) < player.duration - 10)
        ) {
          const a = Math.floor(i / 60),
            r = Math.floor(i % 60);
          (o && (o.innerText = `${a}:${r.toString().padStart(2, "0")}`),
            e && e.classList.remove("hidden"));
          let s = 15;
          const c = setInterval(() => {
              (s--,
                s > 0
                  ? l &&
                    (l.innerText = `Otomatis ulang dari awal dalam ${s}s...`)
                  : clearInterval(c));
            }, 1e3),
            d = setTimeout(() => {
              n && n.click();
            }, 15e3);
          (t &&
            (t.onclick = () => {
              (clearTimeout(d),
                clearInterval(c),
                (player.currentTime = parseFloat(i)),
                player.play(),
                e.classList.add("hidden"));
            }),
            n &&
              (n.onclick = () => {
                (clearTimeout(d),
                  clearInterval(c),
                  localStorage.removeItem(storageKeyVideo),
                  (player.currentTime = 0),
                  player.play(),
                  e.classList.add("hidden"));
              }));
        } else
          (player.play().catch(() => console.log("Menunggu interaksi user...")),
            startStuckDetector(),
            startPlaybackStartTimeout());
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
      if ((stopStuckDetector(), player.loop)) return;
      if (isTransitioningNext) return;
      ((isTransitioningNext = !0), (isRecovering = !0));
      const e = ++nextVideoTransitionId;
      localStorage.removeItem(storageKeyVideo);
      const t = document.querySelector(".rekomendasi-item");
      if (!t) return ((isTransitioningNext = !1), void (isRecovering = !1));
      const n = player.fullscreen.active || !!document.fullscreenElement;
      try {
        const o = await fetch(t.href),
          l = await o.text();
        if (e !== nextVideoTransitionId) return;
        const a = new DOMParser().parseFromString(l, "text/html");
        ((watchUrl = t.href),
          window.history.pushState({}, "", t.href),
          (document.title = a.title));
        const r = a.getElementById("main-video");
        if (!r) throw new Error("Video elemen tidak ditemukan");
        const i = r.getAttribute("data-src"),
          s = "true" === r.getAttribute("data-ishls"),
          c = r.getAttribute("data-poster"),
          d = r.getAttribute("data-vtt");
        ((videoId =
          new URL(t.href, window.location.href).searchParams.get("id") ||
          videoId),
          (storageKeyVideo = `video_pos_${videoId}`),
          (vttSrc = d));
        let p = {};
        (a.querySelectorAll("script:not([src])").forEach((e) => {
          const t = e.textContent.match(
            /window\.playerConfig\s*=\s*(\{[\s\S]*?\});/,
          );
          if (t)
            try {
              p = JSON.parse(t[1]);
            } catch (e) {}
        }),
          (videoTitle = p.title || ""),
          (videoUploader = p.uploader || ""),
          (window.playerConfig = {
            videoSrc: i,
            isHls: s,
            vttSrc: d,
            id: videoId,
            title: videoTitle,
            uploader: videoUploader,
          }),
          isMiniPlayerActive && updateMiniPlayerInfo(videoTitle, videoUploader),
          updateSearchExcludeId(videoId),
          ["watch-details-wrapper", "recommendation-column"].forEach((e) => {
            const t = document.getElementById(e),
              n = a.getElementById(e);
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
          (player.poster = c),
          s
            ? (!hls && window.Hls && Hls.isSupported()
                ? ((hls = new Hls(HLS_CONFIG)),
                  registerHlsErrorListener(hls),
                  hls.attachMedia(player.media))
                : hls &&
                  hls.media !== player.media &&
                  (hls.detachMedia(), hls.attachMedia(player.media)),
              hls.loadSource(i),
              videoElement.addEventListener(
                "loadedmetadata",
                function () {
                  var e = document.getElementById("main-video-wrapper");
                  e &&
                    videoElement &&
                    videoElement.videoWidth &&
                    videoElement.videoHeight &&
                    (e.style.aspectRatio =
                      videoElement.videoWidth + "/" + videoElement.videoHeight);
                },
                { once: !0 },
              ))
            : (hls && (hls.destroy(), (hls = null)),
              (player.media.src = i),
              player.media.load(),
              videoElement.addEventListener(
                "loadedmetadata",
                function () {
                  var e = document.getElementById("main-video-wrapper");
                  e &&
                    videoElement &&
                    videoElement.videoWidth &&
                    videoElement.videoHeight &&
                    (e.style.aspectRatio =
                      videoElement.videoWidth + "/" + videoElement.videoHeight);
                },
                { once: !0 },
              )));
        const u = player.play();
        if (
          (void 0 !== u &&
            u.catch((e) => {
              console.error("Autoplay dicegah oleh browser:", e);
            }),
          d)
        )
          setTimeout(() => refreshVttSprites(d), 300);
        else {
          player.config.previewThumbnails.enabled = !1;
          const e = document.querySelector(".plyr__preview-thumb");
          e && (e.style.display = "none");
        }
        n &&
          !player.fullscreen.active &&
          (player.fullscreen.toggle(),
          d &&
            (setTimeout(() => refreshVttSprites(d), 500),
            setTimeout(() => refreshVttSprites(d), 1500)));
      } catch (e) {
        (console.error("Gagal transisi seamless, fallback ke reload:", e),
          (window.location.href = t.href));
      } finally {
        e === nextVideoTransitionId &&
          ((isTransitioningNext = !1),
          (isRecovering = !1),
          startStuckDetector());
      }
    }),
    player.on("enterfullscreen", () => {
      (screen.orientation?.lock &&
        screen.orientation.lock("landscape").catch(() => {}),
        vttSrc && setTimeout(() => refreshVttSprites(vttSrc), 300));

      /* ── Ignore notch: force true fullscreen ── */
      document.body.classList.add("meel-fs-active");
      const e_fsWrap = document.getElementById("main-video-wrapper"),
        e_fsGlow = document.getElementById("video-glow-container");
      if (e_fsWrap) {
        e_fsWrap._meelSavedRatio = e_fsWrap.style.aspectRatio || "";
        e_fsWrap.style.setProperty("aspect-ratio", "unset", "important");
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

      /* ── Restore notch-ignoring overrides ── */
      document.body.classList.remove("meel-fs-active");
      const e_xsWrap = document.getElementById("main-video-wrapper"),
        e_xsGlow = document.getElementById("video-glow-container");
      if (e_xsWrap) {
        e_xsWrap.style.removeProperty("aspect-ratio");
        e_xsWrap.style.removeProperty("height");
        e_xsWrap.style.removeProperty("width");
        e_xsWrap.style.removeProperty("border-radius");
        if (e_xsWrap._meelSavedRatio)
          e_xsWrap.style.aspectRatio = e_xsWrap._meelSavedRatio;
        delete e_xsWrap._meelSavedRatio;
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
    }));
  const r = document.getElementById("video-glow-canvas");
  if (r && videoElement) {
    const e = document.createElement("canvas");
    ((e.width = GLOW_W), (e.height = GLOW_H));
    const t = e.getContext("2d", { willReadFrequently: !0 });
    ((r.width = GLOW_W), (r.height = GLOW_H));
    const n = r.getContext("2d");
    glowNavbar = document.querySelector("nav");
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
        if (glowNavbar) {
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
        e.querySelector("#plyr-setting-loop")?.remove());
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
        }),
        e.appendChild(t),
        e.appendChild(n),
        p(),
        u());
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
          "meel_glow_enabled",
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
          ((player.loop = !player.loop), u());
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
  setupMobileGestures();
}
