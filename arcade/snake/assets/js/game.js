const canvas = document.getElementById("gameCanvas");
const ctx = canvas.getContext("2d");
// ─── KONFIGURASI ───
const GRID_SIZE = 20;
const CELL_SIZE = canvas.width / GRID_SIZE;
const gameState = {
  isPlaying: false,
  isGameOver: false,
  score: 0,
  hiScore: parseInt(localStorage.getItem("snake_hi_score")) || 0,
  speed: 150,
  minSpeed: 60,
};
// ─── STATE SNAKE ───
let snake = [];
let food = {};
let direction = "RIGHT";
let nextDirection = "RIGHT";
let gameLoopInterval = null;
let foodEatenCount = 0;
const scoreEl = document.getElementById("scoreText");
const hiScoreEl = document.getElementById("hiScoreText");
const startScreen = document.getElementById("startScreen");
const gameOverScreen = document.getElementById("gameOverScreen");
const finalScoreEl = document.getElementById("finalScore");
const newHighScoreEl = document.getElementById("newHighScore");
function updateHiScoreDisplay() {
  hiScoreEl.textContent = String(gameState.hiScore).padStart(5, "0");
}
// ─── FUNGSI GAME ───
function initGame() {
  snake = [
    { x: 10, y: 10 },
    { x: 9, y: 10 },
    { x: 8, y: 10 },
  ];
  direction = "RIGHT";
  nextDirection = "RIGHT";
  gameState.score = 0;
  foodEatenCount = 0;
  gameState.speed = 150;
  gameState.isGameOver = false;
  gameState.isPlaying = true;
  scoreEl.textContent = "00000";
  spawnFood();
  startScreen.classList.add("hidden");
  gameOverScreen.classList.add("hidden");
  gameOverScreen.style.opacity = "0";
  gameOverScreen.style.pointerEvents = "none";
  clearInterval(gameLoopInterval);
  gameLoopInterval = setInterval(gameTick, gameState.speed);
}
function spawnFood() {
  let pos;
  do {
    pos = {
      x: Math.floor(Math.random() * GRID_SIZE),
      y: Math.floor(Math.random() * GRID_SIZE),
    };
  } while (snake.some((seg) => seg.x === pos.x && seg.y === pos.y));
  food = pos;
}
function gameTick() {
  direction = nextDirection;
  const head = { ...snake[0] };
  switch (direction) {
    case "UP":
      head.y--;
      break;
    case "DOWN":
      head.y++;
      break;
    case "LEFT":
      head.x--;
      break;
    case "RIGHT":
      head.x++;
      break;
  }
  if (head.x < 0 || head.x >= GRID_SIZE || head.y < 0 || head.y >= GRID_SIZE) {
    endGame();
    return;
  }
  if (snake.some((seg) => seg.x === head.x && seg.y === head.y)) {
    endGame();
    return;
  }
  snake.unshift(head);
  if (head.x === food.x && head.y === food.y) {
    gameState.score += 10;
    foodEatenCount++;
    scoreEl.textContent = String(gameState.score).padStart(5, "0");
    scoreEl.classList.remove("score-pop");
    void scoreEl.offsetWidth;
    scoreEl.classList.add("score-pop");
    spawnFood();
    if (foodEatenCount % 5 === 0 && gameState.speed > gameState.minSpeed) {
      gameState.speed = Math.max(gameState.minSpeed, gameState.speed - 8);
      clearInterval(gameLoopInterval);
      gameLoopInterval = setInterval(gameTick, gameState.speed);
    }
  } else {
    snake.pop();
  }
  draw();
}
function endGame() {
  gameState.isGameOver = true;
  gameState.isPlaying = false;
  clearInterval(gameLoopInterval);
  gameLoopInterval = null;
  const isNewHigh = gameState.score > gameState.hiScore;
  if (isNewHigh) {
    gameState.hiScore = gameState.score;
    localStorage.setItem("snake_hi_score", gameState.hiScore);
    updateHiScoreDisplay();
  }
  finalScoreEl.textContent = String(gameState.score).padStart(5, "0");
  newHighScoreEl.classList.toggle("hidden", !isNewHigh);
  gameOverScreen.classList.remove("hidden");
  gameOverScreen.style.opacity = "1";
  gameOverScreen.style.pointerEvents = "auto";
  draw();
}
// ─── Helper (cross-browser) ───
function fillRoundRect(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.lineTo(x + w - r, y);
  ctx.arcTo(x + w, y, x + w, y + r, r);
  ctx.lineTo(x + w, y + h - r);
  ctx.arcTo(x + w, y + h, x + w - r, y + h, r);
  ctx.lineTo(x + r, y + h);
  ctx.arcTo(x, y + h, x, y + h - r, r);
  ctx.lineTo(x, y + r);
  ctx.arcTo(x, y, x + r, y, r);
  ctx.closePath();
  ctx.fill();
}
// ─── RENDER ───
function draw() {
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  ctx.fillStyle = "#0d1117";
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.strokeStyle = "rgba(34, 197, 94, 0.04)";
  ctx.lineWidth = 1;
  for (let i = 0; i <= GRID_SIZE; i++) {
    ctx.beginPath();
    ctx.moveTo(i * CELL_SIZE, 0);
    ctx.lineTo(i * CELL_SIZE, canvas.height);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(0, i * CELL_SIZE);
    ctx.lineTo(canvas.width, i * CELL_SIZE);
    ctx.stroke();
  }
  if (food) {
    const fx = food.x * CELL_SIZE;
    const fy = food.y * CELL_SIZE;
    const glow = ctx.createRadialGradient(
      fx + CELL_SIZE / 2,
      fy + CELL_SIZE / 2,
      0,
      fx + CELL_SIZE / 2,
      fy + CELL_SIZE / 2,
      CELL_SIZE,
    );
    glow.addColorStop(0, "rgba(239, 68, 68, 0.4)");
    glow.addColorStop(1, "rgba(239, 68, 68, 0)");
    ctx.fillStyle = glow;
    ctx.fillRect(
      fx - CELL_SIZE / 2,
      fy - CELL_SIZE / 2,
      CELL_SIZE * 2,
      CELL_SIZE * 2,
    );
    ctx.fillStyle = "#ef4444";
    ctx.beginPath();
    ctx.arc(
      fx + CELL_SIZE / 2,
      fy + CELL_SIZE / 2,
      CELL_SIZE / 2.5,
      0,
      Math.PI * 2,
    );
    ctx.fill();
    ctx.fillStyle = "rgba(255,255,255,0.3)";
    ctx.beginPath();
    ctx.arc(fx + CELL_SIZE / 2 - 2, fy + CELL_SIZE / 2 - 2, 3, 0, Math.PI * 2);
    ctx.fill();
  }
  snake.forEach((seg, index) => {
    const x = seg.x * CELL_SIZE;
    const y = seg.y * CELL_SIZE;
    const isHead = index === 0;
    const padding = isHead ? 1 : 2;
    const radius = isHead ? 5 : 4;
    if (isHead) {
      const headGlow = ctx.createRadialGradient(
        x + CELL_SIZE / 2,
        y + CELL_SIZE / 2,
        2,
        x + CELL_SIZE / 2,
        y + CELL_SIZE / 2,
        CELL_SIZE,
      );
      headGlow.addColorStop(0, "rgba(34, 197, 94, 0.3)");
      headGlow.addColorStop(1, "rgba(34, 197, 94, 0)");
      ctx.fillStyle = headGlow;
      ctx.fillRect(x - 4, y - 4, CELL_SIZE + 8, CELL_SIZE + 8);
    }
    const ratio = index / snake.length;
    const r = Math.round(34 + (22 - 34) * ratio);
    const g = Math.round(197 + (163 - 197) * ratio);
    const b = Math.round(94 + (44 - 94) * ratio);
    ctx.fillStyle = `rgb(${r}, ${g}, ${b})`;
    fillRoundRect(
      ctx,
      x + padding,
      y + padding,
      CELL_SIZE - padding * 2,
      CELL_SIZE - padding * 2,
      radius,
    );
    if (isHead) {
      ctx.fillStyle = "white";
      let eyeX1, eyeY1, eyeX2, eyeY2;
      const eyeSize = 3;
      switch (direction) {
        case "RIGHT":
          eyeX1 = x + 13;
          eyeY1 = y + 5;
          eyeX2 = x + 13;
          eyeY2 = y + 12;
          break;
        case "LEFT":
          eyeX1 = x + 4;
          eyeY1 = y + 5;
          eyeX2 = x + 4;
          eyeY2 = y + 12;
          break;
        case "UP":
          eyeX1 = x + 5;
          eyeY1 = y + 4;
          eyeX2 = x + 12;
          eyeY2 = y + 4;
          break;
        case "DOWN":
          eyeX1 = x + 5;
          eyeY1 = y + 13;
          eyeX2 = x + 12;
          eyeY2 = y + 13;
          break;
      }
      ctx.beginPath();
      ctx.arc(eyeX1, eyeY1, eyeSize / 1.5, 0, Math.PI * 2);
      ctx.fill();
      ctx.beginPath();
      ctx.arc(eyeX2, eyeY2, eyeSize / 1.5, 0, Math.PI * 2);
      ctx.fill();
      ctx.fillStyle = "#0d1117";
      ctx.beginPath();
      ctx.arc(
        eyeX1 + (direction === "RIGHT" ? 1 : direction === "LEFT" ? -1 : 0),
        eyeY1 + (direction === "DOWN" ? 1 : direction === "UP" ? -1 : 0),
        1.2,
        0,
        Math.PI * 2,
      );
      ctx.fill();
      ctx.beginPath();
      ctx.arc(
        eyeX2 + (direction === "RIGHT" ? 1 : direction === "LEFT" ? -1 : 0),
        eyeY2 + (direction === "DOWN" ? 1 : direction === "UP" ? -1 : 0),
        1.2,
        0,
        Math.PI * 2,
      );
      ctx.fill();
    }
  });
}
// ─── INPUT ───
window.addEventListener("keydown", (e) => {
  const key = e.key;
  if (["ArrowUp", "ArrowDown", "ArrowLeft", "ArrowRight", " "].includes(key)) {
    e.preventDefault();
  }
  if (!gameState.isPlaying && (key === " " || key === "Enter")) {
    initGame();
    return;
  }
  if (!gameState.isPlaying) return;
  switch (key) {
    case "ArrowUp":
    case "w":
    case "W":
      if (direction !== "DOWN") nextDirection = "UP";
      break;
    case "ArrowDown":
    case "s":
    case "S":
      if (direction !== "UP") nextDirection = "DOWN";
      break;
    case "ArrowLeft":
    case "a":
    case "A":
      if (direction !== "RIGHT") nextDirection = "LEFT";
      break;
    case "ArrowRight":
    case "d":
    case "D":
      if (direction !== "LEFT") nextDirection = "RIGHT";
      break;
  }
});
let touchStartX = 0;
let touchStartY = 0;
canvas.addEventListener(
  "touchstart",
  (e) => {
    e.preventDefault();
    const touch = e.touches[0];
    touchStartX = touch.clientX;
    touchStartY = touch.clientY;

    if (!gameState.isPlaying) {
      initGame();
    }
  },
  { passive: false },
);
canvas.addEventListener(
  "touchmove",
  (e) => {
    e.preventDefault();
  },
  { passive: false },
);
canvas.addEventListener(
  "touchend",
  (e) => {
    if (!gameState.isPlaying) return;
    e.preventDefault();
    const rect = canvas.getBoundingClientRect();
    const touch = e.changedTouches[0];
    const dx = touch.clientX - touchStartX;
    const dy = touch.clientY - touchStartY;
    const absDx = Math.abs(dx);
    const absDy = Math.abs(dy);
    if (Math.max(absDx, absDy) < 20) return;
    if (absDx > absDy) {
      if (dx > 0 && direction !== "LEFT") nextDirection = "RIGHT";
      else if (dx < 0 && direction !== "RIGHT") nextDirection = "LEFT";
    } else {
      if (dy > 0 && direction !== "UP") nextDirection = "DOWN";
      else if (dy < 0 && direction !== "DOWN") nextDirection = "UP";
    }
  },
  { passive: false },
);
document.getElementById("btn-up").addEventListener("touchstart", (e) => {
  e.preventDefault();
  if (gameState.isPlaying && direction !== "DOWN") nextDirection = "UP";
});
document.getElementById("btn-down").addEventListener("touchstart", (e) => {
  e.preventDefault();
  if (gameState.isPlaying && direction !== "UP") nextDirection = "DOWN";
});
document.getElementById("btn-left").addEventListener("touchstart", (e) => {
  e.preventDefault();
  if (gameState.isPlaying && direction !== "RIGHT") nextDirection = "LEFT";
});
document.getElementById("btn-right").addEventListener("touchstart", (e) => {
  e.preventDefault();
  if (gameState.isPlaying && direction !== "LEFT") nextDirection = "RIGHT";
});
document.getElementById("restartBtn").addEventListener("click", initGame);
startScreen.addEventListener("click", () => {
  if (!gameState.isPlaying) initGame();
});
// ─── DRAW ON LOAD ───
function drawEmptyBoard() {
  ctx.fillStyle = "#0d1117";
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.strokeStyle = "rgba(34, 197, 94, 0.04)";
  ctx.lineWidth = 1;
  for (let i = 0; i <= GRID_SIZE; i++) {
    ctx.beginPath();
    ctx.moveTo(i * CELL_SIZE, 0);
    ctx.lineTo(i * CELL_SIZE, canvas.height);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(0, i * CELL_SIZE);
    ctx.lineTo(canvas.width, i * CELL_SIZE);
    ctx.stroke();
  }
  const preview = [
    { x: 10, y: 10 },
    { x: 9, y: 10 },
    { x: 8, y: 10 },
  ];
  preview.forEach((seg, i) => {
    const ratio = i / preview.length;
    const r = Math.round(34 + (22 - 34) * ratio);
    const g = Math.round(197 + (163 - 197) * ratio);
    const b = Math.round(94 + (44 - 94) * ratio);
    ctx.fillStyle = `rgba(${r}, ${g}, ${b}, 0.3)`;
    fillRoundRect(
      ctx,
      seg.x * CELL_SIZE + 2,
      seg.y * CELL_SIZE + 2,
      CELL_SIZE - 4,
      CELL_SIZE - 4,
      4,
    );
  });
}
updateHiScoreDisplay();
drawEmptyBoard();
