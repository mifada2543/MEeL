







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
  COLOR_CLICK: "#3b82f6",   
  COLOR_HOLD: "#22c55e",    
  GOLD_COLOR: "#fbbf24",    
  LANE_KEYS: ["A", "S", "K", "L"],
  ROW_HEIGHT: 30,           
  LANE_WIDTH_MIN: 80,
  LANE_WIDTH_MAX: 140,
  MAX_CANVAS_H: 16384,      
};
CONST.LANE_COLORS = [CONST.COLOR_CLICK, CONST.COLOR_CLICK, CONST.COLOR_CLICK, CONST.COLOR_CLICK];

export const S = {
  notes: [],           
  undoStack: [],
  zoom: 3,
  snapDiv: 8,           
  isPlaying: false,
  audioDuration: 0,
  animFrame: null,
  isDragging: false,
  dragLane: -1,
  dragStartMs: -1,
  dragNoteIdx: -1,
  selectedNoteIdx: -1,
  laneWidth: 100,       
  hasAudio: false,
  gridCanvas: null,     
  gridDirty: true,
  lastUIUpdate: 0,      
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