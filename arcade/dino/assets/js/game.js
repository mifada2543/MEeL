const canvas = document.getElementById("gameCanvas");
const ctx = canvas.getContext("2d");
// 1. STATE & KONFIGURASI GLOBAL
const gameState = {
  isPlaying: false,
  isGameOver: false,
  score: 0,
  hiScore: localStorage.getItem("miku_hi_score") || 0,
  gameSpeed: 6,
  baseSpeedSetting: 6,
  gravityValue: 0.6,
  isTetoActive: false,
};
const cheatState = {
  godMode: false,
  moonGravity: false,
  hyperSpeed: false,
};
document.getElementById("hiScoreText").innerText = String(
  gameState.hiScore,
).padStart(5, "0");
const GAME_CONTROLS = {
  lockableToggles: [
    "themeToggle",
    "godModeToggle",
    "moonGravityToggle",
    "hyperSpeedToggle",
    "resetScoreBtn",
  ],
};
document.getElementById("resetScoreBtn").addEventListener("click", () => {
  if (gameState.isPlaying) return;

  meelConfirm({
    title: "Reset High Score",
    text: "Apakah kamu yakin ingin mereset High Score kembali ke nol?",
    confirmButtonText: "RESET",
  }).then((konfirmasi) => {
    if (konfirmasi) {
      localStorage.removeItem("miku_hi_score");
      gameState.hiScore = 0;
      document.getElementById("hiScoreText").innerText = "00000";
    }
  });
});
document.getElementById("resetGameBtn").addEventListener("click", () => {
  if (!gameState.isPlaying) {
    meelAlert({
      title: "Info",
      text: "Tidak ada permainan yang sedang berjalan!",
      icon: "info",
    });
    return;
  }

  meelConfirm({
    title: "Hentikan Game",
    text: "Apakah kamu ingin menghentikan permainan yang sedang berjalan?",
    confirmButtonText: "HENTIKAN",
  }).then((konfirmasi) => {
    if (konfirmasi) {
      endGame();
    }
  });
});
function setGameplayControlsLocked(isLocked) {
  GAME_CONTROLS.lockableToggles.forEach((id) => {
    const toggleBtn = document.getElementById(id);
    if (!toggleBtn) return;
    toggleBtn.disabled = isLocked;
    if (isLocked) {
      toggleBtn.parentElement.classList.add("opacity-50", "cursor-not-allowed");
      toggleBtn.parentElement.classList.remove("cursor-pointer");
    } else {
      toggleBtn.parentElement.classList.remove(
        "opacity-50",
        "cursor-not-allowed",
      );
      toggleBtn.parentElement.classList.add("cursor-pointer");
    }
  });
}
let activeImgRun1 = imgMikuRun1;
let activeImgRun2 = imgMikuRun2;
let activeImgJump = imgMikuJump;
let activeImgDuck = imgMikuDuck;
let activeImgObstacleDarat = imgNegi;
let activeImgObstacleUdara = imgSpeakerMiku;
function renderStartScreenChibi() {
  const chibiContainer = document.getElementById("chibiIconContainer");
  if (!gameState.isTetoActive) {
    chibiContainer.innerHTML = `<svg class="w-20 h-20" viewBox="0 0 60 70"><path d="M 12,22 C 2,15 -5,25 0,40 C 2,48 8,46 12,40" fill="#208E87" /><path d="M 48,22 C 58,15 65,25 60,40 C 58,48 52,46 48,40" fill="#39C5BB" /><circle cx="30" cy="24" r="14" fill="#FFE0D2" /><path d="M 16,14 C 20,8 40,8 44,14 C 45,18 15,18 16,14 Z" fill="#39C5BB" /><rect x="13" y="18" width="4" height="10" fill="#FF4081" rx="1" /><rect x="43" y="18" width="4" height="10" fill="#FF4081" rx="1" /><circle cx="25" cy="24" r="2.5" fill="#208E87" /><circle cx="25.5" cy="23.5" r="1" fill="white" /><circle cx="35" cy="24" r="2.5" fill="#208E87" /><circle cx="35.5" cy="23.5" r="1" fill="white" /><path d="M 28,28 Q 30,30 32,28" stroke="#FF4081" stroke-width="1.5" fill="none" /></svg>`;
  } else {
    chibiContainer.innerHTML = `<svg class="w-20 h-20" viewBox="0 0 60 70"><path d="M 14,24 C 5,16 -3,28 1,38 C 4,45 10,42 12,35" fill="#C2185B" /><path d="M 12,30 C 5,26 2,34 5,39 C 7,42 10,41 11,36" fill="#FF5E7E" /><path d="M 46,24 C 55,16 63,28 59,38 C 56,45 50,42 48,35" fill="#FF5E7E" /><path d="M 48,30 C 55,26 58,34 55,39 C 53,42 50,41 49,36" fill="#C2185B" /><circle cx="30" cy="24" r="14" fill="#FFE0D2" /><path d="M 16,14 C 20,8 40,8 44,14 C 45,18 15,18 16,14 Z" fill="#FF5E7E" /><rect x="13" y="18" width="4" height="10" fill="#FFD700" rx="1" /><rect x="43" y="18" width="4" height="10" fill="#FFD700" rx="1" /><circle cx="25" cy="24" r="2.5" fill="#C2185B" /><circle cx="25.5" cy="23.5" r="1" fill="white" /><circle cx="35" cy="24" r="2.5" fill="#C2185B" /><circle cx="35.5" cy="23.5" r="1" fill="white" /><path d="M 27,28 Q 30,31 33,28" stroke="#FF5E7E" stroke-width="1.8" fill="none" /></svg>`;
  }
}
// 4. EVENT LISTENERS PANEL CHEAT & TEMA
document.getElementById("themeToggle").addEventListener("change", (e) => {
  gameState.isTetoActive = e.target.checked;
  if (gameState.isTetoActive) {
    activeImgRun1 = imgTetoRun1;
    activeImgRun2 = imgTetoRun2;
    activeImgJump = imgTetoJump;
    activeImgDuck = imgTetoDuck;
    activeImgObstacleDarat = imgBaguette;
    activeImgObstacleUdara = imgSpeakerTeto;
  } else {
    activeImgRun1 = imgMikuRun1;
    activeImgRun2 = imgMikuRun2;
    activeImgJump = imgMikuJump;
    activeImgDuck = imgMikuDuck;
    activeImgObstacleDarat = imgNegi;
    activeImgObstacleUdara = imgSpeakerMiku;
  }
    const root = document.documentElement;
  const title = document.getElementById("gameTitle");
  const desc = document.getElementById("gameDescription");
  const sync = document.getElementById("syncStatus");
  const subTag = document.getElementById("subTitleTag");
  if (gameState.isTetoActive) {
    root.style.setProperty("--theme-primary", "#FF5E7E");
    root.style.setProperty("--theme-glow", "rgba(255, 94, 126, 0.15)");
    root.style.setProperty("--theme-glow-heavy", "rgba(255, 94, 126, 0.4)");
    root.style.setProperty(
      "--theme-gradient",
      "linear-gradient(to right, #FF5E7E, #FFD700)",
    );
    title.className =
      "text-3xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-rose-500 via-pink-400 to-yellow-400 tracking-tight flex items-center gap-2 transition-all duration-300";
    if (subTag) {
      subTag.className =
        "text-[10px] tracking-normal px-2 py-0.5 rounded bg-rose-950/70 text-rose-300 border border-rose-500/30 font-mono";
    }
    desc.innerText =
      "Lompati Roti Baguette lezat bersama sang Diva Chimera Teto!";
    sync.className = "text-rose-400 transition-colors";
  } else {
    root.style.setProperty("--theme-primary", "#39C5BB");
    root.style.setProperty("--theme-glow", "rgba(57, 197, 187, 0.15)");
    root.style.setProperty("--theme-glow-heavy", "rgba(57, 197, 187, 0.4)");
    root.style.setProperty(
      "--theme-gradient",
      "linear-gradient(to right, #39C5BB, #FF4081)",
    );
    title.className =
      "text-3xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-teal-400 via-teal-300 to-pink-400 tracking-tight flex items-center gap-2 transition-all duration-300";
    if (subTag) {
      subTag.className =
        "text-[10px] tracking-normal px-2 py-0.5 rounded bg-teal-950/70 text-teal-300 border border-teal-500/30 font-mono";
    }
    desc.innerText = "Lompati Negi bersama Diva Virtual ter-HD abad ini!";
    sync.className = "text-teal-400 transition-colors";
  }
  renderStartScreenChibi();
  if (!gameState.isPlaying) {
    miku = new Miku();
    miku.y = 220 - miku.height;
  }
});  document
  .getElementById("godModeToggle")
  .addEventListener("change", (e) => (cheatState.godMode = e.target.checked));
document
  .getElementById("hyperSpeedToggle")
  .addEventListener(
    "change",
    (e) => (cheatState.hyperSpeed = e.target.checked),
  );
document.getElementById("moonGravityToggle").addEventListener("change", (e) => {
  cheatState.moonGravity = e.target.checked;
  gameState.gravityValue = cheatState.moonGravity ? 0.25 : 0.6;
  if (miku) miku.gravity = gameState.gravityValue;
});
class Miku {
  constructor() {
    this.x = 50;
    this.y = 150;
    this.width = 54;
    this.height = 66;
    this.vy = 0;
    this.gravity = gameState.gravityValue;
    this.jumpForce = cheatState.moonGravity ? -8 : -12;
    this.isJumping = false;
    this.isDucking = false;
    this.runFrame = 0;
    this.animTimer = 0;
  }
  jump() {
    if (!this.isJumping && !this.isDucking) {
      this.vy = this.jumpForce;
      this.isJumping = true;
    }
  }
  duck(state) {
    if (!this.isJumping) {
      this.isDucking = state;
      this.height = state ? 46 : 66;
    }
  }
  update() {
    this.vy += this.gravity;
    this.y += this.vy;
    const groundY = 220 - this.height;
    if (this.y >= groundY) {
      this.y = groundY;
      this.vy = 0;
      this.isJumping = false;
    }
    if (!this.isJumping && !this.isDucking) {
      this.animTimer++;
      if (this.animTimer > 7) {
        this.runFrame = this.runFrame === 0 ? 1 : 0;
        this.animTimer = 0;
      }
    }
  }
  draw() {
    ctx.save();
    if (this.isJumping) {
      ctx.drawImage(activeImgJump, this.x, this.y, this.width, this.height);
    } else if (this.isDucking) {
      ctx.drawImage(activeImgDuck, this.x, this.y, 64, this.height);
    } else {
      const currentImg = this.runFrame === 0 ? activeImgRun1 : activeImgRun2;
      ctx.drawImage(currentImg, this.x, this.y, this.width, this.height);
    }
    ctx.restore();
  }
}
class Obstacle {
  constructor(type) {
    this.type = type;
    this.x = canvas.width + 50;
    if (type === "darat") {
      this.width = 25 + Math.random() * 10;
      this.height = 42 + Math.random() * 20;
      this.y = 220 - this.height;
    } else {
      this.width = 32;
      this.height = 32;
      this.y = Math.random() > 0.5 ? 135 : 170;
    }
  }
  update() {
    this.x -= gameState.gameSpeed;
  }
  draw() {
    ctx.drawImage(
      this.type === "darat" ? activeImgObstacleDarat : activeImgObstacleUdara,
      this.x,
      this.y,
      this.width,
      this.height,
    );
  }
}
class BackgroundItem {
  constructor(type) {
    this.type = type;
    this.x = canvas.width + Math.random() * 100;
    if (type === "cloud") {
      this.y = 25 + Math.random() * 65;
      this.speed = 0.4 + Math.random() * 0.8;
      this.size = 32 + Math.random() * 38;
    }
  }
  update() {
    if (this.type === "cloud") this.x -= this.speed;
  }
  draw() {
    if (this.type === "cloud") {
      ctx.fillStyle = gameState.isTetoActive
        ? "rgba(255, 94, 126, 0.12)"
        : "rgba(57, 197, 187, 0.12)";
      ctx.beginPath();
      ctx.arc(this.x, this.y, this.size / 2, 0, Math.PI * 2);
      ctx.arc(
        this.x + this.size * 0.4,
        this.y - this.size * 0.1,
        this.size / 3,
        0,
        Math.PI * 2,
      );
      ctx.arc(
        this.x - this.size * 0.4,
        this.y + this.size * 0.05,
        this.size / 3,
        0,
        Math.PI * 2,
      );
      ctx.fill();
    }
  }
}
let miku;
let obstacles = [];
let bgItems = [];
let obstacleTimer = 0;
let nextObstacleInterval = 100;
function resetGame() {
  miku = new Miku();
  obstacles = [];
  bgItems = [];
  gameState.score = 0;
  gameState.gameSpeed = cheatState.hyperSpeed ? 15 : gameState.baseSpeedSetting;
  obstacleTimer = 0;
  nextObstacleInterval = 100;
  gameState.isGameOver = false;
  gameState.isPlaying = true;
  for (let i = 0; i < 4; i++) {
    let cloud = new BackgroundItem("cloud");
    cloud.x = Math.random() * canvas.width;
    bgItems.push(cloud);
  }
  document.getElementById("gameOverScreen").classList.add("hidden");
  document.getElementById("startScreen").classList.add("hidden");
  setGameplayControlsLocked(true);
}
function endGame() {
  gameState.isGameOver = true;
  gameState.isPlaying = false;
  document.getElementById("gameOverScreen").classList.remove("hidden");
  let finalScore = Math.floor(gameState.score);
  if (finalScore > gameState.hiScore) {
    gameState.hiScore = finalScore;
    localStorage.setItem("miku_hi_score", gameState.hiScore);
    document.getElementById("hiScoreText").innerText = String(
      gameState.hiScore,
    ).padStart(5, "0");
  }I
  setGameplayControlsLocked(false);
}
function checkCollision(rect1, rect2) {
  const paddingX = 8;
  const paddingY = 6;
  return (
    rect1.x + paddingX < rect2.x + rect2.width - paddingX &&
    rect1.x + rect1.width - paddingX > rect2.x + paddingX &&
    rect1.y + paddingY < rect2.y + rect2.height - paddingY &&
    rect1.y + rect1.height - paddingY > rect2.y + paddingY
  );
}
function gameLoop() {
  ctx.fillStyle = "#080b11";
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.strokeStyle = gameState.isTetoActive
    ? "rgba(255, 94, 126, 0.25)"
    : "rgba(57, 197, 187, 0.25)";
  ctx.lineWidth = 1.5;
  ctx.beginPath();
  ctx.moveTo(0, 220);
  ctx.lineTo(canvas.width, 220);
  ctx.stroke();
  let gridLineOffset = (Date.now() / 25) % 40;
  ctx.strokeStyle = gameState.isTetoActive
    ? "rgba(255, 94, 126, 0.09)"
    : "rgba(57, 197, 187, 0.09)";
  for (let x = -gridLineOffset; x < canvas.width; x += 40) {
    ctx.beginPath();
    ctx.moveTo(x, 220);
    ctx.lineTo(x - 55, canvas.height);
    ctx.stroke();
  }
  if (gameState.isPlaying && !gameState.isGameOver) {
    bgItems.forEach((item, index) => {
      item.update();
      item.draw();
      if (item.x + 90 < 0) {
        bgItems.splice(index, 1);
        bgItems.push(new BackgroundItem("cloud"));
      }
    });
    obstacleTimer++;
    if (obstacleTimer >= nextObstacleInterval) {
      const type = Math.random() > 0.4 ? "darat" : "udara";
      obstacles.push(new Obstacle(type));
      obstacleTimer = 0;
      let rawInterval =
        100 -
        Math.floor(gameState.gameSpeed * 2.2) +
        Math.floor(Math.random() * 45);
      nextObstacleInterval = Math.max(50, rawInterval);
    }
    obstacles.forEach((obs, index) => {
      obs.update();
      obs.draw();
      if (obs.x + obs.width < 0) obstacles.splice(index, 1);
      if (checkCollision(miku, obs) && !cheatState.godMode) {
        endGame();
      }
    });
    miku.update();
    miku.draw();
    gameState.score += cheatState.hyperSpeed ? 0.45 : 0.15;
    document.getElementById("scoreText").innerText = String(
      Math.floor(gameState.score),
    ).padStart(5, "0");
    if (
      !cheatState.hyperSpeed &&
      Math.floor(gameState.score) % 100 === 0 &&
      Math.floor(gameState.score) > 0
    ) {
      gameState.gameSpeed += 0.18;
    }
  } else {
    if (miku) miku.draw();
    bgItems.forEach((item) => item.draw());
    obstacles.forEach((obs) => obs.draw());
  }
  requestAnimationFrame(gameLoop);
}
const keys = {};
window.addEventListener("keydown", (e) => {
  keys[e.code] = true;
  if (e.code === "Space" || e.code === "ArrowUp") {
    e.preventDefault();
    if (!gameState.isPlaying && !gameState.isGameOver) resetGame();
    else if (gameState.isGameOver) resetGame();
    else miku.jump();
  }
  if (e.code === "ArrowDown") {
    e.preventDefault();
    if (gameState.isPlaying && miku) miku.duck(true);
  }
});
window.addEventListener("keyup", (e) => {
  keys[e.code] = false;
  if (e.code === "ArrowDown" && gameState.isPlaying && miku) miku.duck(false);
});
canvas.addEventListener(
  "touchstart",
  (e) => {
    e.preventDefault();
    if (!gameState.isPlaying) resetGame();
    else miku.jump();
  },
  { passive: false },
);
document.getElementById("mobileJump").addEventListener("touchstart", (e) => {
  e.preventDefault();
  if (!gameState.isPlaying) resetGame();
  else miku.jump();
});
document.getElementById("mobileDuck").addEventListener("touchstart", (e) => {
  e.preventDefault();
  if (miku) miku.duck(true);
});
document.getElementById("mobileDuck").addEventListener("touchend", (e) => {
  e.preventDefault();
  if (miku) miku.duck(false);
});

document.getElementById("restartBtn").addEventListener("click", resetGame);
document.getElementById("startScreen").addEventListener("click", () => {
  if (!gameState.isPlaying) resetGame();
});
window.onload = function () {
  renderStartScreenChibi();
  miku = new Miku();
  miku.y = 220 - miku.height;
  gameLoop();
};
