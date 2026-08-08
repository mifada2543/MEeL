/* gestures.js — Gestur mobile: tap untuk show/hide kontrol, */
function setupMobileGestures() {
  if (!isTouchDevice) return;
  const e = document.querySelector(".plyr");
  if (!e) return;
  let t = !1,
    n = null,
    o = 0,
    l = 0,
    a = null,
    r = !1;
  function i() {
    ((t = !0),
      e.classList.add("plyr--hide-controls"),
      e.classList.remove("plyr--hide-controls"),
      player &&
        player.elements &&
        player.elements.controls &&
        ((player.elements.controls.style.opacity = ""),
        (player.elements.controls.style.pointerEvents = "")));
    const n = e.querySelector(".plyr__control--overlaid");
    (n && (n.style.opacity = ""), c());
  }
  function s() {
    ((t = !1),
      clearTimeout(n),
      player &&
        player.elements &&
        player.elements.controls &&
        ((player.elements.controls.style.opacity = "0"),
        (player.elements.controls.style.pointerEvents = "none")));
    const o = e.querySelector(".plyr__control--overlaid");
    o && (o.style.opacity = "0");
  }
  function c() {
    (clearTimeout(n),
      (n = setTimeout(() => {
        t && s();
      }, 3e3)));
  }
  (e.addEventListener(
    "touchstart",
    (n) => {
      const d = Date.now();
      l = d;
      const p = n.target;
      if (
        p.closest(".plyr__controls") ||
        p.closest(".plyr__control--overlaid") ||
        p.closest(".plyr__menu") ||
        p.closest(".plyr__volume") ||
        p.closest(".plyr__progress")
      )
        return void (t && c());
      const u = e.getBoundingClientRect(),
        m = n.touches[0] || n.changedTouches[0];
      if (!m) return;
      const y =
        ((v = m.clientX - u.left),
        (g = u.width),
        v < 0.4 * g ? "left" : v > 0.6 * g ? "right" : "center");
      var v, g;
      if (d - o < 300 && r)
        return (
          clearTimeout(a),
          (r = !1),
          n.preventDefault(),
          n.stopPropagation(),
          "left" === y
            ? (player && player.rewind(10),
              tampilkanSisiIndikator("rewind", "-10s"))
            : "right" === y &&
              (player && player.forward(10),
              tampilkanSisiIndikator("forward", "+10s")),
          void (o = 0)
        );
      ((o = d),
        (r = !0),
        clearTimeout(a),
        (a = setTimeout(() => {
          ((r = !1),
            (function (e) {
              "left" === e || "right" === e
                ? t
                  ? s()
                  : i()
                : t
                  ? player &&
                    (player.paused ? player.play() : player.pause(), c())
                  : i();
            })(y));
        }, 300)));
    },
    { passive: !1 },
  ),
    e.addEventListener(
      "dblclick",
      (e) => {
        Date.now() - l < 1e3 && (e.preventDefault(), e.stopPropagation());
      },
      !0,
    ),
    (function () {
      let e = null,
        o = null,
        l = null;
      (document.addEventListener(
        "touchstart",
        (a) => {
          const r = a.target.closest(".plyr__volume input[type='range']");
          r &&
            t &&
            ((e = a.touches[0].clientY),
            (o = parseFloat(r.value)),
            (l = r),
            clearTimeout(n),
            a.preventDefault());
        },
        { passive: !1 },
      ),
        document.addEventListener(
          "touchmove",
          (t) => {
            if (!l || null === e) return;
            t.preventDefault();
            const n =
                ((e - t.touches[0].clientY) / 120) *
                (parseFloat(l.max) - parseFloat(l.min)),
              a = Math.min(
                parseFloat(l.max),
                Math.max(parseFloat(l.min), o + n),
              );
            ((l.value = a),
              l.dispatchEvent(new Event("input", { bubbles: !0 })),
              player && (player.volume = a));
          },
          { passive: !1 },
        ),
        document.addEventListener("touchend", () => {
          (l && c(), (e = null), (o = null), (l = null));
        }));
    })(),
    player &&
      (player.on("play", () => {
        c();
      }),
      player.on("pause", () => {
        (clearTimeout(n),
          (t = !0),
          player.elements &&
            player.elements.controls &&
            ((player.elements.controls.style.opacity = ""),
            (player.elements.controls.style.pointerEvents = "")));
      })));
}
