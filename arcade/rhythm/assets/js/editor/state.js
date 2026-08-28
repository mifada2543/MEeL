/**
 * MEeL!Mania — Editor State
 * Single source of truth: DOM refs, constants, mutable state.
 * Other modules import { S, DOM, CONST } and mutate S.* / DOM.* directly
 * (ES modules don't allow reassigning imported bindings, so we mutate
 * object properties instead of exporting bare `let`s).
 */

export const DOM = {
  canvas: document.getElementById("editorCanvas"),
  ctx: null,
  wrap: document.getElementById("canvasWrap"),
  audio: document.getElementById("audioPlayer"),
  audioInput: document.getElementById("f-audio"),
  coverInput: document.getElementById("f-cover"),
  coverPreview: document.getElementById("cover-preview"),
  timelineBar: document.getElementById("timelineBar"),
};
DOM.ctx = DOM.canvas ? DOM.canvas.getContext("2d") : null;

export const CONST = {
  LANE_COUNT: 4,
  COLOR_CLICK: "#3b82f6",   // blue for tap/click notes
  COLOR_HOLD: "#22c55e",    // green for hold notes
  GOLD_COLOR: "#fbbf24",    // gold for bonus notes
  LANE_KEYS: ["A", "S", "K", "L"],
  ROW_HEIGHT: 30,           // pixels per beat row
  LANE_WIDTH_MIN: 80,
  LANE_WIDTH_MAX: 140,
  MAX_CANVAS_H: 16384,      // browser canvas height limit
};
CONST.LANE_COLORS = [CONST.COLOR_CLICK, CONST.COLOR_CLICK, CONST.COLOR_CLICK, CONST.COLOR_CLICK];

export const S = {
  notes: [],           // [{t,l}, {t,e,l}, {t,l,g}, {t,e,l,g}]
  undoStack: [],
  zoom: 3,
  snapDiv: 8,           // snap to 1/8 beat
  isPlaying: false,
  audioDuration: 0,
  animFrame: null,
  isDragging: false,
  dragLane: -1,
  dragStartMs: -1,
  dragNoteIdx: -1,
  selectedNoteIdx: -1,
  laneWidth: 100,       // dynamic, recalculated on resize
  hasAudio: false,
  gridCanvas: null,     // offscreen grid buffer
  gridDirty: true,
  lastUIUpdate: 0,      // throttle DOM updates during playback
  isDraggingCursor: false,
  isMovingNote: false,
  moveNoteIdx: -1,
  moveNoteOrigT: 0,
  moveNoteOrigL: -1,
  moveNoteOrigE: null,
  virtualHeight: 600,
  lastDragPos: { x: 0, y: 0 },
  timelineDragging: false,
};