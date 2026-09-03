



(function () {
  "use strict";
  var src =
    (document.currentScript && document.currentScript.src) ||
    (function () {
      var s = document.getElementsByTagName("script");
      return s[s.length - 1] ? s[s.length - 1].src : "";
    })();
  var base = src.substring(0, src.lastIndexOf("/") + 1);
  var m = src.match(/[?&]v=([^&]+)/);
  var qs = m ? "?v=" + encodeURIComponent(m[1]) : "";
  var files = [
    "state.js",
    "loop-ui.js",
    "audio-state.js",
    "equalizer.js",
    "misc.js",
    "mini-player.js",
    "player-core.js",
  ];
  
  
  window.MEEL_WATCH_BUNDLE = {
    base: base,
    qs: qs,
    files: files.map(function (f) {
      return base + f + qs;
    }),
  };
  if (document.readyState !== "loading") return;
  for (var i = 0; i < files.length; i++) {
    document.write('<script src="' + base + files[i] + qs + '"><\/script>');
  }
})();
