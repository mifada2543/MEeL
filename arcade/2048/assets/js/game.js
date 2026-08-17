const canvas = document.getElementById("gameCanvas");
const ctx = canvas.getContext("2d");

const SIZE = 4;
const CELL = canvas.width / SIZE; // 100
const PAD = 12;

const TILE_COLORS = {
  2:    { bg: "#eee4da", fg: "#776e65" },
  4:    { bg: "#ede0c8", fg: "#776e65" },
  8:    { bg: "#f2b179", fg: "#f9f6f2" },
  16:   { bg: "#f59563", fg: "#f9f6f2" },
  32:   { bg: "#f67c5f", fg: "#f9f6f2" },
  64:   { bg: "#f65e3b", fg: "#f9f6f2" },
  128:  { bg: "#edcf72", fg: "#f9f6f2" },
  256:  { bg: "#edcc61", fg: "#f9f6f2" },
  512:  { bg: "#edc850", fg: "#f9f6f2" },
  1024: { bg: "#edc53f", fg: "#f9f6f2" },
  2048: { bg: "#edc22e", fg: "#f9f6f2" },
};
const SUPER_COLOR = { bg: "#3c3a32", fg: "#f9f6f2" };

const gameState = {
  isPlaying: false,
  isGameOver: false,
  score: 0,
  hiScore: parseInt(localStorage.getItem("g2048_hi_score") || "0", 10),
};

let board = emptyBoard();
let wonNotified = false;
let mergeFlash = [];
let flashTimer = 0;

// Animasi tile: tile lama meluncur ke posisi baru + tile baru pop-in.
let slidePrev = null; // board sebelum move
let slideDest = null; // { r, c } tujuan tiap tile lama (kunci "r,c")
let slideNew = []; // [ { r, c, val } ] tile yang baru muncul
let animTimer = 0;
const ANIM_MS = 150; // durasi slide (ms)

function easeOutCubic(t) {
  return 1 - Math.pow(1 - t, 3);
}

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

function emptyBoard() {
  return Array.from({ length: SIZE }, () => Array(SIZE).fill(0));
}

function randomEmptyCell() {
  const empties = [];
  for (let r = 0; r < SIZE; r++)
    for (let c = 0; c < SIZE; c++) if (!board[r][c]) empties.push([r, c]);
  if (!empties.length) return null;
  return empties[Math.floor(Math.random() * empties.length)];
}

function spawnTile() {
  const cell = randomEmptyCell();
  if (!cell) return;
  board[cell[0]][cell[1]] = Math.random() < 0.9 ? 2 : 4;
}

function canMove() {
  for (let r = 0; r < SIZE; r++)
    for (let c = 0; c < SIZE; c++) {
      if (!board[r][c]) return true;
      if (c + 1 < SIZE && board[r][c] === board[r][c + 1]) return true;
      if (r + 1 < SIZE && board[r][c] === board[r + 1][c]) return true;
    }
  return false;
}

// Geser satu baris (sudah diurutkan searah gerakan), gabungkan nilai sama.
function slideLine(line) {
  const tiles = line.filter((v) => v);
  const out = [];
  let gained = 0;
  const mergedIdx = [];
  for (let i = 0; i < tiles.length; i++) {
    if (i + 1 < tiles.length && tiles[i] === tiles[i + 1]) {
      const v = tiles[i] * 2;
      out.push(v);
      gained += v;
      mergedIdx.push(out.length - 1);
      i++;
    } else {
      out.push(tiles[i]);
    }
  }
  while (out.length < line.length) out.push(0);
  return { out, gained, mergedIdx };
}

function move(dir) {
  let changed = false;
  let gained = 0;
  const flash = [];
  const order = (r, c) => {
    if (dir === "left") return { r, c };
    if (dir === "right") return { r, c: SIZE - 1 - c };
    if (dir === "up") return { r: c, c: r };
    return { r: SIZE - 1 - c, c: r }; // down
  };
  for (let i = 0; i < SIZE; i++) {
    const line = [];
    for (let j = 0; j < SIZE; j++) {
      const p = order(i, j);
      line.push(board[p.r][p.c]);
    }
    const { out, gained: g, mergedIdx } = slideLine(line);
    gained += g;
    for (let j = 0; j < SIZE; j++) {
      const p = order(i, j);
      if (board[p.r][p.c] !== out[j]) {
        changed = true;
        board[p.r][p.c] = out[j];
      }
    }
    mergedIdx.forEach((idx) => {
      const p = order(i, idx);
      flash.push({ r: p.r, c: p.c });
    });
  }
  return { changed, gained, flash };
}

function doMove(dir) {
  if (!gameState.isPlaying || gameState.isGameOver) return;
  const prev = board.map((row) => [...row]);
  const { changed, gained, flash } = move(dir);
  if (!changed) return;
  gameState.score += gained;
  mergeFlash = flash;
  flashTimer = 20;
  scorePop();

  // Siapkan animasi slide: tentukan tujuan tiap tile lama di board baru.
  const newTiles = [];
  for (let r = 0; r < SIZE; r++)
    for (let c = 0; c < SIZE; c++) if (board[r][c]) newTiles.push({ r, c, val: board[r][c] });
  const dest = {};
  const used = new Set();
  for (let r = 0; r < SIZE; r++)
    for (let c = 0; c < SIZE; c++) {
      const v = prev[r][c];
      if (!v) continue;
      // Tile lama bergabung (di sel merge) -> tujuan sel merge tsb.
      if (flash.some((m) => m.r === r && m.c === c)) {
        dest[`${r},${c}`] = { r, c };
        continue;
      }
      const target = newTiles.find((t) => t.val === v && !used.has(`${t.r},${t.c}`) && !(t.r === r && t.c === c));
      if (target) {
        used.add(`${target.r},${target.c}`);
        dest[`${r},${c}`] = { r: target.r, c: target.c };
      } else {
        dest[`${r},${c}`] = { r, c }; // tidak berpindah
      }
    }
  slidePrev = prev;
  slideDest = dest;
  slideNew = [];
  // Tile yang baru muncul = sel terisi yang tidak berasal dari tile lama mana pun.
  const destSet = new Set(Object.values(dest).map((d) => `${d.r},${d.c}`));
  for (const t of newTiles) if (!destSet.has(`${t.r},${t.c}`)) slideNew.push(t);
  animTimer = ANIM_MS;

  spawnTile();
  renderHUD();
  if (!canMove()) {
    endGame();
  } else if (!wonNotified && board.flat().includes(2048)) {
    wonNotified = true;
    meelAlert({
      title: "2048!",
      text: "Kamu berhasil mencapai 2048! Lanjutkan untuk skor yang lebih tinggi!",
      icon: "success",
      confirmButtonText: "LANJUT",
    });
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

function drawTile(x, y, w, val, scale = 1, glow = 0) {
  const color = val > 2048 ? SUPER_COLOR : TILE_COLORS[val] || null;
  if (scale !== 1) {
    const cx = x + w / 2;
    const cy = y + w / 2;
    const sw = w * scale;
    ctx.save();
    ctx.translate(cx, cy);
    ctx.scale(scale, scale);
    ctx.translate(-cx, -cy);
    ctx.fillStyle = color.bg;
    roundRect(x + (w - sw) / 2, y + (w - sw) / 2, sw, sw, 10);
    ctx.fill();
    ctx.restore();
  } else {
    ctx.fillStyle = color.bg;
    roundRect(x, y, w, w, 10);
    ctx.fill();
  }
  if (glow > 0) {
    ctx.strokeStyle = `rgba(255,255,255,${glow})`;
    ctx.lineWidth = 3;
    roundRect(x - 1.5, y - 1.5, w + 3, w + 3, 12);
    ctx.stroke();
  }
  const digits = String(val).length;
  const fs = digits <= 1 ? 42 : digits === 2 ? 36 : digits === 3 ? 28 : digits === 4 ? 22 : 18;
  ctx.fillStyle = color.fg;
  ctx.font = `bold ${fs}px "Plus Jakarta Sans", sans-serif`;
  ctx.textAlign = "center";
  ctx.textBaseline = "middle";
  ctx.fillText(String(val), x + w / 2, y + w / 2 + 1);
}

function draw() {
  ctx.fillStyle = "#0b0e14";
  ctx.fillRect(0, 0, canvas.width, canvas.height);

  // Grid latar
  for (let r = 0; r < SIZE; r++)
    for (let c = 0; c < SIZE; c++) {
      const x = c * CELL + PAD / 2;
      const y = r * CELL + PAD / 2;
      ctx.fillStyle = "rgba(255,255,255,0.045)";
      roundRect(x, y, CELL - PAD, CELL - PAD, 10);
      ctx.fill();
    }

  const w = CELL - PAD;
  const glow = (flashTimer / 20) * 0.55;

  // Fase animasi slide: gambar tile lama di posisi interpolasi
  if (animTimer > 0 && slidePrev) {
    const t = 1 - animTimer / ANIM_MS;
    const ease = easeOutCubic(Math.min(1, t));
    for (let r = 0; r < SIZE; r++)
      for (let c = 0; c < SIZE; c++) {
        const v = slidePrev[r][c];
        if (!v) continue;
        const d = slideDest[`${r},${c}`] || { r, c };
        const x = (c + (d.c - c) * ease) * CELL + PAD / 2;
        const y = (r + (d.r - r) * ease) * CELL + PAD / 2;
        const isMerge = mergeFlash.some((m) => m.r === d.r && m.c === d.c);
        drawTile(x, y, w, v, 1, isMerge ? glow : 0);
      }
    // Tile baru muncul dengan pop
    for (const t of slideNew) {
      const scale = easeOutCubic(Math.min(1, t * 1.6));
      drawTile(t.c * CELL + PAD / 2, t.r * CELL + PAD / 2, w, t.val, scale);
    }
    return;
  }

  // State akhir
  for (let r = 0; r < SIZE; r++)
    for (let c = 0; c < SIZE; c++) {
      const val = board[r][c];
      if (!val) continue;
      const x = c * CELL + PAD / 2;
      const y = r * CELL + PAD / 2;
      const isMerge = mergeFlash.some((m) => m.r === r && m.c === c);
      drawTile(x, y, w, val, 1, isMerge ? glow : 0);
    }
}

// Defensive loop: jika frame melempar exception, rAF tetap dijadwalkan agar
// loop tidak pernah mati permanen. 1-2 error transien dilewati (frame di-skip);
// error beruntun (>=3) memicu self-heal: board di-reset ke keadaan valid.
let consecutiveErrors = 0;

let lastFrame = 0;
function gameLoop(ts) {
  if (consecutiveErrors >= 3) {
    consecutiveErrors = 0;
    console.error("[2048] Memulihkan board setelah error loop beruntun...");
    startGame();
  }
  try {
    const dt = lastFrame ? Math.min(50, ts - lastFrame) : 16;
    lastFrame = ts;
    if (flashTimer > 0) flashTimer--;
    if (animTimer > 0) animTimer = Math.max(0, animTimer - dt);
    draw();
    consecutiveErrors = 0;
  } catch (err) {
    consecutiveErrors++;
    console.error("[2048] Error di frame:", err);
  } finally {
    requestAnimationFrame(gameLoop);
  }
}

function startGame() {
  board = emptyBoard();
  gameState.score = 0;
  gameState.isGameOver = false;
  gameState.isPlaying = true;
  wonNotified = false;
  mergeFlash = [];
  flashTimer = 0;
  slidePrev = null;
  slideDest = null;
  slideNew = [];
  animTimer = 0;
  spawnTile();
  spawnTile();
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
    localStorage.setItem("g2048_hi_score", String(finalScore));
    renderHUD();
  }

  document.getElementById("finalScore").textContent = pad(finalScore);
  document
    .getElementById("newHighScore")
    .classList.toggle("hidden", !isNewHigh);

  const gos = document.getElementById("gameOverScreen");
  gos.classList.remove("hidden");
  requestAnimationFrame(() => {
    gos.style.opacity = "1";
    gos.style.pointerEvents = "auto";
  });
}

// ---- Input ----
const KEY_DIRS = {
  ArrowLeft: "left", ArrowRight: "right", ArrowUp: "up", ArrowDown: "down",
  a: "left", d: "right", w: "up", s: "down",
  A: "left", D: "right", W: "up", S: "down",
};

window.addEventListener("keydown", (e) => {
  const dir = KEY_DIRS[e.key];
  if (dir) {
    e.preventDefault();
    if (!gameState.isPlaying) startGame();
    if (gameState.isPlaying && !gameState.isGameOver) doMove(dir);
    return;
  }
  if (e.key === " " || e.key === "Enter") {
    e.preventDefault();
    if (!gameState.isPlaying) startGame();
  }
});

let touchStart = null;
canvas.addEventListener(
  "touchstart",
  (e) => {
    e.preventDefault();
    const t = e.touches[0];
    touchStart = { x: t.clientX, y: t.clientY };
    if (!gameState.isPlaying) startGame();
  },
  { passive: false },
);
canvas.addEventListener("touchmove", (e) => e.preventDefault(), {
  passive: false,
});
canvas.addEventListener(
  "touchend",
  (e) => {
    if (!touchStart) return;
    const t = e.changedTouches[0];
    const dx = t.clientX - touchStart.x;
    const dy = t.clientY - touchStart.y;
    touchStart = null;
    const ax = Math.abs(dx);
    const ay = Math.abs(dy);
    if (ax < 30 && ay < 30) return;
    const dir = ax > ay ? (dx > 0 ? "right" : "left") : dy > 0 ? "down" : "up";
    if (gameState.isPlaying && !gameState.isGameOver) doMove(dir);
  },
  { passive: false },
);

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
      localStorage.removeItem("g2048_hi_score");
      gameState.hiScore = 0;
      renderHUD();
    }
  });
});

// ---- Init ----
renderHUD();
gameLoop();
