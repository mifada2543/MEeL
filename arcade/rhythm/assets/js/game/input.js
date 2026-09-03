



import {
  S, canvas, KEY_MAP, LANE_COUNT,
} from "./state.js";
import { getW, laneWidth, playfieldX } from "./canvas.js";
import { hitLane, releaseLane } from "./hitDetection.js";
import { startGame, pauseGame } from "./gameFlow.js";


export function initKeyboard() {
  document.addEventListener("keydown", (e) => {
    const key = e.key.toLowerCase();

    if (S.gameState === "start" && (key === " " || key === "enter")) {
      e.preventDefault();
      startGame();
      return;
    }
    if (S.gameState === "playing" && key === "escape") {
      e.preventDefault();
      pauseGame();
      return;
    }
    if (S.gameState === "paused" && (key === " " || key === "enter" || key === "escape")) {
      e.preventDefault();
      window.resumeGame();
      return;
    }
    if (S.gameState === "playing" && key in KEY_MAP) {
      e.preventDefault();
      hitLane(KEY_MAP[key]);
    }
  });

  document.addEventListener("keyup", (e) => {
    const key = e.key.toLowerCase();
    if (key in KEY_MAP) {
      releaseLane(KEY_MAP[key]);
    }
  });
}


export function initTouch() {
  document.querySelectorAll(".touch-btn").forEach((btn) => {
    const lane = parseInt(btn.dataset.lane);
    const onDown = (e) => {
      e.preventDefault();
      if (S.gameState === "start") { startGame(); return; }
      if (S.gameState === "playing") hitLane(lane);
    };
    btn.addEventListener("touchstart", onDown, { passive: false });
    btn.addEventListener("mousedown", onDown);
    btn.addEventListener("touchend", () => { releaseLane(lane); });
    btn.addEventListener("mouseup", () => { releaseLane(lane); });
  });

  canvas.addEventListener("touchstart", (e) => {
    e.preventDefault();
    if (S.gameState === "start") { startGame(); return; }
    if (S.gameState !== "playing") return;
    for (const touch of e.changedTouches) {
      const rect = canvas.getBoundingClientRect();
      const scaleX = getW() / rect.width;
      const x = (touch.clientX - rect.left) * scaleX;
      const lane = Math.floor((x - playfieldX()) / laneWidth());
      if (lane >= 0 && lane < LANE_COUNT) hitLane(lane);
    }
  }, { passive: false });

  canvas.addEventListener("touchend", (e) => {
    for (const touch of e.changedTouches) {
      const rect = canvas.getBoundingClientRect();
      const scaleX = getW() / rect.width;
      const x = (touch.clientX - rect.left) * scaleX;
      const lane = Math.floor((x - playfieldX()) / laneWidth());
      if (lane >= 0 && lane < LANE_COUNT) releaseLane(lane);
    }
  });

  canvas.addEventListener("mousedown", (e) => {
    if (S.gameState === "start") { startGame(); return; }
    if (S.gameState !== "playing") return;
    const rect = canvas.getBoundingClientRect();
    const scaleX = getW() / rect.width;
    const x = (e.clientX - rect.left) * scaleX;
    const lane = Math.floor((x - playfieldX()) / laneWidth());
    if (lane >= 0 && lane < LANE_COUNT) hitLane(lane);
  });

  canvas.addEventListener("mouseup", () => {
    for (let i = 0; i < LANE_COUNT; i++) releaseLane(i);
  });
}


export function initButtons() {
  const startOverlay = document.getElementById("startOverlay");
  const resultsOverlay = document.getElementById("resultsOverlay");

  startOverlay.addEventListener("click", () => { if (S.gameState === "start") startGame(); });
  document.getElementById("btnQuit").addEventListener("click", window.quitToLobby);
  document.getElementById("btnRetry").addEventListener("click", () => {
    resultsOverlay.classList.add("hidden");
    startGame();
  });
  document.getElementById("btnBackLobby").addEventListener("click", window.quitToLobby);
}
