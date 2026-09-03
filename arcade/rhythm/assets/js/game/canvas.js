



import { canvas, ctx, LANE_COUNT, HIT_Y_RATIO } from "./state.js";

export function resizeCanvas() {
  const dpr = window.devicePixelRatio || 1;
  const w = window.innerWidth;
  const h = window.innerHeight;
  canvas.width = w * dpr;
  canvas.height = h * dpr;
  canvas.style.width = w + "px";
  canvas.style.height = h + "px";
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
}

export function getW() { return canvas.width / (window.devicePixelRatio || 1); }
export function getH() { return canvas.height / (window.devicePixelRatio || 1); }

const PLAYFIELD_RATIO = 0.40;
export function laneWidth() { return (getW() * PLAYFIELD_RATIO) / LANE_COUNT; }
export function playfieldX() { return (getW() - getW() * PLAYFIELD_RATIO) / 2; }
export function hitY() { return getH() * HIT_Y_RATIO; }
