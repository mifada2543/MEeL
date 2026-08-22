/**
 * MEeL!Mania Game — Game Flow
 * Start, pause, resume, restart, countdown, results, options.
 */
import {
  S, songId, speedMult, audioElement,
  hudTitle, hudArtist, hudScore, hudAcc, comboWrap, judgmentWrap,
  progressFill, startOverlay, pauseOverlay, resultsOverlay,
  countdownOverlay, countdownNum, optionsOverlay, touchLanes, hud,
  TIMING, APPROACH_TIME, ACC_WEIGHT,
} from "./state.js";
import { initAudio, resumeAudio, startBGM, stopBGM, playCountdownBeep } from "./audio.js";
import { draw, updateHUD, pad6 } from "./renderer.js";
import { hitY } from "./canvas.js";
import { releaseLane } from "./hitDetection.js";

function isMobile() {
  return "ontouchstart" in window || navigator.maxTouchPoints > 0;
}

/* ═══════════════════════════════════════════════════════
   GAME LOOP
   ═══════════════════════════════════════════════════════ */
export function gameLoop(ts) {
  if (S.gameState !== "playing") return;

  try {
    // Sync songTime from real audio when available
    if (S._audioPlaying && audioElement && audioElement.duration) {
      S.songTime = audioElement.currentTime * 1000;
    } else {
      if (!S.lastTime) S.lastTime = ts;
      const dt = ts - S.lastTime;
      S.lastTime = ts;
      S.songTime += dt;
    }
    updateFPS();

    const hy = hitY();
    const ppm = hy / APPROACH_TIME;

    // Spawn notes
    while (S.noteIndex < S.notes.length && S.notes[S.noteIndex].time - S.songTime <= APPROACH_TIME) {
      S.activeNotes.push({ ...S.notes[S.noteIndex] });
      S.noteIndex++;
    }

    // Miss detection
    for (const n of S.activeNotes) {
      if (n.hit || n.missed) continue;
      if (n.holding) continue;
      if (n.time - S.songTime < -TIMING.bad - 30) {
        n.missed = true;
        S.judgmentCounts.miss++;
        S.combo = 0;
        showJudgment("miss");
        updateHUD();
        playSFX("miss");
      }
    }

    // Release holds: user stopped pressing OR end time passed
    const holdLanes = Object.keys(S.holdNotes).map(Number);
    for (const lane of holdLanes) {
      const hold = S.holdNotes[lane];
      if (!hold) { delete S.holdNotes[lane]; continue; }

      // User released key — score the release
      if (!S.lanePressed[lane]) {
        try {
          releaseLane(lane);
        } catch (e) {
          // Fallback: manually release if releaseLane fails
          hold.hit = true;
          hold.holding = false;
          delete S.holdNotes[lane];
          S.judgmentCounts.good++;
          S.combo++;
          if (S.combo > S.maxCombo) S.maxCombo = S.combo;
          updateHUD();
        }
        continue;
      }

      // Hold end time passed + tolerance — auto-release as miss
      if (S.songTime > hold.endTime + TIMING.bad + 50) {
        hold.hit = true;
        hold.holding = false;
        delete S.holdNotes[lane];
        S.judgmentCounts.miss++;
        S.combo = 0;
        showJudgment("miss");
        updateHUD();
        playSFX("miss");
      }
    }

    // Cleanup
    S.activeNotes = S.activeNotes.filter((n) => {
      if (n.hit || n.missed) return (n.time - S.songTime) > -500;
      return true;
    });

    // Progress
    if (S.songDuration > 0) {
      progressFill.style.width = Math.min(S.songTime / S.songDuration * 100, 100) + "%";
    }

    draw();

    // End check
    const allSpawned = S.noteIndex >= S.notes.length;
    const allProcessed = S.activeNotes.length === 0 && allSpawned;
    if (allSpawned && S.songTime >= S.songDuration + 2000 && (allProcessed || S.activeNotes.every((n) => n.hit || n.missed))) {
      showResults();
      return;
    }
  } catch (e) {
    console.error("[Game] gameLoop error:", e);
  }

  S.animFrame = requestAnimationFrame(gameLoop);
}

/* ═══════════════════════════════════════════════════════
   FPS COUNTER
   ═══════════════════════════════════════════════════════ */
let fpsDisplay = null;
let fpsFrames = 0;
let fpsLastTime = performance.now();

function updateFPS() {
  if (!S.gameOptions.fps || !fpsDisplay) return;
  fpsFrames++;
  const now = performance.now();
  if (now - fpsLastTime >= 1000) {
    fpsDisplay.textContent = fpsFrames + " FPS";
    fpsFrames = 0;
    fpsLastTime = now;
  }
}

/* ═══════════════════════════════════════════════════════
   COUNTDOWN (3-2-1-GO)
   ═══════════════════════════════════════════════════════ */
function runCountdown(callback) {
  S.gameState = "countdown";
  countdownOverlay.classList.remove("hidden");
  let count = 3;
  countdownNum.textContent = count;
  countdownNum.className = "countdown-num";
  void countdownNum.offsetWidth;
  countdownNum.style.animation = "none";
  void countdownNum.offsetWidth;
  countdownNum.style.animation = "";

  playCountdownBeep(440, 0.15);

  const timer = setInterval(function () {
    count--;
    if (count > 0) {
      countdownNum.textContent = count;
      countdownNum.className = "countdown-num";
      void countdownNum.offsetWidth;
      countdownNum.style.animation = "none";
      void countdownNum.offsetWidth;
      countdownNum.style.animation = "";
      playCountdownBeep(440, 0.15);
    } else if (count === 0) {
      countdownNum.textContent = "GO!";
      countdownNum.className = "countdown-go";
      void countdownNum.offsetWidth;
      countdownNum.style.animation = "none";
      void countdownNum.offsetWidth;
      countdownNum.style.animation = "";
      playCountdownBeep(880, 0.25);
    } else {
      clearInterval(timer);
      countdownOverlay.classList.add("hidden");
      callback();
    }
  }, 800);
}

/* ═══════════════════════════════════════════════════════
   START GAME
   ═══════════════════════════════════════════════════════ */
export function startGame() {
  initAudio();
  resumeAudio();

  S.score = 0; S.combo = 0; S.maxCombo = 0; S.noteIndex = 0;
  S.songTime = 0; S.lastTime = 0;
  S.activeNotes = [];
  S.laneFlashes = [0, 0, 0, 0];
  S.lanePressed = [false, false, false, false];
  S.judgmentCounts = { perfect: 0, great: 0, good: 0, bad: 0, miss: 0 };

  S.notes = S.beatmapData.notes.map((n) => ({
    time: n.t,
    endTime: n.e || null,
    lane: n.l,
    gold: !!n.g,
    hit: false,
    missed: false,
    holding: false,
    holdType: null,
  }));
  S.holdNotes = {};
  S.songDuration = S.beatmapData.duration;
  S.totalNotes = S.notes.length;

  hudTitle.textContent = S.song.title;
  hudArtist.textContent = S.song.artist;
  hudScore.textContent = pad6(0);
  hudAcc.textContent = "100.0%";
  progressFill.style.width = "0%";
  comboWrap.classList.add("hidden");
  judgmentWrap.classList.add("hidden");

  startOverlay.classList.add("hidden");
  pauseOverlay.classList.add("hidden");
  resultsOverlay.classList.add("hidden");
  hud.classList.remove("hidden");
  if (isMobile()) touchLanes.classList.remove("hidden");

  applyOptions();
  runCountdown(function () {
    S.gameState = "playing";
    S.lastTime = 0;
    if (S._audioPlaying && audioElement) {
      audioElement.currentTime = 0;
    }
    startBGM();
    S.animFrame = requestAnimationFrame(gameLoop);
  });
}

/* ═══════════════════════════════════════════════════════
   PAUSE / RESUME / RESTART
   ═══════════════════════════════════════════════════════ */
export function pauseGame() {
  if (S.gameState !== "playing") return;
  S.gameState = "paused";
  stopBGM();
  if (S.animFrame) cancelAnimationFrame(S.animFrame);
  pauseOverlay.classList.remove("hidden");
  S.lanePressed = [false, false, false, false];

  // Release all active holds — prevent zombie holds after resume
  for (const laneStr of Object.keys(S.holdNotes)) {
    const hold = S.holdNotes[laneStr];
    if (hold) {
      hold.hit = true;
      hold.holding = false;
      S.judgmentCounts.miss++;
      S.combo = 0;
    }
  }
  S.holdNotes = {};
}

window.resumeGame = function () {
  if (S.gameState !== "paused") return;
  pauseOverlay.classList.add("hidden");
  runCountdown(function () {
    S.gameState = "playing";
    S.lastTime = 0;
    startBGM();
    S.animFrame = requestAnimationFrame(gameLoop);
  });
};

window.restartGame = function () {
  pauseOverlay.classList.add("hidden");
  S.score = 0; S.combo = 0; S.maxCombo = 0;
  S.judgmentCounts = { perfect: 0, great: 0, good: 0, bad: 0, miss: 0 };
  S.noteIndex = 0;
  S.lanePressed = [false, false, false, false];
  audioElement.currentTime = 0;
  runCountdown(function () {
    S.gameState = "playing";
    S.lastTime = 0;
    if (S._audioPlaying) {
      audioElement.currentTime = 0;
    }
    startBGM();
    S.animFrame = requestAnimationFrame(gameLoop);
  });
};

/* ═══════════════════════════════════════════════════════
   OPTIONS / ADVANCED
   ═══════════════════════════════════════════════════════ */
export function loadOptions() {
  try {
    const saved = JSON.parse(localStorage.getItem("mania_options") || "null");
    if (saved) Object.assign(S.gameOptions, saved);
  } catch (e) {}
  document.getElementById("optSpeed").value = S.gameOptions.speed;
  document.getElementById("optDim").value = S.gameOptions.dim;
  document.getElementById("optVolume").value = S.gameOptions.volume;
  document.getElementById("optBlurBg").checked = S.gameOptions.blurBg;
  document.getElementById("optFPS").checked = S.gameOptions.fps;
  document.getElementById("optLowGfx").checked = S.gameOptions.lowGfx;
}

window.toggleAdvanced = function () {
  pauseOverlay.classList.add("hidden");
  loadOptions();
  optionsOverlay.classList.remove("hidden");
};

window.closeOptions = function () {
  optionsOverlay.classList.add("hidden");
  pauseOverlay.classList.remove("hidden");
};

window.saveOptions = function () {
  S.gameOptions.speed = parseInt(document.getElementById("optSpeed").value);
  S.gameOptions.dim = parseInt(document.getElementById("optDim").value);
  S.gameOptions.volume = parseInt(document.getElementById("optVolume").value);
  S.gameOptions.blurBg = document.getElementById("optBlurBg").checked;
  S.gameOptions.fps = document.getElementById("optFPS").checked;
  S.gameOptions.lowGfx = document.getElementById("optLowGfx").checked;
  localStorage.setItem("mania_options", JSON.stringify(S.gameOptions));
  applyOptions();
  optionsOverlay.classList.add("hidden");
  pauseOverlay.classList.remove("hidden");
};

export function applyOptions() {
  if (S.gameOptions.fps && !fpsDisplay) {
    fpsDisplay = document.getElementById("fpsCounter");
    if (fpsDisplay) fpsDisplay.classList.remove("hidden");
  } else if (!S.gameOptions.fps && fpsDisplay) {
    fpsDisplay.classList.add("hidden");
  }
  const bgEl = document.getElementById("bgImage");
  if (bgEl) {
    const coverUrl = S.song && (S.song.cover_url || S.song.coverUrl);
    if (S.gameOptions.blurBg && coverUrl) {
      bgEl.style.backgroundImage = "url('" + coverUrl + "')";
      bgEl.classList.remove("hidden");
    } else {
      bgEl.classList.add("hidden");
    }
  }
  const dimEl = document.getElementById("dimOverlay");
  if (dimEl) {
    const coverUrl2 = S.song && (S.song.cover_url || S.song.coverUrl);
    if (S.gameOptions.blurBg && coverUrl2) {
      dimEl.classList.add("active");
      dimEl.style.opacity = S.gameOptions.dim / 100;
    } else {
      dimEl.classList.remove("active");
    }
  }
}

window.quitToLobby = function () {
  S.gameState = "start";
  stopBGM();
  if (S.animFrame) cancelAnimationFrame(S.animFrame);
  window.location.href = "/MEeL/arcade/rhythm/";
};

/* ═══════════════════════════════════════════════════════
   RESULTS
   ═══════════════════════════════════════════════════════ */
function showResults() {
  S.gameState = "results";
  stopBGM();
  if (S.animFrame) cancelAnimationFrame(S.animFrame);

  try {
    const saved = JSON.parse(localStorage.getItem("mania_scores")) || {};
    const key = String(songId);
    const isNew = !saved[key] || S.score > saved[key];
    if (isNew) saved[key] = S.score;
    localStorage.setItem("mania_scores", JSON.stringify(saved));
    S.highScore = saved[key];
    document.getElementById("newHighScoreBanner").classList.toggle("hidden", !isNew);
  } catch (e) {}

  const total = S.judgmentCounts.perfect + S.judgmentCounts.great + S.judgmentCounts.good + S.judgmentCounts.bad + S.judgmentCounts.miss;
  const acc = total > 0
    ? (S.judgmentCounts.perfect * ACC_WEIGHT.perfect + S.judgmentCounts.great * ACC_WEIGHT.great + S.judgmentCounts.good * ACC_WEIGHT.good + S.judgmentCounts.bad * ACC_WEIGHT.bad) / total * 100
    : 0;
  let rank = "D";
  if (acc >= 95) rank = "S";
  else if (acc >= 90) rank = "A";
  else if (acc >= 80) rank = "B";
  else if (acc >= 70) rank = "C";

  document.getElementById("resultsRank").textContent = rank;
  document.getElementById("resultsScore").textContent = pad6(S.score);
  document.getElementById("resPerfect").textContent = S.judgmentCounts.perfect;
  document.getElementById("resGreat").textContent = S.judgmentCounts.great;
  document.getElementById("resGood").textContent = S.judgmentCounts.good;
  document.getElementById("resBad").textContent = S.judgmentCounts.bad;
  document.getElementById("resMiss").textContent = S.judgmentCounts.miss;
  document.getElementById("resMaxCombo").textContent = S.maxCombo;
  document.getElementById("resultsAcc").textContent = acc.toFixed(1) + "%";

  resultsOverlay.classList.remove("hidden");
  hud.classList.add("hidden");
  touchLanes.classList.add("hidden");
}
