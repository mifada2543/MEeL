const canvas = document.getElementById("gameCanvas");
const ctx = canvas.getContext("2d");

const SIZE = 400;
const MARGIN = 16;
const GAP = 12;
const PAD_W = (SIZE - MARGIN * 2 - GAP) / 2;
const PAD_H = PAD_W;

const PADS = [
  { color: "#22c55e", dark: "#14532d", freq: 440 },
  { color: "#ef4444", dark: "#7f1d1d", freq: 330 },
  { color: "#eab308", dark: "#713f12", freq: 262 },
  { color: "#3b82f6", dark: "#1e3a8a", freq: 196 },
].map((p, i) => ({
  ...p,
  x: MARGIN + (i % 2) * (PAD_W + GAP),
  y: MARGIN + Math.floor(i / 2) * (PAD_H + GAP),
  w: PAD_W,
  h: PAD_H,
}));

const gameState = {
  isPlaying: false,
  isGameOver: false,
  score: 0,
  hiScore: parseInt(localStorage.getItem("simon_hi_score") || "0", 10),
};

let sequence = [];
let inputIdx = 0;
let mode = "idle"; 
let litPad = -1;
let timers = [];
let audioCtx = null;

let glowDecay = 0;
let frameCount = 0;
let roundSplash = 0; 

const pad = (n) => String(n).padStart(5, "0");

function renderHUD() {
  document.getElementById("scoreText").textContent = pad(gameState.score);
  document.getElementById("hiScoreText").textContent = pad(gameState.hiScore);
}

function scorePop() {
  const el = document.getElementById("scoreText");
  el.classList.remove("score-pop");
  void el.offsetWidth;
  el.classList.add("score-pop");
}


function beep(freq, dur = 0.16, type = "sine") {
  try {
    audioCtx =
      audioCtx || new (window.AudioContext || window.webkitAudioContext)();
    if (audioCtx.state === "suspended") audioCtx.resume();
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.type = type;
    osc.frequency.value = freq;
    gain.gain.setValueAtTime(0.22, audioCtx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + dur);
    osc.connect(gain).connect(audioCtx.destination);
    osc.start();
    osc.stop(audioCtx.currentTime + dur);
  } catch (_) {
    
  }
}

function buzzWrong() {
  beep(110, 0.4, "sawtooth");
}


function clearTimers() {
  timers.forEach(clearTimeout);
  timers = [];
}

function flashPad(idx, dur) {
  litPad = idx;
  glowDecay = 1;
  beep(PADS[idx].freq, dur / 1000);
  timers.push(
    setTimeout(() => {
      litPad = -1;
    }, dur),
  );
}

function playSequence() {
  mode = "showing";
  litPad = -1;
  sequence.forEach((idx, i) => {
    timers.push(
      setTimeout(() => {
        if (gameState.isGameOver) return;
        flashPad(idx, 420);
        if (i === sequence.length - 1) {
          timers.push(
            setTimeout(() => {
              mode = "input";
              inputIdx = 0;
            }, 700),
          );
        }
      }, 750 * i),
    );
  });
}

function nextRound() {
  sequence.push(Math.floor(Math.random() * 4));
  roundSplash = 1.0; 
  playSequence();
}

function startGame() {
  clearTimers();
  sequence = [];
  inputIdx = 0;
  mode = "idle";
  litPad = -1;
  glowDecay = 0;
  roundSplash = 0;
  gameState.score = 0;
  gameState.isGameOver = false;
  gameState.isPlaying = true;
  renderHUD();

  document.getElementById("startScreen").classList.add("hidden");
  const gos = document.getElementById("gameOverScreen");
  gos.classList.add("hidden");
  gos.style.opacity = "0";
  gos.style.pointerEvents = "none";

  
  timers.push(setTimeout(nextRound, 600));
}

function endGame() {
  gameState.isGameOver = true;
  gameState.isPlaying = false;
  mode = "idle";
  clearTimers();
  litPad = -1;
  buzzWrong();

  const finalScore = gameState.score;
  const isNewHigh = finalScore > gameState.hiScore;
  if (isNewHigh) {
    gameState.hiScore = finalScore;
    localStorage.setItem("simon_hi_score", String(finalScore));
    renderHUD();
  }

  document.getElementById("finalScore").textContent = pad(finalScore);
  document.getElementById("newHighScore").classList.toggle("hidden", !isNewHigh);

  const gos = document.getElementById("gameOverScreen");
  gos.classList.remove("hidden");
  requestAnimationFrame(() => {
    gos.style.opacity = "1";
    gos.style.pointerEvents = "auto";
  });
}

function handlePad(idx) {
  if (!gameState.isPlaying || gameState.isGameOver) return;
  if (mode !== "input") return;

  flashPad(idx, 180);
  beep(PADS[idx].freq, 0.16);

  if (idx !== sequence[inputIdx]) {
    endGame();
    return;
  }
  inputIdx++;
  if (inputIdx === sequence.length) {
    
    gameState.score++;
    scorePop();
    renderHUD();
    timers.push(setTimeout(nextRound, 900));
  }
}


function roundRect(x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}

function draw() {
  ctx.fillStyle = "#0b0e14";
  ctx.fillRect(0, 0, SIZE, SIZE);

  PADS.forEach((p, i) => {
    const lit = litPad === i;
    
    const glow = lit ? Math.max(glowDecay, 0.55) : glowDecay * 0.9;
    ctx.save();
    if (glow > 0.05) {
      ctx.shadowColor = p.color;
      ctx.shadowBlur = 34 * glow;
    }
    const col = lit ? p.color : p.dark;
    
    ctx.fillStyle = lit
      ? col
      : `rgb(${Math.round(18 + (34 - 18) * glow)}, ${Math.round(40 + (92 - 40) * glow)}, ${Math.round(18 + (84 - 18) * glow)})`;
    roundRect(p.x, p.y, p.w, p.h, 22);
    ctx.fill();
    ctx.restore();

    if (lit) {
      ctx.fillStyle = `rgba(255,255,255,${0.3 * Math.max(glow, 0.5)})`;
      roundRect(p.x + 6, p.y + 6, p.w - 12, p.h - 12, 16);
      ctx.fill();
    }
  });

  
  if (roundSplash > 0 && gameState.isPlaying && !gameState.isGameOver) {
    const a = Math.min(1, roundSplash * 2.5);
    ctx.save();
    ctx.globalAlpha = a;
    ctx.fillStyle = "rgba(0,0,0,0.5)";
    roundRect(SIZE / 2 - 90, SIZE / 2 - 26, 180, 52, 16);
    ctx.fill();
    ctx.fillStyle = "#fde68a";
    ctx.font = 'bold 15px "Press Start 2P", monospace';
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    ctx.fillText(`RONDE ${gameState.score + 1}`, SIZE / 2, SIZE / 2);
    ctx.restore();
  }

  
  if (gameState.isPlaying && !gameState.isGameOver) {
    const label = `RONDE ${gameState.score + 1}`;
    ctx.font = 'bold 11px "Press Start 2P", monospace';
    const tw = ctx.measureText(label).width;
    ctx.fillStyle = "rgba(0,0,0,0.65)";
    roundRect(SIZE / 2 - tw / 2 - 14, SIZE - 34, tw + 28, 24, 12);
    ctx.fill();
    ctx.fillStyle = "#6ee7b7";
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    ctx.fillText(label, SIZE / 2, SIZE - 22);
  }
}

function gameLoop() {
  frameCount++;
  
  if (glowDecay > 0) glowDecay = Math.max(0, glowDecay - 0.06);
  if (roundSplash > 0) roundSplash -= 1 / 60;
  draw();
  requestAnimationFrame(gameLoop);
}


function padFromPointer(clientX, clientY) {
  const rect = canvas.getBoundingClientRect();
  const x = ((clientX - rect.left) / rect.width) * SIZE;
  const y = ((clientY - rect.top) / rect.height) * SIZE;
  for (let i = 0; i < PADS.length; i++) {
    const p = PADS[i];
    if (x >= p.x && x <= p.x + p.w && y >= p.y && y <= p.y + p.h) return i;
  }
  return -1;
}

canvas.addEventListener(
  "touchstart",
  (e) => {
    e.preventDefault();
    const t = e.touches[0];
    if (!gameState.isPlaying) {
      startGame();
      return;
    }
    const idx = padFromPointer(t.clientX, t.clientY);
    if (idx >= 0) handlePad(idx);
  },
  { passive: false },
);

canvas.addEventListener("click", (e) => {
  if (!gameState.isPlaying) {
    startGame();
    return;
  }
  const idx = padFromPointer(e.clientX, e.clientY);
  if (idx >= 0) handlePad(idx);
});

window.addEventListener("keydown", (e) => {
  if (e.key === " " || e.key === "Enter") {
    e.preventDefault();
    if (!gameState.isPlaying) startGame();
    return;
  }
  const map = { "1": 0, "2": 1, "3": 2, "4": 3 };
  const idx = map[e.key];
  if (idx !== undefined) handlePad(idx);
});

document.getElementById("startScreen").addEventListener("click", () => {
  if (!gameState.isPlaying) startGame();
});
document.getElementById("restartBtn").addEventListener("click", startGame);

document.getElementById("resetScoreBtn").addEventListener("click", () => {
  if (gameState.isPlaying) return;
  meelConfirm({
    title: "Reset High Score",
    text: "Reset High Score ke nol?",
    confirmButtonText: "RESET",
  }).then((ok) => {
    if (ok) {
      localStorage.removeItem("simon_hi_score");
      gameState.hiScore = 0;
      renderHUD();
    }
  });
});


renderHUD();
gameLoop();
