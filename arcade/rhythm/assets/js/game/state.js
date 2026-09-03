/**
 * MEeL!Mania Game — Shared State
 * Single mutable state object that all modules can freely modify.
 */

/* URL PARAMS */
export const speedMult = window.MANIA_SPEED || 1.5;
export const phpSong = window.MANIA_SONG || null;
export const phpBeatmap = window.MANIA_BEATMAP || null;
export const songId = phpSong ? phpSong.id : "starlight";

/* DOM */
export const canvas = document.getElementById("gameCanvas");
export const ctx = canvas ? canvas.getContext("2d") : null;
export const audioElement = document.getElementById("audioPlayer") || new Audio();
export const hud = document.getElementById("hud");
export const hudTitle = document.getElementById("hudTitle");
export const hudArtist = document.getElementById("hudArtist");
export const hudScore = document.getElementById("hudScore");
export const hudAcc = document.getElementById("hudAcc");
export const comboWrap = document.getElementById("comboWrap");
export const comboNumber = document.getElementById("comboNumber");
export const judgmentWrap = document.getElementById("judgmentWrap");
export const judgmentText = document.getElementById("judgmentText");
export const progressFill = document.getElementById("progressFill");
export const touchLanes = document.getElementById("touchLanes");
export const startOverlay = document.getElementById("startOverlay");
export const pauseOverlay = document.getElementById("pauseOverlay");
export const resultsOverlay = document.getElementById("resultsOverlay");
export const countdownOverlay = document.getElementById("countdownOverlay");
export const countdownNum = document.getElementById("countdownNum");
export const optionsOverlay = document.getElementById("optionsOverlay");

/* CONSTANTS (frozen, never change) */
export const LANE_COUNT = 4;
export const KEY_MAP = { a: 0, s: 1, k: 2, l: 3 };
export const COLOR_CLICK = "#3b82f6";
export const COLOR_CLICK_BRIGHT = "#60a5fa";
export const COLOR_HOLD = "#22c55e";
export const COLOR_HOLD_BRIGHT = "#4ade80";
export const GOLD_COLOR = "#fbbf24";
export const GOLD_BRIGHT = "#fde047";
export const LANE_COLORS = [COLOR_CLICK, COLOR_CLICK, COLOR_CLICK, COLOR_CLICK];
export const LANE_COLORS_BRIGHT = [COLOR_CLICK_BRIGHT, COLOR_CLICK_BRIGHT, COLOR_CLICK_BRIGHT, COLOR_CLICK_BRIGHT];
export const HIT_Y_RATIO = 0.88;
export const NOTE_HEIGHT_BASE = 22;
export const NOTE_RADIUS = 0;
export const APPROACH_TIME_BASE = 1800;
export const APPROACH_TIME = APPROACH_TIME_BASE / speedMult;

/* ─── Timing windows (ms) ─── */
export const TIMING = { perfect: 24, great: 52, good: 85, bad: 115 };

/* ─── Hold note specific ─── */
// How early (ms) before the note reaches hit zone a player can start pressing
// and still register the hold. Prevents frustrating "just barely missed" situations.
export const HOLD_BUFFER = 180;
// Hold start input window = TIMING.bad * HOLD_RELEASE_SCALE (more generous than click notes)
export const HOLD_RELEASE_SCALE = 1.4;
// Sustain penalty: when player loses a hold mid-way, the judgment degrades
// instead of immediate miss. List: [judgment after 0ms lost, 200ms lost, 500ms lost]
export const HOLD_SUSTAIN_PENALTY = { after200ms: "bad", after500ms: "miss" };

export const SCORE_VALUES = { perfect: 320, great: 200, good: 100, bad: 50, miss: 0 };
export const GOLD_MULTIPLIER = 3;
export const ACC_WEIGHT = { perfect: 1.0, great: 0.75, good: 0.5, bad: 0.25, miss: 0 };
export const JUDGE_COLORS = {
  perfect: "#fbbf24",
  great: "#34d399",
  good: "#60a5fa",
  bad: "#f87171",
  miss: "#6b7280",
};
export const GOLD_GLOW = "rgba(251,191,36,0.4)";

/* MUTABLE STATE (single object — all modules can freely mutate) */
export const S = {
  song: null,
  beatmapData: null,
  gameState: "loading",
  score: 0,
  combo: 0,
  maxCombo: 0,
  noteIndex: 0,
  notes: [],
  activeNotes: [],
  laneFlashes: [0, 0, 0, 0],
  lanePressed: [false, false, false, false],
  holdNotes: {},
  // Hold buffer: key pressed early, waiting for note to arrive
  holdPending: {},
  judgmentCounts: { perfect: 0, great: 0, good: 0, bad: 0, miss: 0 },
  totalNotes: 0,
  lastTime: 0,
  animFrame: null,
  songTime: 0,
  songDuration: 0,
  highScore: 0,
  gameOptions: {
    speed: 10,
    dim: 70,
    volume: 80,
    blurBg: false,
    fps: false,
    lowGfx: false,
  },
};
