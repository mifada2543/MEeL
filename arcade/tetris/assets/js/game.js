const canvas = document.getElementById("gameCanvas");
const ctx = canvas.getContext("2d");

const COLS = 10;
const ROWS = 20;
const CELL = 24;
const BOARD_W = COLS * CELL; // 240
const BOARD_H = ROWS * CELL; // 480
const SIDE = 80;
canvas.width = BOARD_W + SIDE;
canvas.height = BOARD_H;

const SHAPES = {
  I: { color: "#00e5ff", matrix: [[0, 0, 0, 0], [1, 1, 1, 1], [0, 0, 0, 0], [0, 0, 0, 0]] },
  O: { color: "#ffe066", matrix: [[1, 1], [1, 1]] },
  T: { color: "#b388ff", matrix: [[0, 1, 0], [1, 1, 1], [0, 0, 0]] },
  S: { color: "#69f0ae", matrix: [[0, 1, 1], [1, 1, 0], [0, 0, 0]] },
  Z: { color: "#ff8a80", matrix: [[1, 1, 0], [0, 1, 1], [0, 0, 0]] },
  J: { color: "#82b1ff", matrix: [[1, 0, 0], [1, 1, 1], [0, 0, 0]] },
  L: { color: "#ffab40", matrix: [[0, 0, 1], [1, 1, 1], [0, 0, 0]] },
};
const SHAPE_KEYS = ["I", "O", "T", "S", "Z", "J", "L"];

const gameState = {
  isPlaying: false,
  isGameOver: false,
  isPaused: false,
  score: 0,
  hiScore: parseInt(localStorage.getItem("tetris_hi_score") || "0", 10),
  level: 1,
  lines: 0,
};

let board = Array.from({ length: ROWS }, () => Array(COLS).fill(0));
let piece = null;
let nextKey = null;
let bag = [];
let dropAcc = 0;
let lockTimer = 0;
let lastTime = 0;

// Animasi clear baris: baris penuh berkedip dulu, baru dihapus & di-compact.
let clearingRows = null; // [rowIndex...] yang sedang berkedip
let clearTimer = 0;
let pendingClear = null; // { cleared, points, lines } untuk diselesaikan setelah animasi
let lockFlash = 0; // kilat singkat saat piece terkunci
const CLEAR_MS = 280; // durasi animasi clear

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

function dropInterval() {
  return Math.max(80, 800 - (gameState.level - 1) * 70);
}

function buildBag() {
  bag = [...SHAPE_KEYS];
  for (let i = bag.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [bag[i], bag[j]] = [bag[j], bag[i]];
  }
}

function takeNext() {
  if (!bag.length) buildBag();
  return bag.pop();
}

function newPiece(key) {
  const shape = SHAPES[key];
  return {
    key,
    color: shape.color,
    matrix: shape.matrix.map((row) => [...row]),
    x: Math.floor((COLS - shape.matrix[0].length) / 2),
    y: 0,
  };
}

function collides(m, x, y) {
  for (let r = 0; r < m.length; r++)
    for (let c = 0; c < m[r].length; c++) {
      if (!m[r][c]) continue;
      const bx = x + c;
      const by = y + r;
      if (bx < 0 || bx >= COLS || by >= ROWS) return true;
      if (by >= 0 && board[by][bx]) return true;
    }
  return false;
}

function rotateMatrix(m) {
  const n = m.length;
  const out = Array.from({ length: n }, () => Array(n).fill(0));
  for (let r = 0; r < n; r++)
    for (let c = 0; c < n; c++) out[c][n - 1 - r] = m[r][c];
  return out;
}

function tryRotate() {
  if (!piece || piece.key === "O") return false;
  const rotated = rotateMatrix(piece.matrix);
  for (const kick of [0, -1, 1, -2, 2]) {
    if (!collides(rotated, piece.x + kick, piece.y)) {
      piece.matrix = rotated;
      piece.x += kick;
      lockTimer = 0;
      return true;
    }
  }
  return false;
}

function tryMove(dx, dy) {
  if (!piece) return false;
  if (!collides(piece.matrix, piece.x + dx, piece.y + dy)) {
    piece.x += dx;
    piece.y += dy;
    lockTimer = 0;
    return true;
  }
  return false;
}

function ghostY() {
  let y = piece.y;
  while (!collides(piece.matrix, piece.x, y + 1)) y++;
  return y;
}

function hardDrop() {
  if (!piece) return;
  const gy = ghostY();
  gameState.score += (gy - piece.y) * 2;
  piece.y = gy;
  lockPiece();
  scorePop();
  renderHUD();
}

function softDrop() {
  if (tryMove(0, 1)) {
    gameState.score += 1;
    renderHUD();
    return true;
  }
  return false;
}

function lockPiece() {
  piece.matrix.forEach((row, r) =>
    row.forEach((v, c) => {
      if (!v) return;
      const by = piece.y + r;
      if (by >= 0) board[by][piece.x + c] = piece.color;
    }),
  );
  lockFlash = 6; // ~100ms kilat di area lock

  // Deteksi baris penuh -> mulai animasi flash
  const full = [];
  for (let r = 0; r < ROWS; r++) if (board[r].every((v) => v)) full.push(r);
  if (full.length) {
    clearingRows = full;
    clearTimer = CLEAR_MS;
    pendingClear = { cleared: full.length, rowIndices: full };
    return; // piece berikutnya menunggu sampai animasi selesai
  }
  spawnPiece();
}

function finishClear() {
  const { cleared } = pendingClear;
  // Hapus baris penuh (dari bawah ke atas) & compact
  const rowsToRemove = new Set(pendingClear.rowIndices);
  const newBoard = board.filter((_, r) => !rowsToRemove.has(r));
  while (newBoard.length < ROWS) newBoard.unshift(Array(COLS).fill(0));
  board = newBoard;
  clearingRows = null;
  pendingClear = null;
  clearTimer = 0;

  const points = [0, 100, 300, 500, 800][cleared] * gameState.level;
  gameState.score += points;
  gameState.lines += cleared;
  gameState.level = Math.floor(gameState.lines / 10) + 1;
  scorePop();
  renderHUD();
  spawnPiece();
}

function spawnPiece() {
  nextKey = nextKey || takeNext();
  piece = newPiece(nextKey);
  nextKey = takeNext();
  lockTimer = 0;
  if (collides(piece.matrix, piece.x, piece.y)) {
    endGame();
  }
}

// ---- Rendering ----
function roundRect(x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}

function drawCell(x, y, color, alpha = 1) {
  ctx.globalAlpha = alpha;
  ctx.fillStyle = color;
  roundRect(x + 1, y + 1, CELL - 2, CELL - 2, 4);
  ctx.fill();
  ctx.fillStyle = "rgba(255,255,255,0.16)";
  ctx.fillRect(x + 2, y + 2, CELL - 4, 4);
  ctx.globalAlpha = 1;
}

function drawSidePanel() {
  const px = BOARD_W + 8;
  const pw = SIDE - 16;

  // Kotak preview NEXT
  ctx.fillStyle = "rgba(255,255,255,0.05)";
  roundRect(px, 10, pw, 76, 10);
  ctx.fill();
  ctx.fillStyle = "#94a3b8";
  ctx.font = 'bold 10px "Plus Jakarta Sans", sans-serif';
  ctx.textAlign = "left";
  ctx.textBaseline = "middle";
  ctx.fillText("NEXT", px + 8, 26);

  if (nextKey && SHAPES[nextKey]) {
    const shape = SHAPES[nextKey];
    const m = shape.matrix;
    const cs = 12;
    const w = m[0].length * cs;
    const h = m.length * cs;
    const ox = px + (pw - w) / 2;
    const oy = 38 + (44 - h) / 2;
    for (let r = 0; r < m.length; r++)
      for (let c = 0; c < m[r].length; c++)
        if (m[r][c]) {
          ctx.fillStyle = shape.color;
          roundRect(ox + c * cs + 1, oy + r * cs + 1, cs - 2, cs - 2, 2);
          ctx.fill();
        }
  }

  ctx.fillStyle = "#94a3b8";
  ctx.font = 'bold 10px "Plus Jakarta Sans", sans-serif';
  ctx.fillText("LEVEL", px + 8, 118);
  ctx.fillStyle = "#c4b5fd";
  ctx.font = '11px "Press Start 2P", monospace';
  ctx.fillText(pad(gameState.level), px + 8, 140);

  ctx.fillStyle = "#94a3b8";
  ctx.font = 'bold 10px "Plus Jakarta Sans", sans-serif';
  ctx.fillText("LINES", px + 8, 180);
  ctx.fillStyle = "#c4b5fd";
  ctx.font = '11px "Press Start 2P", monospace';
  ctx.fillText(pad(gameState.lines), px + 8, 202);
}

function drawCenterText(title, sub) {
  ctx.fillStyle = "rgba(0,0,0,0.62)";
  ctx.fillRect(0, 0, BOARD_W, BOARD_H);
  ctx.fillStyle = "#e9d5ff";
  ctx.font = '16px "Press Start 2P", monospace';
  ctx.textAlign = "center";
  ctx.textBaseline = "middle";
  ctx.fillText(title, BOARD_W / 2, BOARD_H / 2 - 12);
  ctx.fillStyle = "#94a3b8";
  ctx.font = '10px "Plus Jakarta Sans", sans-serif';
  ctx.fillText(sub, BOARD_W / 2, BOARD_H / 2 + 16);
}

function draw() {
  ctx.fillStyle = "#0b0e14";
  ctx.fillRect(0, 0, canvas.width, canvas.height);

  // Papan + grid
  ctx.fillStyle = "#0f131c";
  ctx.fillRect(0, 0, BOARD_W, BOARD_H);
  ctx.strokeStyle = "rgba(255,255,255,0.04)";
  ctx.lineWidth = 1;
  for (let c = 1; c < COLS; c++) {
    ctx.beginPath();
    ctx.moveTo(c * CELL, 0);
    ctx.lineTo(c * CELL, BOARD_H);
    ctx.stroke();
  }
  for (let r = 1; r < ROWS; r++) {
    ctx.beginPath();
    ctx.moveTo(0, r * CELL);
    ctx.lineTo(BOARD_W, r * CELL);
    ctx.stroke();
  }

  for (let r = 0; r < ROWS; r++)
    for (let c = 0; c < COLS; c++)
      if (board[r][c]) drawCell(c * CELL, r * CELL, board[r][c]);

  // Flash baris yang sedang di-clear (berkedip putih, memudar)
  if (clearingRows) {
    const phase = clearTimer / CLEAR_MS;
    const flashA = 0.25 + 0.55 * Math.abs(Math.sin((1 - phase) * Math.PI));
    for (const r of clearingRows) {
      ctx.fillStyle = `rgba(255,255,255,${flashA})`;
      ctx.fillRect(0, r * CELL, BOARD_W, CELL);
    }
  }

  // Kilat singkat saat piece terkunci
  if (lockFlash > 0) {
    ctx.fillStyle = `rgba(255,255,255,${(lockFlash / 6) * 0.18})`;
    ctx.fillRect(0, 0, BOARD_W, BOARD_H);
  }

  if (piece) {
    const gy = ghostY();
    for (let r = 0; r < piece.matrix.length; r++)
      for (let c = 0; c < piece.matrix[r].length; c++)
        if (piece.matrix[r][c] && gy + r >= 0)
          drawCell((piece.x + c) * CELL, (gy + r) * CELL, piece.color, 0.22);
    for (let r = 0; r < piece.matrix.length; r++)
      for (let c = 0; c < piece.matrix[r].length; c++)
        if (piece.matrix[r][c] && piece.y + r >= 0)
          drawCell((piece.x + c) * CELL, (piece.y + r) * CELL, piece.color);
  }

  drawSidePanel();

  if (gameState.isPaused) drawCenterText("PAUSE", "Tekan P atau SPASI untuk lanjut");
}

// ---- Loop ----
// Defensive loop: jika frame melempar exception, rAF tetap dijadwalkan agar
// loop tidak pernah mati permanen. 1-2 error transien dilewati (frame di-skip);
// error beruntun (>=3) memicu self-heal: board di-reset ke keadaan valid.
let consecutiveErrors = 0;

function gameLoop(ts) {
  if (consecutiveErrors >= 3) {
    consecutiveErrors = 0;
    console.error("[Tetris] Memulihkan board setelah error loop beruntun...");
    startGame();
  }
  try {
    const dt = Math.min(100, ts - lastTime);
    lastTime = ts;
    // Animasi clear baris: pause drop & tunda piece berikutnya sampai selesai
    if (clearingRows && gameState.isPlaying) {
      clearTimer -= dt;
      if (clearTimer <= 0) finishClear();
    } else if (gameState.isPlaying && !gameState.isPaused && !gameState.isGameOver) {
      dropAcc += dt;
      const interval = dropInterval();
      if (dropAcc >= interval) {
        dropAcc = 0;
        if (!tryMove(0, 1)) {
          lockTimer += interval;
          if (lockTimer >= 500) {
            lockPiece();
            lockTimer = 0;
          }
        }
      }
    }
    if (lockFlash > 0) lockFlash--;
    draw();
    consecutiveErrors = 0;
  } catch (err) {
    consecutiveErrors++;
    console.error("[Tetris] Error di frame:", err);
  } finally {
    requestAnimationFrame(gameLoop);
  }
}

// ---- Flow ----
function startGame() {
  board = Array.from({ length: ROWS }, () => Array(COLS).fill(0));
  bag = [];
  nextKey = null;
  piece = null;
  dropAcc = 0;
  lockTimer = 0;
  gameState.score = 0;
  gameState.level = 1;
  gameState.lines = 0;
  gameState.isGameOver = false;
  gameState.isPaused = false;
  gameState.isPlaying = true;
  clearingRows = null;
  clearTimer = 0;
  pendingClear = null;
  lockFlash = 0;
  spawnPiece();
  renderHUD();

  document.getElementById("startScreen").classList.add("hidden");
  const gos = document.getElementById("gameOverScreen");
  gos.classList.add("hidden");
  gos.style.opacity = "0";
  gos.style.pointerEvents = "none";
}

function endGame() {
  gameState.isGameOver = true;
  gameState.isPlaying = false;

  const finalScore = gameState.score;
  const isNewHigh = finalScore > gameState.hiScore;
  if (isNewHigh) {
    gameState.hiScore = finalScore;
    localStorage.setItem("tetris_hi_score", String(finalScore));
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

// ---- Input ----
window.addEventListener("keydown", (e) => {
  const k = e.key;
  if (k === " " || k === "Enter") {
    e.preventDefault();
    if (!gameState.isPlaying) {
      startGame();
      return;
    }
    if (gameState.isGameOver) return;
    if (gameState.isPaused) {
      gameState.isPaused = false;
      return;
    }
    hardDrop();
    return;
  }
  if (k === "p" || k === "P") {
    if (gameState.isPlaying && !gameState.isGameOver) {
      gameState.isPaused = !gameState.isPaused;
    }
    return;
  }
  if (!gameState.isPlaying || gameState.isGameOver || gameState.isPaused) return;
  switch (k) {
    case "ArrowLeft":
    case "a":
    case "A":
      e.preventDefault();
      tryMove(-1, 0);
      break;
    case "ArrowRight":
    case "d":
    case "D":
      e.preventDefault();
      tryMove(1, 0);
      break;
    case "ArrowDown":
    case "s":
    case "S":
      e.preventDefault();
      softDrop();
      break;
    case "ArrowUp":
    case "w":
    case "W":
    case "x":
    case "X":
      e.preventDefault();
      tryRotate();
      break;
  }
});

const mobileAction = (id, fn) =>
  document.getElementById(id).addEventListener("click", fn);

mobileAction("btn-left", () => {
  if (gameState.isPlaying && !gameState.isPaused) tryMove(-1, 0);
});
mobileAction("btn-right", () => {
  if (gameState.isPlaying && !gameState.isPaused) tryMove(1, 0);
});
mobileAction("btn-rotate", () => {
  if (gameState.isPlaying && !gameState.isPaused) tryRotate();
});
mobileAction("btn-down", () => {
  if (gameState.isPlaying && !gameState.isPaused) softDrop();
});
mobileAction("btn-drop", () => {
  if (gameState.isPlaying && !gameState.isPaused) hardDrop();
});

canvas.addEventListener("click", () => {
  if (!gameState.isPlaying) startGame();
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
      localStorage.removeItem("tetris_hi_score");
      gameState.hiScore = 0;
      renderHUD();
    }
  });
});

// ---- Init ----
renderHUD();
requestAnimationFrame(gameLoop);
