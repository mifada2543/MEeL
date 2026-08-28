/**
 * MEeL!Mania — Canvas geometry
 * Coordinate conversions, responsive sizing, and hit-testing helpers.
 * No drawing here (see renderer.js) — just math + layout.
 */
import { S, DOM, CONST } from "./state.js";

export function getBPM() {
  var el = document.getElementById("f-bpm");
  return (el && parseInt(el.value)) || 120;
}

export function msToY(ms) {
  var bpm = getBPM();
  var beatMs = 60000 / bpm;
  var pxPerMs = (CONST.ROW_HEIGHT * S.zoom) / beatMs;
  return ms * pxPerMs;
}

export function yToMs(y) {
  var bpm = getBPM();
  var beatMs = 60000 / bpm;
  var pxPerMs = (CONST.ROW_HEIGHT * S.zoom) / beatMs;
  return y / pxPerMs;
}

export function snapMs(ms) {
  if (S.snapDiv <= 0) return ms;
  var bpm = getBPM();
  var beatMs = 60000 / bpm;
  var snap = beatMs / S.snapDiv;
  return Math.round(ms / snap) * snap;
}

export function formatTime(sec) {
  var m = Math.floor(sec / 60);
  var s = Math.floor(sec % 60);
  return m + ":" + String(s).padStart(2, "0");
}

export function resizeCanvas() {
  var canvas = DOM.canvas, wrap = DOM.wrap;
  var availW = wrap.clientWidth - 20;
  S.laneWidth = Math.max(CONST.LANE_WIDTH_MIN, Math.min(CONST.LANE_WIDTH_MAX, (availW - 60) / CONST.LANE_COUNT));
  var w = Math.max(CONST.LANE_COUNT * S.laneWidth + 60, 380);

  S.virtualHeight = 600;
  if (S.audioDuration > 0) {
    S.virtualHeight = Math.max(800, S.audioDuration * CONST.ROW_HEIGHT * S.zoom * getBPM() / 60 + 100);
  }

  var h = Math.min(S.virtualHeight, CONST.MAX_CANVAS_H);
  canvas.width = w;
  canvas.height = h;
  canvas.style.width = w + "px";
  canvas.style.height = h + "px";

  var spacer = document.getElementById("canvasSpacer");
  if (!spacer) {
    spacer = document.createElement("div");
    spacer.id = "canvasSpacer";
    spacer.style.width = "1px";
    wrap.appendChild(spacer);
  }
  spacer.style.height = (S.virtualHeight - h) + "px";
  S.gridDirty = true;
}

export function getCanvasPos(e) {
  var canvas = DOM.canvas, wrap = DOM.wrap;
  var rect = canvas.getBoundingClientRect();
  // Scale from rendered CSS px to the canvas's actual pixel-buffer space.
  // Normally 1:1 (canvas.style.width/height are kept in sync with
  // canvas.width/height in resizeCanvas), but this guards against any
  // divergence — e.g. HiDPI quirks or a stale layout — the same way the
  // Ctrl+right-click seek handler already protects itself.
  var scaleX = canvas.width / rect.width;
  var scaleY = canvas.height / rect.height;
  return {
    x: (e.clientX - rect.left) * scaleX + wrap.scrollLeft * scaleX,
    y: (e.clientY - rect.top) * scaleY + wrap.scrollTop * scaleY,
  };
}

export function getLaneAndTime(pos) {
  var offset = 50; // left padding for time labels
  var lane = Math.floor((pos.x - offset) / S.laneWidth);
  if (lane < 0 || lane >= CONST.LANE_COUNT) return null;
  var ms = snapMs(yToMs(pos.y));
  return { lane: lane, ms: Math.max(0, ms) };
}

export function findNoteAt(lane, ms, tolerance) {
  tolerance = tolerance || 60;
  for (var i = 0; i < S.notes.length; i++) {
    if (S.notes[i].l === lane && Math.abs(S.notes[i].t - ms) < tolerance) return i;
  }
  return -1;
}