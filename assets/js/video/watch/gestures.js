/* gestures.js — Gestur mobile: tap, stacking double-tap (10→20→30 like YouTube). */
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

  /* Double-tap stacking state */
  var ddStreak = 0,
    ddSide = null,
    ddResetTimer = null,
    ddLastTapTime = 0,
    DD_WINDOW = 600,
    DD_SEEK_STEP = 10;

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

  /* DOUBLE-TAP STACKING (10→20→30) */
  function handleDoubleTap(x, y, side) {
    var now = Date.now();

    if (ddSide !== side || now - ddLastTapTime > DD_WINDOW) {
      ddStreak = 0;
    }

    ddStreak++;
    ddSide = side;
    ddLastTapTime = now;

    var seekAmount = ddStreak * DD_SEEK_STEP;
    var seekLabel = (side === "rewind" ? "-" : "+") + seekAmount + "s";

    if (player) {
      if (side === "rewind") player.rewind(seekAmount);
      else player.forward(seekAmount);
    }

    /* Use original indicator — label updates otomatis */
    tampilkanSisiIndikator(side, seekLabel);

    clearTimeout(ddResetTimer);
    ddResetTimer = setTimeout(function () {
      ddStreak = 0;
      ddSide = null;
    }, DD_WINDOW);
  }



  /* MAIN TOUCH HANDLER */
  plyr.addEventListener(
    "touchstart",
    function (ev) {
      var now = Date.now();
      var target = ev.target;

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

      /* Double-tap detection */
      if (now - lastTapTime < 300 && tapPending) {
        clearTimeout(tapTimer);
        tapPending = false;
        ev.preventDefault();
        ev.stopPropagation();

        if (zone === "left") handleDoubleTap(x, y, "rewind");
        else if (zone === "right") handleDoubleTap(x, y, "forward");
        lastTapTime = 0;
        return;
      }

      /* First tap */
      lastTapTime = now;
      tapPending = true;
      tapZone = zone;
      clearTimeout(tapTimer);
      tapTimer = setTimeout(function () {
        if (!tapPending) return;
        tapPending = false;

        if (tapZone === "left" || tapZone === "right") {
          controlsVisible ? hideControls() : showControls();
        } else {
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

  /* ── Volume slider swipe ── */
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

