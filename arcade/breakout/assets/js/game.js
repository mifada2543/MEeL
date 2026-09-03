const canvas = document.getElementById("gameCanvas");
const ctx = canvas.getContext("2d");

const W = 480;
const H = 600;
const PADDLE_W = 90;
const PADDLE_H = 14;
const PADDLE_Y = H - 44;
const BALL_R = 8;
const PADDLE_SPEED = 520;

const BRICK_COLS = 8;
const BRICK_ROWS = 6;
const BRICK_MARGIN_X = 24;
const BRICK_GAP_X = 4;
const BRICK_GAP_Y = 6;
const BRICK_H = 22;
const BRICK_START_Y = 70;
const BRICK_W =
  (W - BRICK_MARGIN_X * 2 - (BRICK_COLS - 1) * BRICK_GAP_X) / BRICK_COLS;

const ROW_DEFS = [
  { color: "#ff5252", points: 50 },
  { color: "#ff7043", points: 40 },
  { color: "#ffca28", points: 30 },
  { color: "#66bb6a", points: 20 },
  { color: "#42a5f5", points: 10 },
  { color: "#ab47bc", points: 10 },
];

const gameState = {
  isPlaying: false,
  isGameOver: false,
  score: 0,
  hiScore: parseInt(localStorage.getItem("breakout_hi_score") || "0", 10),
  lives: 3,
  level: 1,
};

const paddle = { x: W / 2 - PADDLE_W / 2, w: PADDLE_W, h: PADDLE_H, y: PADDLE_Y };
const ball = { x: 0, y: 0, vx: 0, vy: 0, r: BALL_R, stuck: true };
let bricks = [];
let keys = { left: false, right: false };
let lastTime = 0;
let particles = []; 

function spawnParticles(x, y, color) {
  for (let i = 0; i < 10; i++) {
    const angle = Math.random() * Math.PI * 2;
    const speed = 60 + Math.random() * 140;
    particles.push({
      x,
      y,
      vx: Math.cos(angle) * speed,
      vy: Math.sin(angle) * speed - 60,
      life: 0.5 + Math.random() * 0.3,
      maxLife: 0.8,
      size: 2 + Math.random() * 3,
      color,
    });
  }
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

function baseSpeed() {
  return 5.5 + (gameState.level - 1) * 0.7;
}

function resetPaddleAndBall() {
  paddle.x = W / 2 - PADDLE_W / 2;
  ball.x = paddle.x + PADDLE_W / 2;
  ball.y = PADDLE_Y - BALL_R;
  ball.vx = 0;
  ball.vy = 0;
  ball.stuck = true;
}

function buildBricks() {
  bricks = [];
  for (let r = 0; r < BRICK_ROWS; r++) {
    const def = ROW_DEFS[r % ROW_DEFS.length];
    for (let c = 0; c < BRICK_COLS; c++) {
      bricks.push({
        x: BRICK_MARGIN_X + c * (BRICK_W + BRICK_GAP_X),
        y: BRICK_START_Y + r * (BRICK_H + BRICK_GAP_Y),
        w: BRICK_W,
        h: BRICK_H,
        color: def.color,
        points: def.points,
        alive: true,
      });
    }
  }
}

function launchBall() {
  if (!ball.stuck) return;
  ball.stuck = false;
  const speed = baseSpeed();
  const angle = (Math.random() - 0.5) * (Math.PI / 3); 
  ball.vx = Math.sin(angle) * speed;
  ball.vy = -Math.cos(angle) * speed;
}

function bounceOffWalls() {
  if (ball.x - ball.r <= 0) {
    ball.x = ball.r;
    ball.vx = Math.abs(ball.vx);
  } else if (ball.x + ball.r >= W) {
    ball.x = W - ball.r;
    ball.vx = -Math.abs(ball.vx);
  }
  if (ball.y - ball.r <= 0) {
    ball.y = ball.r;
    ball.vy = Math.abs(ball.vy);
  }
}

function hitPaddle() {
  if (
    ball.vy > 0 &&
    ball.y + ball.r >= paddle.y &&
    ball.y + ball.r <= paddle.y + paddle.h + 10 &&
    ball.x >= paddle.x - 6 &&
    ball.x <= paddle.x + paddle.w + 6
  ) {
    const rel = Math.max(0, Math.min(1, (ball.x - paddle.x) / paddle.w));
    const angle = (rel - 0.5) * (Math.PI / 3); 
    const speed = Math.hypot(ball.vx, ball.vy);
    ball.vx = Math.sin(angle) * speed;
    ball.vy = -Math.cos(angle) * speed;
    ball.y = paddle.y - ball.r;
    if (Math.abs(ball.vx) < 2.5) ball.vx = ball.vx < 0 ? -2.5 : 2.5;
  }
}

function hitBricks() {
  for (const b of bricks) {
    if (!b.alive) continue;
    const cx = Math.max(b.x, Math.min(ball.x, b.x + b.w));
    const cy = Math.max(b.y, Math.min(ball.y, b.y + b.h));
    const dx = ball.x - cx;
    const dy = ball.y - cy;
    if (dx * dx + dy * dy > ball.r * ball.r) continue;

    b.alive = false;
    gameState.score += b.points;
    scorePop();
    renderHUD();
    spawnParticles(b.x + b.w / 2, b.y + b.h / 2, b.color);

    
    const overlapX = Math.abs(ball.x - (b.x + b.w / 2));
    const overlapY = Math.abs(ball.y - (b.y + b.h / 2));
    if (overlapX / (b.w / 2 + ball.r) > overlapY / (b.h / 2 + ball.r)) {
      ball.vx = ball.x < b.x + b.w / 2 ? -Math.abs(ball.vx) : Math.abs(ball.vx);
    } else {
      ball.vy = ball.y < b.y + b.h / 2 ? -Math.abs(ball.vy) : Math.abs(ball.vy);
    }
    return;
  }
}

function loseLife() {
  gameState.lives--;
  if (gameState.lives > 0) {
    resetPaddleAndBall();
  } else {
    endGame();
  }
}

function levelUp() {
  gameState.level++;
  buildBricks();
  resetPaddleAndBall();
}

function update(dt) {
  
  if (keys.left) paddle.x -= PADDLE_SPEED * dt;
  if (keys.right) paddle.x += PADDLE_SPEED * dt;
  paddle.x = Math.max(6, Math.min(W - paddle.w - 6, paddle.x));

  if (ball.stuck) {
    ball.x = paddle.x + paddle.w / 2;
    ball.y = PADDLE_Y - ball.r;
    return;
  }

  ball.x += ball.vx * dt * 60;
  ball.y += ball.vy * dt * 60;

  bounceOffWalls();
  hitBricks();
  hitPaddle();

  
  if (ball.y - ball.r > H) {
    loseLife();
    return;
  }

  
  if (bricks.every((b) => !b.alive)) {
    levelUp();
  }

  
  for (let i = particles.length - 1; i >= 0; i--) {
    const p = particles[i];
    p.life -= dt;
    if (p.life <= 0) {
      particles.splice(i, 1);
      continue;
    }
    p.vy += 380 * dt; 
    p.x += p.vx * dt;
    p.y += p.vy * dt;
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

function drawHeart(x, y, size) {
  ctx.fillStyle = "#fb7185";
  ctx.font = `${size}px serif`;
  ctx.textAlign = "left";
  ctx.textBaseline = "middle";
  ctx.fillText("♥", x, y);
}

function draw() {
  ctx.fillStyle = "#0b0e14";
  ctx.fillRect(0, 0, W, H);

  
  for (let i = 0; i < gameState.lives; i++) drawHeart(14 + i * 26, 24, 18);
  ctx.fillStyle = "#fb923c";
  ctx.font = 'bold 13px "Plus Jakarta Sans", sans-serif';
  ctx.textAlign = "right";
  ctx.textBaseline = "middle";
  ctx.fillText(`LEVEL ${gameState.level}`, W - 14, 24);

  
  for (const b of bricks) {
    if (!b.alive) continue;
    ctx.fillStyle = b.color;
    roundRect(b.x, b.y, b.w, b.h, 5);
    ctx.fill();
    ctx.fillStyle = "rgba(255,255,255,0.18)";
    ctx.fillRect(b.x + 3, b.y + 3, b.w - 6, 4);
  }

  
  for (const p of particles) {
    ctx.globalAlpha = Math.max(0, p.life / p.maxLife);
    ctx.fillStyle = p.color;
    ctx.fillRect(p.x - p.size / 2, p.y - p.size / 2, p.size, p.size);
  }
  ctx.globalAlpha = 1;

  
  ctx.fillStyle = "#fda4af";
  roundRect(paddle.x, paddle.y, paddle.w, paddle.h, 7);
  ctx.fill();
  ctx.fillStyle = "rgba(255,255,255,0.25)";
  ctx.fillRect(paddle.x + 4, paddle.y + 3, paddle.w - 8, 4);

  
  ctx.fillStyle = "#ffffff";
  ctx.beginPath();
  ctx.arc(ball.x, ball.y, ball.r, 0, Math.PI * 2);
  ctx.fill();
  ctx.fillStyle = "rgba(255,255,255,0.35)";
  ctx.beginPath();
  ctx.arc(ball.x - 2.5, ball.y - 2.5, 3, 0, Math.PI * 2);
  ctx.fill();

  
  if (ball.stuck && gameState.isPlaying) {
    ctx.fillStyle = "rgba(148,163,184,0.9)";
    ctx.font = 'bold 11px "Plus Jakarta Sans", sans-serif';
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    ctx.fillText("SPASI / TAP untuk meluncurkan", W / 2, H - 16);
  }
}


function gameLoop(ts) {
  const dt = Math.min(0.05, (ts - lastTime) / 1000);
  lastTime = ts;
  if (gameState.isPlaying && !gameState.isGameOver) update(dt);
  draw();
  requestAnimationFrame(gameLoop);
}


function startGame() {
  gameState.score = 0;
  gameState.lives = 3;
  gameState.level = 1;
  gameState.isGameOver = false;
  gameState.isPlaying = true;
  particles = [];
  buildBricks();
  resetPaddleAndBall();
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
    localStorage.setItem("breakout_hi_score", String(finalScore));
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


window.addEventListener("keydown", (e) => {
  if (e.key === " " || e.key === "Enter") {
    e.preventDefault();
    if (!gameState.isPlaying) {
      startGame();
    } else if (!gameState.isGameOver) {
      launchBall();
    }
    return;
  }
  if (e.key === "ArrowLeft") {
    e.preventDefault();
    keys.left = true;
  } else if (e.key === "ArrowRight") {
    e.preventDefault();
    keys.right = true;
  }
});
window.addEventListener("keyup", (e) => {
  if (e.key === "ArrowLeft") keys.left = false;
  if (e.key === "ArrowRight") keys.right = false;
});

function pointerX(clientX) {
  const rect = canvas.getBoundingClientRect();
  return ((clientX - rect.left) / rect.width) * W;
}

canvas.addEventListener("mousemove", (e) => {
  if (!gameState.isPlaying) return;
  paddle.x = Math.max(6, Math.min(W - paddle.w - 6, pointerX(e.clientX) - paddle.w / 2));
});
canvas.addEventListener(
  "touchmove",
  (e) => {
    e.preventDefault();
    if (!gameState.isPlaying) return;
    const t = e.touches[0];
    paddle.x = Math.max(6, Math.min(W - paddle.w - 6, pointerX(t.clientX) - paddle.w / 2));
  },
  { passive: false },
);
canvas.addEventListener(
  "touchstart",
  (e) => {
    e.preventDefault();
    if (!gameState.isPlaying) {
      startGame();
    } else if (!gameState.isGameOver) {
      launchBall();
    }
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
      localStorage.removeItem("breakout_hi_score");
      gameState.hiScore = 0;
      renderHUD();
    }
  });
});


renderHUD();
requestAnimationFrame(gameLoop);
