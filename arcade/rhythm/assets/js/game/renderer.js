



import {
  S, canvas, ctx, LANE_COUNT, LANE_COLORS, LANE_COLORS_BRIGHT,
  COLOR_CLICK, COLOR_CLICK_BRIGHT, COLOR_HOLD, COLOR_HOLD_BRIGHT,
  GOLD_COLOR, GOLD_BRIGHT, APPROACH_TIME, NOTE_HEIGHT_BASE,
  JUDGE_COLORS, GOLD_GLOW, ACC_WEIGHT,
} from "./state.js";
import { getW, getH, laneWidth, playfieldX, hitY } from "./canvas.js";


export function pad6(n) { return String(Math.floor(n)).padStart(6, "0"); }

function getNoteSize() {
  try {
    const s = JSON.parse(localStorage.getItem("mania_settings"));
    if (s && s.noteSize === "small") return 0.8;
    if (s && s.noteSize === "large") return 1.3;
  } catch (e) {}
  return 1.0;
}

function noteColorFor(note) {
  if (note.gold) return GOLD_COLOR;
  return note.endTime ? COLOR_HOLD : COLOR_CLICK;
}


export function updateHUD() {
  const hs = document.getElementById("hudScore");
  const ha = document.getElementById("hudAcc");
  const cw = document.getElementById("comboWrap");
  const cn = document.getElementById("comboNumber");

  hs.textContent = pad6(S.score);
  hs.classList.remove("score-pop");
  void hs.offsetWidth;
  hs.classList.add("score-pop");

  if (S.combo >= 2) {
    cw.classList.remove("hidden");
    cn.textContent = S.combo;
    cn.classList.remove("pop");
    void cn.offsetWidth;
    cn.classList.add("pop");
  } else {
    cw.classList.add("hidden");
  }

  const total = S.judgmentCounts.perfect + S.judgmentCounts.great + S.judgmentCounts.good + S.judgmentCounts.bad + S.judgmentCounts.miss;
  if (total > 0) {
    const acc = ((S.judgmentCounts.perfect * ACC_WEIGHT.perfect + S.judgmentCounts.great * ACC_WEIGHT.great + S.judgmentCounts.good * ACC_WEIGHT.good + S.judgmentCounts.bad * ACC_WEIGHT.bad) / total * 100).toFixed(1);
    ha.textContent = acc + "%";
  }
}

let judgeTimer = null;
export function showJudgment(type, isGold) {
  const jw = document.getElementById("judgmentWrap");
  const jt = document.getElementById("judgmentText");
  const text = isGold ? "GOLD " + type.toUpperCase() : type.toUpperCase();
  const color = isGold ? GOLD_COLOR : JUDGE_COLORS[type];
  jt.textContent = text;
  jt.style.color = color;
  jt.style.textShadow = isGold
    ? `0 0 20px ${GOLD_GLOW}, 0 0 40px ${GOLD_GLOW}`
    : `0 0 16px ${JUDGE_COLORS[type]}80`;
  jw.classList.remove("hidden");
  clearTimeout(judgeTimer);
  jt.style.animation = "none";
  void jt.offsetWidth;
  jt.style.animation = "";
  judgeTimer = setTimeout(() => jw.classList.add("hidden"), 550);
}


export function draw() {
  const w = getW(), h = getH();
  const lw = laneWidth();
  const hy = hitY();
  const ppm = hy / APPROACH_TIME;
  const ns = getNoteSize();
  const nh = NOTE_HEIGHT_BASE * ns;
  const pfx = playfieldX();
  const pfw = lw * LANE_COUNT;

  ctx.clearRect(0, 0, w, h);

  
  ctx.fillStyle = "#08080f";
  ctx.fillRect(0, 0, w, h);
  if (S.gameOptions.lowGfx) {  } else {
    const bgGrad = ctx.createLinearGradient(0, 0, 0, h);
    bgGrad.addColorStop(0, "rgba(168,85,247,0.03)");
    bgGrad.addColorStop(0.5, "transparent");
    bgGrad.addColorStop(1, "rgba(244,63,122,0.02)");
    ctx.fillStyle = bgGrad;
    ctx.fillRect(0, 0, w, h);
  }

  
  ctx.fillStyle = "rgba(0,0,0,0.35)";
  ctx.fillRect(pfx, 0, pfw, h);

  
  for (let i = 0; i < LANE_COUNT; i++) {
    const x = pfx + i * lw;
    ctx.fillStyle = i % 2 === 0 ? "rgba(255,255,255,0.02)" : "rgba(255,255,255,0.01)";
    ctx.fillRect(x, 0, lw, h);

    ctx.strokeStyle = "rgba(255,255,255,0.06)";
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(x, 0);
    ctx.lineTo(x, h);
    ctx.stroke();

    if (S.laneFlashes[i] > 0) {
      const flashColor = S.lanePressed[i] ? LANE_COLORS_BRIGHT[i] : LANE_COLORS[i];
      const fg = ctx.createLinearGradient(x, hy, x, hy - 100);
      fg.addColorStop(0, flashColor + "50");
      fg.addColorStop(1, "transparent");
      ctx.fillStyle = fg;
      ctx.globalAlpha = S.laneFlashes[i] * 0.4;
      ctx.fillRect(x, hy - 100, lw, 100);
      ctx.globalAlpha = 1;
      S.laneFlashes[i] -= 0.05;
    }

    if (S.lanePressed[i]) {
      const pg = ctx.createLinearGradient(x, hy, x, hy - 50);
      pg.addColorStop(0, LANE_COLORS[i] + "25");
      pg.addColorStop(1, "transparent");
      ctx.fillStyle = pg;
      ctx.fillRect(x, hy - 50, lw, 50);
    }

    ctx.strokeStyle = "rgba(255,255,255,0.06)";
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(x + lw, 0);
    ctx.lineTo(x + lw, h);
    ctx.stroke();
  }

  
  const hlGlow = ctx.createLinearGradient(0, hy - 3, 0, hy + 3);
  hlGlow.addColorStop(0, "transparent");
  hlGlow.addColorStop(0.5, "rgba(255,255,255,0.08)");
  hlGlow.addColorStop(1, "transparent");
  ctx.fillStyle = hlGlow;
  ctx.fillRect(pfx, hy - 3, pfw, 6);

  ctx.strokeStyle = "rgba(255,255,255,0.25)";
  ctx.lineWidth = 1.5;
  ctx.beginPath();
  ctx.moveTo(pfx, hy);
  ctx.lineTo(pfx + pfw, hy);
  ctx.stroke();

  
  for (let i = 0; i < LANE_COUNT; i++) {
    const rx = pfx + i * lw;
    ctx.fillStyle = S.lanePressed[i] ? "rgba(255,255,255,0.6)" : "rgba(255,255,255,0.2)";
    ctx.fillRect(rx + lw * 0.08, hy - 2, lw * 0.84, 3);
  }

  
  for (const note of S.activeNotes) {
    if (!note.endTime || note.hit || note.missed) continue;
    const cx = pfx + note.lane * lw + lw / 2;
    const cyStart = hy - (note.time - S.songTime) * ppm;
    const cyEnd = hy - (note.endTime - S.songTime) * ppm;

    const drawTop = note.holding ? hy : cyStart;
    const drawBottom = cyEnd;
    if (drawTop < -200 && drawBottom < -200) continue;
    if (drawTop > h + 50 && drawBottom > h + 50) continue;
    if (drawTop <= drawBottom) continue;

    const trailW = lw * 0.75 * ns;
    const color = noteColorFor(note);

    
    const visTop = Math.max(0, drawTop);
    const visBot = Math.min(h, drawBottom);
    const visH = visTop - visBot;
    if (visH <= 0) { ctx.globalAlpha = 1; continue; }

    ctx.globalAlpha = note.holding ? 0.75 : 0.5;
    ctx.fillStyle = color;
    ctx.fillRect(cx - trailW / 2, visBot, trailW, visH);

    ctx.globalAlpha = note.holding ? 0.9 : 0.6;
    ctx.strokeStyle = color;
    ctx.lineWidth = 2;
    ctx.strokeRect(cx - trailW / 2, visBot, trailW, visH);

    
    if (!note.holding && cyStart >= 0 && cyStart <= h) {
      ctx.globalAlpha = 0.85;
      ctx.fillStyle = color;
      ctx.fillRect(cx - trailW / 2, cyStart - 5, trailW, 10);
    }

    
    if (visBot >= 0 && visBot <= h) {
      ctx.globalAlpha = 0.9;
      ctx.fillStyle = color;
      ctx.fillRect(cx - trailW / 2, visBot - 5, trailW, 10);
    }

    ctx.globalAlpha = 1;
  }

  
  for (const note of S.activeNotes) {
    if (note.hit || note.missed) continue;
    if (note.holding) continue;
    const cx = pfx + note.lane * lw + lw / 2;
    const cy = hy - (note.time - S.songTime) * ppm;
    if (cy < -30 || cy > h + 30) continue;

    const noteW = lw * 0.85 * ns;
    const noteColor = noteColorFor(note);

    ctx.fillStyle = noteColor;
    ctx.fillRect(cx - noteW / 2, cy - nh / 2, noteW, nh);

    ctx.strokeStyle = "rgba(255,255,255,0.15)";
    ctx.lineWidth = 1.5;
    ctx.strokeRect(cx - noteW / 2, cy - nh / 2, noteW, nh);

    ctx.fillStyle = "rgba(255,255,255,0.12)";
    ctx.fillRect(cx - noteW / 2 + 2, cy - nh / 2 + 1, noteW - 4, nh * 0.35);

    if (note.endTime) {
      ctx.fillStyle = "rgba(255,255,255,0.5)";
      ctx.beginPath();
      ctx.moveTo(cx, cy - nh / 2 - 4);
      ctx.lineTo(cx + 5, cy - nh / 2 + 2);
      ctx.lineTo(cx, cy - nh / 2 + 8);
      ctx.lineTo(cx - 5, cy - nh / 2 + 2);
      ctx.closePath();
      ctx.fill();
    }
  }

  
  for (let i = 0; i < LANE_COUNT; i++) {
    const cx = pfx + i * lw + lw / 2;
    ctx.fillStyle = LANE_COLORS[i] + "15";
    ctx.fillRect(cx - 1, 0, 2, hy);
  }
}
