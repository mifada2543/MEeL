/* gestures.js — Gestur mobile: tap, swipe-to-seek, double-tap ripple,
   long-press 2x speed, swipe-down mini-player close. */
function setupMobileGestures() {
  if (!isTouchDevice) return;
  var plyr = document.querySelector(".plyr");
  if (!plyr) return;

  /* ── State ── */
  var controlsVisible = false,
    hideTimer = null,
    lastTapTime = 0,
    tapPending = false,
    tapTimer = null,
    tapZone = null;

  /* Swipe-to-seek state */
  var swipeState = null; // { startX, startY, startTime, seeking, locked }
  var SWIPE_THRESHOLD = 30;   // px sebelum dianggap swipe
  var SWIPE_SEEK_PX = 200;    // px = ~10 detik

  /* Long-press state */
  var longPressTimer = null,
    longPressActive = false,
    originalPlaybackRate = 1;

  /* ── Controls visibility ── */
  function showControls() {
    controlsVisible = true;
    plyr.classList.add("plyr--hide-controls");
    plyr.classList.remove("plyr--hide-controls");
    if (player && player.elements && player.elements.controls) {
      player.elements.controls.style.opacity = "";
      player.elements.controls.style.pointerEvents = "";
    }
    var overlay = plyr.querySelector(".plyr__control--overlaid");
    if (overlay) overlay.style.opacity = "";
    resetHideTimer();
  }

  function hideControls() {
    controlsVisible = false;
    clearTimeout(hideTimer);
    if (player && player.elements && player.elements.controls) {
      player.elements.controls.style.opacity = "0";
      player.elements.controls.style.pointerEvents = "none";
    }
    var overlay = plyr.querySelector(".plyr__control--overlaid");
    if (overlay) overlay.style.opacity = "0";
  }

  function resetHideTimer() {
    clearTimeout(hideTimer);
    hideTimer = setTimeout(function () {
      if (controlsVisible) hideControls();
    }, 3000);
  }

  /* ── Double-tap ripple animation ── */
  function createTapRipple(x, y, type) {
    var container = plyr;
    var ripple = document.createElement("div");
    ripple.className = "meel-tap-ripple meel-tap-ripple-" + type;

    /* Icon */
    var icon =
      type === "rewind"
        ? '<svg viewBox="0 0 24 24" width="32" height="32"><path d="M11 18V6l-8.5 6 8.5 6zm.5-6l8.5 6V6l-8.5 6z" fill="currentColor"/></svg>'
        : '<svg viewBox="0 0 24 24" width="32" height="32"><path d="M4 18l8.5-6L4 6v12zm9-12v12l8.5-6L13 6z" fill="currentColor"/></svg>';

    ripple.innerHTML =
      '<div class="meel-tap-ripple-circle"></div>' +
      '<div class="meel-tap-ripple-icon">' +
      icon +
      "</div>";

    /* Position */
    var rect = container.getBoundingClientRect();
    ripple.style.left = x - rect.left + "px";
    ripple.style.top = y - rect.top + "px";

    container.appendChild(ripple);

    /* Trigger animation */
    requestAnimationFrame(function () {
      ripple.classList.add("meel-tap-ripple-active");
    });

    /* Remove after animation */
    setTimeout(function () {
      if (ripple.parentNode) ripple.parentNode.removeChild(ripple);
    }, 700);
  }

  /* ── Swipe-to-seek ── */
  function createSeekOverlay() {
    var existing = document.getElementById("meel-seek-overlay");
    if (existing) return existing;

    var overlay = document.createElement("div");
    overlay.id = "meel-seek-overlay";
    overlay.innerHTML =
      '<div class="meel-seek-preview">' +
      '<div class="meel-seek-time" id="meel-seek-time">0:00</div>' +
      '<div class="meel-seek-delta" id="meel-seek-delta"></div>' +
      "</div>";
    plyr.appendChild(overlay);
    return overlay;
  }

  function updateSeekOverlay(deltaSeconds, currentTime) {
    var overlay = createSeekOverlay();
    var timeEl = document.getElementById("meel-seek-time");
    var deltaEl = document.getElementById("meel-seek-delta");

    var targetTime = Math.max(0, Math.min(currentTime + deltaSeconds, player.duration || 0));
    timeEl.textContent = formatTime(targetTime);

    var sign = deltaSeconds >= 0 ? "+" : "";
    deltaEl.textContent = sign + Math.round(deltaSeconds) + "s";

    overlay.classList.add("meel-seek-overlay-active");
  }

  function hideSeekOverlay() {
    var overlay = document.getElementById("meel-seek-overlay");
    if (overlay) overlay.classList.remove("meel-seek-overlay-active");
  }

  function formatTime(seconds) {
    var m = Math.floor(seconds / 60);
    var s = Math.floor(seconds % 60);
    return m + ":" + (s < 10 ? "0" : "") + s;
  }

  /* ── Long-press 2x speed ── */
  function startLongPress() {
    clearTimeout(longPressTimer);
    longPressTimer = setTimeout(function () {
      if (player && !player.paused) {
        longPressActive = true;
        originalPlaybackRate = player.playbackRate || 1;
        player.playbackRate = 2;
        showSpeedIndicator();
      }
    }, 500);
  }

  function endLongPress() {
    clearTimeout(longPressTimer);
    if (longPressActive) {
      longPressActive = false;
      if (player) player.playbackRate = originalPlaybackRate;
      hideSpeedIndicator();
    }
  }

  function showSpeedIndicator() {
    var existing = document.getElementById("meel-speed-indicator");
    if (existing) return;
    var el = document.createElement("div");
    el.id = "meel-speed-indicator";
    el.className = "meel-speed-indicator";
    el.textContent = "2x";
    plyr.appendChild(el);
    requestAnimationFrame(function () {
      el.classList.add("meel-speed-indicator-active");
    });
  }

  function hideSpeedIndicator() {
    var el = document.getElementById("meel-speed-indicator");
    if (el) {
      el.classList.remove("meel-speed-indicator-active");
      setTimeout(function () {
        if (el.parentNode) el.parentNode.removeChild(el);
      }, 300);
    }
  }

  /* ── Main touch handler ── */
  plyr.addEventListener(
    "touchstart",
    function (ev) {
      var now = Date.now();
      var target = ev.target;

      /* Ignore touches on controls/menu/volume/progress */
      if (
        target.closest(".plyr__controls") ||
        target.closest(".plyr__control--overlaid") ||
        target.closest(".plyr__menu") ||
        target.closest(".plyr__volume") ||
        target.closest(".plyr__progress")
      ) {
        if (controlsVisible) resetHideTimer();
        return;
      }

      var touch = ev.touches[0] || ev.changedTouches[0];
      if (!touch) return;

      var rect = plyr.getBoundingClientRect();
      var x = touch.clientX;
      var y = touch.clientY;
      var relX = x - rect.left;
      var zone = relX < 0.4 * rect.width ? "left" : relX > 0.6 * rect.width ? "right" : "center";

      /* ── Double-tap detection ── */
      if (now - lastTapTime < 300 && tapPending) {
        /* Double tap! */
        clearTimeout(tapTimer);
        tapPending = false;
        ev.preventDefault();
        ev.stopPropagation();

        if (zone === "left") {
          player && player.rewind(10);
          tampilkanSisiIndikator("rewind", "-10s");
          createTapRipple(x, y, "rewind");
        } else if (zone === "right") {
          player && player.forward(10);
          tampilkanSisiIndikator("forward", "+10s");
          createTapRipple(x, y, "forward");
        }
        lastTapTime = 0;
        return;
      }

      /* ── Start swipe tracking ── */
      swipeState = {
        startX: x,
        startY: y,
        startTime: now,
        seeking: false,
        locked: false,
      };

      /* ── Long-press detection (center zone only) ── */
      if (zone === "center") {
        startLongPress();
      }

      /* ── First tap — wait for potential double-tap ── */
      lastTapTime = now;
      tapPending = true;
      tapZone = zone;
      clearTimeout(tapTimer);
      tapTimer = setTimeout(function () {
        if (!tapPending) return;
        tapPending = false;

        /* Single tap action */
        if (tapZone === "left" || tapZone === "right") {
          controlsVisible ? hideControls() : showControls();
        } else {
          /* center */
          if (controlsVisible) {
            if (player) {
              player.paused ? player.play() : player.pause();
              resetHideTimer();
            }
          } else {
            showControls();
          }
        }
      }, 300);
    },
    { passive: false },
  );

  /* ── Touch move — swipe-to-seek ── */
  plyr.addEventListener(
    "touchmove",
    function (ev) {
      if (!swipeState) return;
      var touch = ev.touches[0];
      if (!touch) return;

      var dx = touch.clientX - swipeState.startX;
      var dy = touch.clientY - swipeState.startY;

      /* Lock direction on first significant move */
      if (!swipeState.locked) {
        if (Math.abs(dx) > SWIPE_THRESHOLD || Math.abs(dy) > SWIPE_THRESHOLD) {
          swipeState.locked = true;
          swipeState.seeking = Math.abs(dx) > Math.abs(dy);
        }
        return;
      }

      if (!swipeState.seeking) return;

      /* Cancel long-press if swiping */
      endLongPress();
      clearTimeout(tapTimer);
      tapPending = false;

      ev.preventDefault();

      /* Calculate seek delta */
      var seekDelta = (dx / SWIPE_SEEK_PX) * 10;
      var currentTime = player ? player.currentTime || 0 : 0;
      updateSeekOverlay(seekDelta, currentTime);
    },
    { passive: false },
  );

  /* ── Touch end ── */
  plyr.addEventListener(
    "touchend",
    function (ev) {
      /* End long-press */
      endLongPress();

      /* End swipe-to-seek */
      if (swipeState && swipeState.seeking) {
        var touch = ev.changedTouches[0];
        if (touch) {
          var dx = touch.clientX - swipeState.startX;
          var seekDelta = (dx / SWIPE_SEEK_PX) * 10;
          if (player && Math.abs(seekDelta) > 0.5) {
            player.currentTime = Math.max(
              0,
              Math.min(player.currentTime + seekDelta, player.duration || 0),
            );
          }
        }
        hideSeekOverlay();
      }
      swipeState = null;
    },
    { passive: true },
  );

  plyr.addEventListener("touchcancel", function () {
    endLongPress();
    hideSeekOverlay();
    swipeState = null;
  });

  /* ── Prevent native dblclick ── */
  plyr.addEventListener(
    "dblclick",
    function (ev) {
      if (Date.now() - lastTapTime < 1000) {
        ev.preventDefault();
        ev.stopPropagation();
      }
    },
    true,
  );

  /* ── Volume slider swipe (keep existing) ── */
  (function () {
    var startY = null,
      startVal = null,
      slider = null;
    document.addEventListener(
      "touchstart",
      function (ev) {
        var s = ev.target.closest(".plyr__volume input[type='range']");
        if (s && controlsVisible) {
          startY = ev.touches[0].clientY;
          startVal = parseFloat(s.value);
          slider = s;
          clearTimeout(hideTimer);
          ev.preventDefault();
        }
      },
      { passive: false },
    );
    document.addEventListener(
      "touchmove",
      function (ev) {
        if (!slider || startY === null) return;
        ev.preventDefault();
        var delta = ((startY - ev.touches[0].clientY) / 120) * (parseFloat(slider.max) - parseFloat(slider.min));
        var val = Math.min(parseFloat(slider.max), Math.max(parseFloat(slider.min), startVal + delta));
        slider.value = val;
        slider.dispatchEvent(new Event("input", { bubbles: true }));
        if (player) player.volume = val;
      },
      { passive: false },
    );
    document.addEventListener("touchend", function () {
      if (slider) resetHideTimer();
      startY = null;
      startVal = null;
      slider = null;
    });
  })();

  /* ── Player events ── */
  if (player) {
    player.on("play", function () {
      resetHideTimer();
    });
    player.on("pause", function () {
      clearTimeout(hideTimer);
      controlsVisible = true;
      if (player.elements && player.elements.controls) {
        player.elements.controls.style.opacity = "";
        player.elements.controls.style.pointerEvents = "";
      }
    });
  }
}

/* ── Swipe-down mini-player close ── */
(function () {
  if (!isTouchDevice) return;
  var miniState = null;
  document.addEventListener(
    "touchstart",
    function (ev) {
      var shell = document.getElementById("mini-player-shell");
      if (!shell || shell.style.display === "none") return;
      var target = ev.target;
      /* Ignore if touching buttons/controls */
      if (
        target.closest("button") ||
        target.closest(".plyr__controls") ||
        target.closest(".plyr__menu")
      )
        return;
      var touch = ev.touches[0];
      miniState = {
        startX: touch.clientX,
        startY: touch.clientY,
        shell: shell,
      };
    },
    { passive: true },
  );
  document.addEventListener(
    "touchend",
    function (ev) {
      if (!miniState) return;
      var touch = ev.changedTouches[0];
      if (!touch) {
        miniState = null;
        return;
      }
      var dy = touch.clientY - miniState.startY;
      var dx = Math.abs(touch.clientX - miniState.startX);
      /* Swipe down > 80px, mostly vertical */
      if (dy > 80 && dx < 60) {
        miniState.shell.style.transition = "transform 0.25s ease-in, opacity 0.25s ease-in";
        miniState.shell.style.transform = "translateY(120px)";
        miniState.shell.style.opacity = "0";
        setTimeout(function () {
          if (typeof closeMiniPlayer === "function") closeMiniPlayer();
        }, 250);
      }
      miniState = null;
    },
    { passive: true },
  );
})();
