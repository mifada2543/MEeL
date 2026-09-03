

const TRACK = [
  [6, 0], [7, 0], [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5],
  [9, 6], [10, 6], [11, 6], [12, 6], [13, 6], [14, 6], [14, 7], [14, 8],
  [13, 8], [12, 8], [11, 8], [10, 8], [9, 8], [8, 9], [8, 10], [8, 11],
  [8, 12], [8, 13], [8, 14], [7, 14], [6, 14], [6, 13], [6, 12], [6, 11],
  [6, 10], [6, 9], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
  [0, 7], [0, 6], [1, 6], [2, 6], [3, 6], [4, 6], [5, 6], [6, 5],
  [6, 4], [6, 3], [6, 2], [6, 1],
];


const START = { red: 40, green: 1, blue: 14, yellow: 27 };
const SAFE = new Set(Object.values(START));


const HOME = {
  red:    [[1, 7], [2, 7], [3, 7], [4, 7], [5, 7]],
  green:  [[7, 1], [7, 2], [7, 3], [7, 4], [7, 5]],
  blue:   [[13, 7], [12, 7], [11, 7], [10, 7], [9, 7]],
  yellow: [[7, 13], [7, 12], [7, 11], [7, 10], [7, 9]],
};


const YARD = { red: [0, 0], green: [9, 0], yellow: [0, 9], blue: [9, 9] };
const ORDER = ["red", "green", "blue", "yellow"];
const COLORS = {
  red:    { name: "Merah",  hex: "#ef4444" },
  green:  { name: "Hijau",  hex: "#22c55e" },
  blue:   { name: "Biru",   hex: "#3b82f6" },
  yellow: { name: "Kuning", hex: "#eab308" },
};

const FINISH_STEPS = 56; 


let players = [];
let turnIdx = 0;
let phase = "idle"; 
let lastRoll = 0;
let sixCount = 0;
let gameOver = false;
let botTimers = [];

const cellEls = {};
const boardEl = document.getElementById("board");
const diceBtn = document.getElementById("diceBtn");
const diceFace = document.getElementById("diceFace");
const diceHint = document.getElementById("diceHint");


function cellInfo(c, r) {
  for (const color of ORDER) {
    const [oc, orr] = YARD[color];
    if (c >= oc && c < oc + 6 && r >= orr && r < orr + 6) return { type: "yard", color };
  }
  const ti = TRACK.findIndex(([tc, tr]) => tc === c && tr === r);
  if (ti >= 0) {
    const startColor = ORDER.find((k) => START[k] === ti);
    return { type: "track", trackIdx: ti, start: !!startColor, color: startColor };
  }
  for (const color of ORDER) {
    if (HOME[color].some(([hc, hr]) => hc === c && hr === r)) return { type: "home", color };
  }
  if (c >= 6 && c <= 8 && r >= 6 && r <= 8) {
    const tri = { "7,6": "green", "7,8": "yellow", "6,7": "red", "8,7": "blue" }[`${c},${r}`];
    return { type: "center", tri, dot: c === 7 && r === 7 };
  }
  return { type: "empty" };
}

function buildBoard() {
  boardEl.innerHTML = "";
  for (let r = 0; r < 15; r++) {
    for (let c = 0; c < 15; c++) {
      const cell = document.createElement("div");
      cell.className = "board-cell";
      const key = `${c},${r}`;
      const info = cellInfo(c, r);
      if (info.type === "track") cell.classList.add("cell-track");
      if (info.type === "yard") cell.classList.add("cell-yard", `c-${info.color}`);
      if (info.type === "home") cell.classList.add("cell-home", `c-${info.color}`);
      if (info.type === "center") {
        cell.classList.add("cell-center");
        if (info.tri) {
          const tri = document.createElement("div");
          tri.className = `finish-tri c-${info.tri}`;
          cell.appendChild(tri);
        }
        if (info.dot) {
          const dot = document.createElement("div");
          dot.className = "finish-dot";
          cell.appendChild(dot);
        }
      }
      if (info.start) cell.classList.add("cell-start", `c-${info.color}`);
      cell.dataset.key = key;
      cellEls[key] = cell;
      boardEl.appendChild(cell);
    }
  }
}


function newToken() {
  return { state: "yard", steps: 0 };
}

function tokenCellKey(color, token, ti) {
  if (token.state === "yard") {
    const [oc, orr] = YARD[color];
    const slot = [[2, 2], [3, 2], [2, 3], [3, 3]][ti];
    return `${oc + slot[0]},${orr + slot[1]}`;
  }
  if (token.state === "finished") return "7,7";
  if (token.state === "path") {
    const idx = (START[color] + token.steps) % 52;
    return `${TRACK[idx][0]},${TRACK[idx][1]}`;
  }
  const [hc, hr] = HOME[color][token.steps - 51];
  return `${hc},${hr}`;
}

function isOpponentBlock(color, trackIdx) {
  let count = 0;
  for (const p of players) {
    if (p.color === color) continue;
    for (const t of p.tokens) {
      if (t.state === "path" && (START[p.color] + t.steps) % 52 === trackIdx) count++;
    }
  }
  return count >= 2;
}

function canMoveToken(player, token, roll) {
  if (token.state === "finished") return false;
  if (token.state === "yard") return roll === 6;
  const ns = token.steps + roll;
  if (ns > FINISH_STEPS) return false; 
  if (ns <= 50) {
    const idx = (START[player.color] + ns) % 52;
    if (isOpponentBlock(player.color, idx)) return false; 
  }
  return true;
}

function getMovableTokens(player, roll) {
  return player.tokens.filter((t) => canMoveToken(player, t, roll));
}

function captureAt(trackIdx, moverColor) {
  for (const p of players) {
    if (p.color === moverColor) continue;
    for (const t of p.tokens) {
      if (t.state === "path" && (START[p.color] + t.steps) % 52 === trackIdx) {
        t.state = "yard";
        t.steps = 0;
      }
    }
  }
}

function moveToken(player, token, roll) {
  if (token.state === "yard") {
    token.state = "path";
    token.steps = 0;
  } else {
    token.steps += roll;
    if (token.steps >= FINISH_STEPS) token.state = "finished";
    else if (token.steps >= 51) token.state = "home";
  }

  
  if (token.state === "path") {
    const idx = (START[player.color] + token.steps) % 52;
    if (!SAFE.has(idx)) captureAt(idx, player.color);
  }

  renderBoard();

  if (player.tokens.every((t) => t.state === "finished")) {
    endGame(player);
    return;
  }

  if (roll === 6) {
    phase = "roll";
    setDiceEnabled(true, "Dadu 6! Lempar lagi.");
    if (player.isBot) botTimers.push(setTimeout(rollDice, 650));
  } else {
    nextTurn();
  }
}

function rollDice() {
  if (phase !== "roll" || gameOver) return;
  const player = players[turnIdx];

  lastRoll = 1 + Math.floor(Math.random() * 6);
  sixCount = lastRoll === 6 ? sixCount + 1 : 0;
  renderDice();

  
  if (sixCount >= 3) {
    setDiceEnabled(false, "Tiga kali 6 beruntun — giliran gugur!");
    botTimers.push(setTimeout(nextTurn, 900));
    return;
  }

  const movable = getMovableTokens(player, lastRoll);
  if (!movable.length) {
    setDiceEnabled(false, "Tidak ada pion yang bisa digerakkan.");
    botTimers.push(setTimeout(nextTurn, 900));
    return;
  }

  phase = "move";
  setDiceEnabled(false, "Pilih pion untuk digerakkan.");
  renderBoard();

  if (player.isBot) {
    botTimers.push(setTimeout(() => botMove(player, lastRoll, movable), 700));
  }
}

function nextTurn() {
  sixCount = 0;
  phase = "roll";
  do {
    turnIdx = (turnIdx + 1) % players.length;
  } while (players[turnIdx].tokens.every((t) => t.state === "finished"));

  updateTurnUI();
  renderBoard();
  const p = players[turnIdx];
  if (p.isBot) botTimers.push(setTimeout(rollDice, 800));
  else setDiceEnabled(true, "Gulingkan dadu untuk melempar.");
}


function scoreMove(player, token, roll) {
  if (token.state === "yard") return 60; 
  const ns = token.steps + roll;
  if (ns === FINISH_STEPS) return 1000; 
  if (ns >= 51) return 400 + (FINISH_STEPS - ns); 
  const idx = (START[player.color] + ns) % 52;
  let s = ns; 
  for (const p of players) {
    if (p.color === player.color) continue;
    for (const t of p.tokens) {
      if (t.state === "path" && (START[p.color] + t.steps) % 52 === idx) s += 120; 
    }
  }
  return s;
}

function botMove(player, roll, movable) {
  let best = null;
  let bestScore = -Infinity;
  for (const t of movable) {
    const s = scoreMove(player, t, roll);
    if (s > bestScore) {
      bestScore = s;
      best = t;
    }
  }
  if (best) moveToken(player, best, roll);
}


function setDiceEnabled(enabled, label) {
  diceBtn.disabled = !enabled;
  diceHint.textContent = label || "";
}

function renderDice() {
  diceFace.textContent = lastRoll || "?";
  diceFace.classList.remove("dice-show");
  void diceFace.offsetWidth;
  diceFace.classList.add("dice-show");
  diceBtn.classList.remove("rolling");
  void diceBtn.offsetWidth;
  diceBtn.classList.add("rolling");
}

function isInteractiveToken(p, t) {
  return (
    phase === "move" &&
    !gameOver &&
    players[turnIdx] === p &&
    !p.isBot &&
    canMoveToken(p, t, lastRoll)
  );
}



let prevTokenPos = new Map(); 

function captureTokenPositions() {
  prevTokenPos = new Map();
  document.querySelectorAll(".token").forEach((el) => {
    const key = el.dataset.key;
    if (!key) return;
    const r = el.getBoundingClientRect();
    prevTokenPos.set(key, { x: r.left + r.width / 2, y: r.top + r.height / 2 });
  });
}

function animateTokenSlide(el, oldPos) {
  const r = el.getBoundingClientRect();
  const dx = oldPos.x - (r.left + r.width / 2);
  const dy = oldPos.y - (r.top + r.height / 2);
  if (Math.abs(dx) < 2 && Math.abs(dy) < 2) return;
  el.style.transition = "none";
  el.style.transform = `translate(${dx}px, ${dy}px)`;
  
  void el.offsetWidth;
  el.style.transition = "transform 0.3s cubic-bezier(0.22, 1, 0.36, 1)";
  el.style.transform = "translate(0, 0)";
  setTimeout(() => {
    el.style.transition = "";
    el.style.transform = "";
  }, 320);
}

function renderBoard() {
  captureTokenPositions();
  document.querySelectorAll(".token").forEach((el) => el.remove());
  const byCell = {};
  players.forEach((p, pi) => {
    p.tokens.forEach((t, ti) => {
      const key = tokenCellKey(p.color, t, ti);
      (byCell[key] = byCell[key] || []).push({ color: p.color, pi, ti, movable: isInteractiveToken(p, t) });
    });
  });

  for (const key in byCell) {
    const cellEl = cellEls[key];
    if (!cellEl) continue;
    const list = byCell[key];
    list.forEach((item, i) => {
      const el = document.createElement("div");
      el.className = `token c-${item.color}`;
      el.dataset.key = `${item.color},${item.ti}`;
      if (list.length === 1) {
        el.style.left = "19%";
        el.style.top = "19%";
      } else {
        el.style.width = "47%";
        el.style.height = "47%";
        el.style.left = i % 2 === 0 ? "3%" : "50%";
        el.style.top = Math.floor(i / 2) === 0 ? "3%" : "50%";
      }
      if (item.movable) {
        el.classList.add("movable");
        el.addEventListener("click", (e) => {
          e.stopPropagation();
          if (phase === "move" && players[turnIdx] === players[item.pi] && !players[item.pi].isBot) {
            moveToken(players[item.pi], players[item.pi].tokens[item.ti], lastRoll);
          }
        });
      }
      cellEl.appendChild(el);
      
      const old = prevTokenPos.get(el.dataset.key);
      if (old) animateTokenSlide(el, old);
    });
  }
}

function updateTurnUI() {
  const p = players[turnIdx];
  const dot = document.getElementById("turnDot");
  dot.style.background = COLORS[p.color].hex;
  document.getElementById("turnName").textContent = `${p.isBot ? "🤖 " : ""}${COLORS[p.color].name}`;
  document.getElementById("turnSub").textContent = p.isBot
    ? "Bot sedang berpikir..."
    : "Klik dadu untuk melempar";

  const wrap = document.getElementById("playerBadges");
  wrap.innerHTML = "";
  players.forEach((pl, i) => {
    const homeCount = pl.tokens.filter((t) => t.state === "finished").length;
    const badge = document.createElement("div");
    badge.className =
      "player-badge" +
      (i === turnIdx ? " current" : "") +
      (homeCount === 4 ? " finished-badge" : "");
    badge.innerHTML =
      `<span class="p-dot" style="background:${COLORS[pl.color].hex}"></span>` +
      `<span>${pl.isBot ? "🤖 " : ""}${COLORS[pl.color].name}</span>` +
      `<span class="text-gray-500">${homeCount}/4</span>`;
    wrap.appendChild(badge);
  });
}

function setHint(text) {
  diceHint.textContent = text;
}


function setupPlayers(count, botFlags) {
  players = ORDER.slice(0, count).map((color, i) => ({
    color,
    isBot: !!botFlags[i],
    tokens: [newToken(), newToken(), newToken(), newToken()],
  }));
}

function startGame() {
  botTimers.forEach(clearTimeout);
  botTimers = [];
  turnIdx = 0;
  phase = "roll";
  lastRoll = 0;
  sixCount = 0;
  gameOver = false;

  document.getElementById("setupOverlay").classList.add("hidden");
  const wo = document.getElementById("winOverlay");
  wo.classList.add("hidden");
  wo.style.opacity = "0";
  wo.style.pointerEvents = "none";

  updateTurnUI();
  renderBoard();
  const p = players[0];
  if (p.isBot) botTimers.push(setTimeout(rollDice, 700));
  else setDiceEnabled(true, "Gulingkan dadu untuk memulai.");
}

function endGame(winner) {
  gameOver = true;
  phase = "idle";
  setDiceEnabled(false, "");
  renderBoard();

  document.getElementById("winnerName").textContent =
    `${winner.isBot ? "🤖 " : ""}${COLORS[winner.color].name}`;
  const wo = document.getElementById("winOverlay");
  wo.classList.remove("hidden");
  requestAnimationFrame(() => {
    wo.style.opacity = "1";
    wo.style.pointerEvents = "auto";
  });
}


let setupCount = 2;
let setupBots = [false, true, true, true];

function renderSetupPlayers() {
  const wrap = document.getElementById("setupPlayers");
  wrap.innerHTML = "";
  ORDER.slice(0, setupCount).forEach((color, i) => {
    const row = document.createElement("div");
    row.className = "setup-player-row";
    row.innerHTML =
      `<span class="p-dot" style="background:${COLORS[color].hex}"></span>` +
      `<span class="text-sm font-bold text-white flex-1">${COLORS[color].name}</span>` +
      `<button class="type-btn ${setupBots[i] ? "" : "active"}" data-i="${i}" data-v="0">Manusia</button>` +
      `<button class="type-btn ${setupBots[i] ? "active" : ""}" data-i="${i}" data-v="1">Bot</button>`;
    wrap.appendChild(row);
  });
  wrap.querySelectorAll(".type-btn").forEach((btn) => {
    btn.addEventListener("click", () => {
      const i = parseInt(btn.dataset.i, 10);
      setupBots[i] = btn.dataset.v === "1";
      renderSetupPlayers();
    });
  });
}

function initSetup() {
  const countBtns = document.querySelectorAll(".player-count-btn");
  const setActiveCount = (n) => {
    setupCount = n;
    countBtns.forEach((b) => b.classList.toggle("active", parseInt(b.dataset.count, 10) === n));
    renderSetupPlayers();
  };
  countBtns.forEach((b) =>
    b.addEventListener("click", () => setActiveCount(parseInt(b.dataset.count, 10))),
  );
  setActiveCount(2);

  document.getElementById("startGameBtn").addEventListener("click", () => {
    setupPlayers(setupCount, setupBots);
    startGame();
  });
  document.getElementById("newGameBtn").addEventListener("click", () => {
    document.getElementById("setupOverlay").classList.remove("hidden");
  });
  document.getElementById("playAgainBtn").addEventListener("click", () => {
    document.getElementById("winOverlay").classList.add("hidden");
    document.getElementById("setupOverlay").classList.remove("hidden");
  });
}


diceBtn.addEventListener("click", rollDice);
initSetup();
buildBoard();
renderBoard();
