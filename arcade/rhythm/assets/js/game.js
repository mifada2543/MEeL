/**
 * MEeL!Mania — Gameplay Engine
 * Loads beatmaps from songs/{id}/beatmap.json
 * Loads song metadata from songs/_index.json
 */
(function () {
  "use strict";

  /* ═══════════════════════════════════════════════════════
     URL PARAMS
     ═══════════════════════════════════════════════════════ */
  const speedMult = window.MANIA_SPEED || 1.5;
  const phpSong = window.MANIA_SONG || null;
  const phpBeatmap = window.MANIA_BEATMAP || null;
  const songId = phpSong ? phpSong.id : 'starlight';

  /* ═══════════════════════════════════════════════════════
     DOM
     ═══════════════════════════════════════════════════════ */
  const canvas = document.getElementById("gameCanvas");
  const ctx = canvas.getContext("2d");
  const audioElement = document.getElementById("audioPlayer") || new Audio();
  const hud = document.getElementById("hud");
  const hudTitle = document.getElementById("hudTitle");
  const hudArtist = document.getElementById("hudArtist");
  const hudScore = document.getElementById("hudScore");
  const hudAcc = document.getElementById("hudAcc");
  const comboWrap = document.getElementById("comboWrap");
  const comboNumber = document.getElementById("comboNumber");
  const judgmentWrap = document.getElementById("judgmentWrap");
  const judgmentText = document.getElementById("judgmentText");
  const progressFill = document.getElementById("progressFill");
  const touchLanes = document.getElementById("touchLanes");
  const startOverlay = document.getElementById("startOverlay");
  const pauseOverlay = document.getElementById("pauseOverlay");
  const resultsOverlay = document.getElementById("resultsOverlay");

  /* ═══════════════════════════════════════════════════════
     CONSTANTS
     ═══════════════════════════════════════════════════════ */
  const LANE_COUNT = 4;
  const KEY_MAP = { a: 0, s: 1, k: 2, l: 3 };
  // Note colors: click=blue, hold=green, gold=bonus for both
  const COLOR_CLICK = "#3b82f6";       // blue for tap/click notes
  const COLOR_CLICK_BRIGHT = "#60a5fa";
  const COLOR_HOLD = "#22c55e";         // green for hold notes
  const COLOR_HOLD_BRIGHT = "#4ade80";
  const GOLD_COLOR = "#fbbf24";         // gold for bonus notes
  const GOLD_BRIGHT = "#fde047";
  const LANE_COLORS = [COLOR_CLICK, COLOR_CLICK, COLOR_CLICK, COLOR_CLICK];
  const LANE_COLORS_BRIGHT = [COLOR_CLICK_BRIGHT, COLOR_CLICK_BRIGHT, COLOR_CLICK_BRIGHT, COLOR_CLICK_BRIGHT];
  const HIT_Y_RATIO = 0.88;
  const NOTE_HEIGHT_BASE = 22;
  const NOTE_RADIUS = 0; // rectangular notes, no rounding
  const APPROACH_TIME_BASE = 1800;

  const TIMING = { perfect: 24, great: 52, good: 85, bad: 115 };
  const SCORE_VALUES = { perfect: 320, great: 200, good: 100, bad: 50, miss: 0 };
  const GOLD_MULTIPLIER = 3; // gold notes give 3x score
  const ACC_WEIGHT = { perfect: 1.0, great: 0.75, good: 0.5, bad: 0.25, miss: 0 };
  const JUDGE_COLORS = {
    perfect: "#fbbf24", great: "#34d399", good: "#60a5fa",
    bad: "#f87171", miss: "#6b7280",
  };
  const GOLD_GLOW = "rgba(251,191,36,0.4)";

  /* ═══════════════════════════════════════════════════════
     STATE
     ═══════════════════════════════════════════════════════ */
  let song = null;         // metadata from _index.json
  let beatmapData = null;  // { notes: [{t, l}], duration }
  let gameState = "loading"; // loading | start | playing | paused | results
  let score = 0, combo = 0, maxCombo = 0;
  let noteIndex = 0, notes = [], activeNotes = [];
  let laneFlashes = [0, 0, 0, 0];
  let lanePressed = [false, false, false, false];
  let holdNotes = {}; // { lane: activeHoldNote } — currently held notes
  let judgmentCounts = { perfect: 0, great: 0, good: 0, bad: 0, miss: 0 };
  let totalNotes = 0;
  let lastTime = 0, animFrame = null;
  let songTime = 0, songDuration = 0;
  let highScore = 0;

  /* ═══════════════════════════════════════════════════════
     AUDIO
     ═══════════════════════════════════════════════════════ */
  let audioCtx = null, masterGain = null, sfxGain = null, bgmGain = null;

  function initAudio() {
    if (audioCtx) return;
    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    masterGain = audioCtx.createGain();
    masterGain.connect(audioCtx.destination);
    sfxGain = audioCtx.createGain();
    sfxGain.gain.value = 0.7;
    sfxGain.connect(masterGain);
    bgmGain = audioCtx.createGain();
    bgmGain.gain.value = 0.5;
    bgmGain.connect(masterGain);
  }

  function resumeAudio() {
    if (audioCtx && audioCtx.state === "suspended") audioCtx.resume();
  }

  function playSFX(type) {
    if (!audioCtx) return;
    const now = audioCtx.currentTime;
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    const cfgs = {
      perfect: { freq: 1046, wave: "sine", vol: 0.2, dur: 0.06 },
      great: { freq: 784, wave: "sine", vol: 0.16, dur: 0.055 },
      good: { freq: 523, wave: "triangle", vol: 0.12, dur: 0.05 },
      bad: { freq: 262, wave: "sawtooth", vol: 0.08, dur: 0.04 },
      miss: { freq: 131, wave: "sawtooth", vol: 0.1, dur: 0.12 },
    };
    const c = cfgs[type] || cfgs.miss;
    osc.type = c.wave;
    osc.frequency.setValueAtTime(c.freq, now);
    if (type === "miss") osc.frequency.exponentialRampToValueAtTime(55, now + c.dur);
    gain.gain.setValueAtTime(c.vol, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + c.dur);
    osc.connect(gain);
    gain.connect(sfxGain);
    osc.start(now);
    osc.stop(now + c.dur + 0.01);
  }

  // ─── BGM ─────────────────────────────────────────────
  let bgmInterval = null;

  function startBGM() {
    if (!audioCtx || !song) return;
    let beat = 0;
    const bpm = song.bpm;
    const beatMs = 60000 / bpm;

    function playKick(t) {
      const o = audioCtx.createOscillator(), g = audioCtx.createGain();
      o.type = "sine";
      o.frequency.setValueAtTime(150, t);
      o.frequency.exponentialRampToValueAtTime(30, t + 0.12);
      g.gain.setValueAtTime(0.2, t);
      g.gain.exponentialRampToValueAtTime(0.001, t + 0.12);
      o.connect(g); g.connect(bgmGain);
      o.start(t); o.stop(t + 0.15);
    }

    function playHihat(t) {
      const len = audioCtx.sampleRate * 0.03;
      const buf = audioCtx.createBuffer(1, len, audioCtx.sampleRate);
      const d = buf.getChannelData(0);
      for (let i = 0; i < len; i++) d[i] = (Math.random() * 2 - 1) * 0.3;
      const n = audioCtx.createBufferSource();
      n.buffer = buf;
      const bp = audioCtx.createBiquadFilter();
      bp.type = "bandpass"; bp.frequency.value = 8000; bp.Q.value = 1;
      const g = audioCtx.createGain();
      g.gain.setValueAtTime(0.07, t);
      g.gain.exponentialRampToValueAtTime(0.001, t + 0.03);
      n.connect(bp); bp.connect(g); g.connect(bgmGain);
      n.start(t); n.stop(t + 0.05);
    }

    function playBass(t, freq) {
      const o = audioCtx.createOscillator(), g = audioCtx.createGain();
      o.type = "square";
      o.frequency.setValueAtTime(freq, t);
      g.gain.setValueAtTime(0.05, t);
      g.gain.exponentialRampToValueAtTime(0.001, t + 0.18);
      o.connect(g); g.connect(bgmGain);
      o.start(t); o.stop(t + 0.2);
    }

    const bassNotes = [110, 110, 130.81, 146.83, 110, 130.81, 146.83, 164.81];

    bgmInterval = setInterval(() => {
      if (gameState !== "playing") { clearInterval(bgmInterval); return; }
      const now = audioCtx.currentTime;
      const bi = beat % 8;
      if (bi === 0 || bi === 4) playKick(now);
      playHihat(now);
      setTimeout(() => { if (gameState === "playing") playHihat(audioCtx.currentTime); }, beatMs / 2);
      if (bi % 2 === 0) playBass(now, bassNotes[bi]);
      beat++;
    }, 60000 / bpm / 2);
  }

  function stopBGM() {
    if (bgmInterval) { clearInterval(bgmInterval); bgmInterval = null; }
  }

  /* ═══════════════════════════════════════════════════════
     CANVAS SIZING
     ═══════════════════════════════════════════════════════ */
  function resizeCanvas() {
    const dpr = window.devicePixelRatio || 1;
    const w = window.innerWidth;
    const h = window.innerHeight;
    canvas.width = w * dpr;
    canvas.height = h * dpr;
    canvas.style.width = w + "px";
    canvas.style.height = h + "px";
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  function getW() { return canvas.width / (window.devicePixelRatio || 1); }
  function getH() { return canvas.height / (window.devicePixelRatio || 1); }
  // Centered playfield — lanes occupy 40% of screen width, centered
  const PLAYFIELD_RATIO = 0.40;
  function laneWidth() { return (getW() * PLAYFIELD_RATIO) / LANE_COUNT; }
  function playfieldX() { return (getW() - getW() * PLAYFIELD_RATIO) / 2; }
  function hitY() { return getH() * HIT_Y_RATIO; }

  /* ═══════════════════════════════════════════════════════
     HELPERS
     ═══════════════════════════════════════════════════════ */
  function roundRect(x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }

  function pad6(n) { return String(Math.floor(n)).padStart(6, "0"); }

  function getNoteSize() {
    try {
      const s = JSON.parse(localStorage.getItem("mania_settings"));
      if (s && s.noteSize === "small") return 0.8;
      if (s && s.noteSize === "large") return 1.3;
    } catch (e) {}
    return 1.0;
  }

  /* ═══════════════════════════════════════════════════════
     LOAD SONG DATA & BEATMAP
     ═══════════════════════════════════════════════════════ */
  async function loadSongData() {
    // Data already loaded from PHP via window globals
    if (phpSong) {
      song = {
        id: phpSong.id,
        title: phpSong.title,
        artist: phpSong.artist,
        bpm: phpSong.bpm,
        difficulty: phpSong.difficulty,
        difficultyLabel: phpSong.difficulty_label,
        color: phpSong.color,
        emoji: phpSong.emoji,
        duration: phpSong.duration,
        noteCount: phpSong.note_count,
        type: phpSong.type,
        audioUrl: phpSong.audio_url,
        coverUrl: phpSong.cover_url,
      };
      beatmapData = phpBeatmap || { notes: [], duration: 0 };

      if (phpSong.audio_url) {
        audioElement.src = phpSong.audio_url;
        audioElement.load();
      }
    } else {
      // Fallback: load from filesystem
      await loadFromFiles();
    }

    // Load high score
    try {
      const saved = JSON.parse(localStorage.getItem("mania_scores")) || {};
      highScore = saved[String(songId)] || 0;
    } catch (e) { highScore = 0; }

    // Setup start overlay
    document.getElementById("overlayEmoji").textContent = song.emoji;
    document.getElementById("overlayTitle").textContent = song.title;
    document.getElementById("overlaySub").textContent =
      `${song.artist} · ${song.bpm} BPM · ${beatmapData.notes.length} notes · Speed ${speedMult}×`;

    gameState = "start";
    draw();
  }

  async function loadFromFiles() {
    try {
      // Load metadata from _index.json
      const idxResp = await fetch("songs/_index.json");
      if (!idxResp.ok) throw new Error("No _index.json");
      const index = await idxResp.json();
      const meta = (index || []).find((s) => s.id === songId);
      if (!meta) throw new Error("Song not in index");

      song = {
        id: meta.id,
        title: meta.title,
        artist: meta.artist,
        bpm: meta.bpm,
        difficulty: meta.difficulty,
        difficultyLabel: meta.difficultyLabel || "Normal",
        color: meta.color || ["#ec4899", "#a855f7"],
        emoji: meta.emoji || "♪",
        duration: meta.duration || 60,
        noteCount: meta.noteCount || 0,
        type: "builtin",
        audioUrl: null,
        coverUrl: "songs/" + meta.id + "/cover.svg",
      };

      // Load beatmap from songs/{id}/beatmap.json
      const bmResp = await fetch("songs/" + songId + "/beatmap.json");
      if (!bmResp.ok) throw new Error("No beatmap.json");
      beatmapData = await bmResp.json();
    } catch (e) {
      console.error("Filesystem fallback failed:", e);
      song = {
        id: songId, title: songId, artist: "Unknown",
        bpm: 120, difficulty: 2, difficultyLabel: "Normal",
        color: ["#ec4899", "#a855f7"], emoji: "♪",
        duration: 60, noteCount: 0, type: "builtin",
      };
      beatmapData = { notes: [], duration: 0 };
    }
  }

  /* ═══════════════════════════════════════════════════════
     HIT DETECTION
     ═══════════════════════════════════════════════════════ */
  const APPROACH_TIME = APPROACH_TIME_BASE / speedMult;

  function hitLane(lane) {
    if (gameState !== "playing") return;
    laneFlashes[lane] = 1.0;
    lanePressed[lane] = true;

    // Skip if already holding this lane
    if (holdNotes[lane]) return;

    let best = null, bestDiff = Infinity;
    for (const n of activeNotes) {
      if (n.lane !== lane || n.hit || n.missed) continue;
      const diffMs = Math.abs(n.time - songTime);
      if (diffMs < bestDiff) { bestDiff = diffMs; best = n; }
    }

    if (best && bestDiff <= TIMING.bad) {
      let type;
      if (bestDiff <= TIMING.perfect) type = "perfect";
      else if (bestDiff <= TIMING.great) type = "great";
      else if (bestDiff <= TIMING.good) type = "good";
      else type = "bad";

      if (best.endTime) {
        // Hold note: mark as holding, judge at release
        best.holding = true;
        best.holdType = type;
        holdNotes[lane] = best;
      } else {
        // Tap note: judge immediately
        best.hit = true;
      }

      judgmentCounts[type]++;
      combo++;
      if (combo > maxCombo) maxCombo = combo;
      let noteScore = SCORE_VALUES[type] * (1 + Math.floor(combo / 10) * 0.1);
      if (best.gold) noteScore *= GOLD_MULTIPLIER;
      score += Math.floor(noteScore);

      updateHUD();
      showJudgment(type, best.gold);
      playSFX(type);
    }
  }

  function releaseLane(lane) {
    if (gameState !== "playing") return;
    lanePressed[lane] = false;

    const hold = holdNotes[lane];
    if (!hold) return;

    // Check release timing
    const diffMs = Math.abs(hold.endTime - songTime);
    let releaseType;
    if (diffMs <= TIMING.perfect) releaseType = "perfect";
    else if (diffMs <= TIMING.great) releaseType = "great";
    else if (diffMs <= TIMING.good) releaseType = "good";
    else if (diffMs <= TIMING.bad) releaseType = "bad";
    else releaseType = "miss";

    // Use the better of hold start or release judgment
    // BUT: if player started the hold correctly, NEVER count as full miss
    const types = ["miss", "bad", "good", "great", "perfect"];
    const startIdx = types.indexOf(hold.holdType);
    const releaseIdx = types.indexOf(releaseType);
    let finalType;

    if (releaseType === "miss") {
      // Player released too early/late, but they DID start the hold
      // Give at least 'bad' judgment — don't break combo
      finalType = types[Math.max(startIdx, 1)]; // at least 'bad'
    } else {
      finalType = types[Math.max(startIdx, releaseIdx)];
    }

    hold.hit = true;
    hold.holding = false;
    delete holdNotes[lane];

    judgmentCounts[finalType]++;
    combo++;
    if (combo > maxCombo) maxCombo = combo;

    let holdScore = SCORE_VALUES[finalType] * 1.5 * (1 + Math.floor(combo / 10) * 0.1);
    if (hold.gold) holdScore *= GOLD_MULTIPLIER;
    score += Math.floor(holdScore);

    updateHUD();
    showJudgment(finalType, hold.gold);
    playSFX(finalType);
  }

  /* ═══════════════════════════════════════════════════════
     HUD
     ═══════════════════════════════════════════════════════ */
  function updateHUD() {
    hudScore.textContent = pad6(score);
    hudScore.classList.remove("score-pop");
    void hudScore.offsetWidth;
    hudScore.classList.add("score-pop");

    if (combo >= 2) {
      comboWrap.classList.remove("hidden");
      comboNumber.textContent = combo;
      comboNumber.classList.remove("pop");
      void comboNumber.offsetWidth;
      comboNumber.classList.add("pop");
    } else {
      comboWrap.classList.add("hidden");
    }

    const total = judgmentCounts.perfect + judgmentCounts.great + judgmentCounts.good + judgmentCounts.bad + judgmentCounts.miss;
    if (total > 0) {
      const acc = ((judgmentCounts.perfect * ACC_WEIGHT.perfect + judgmentCounts.great * ACC_WEIGHT.great + judgmentCounts.good * ACC_WEIGHT.good + judgmentCounts.bad * ACC_WEIGHT.bad) / total * 100).toFixed(1);
      hudAcc.textContent = acc + "%";
    }
  }

  let judgeTimer = null;
  function showJudgment(type, isGold) {
    const text = isGold ? "GOLD " + type.toUpperCase() : type.toUpperCase();
    const color = isGold ? GOLD_COLOR : JUDGE_COLORS[type];
    judgmentText.textContent = text;
    judgmentText.style.color = color;
    judgmentText.style.textShadow = isGold
      ? `0 0 20px ${GOLD_GLOW}, 0 0 40px ${GOLD_GLOW}`
      : `0 0 16px ${JUDGE_COLORS[type]}80`;
    judgmentWrap.classList.remove("hidden");
    clearTimeout(judgeTimer);
    judgmentText.style.animation = "none";
    void judgmentText.offsetWidth;
    judgmentText.style.animation = "";
    judgeTimer = setTimeout(() => judgmentWrap.classList.add("hidden"), 550);
  }

  /* ═══════════════════════════════════════════════════════
     DRAWING
     ═══════════════════════════════════════════════════════ */
  // Determine note color based on type and gold status
  function noteColorFor(note) {
    if (note.gold) return GOLD_COLOR;
    return note.endTime ? COLOR_HOLD : COLOR_CLICK;
  }
  function noteColorBrightFor(note) {
    if (note.gold) return GOLD_BRIGHT;
    return note.endTime ? COLOR_HOLD_BRIGHT : COLOR_CLICK_BRIGHT;
  }

  function draw() {
    const w = getW(), h = getH();
    const lw = laneWidth();
    const hy = hitY();
    const ppm = hy / APPROACH_TIME;
    const ns = getNoteSize();
    const nh = NOTE_HEIGHT_BASE * ns;
    const pfx = playfieldX(); // left edge of playfield
    const pfw = lw * LANE_COUNT; // playfield total width

    ctx.clearRect(0, 0, w, h);

    // Background
    ctx.fillStyle = "#08080f";
    ctx.fillRect(0, 0, w, h);
    if (gameOptions.lowGfx) { /* skip gradient in low gfx */ } else {
    const bgGrad = ctx.createLinearGradient(0, 0, 0, h);
    bgGrad.addColorStop(0, "rgba(168,85,247,0.03)");
    bgGrad.addColorStop(0.5, "transparent");
    bgGrad.addColorStop(1, "rgba(244,63,122,0.02)");
    ctx.fillStyle = bgGrad;
    ctx.fillRect(0, 0, w, h);
    } // end else lowGfx

    // Playfield background (subtle dark panel)
    ctx.fillStyle = "rgba(0,0,0,0.35)";
    ctx.fillRect(pfx, 0, pfw, h);

    // Lanes
    for (let i = 0; i < LANE_COUNT; i++) {
      const x = pfx + i * lw;
      // Subtle lane background
      ctx.fillStyle = i % 2 === 0 ? "rgba(255,255,255,0.02)" : "rgba(255,255,255,0.01)";
      ctx.fillRect(x, 0, lw, h);

      ctx.strokeStyle = "rgba(255,255,255,0.06)";
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(x, 0);
      ctx.lineTo(x, h);
      ctx.stroke();

      // Flash
      if (laneFlashes[i] > 0) {
        const flashColor = lanePressed[i] ? LANE_COLORS_BRIGHT[i] : LANE_COLORS[i];
        const fg = ctx.createLinearGradient(x, hy, x, hy - 100);
        fg.addColorStop(0, flashColor + "50");
        fg.addColorStop(1, "transparent");
        ctx.fillStyle = fg;
        ctx.globalAlpha = laneFlashes[i] * 0.4;
        ctx.fillRect(x, hy - 100, lw, 100);
        ctx.globalAlpha = 1;
        laneFlashes[i] -= 0.05;
      }

      // Pressed glow
      if (lanePressed[i]) {
        const pg = ctx.createLinearGradient(x, hy, x, hy - 50);
        pg.addColorStop(0, LANE_COLORS[i] + "25");
        pg.addColorStop(1, "transparent");
        ctx.fillStyle = pg;
        ctx.fillRect(x, hy - 50, lw, 50);
      }

      // Lane border on right
      ctx.strokeStyle = "rgba(255,255,255,0.06)";
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(x + lw, 0);
      ctx.lineTo(x + lw, h);
      ctx.stroke();
    }

    // Hit line glow (only within playfield)
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

    // Receptors — just thin lines, no key labels
    for (let i = 0; i < LANE_COUNT; i++) {
      const rx = pfx + i * lw;
      ctx.fillStyle = lanePressed[i] ? "rgba(255,255,255,0.6)" : "rgba(255,255,255,0.2)";
      ctx.fillRect(rx + lw * 0.08, hy - 2, lw * 0.84, 3);
    }

    // Hold note trails (draw behind tap notes)
    for (const note of activeNotes) {
      if (!note.endTime || note.hit || note.missed) continue;
      const cx = pfx + note.lane * lw + lw / 2;
      const cyStart = hy - (note.time - songTime) * ppm;
      const cyEnd = hy - (note.endTime - songTime) * ppm;
      if (cyStart < -200 && cyEnd < -200) continue;
      if (cyStart > h + 50 && cyEnd > h + 50) continue;

      const trailW = lw * 0.75 * ns;
      const color = noteColorFor(note);

      // When holding: trail only from hit line to end (already-held part disappears)
      const drawTop = note.holding ? hy : cyStart;
      const drawBottom = cyEnd;
      if (drawTop <= drawBottom) continue;

      // Trail body
      ctx.globalAlpha = note.holding ? 0.75 : 0.5;
      ctx.fillStyle = color;
      ctx.fillRect(cx - trailW / 2, drawBottom, trailW, drawTop - drawBottom);

      // Trail border
      ctx.globalAlpha = note.holding ? 0.9 : 0.6;
      ctx.strokeStyle = color;
      ctx.lineWidth = 2;
      ctx.strokeRect(cx - trailW / 2, drawBottom, trailW, drawTop - drawBottom);

      // Head (start) cap
      if (!note.holding) {
        ctx.globalAlpha = 0.85;
        ctx.fillStyle = color;
        ctx.fillRect(cx - trailW / 2, cyStart - 5, trailW, 10);
      }

      // Tail (end) cap
      ctx.globalAlpha = 0.9;
      ctx.fillStyle = color;
      ctx.fillRect(cx - trailW / 2, drawBottom - 5, trailW, 10);

      ctx.globalAlpha = 1;
    }

    // Tap & hold head notes
    for (const note of activeNotes) {
      if (note.hit || note.missed) continue;
      if (note.holding) continue;
      const cx = pfx + note.lane * lw + lw / 2;
      const cy = hy - (note.time - songTime) * ppm;
      if (cy < -30 || cy > h + 30) continue;

      const noteW = lw * 0.85 * ns;
      const noteColor = noteColorFor(note);
      const noteH = nh;
      const isHold = !!note.endTime;

      // Note body
      ctx.fillStyle = noteColor;
      ctx.fillRect(cx - noteW / 2, cy - noteH / 2, noteW, noteH);

      // Note border
      ctx.strokeStyle = "rgba(255,255,255,0.15)";
      ctx.lineWidth = 1.5;
      ctx.strokeRect(cx - noteW / 2, cy - noteH / 2, noteW, noteH);

      // Inner highlight
      ctx.fillStyle = "rgba(255,255,255,0.12)";
      ctx.fillRect(cx - noteW / 2 + 2, cy - noteH / 2 + 1, noteW - 4, noteH * 0.35);

      // Hold indicator triangle
      if (isHold) {
        ctx.fillStyle = "rgba(255,255,255,0.5)";
        ctx.beginPath();
        ctx.moveTo(cx, cy - noteH / 2 - 4);
        ctx.lineTo(cx + 5, cy - noteH / 2 + 2);
        ctx.lineTo(cx, cy - noteH / 2 + 8);
        ctx.lineTo(cx - 5, cy - noteH / 2 + 2);
        ctx.closePath();
        ctx.fill();
      }
    }

    // Approach lines
    for (let i = 0; i < LANE_COUNT; i++) {
      const cx = pfx + i * lw + lw / 2;
      ctx.fillStyle = LANE_COLORS[i] + "15";
      ctx.fillRect(cx - 1, 0, 2, hy);
    }
  }

  /* ═══════════════════════════════════════════════════════
     GAME LOOP
     ═══════════════════════════════════════════════════════ */
  function gameLoop(ts) {
    if (gameState !== "playing") return;

    if (!lastTime) lastTime = ts;
    const dt = ts - lastTime;
    lastTime = ts;
    songTime += dt;
    updateFPS();

    const hy = hitY();
    const ppm = hy / APPROACH_TIME;

    // Spawn notes
    while (noteIndex < notes.length && notes[noteIndex].time - songTime <= APPROACH_TIME) {
      activeNotes.push({ ...notes[noteIndex] });
      noteIndex++;
    }

    // Miss detection
    for (const n of activeNotes) {
      if (n.hit || n.missed) continue;
      if (n.holding) continue; // don't miss while holding
      if (n.time - songTime < -TIMING.bad - 30) {
        n.missed = true;
        judgmentCounts.miss++;
        combo = 0;
        showJudgment("miss");
        updateHUD();
        playSFX("miss");
      }
    }

    // Auto-release holds that have passed their end time
    for (const laneStr of Object.keys(holdNotes)) {
      const lane = parseInt(laneStr);
      const hold = holdNotes[lane];
      if (hold && songTime > hold.endTime + TIMING.bad + 50) {
        // Player missed the release
        hold.hit = true;
        hold.holding = false;
        delete holdNotes[lane];
        judgmentCounts.miss++;
        combo = 0;
        showJudgment("miss");
        updateHUD();
        playSFX("miss");
      }
    }

    // Cleanup
    activeNotes = activeNotes.filter((n) => {
      if (n.hit || n.missed) return (n.time - songTime) > -500;
      return true;
    });

    // Progress
    if (songDuration > 0) {
      progressFill.style.width = Math.min(songTime / songDuration * 100, 100) + "%";
    }

    draw();

    // End check — only when all notes have been spawned AND processed
    const allSpawned = noteIndex >= notes.length;
    const allProcessed = activeNotes.length === 0 && allSpawned;
    if (allSpawned && songTime >= songDuration + 2000 && (allProcessed || activeNotes.every((n) => n.hit || n.missed))) {
      showResults();
      return;
    }

    animFrame = requestAnimationFrame(gameLoop);
  }

  /* ═══════════════════════════════════════════════════════
     GAME FLOW
     ═══════════════════════════════════════════════════════ */
  function startGame() {
    initAudio();
    resumeAudio();

    score = 0; combo = 0; maxCombo = 0; noteIndex = 0;
    songTime = 0; lastTime = 0;
    activeNotes = [];
    laneFlashes = [0, 0, 0, 0];
    lanePressed = [false, false, false, false];
    judgmentCounts = { perfect: 0, great: 0, good: 0, bad: 0, miss: 0 };

    // Use loaded beatmap data (support hold notes 'e' and gold notes 'g')
    notes = beatmapData.notes.map((n) => ({
      time: n.t,
      endTime: n.e || null,
      lane: n.l,
      gold: !!n.g,
      hit: false,
      missed: false,
      holding: false,
      holdType: null,
    }));
    holdNotes = {};
    songDuration = beatmapData.duration;
    totalNotes = notes.length;

    hudTitle.textContent = song.title;
    hudArtist.textContent = song.artist;
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
      gameState = "playing";
      lastTime = 0;
      startBGM();
      animFrame = requestAnimationFrame(gameLoop);
    });
  }

  function pauseGame() {
    if (gameState !== "playing") return;
    gameState = "paused";
    stopBGM();
    if (animFrame) cancelAnimationFrame(animFrame);
    pauseOverlay.classList.remove("hidden");
    lanePressed = [false, false, false, false];
  }

  // ─── Countdown (3-2-1-GO) ──────────────────────────
  var countdownOverlay = document.getElementById("countdownOverlay");
  var countdownNum = document.getElementById("countdownNum");

  function playCountdownBeep(freq, duration) {
    if (!audioCtx) return;
    try {
      if (audioCtx.state === "suspended") audioCtx.resume();
      var osc = audioCtx.createOscillator();
      var gain = audioCtx.createGain();
      osc.type = "sine";
      osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
      gain.gain.setValueAtTime(0.3, audioCtx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + duration);
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.start(audioCtx.currentTime);
      osc.stop(audioCtx.currentTime + duration);
    } catch(e) {}
  }

  function runCountdown(callback) {
    gameState = "countdown";
    countdownOverlay.classList.remove("hidden");
    var count = 3;
    countdownNum.textContent = count;
    countdownNum.className = "countdown-num";
    void countdownNum.offsetWidth;
    countdownNum.style.animation = "none";
    void countdownNum.offsetWidth;
    countdownNum.style.animation = "";

    // Play first beep immediately
    playCountdownBeep(440, 0.15);

    var timer = setInterval(function () {
      count--;
      if (count > 0) {
        countdownNum.textContent = count;
        countdownNum.className = "countdown-num";
        void countdownNum.offsetWidth;
        countdownNum.style.animation = "none";
        void countdownNum.offsetWidth;
        countdownNum.style.animation = "";
        playCountdownBeep(440, 0.15); // normal beep
      } else if (count === 0) {
        countdownNum.textContent = "GO!";
        countdownNum.className = "countdown-go";
        void countdownNum.offsetWidth;
        countdownNum.style.animation = "none";
        void countdownNum.offsetWidth;
        countdownNum.style.animation = "";
        playCountdownBeep(880, 0.25); // higher pitch for GO!
      } else {
        clearInterval(timer);
        countdownOverlay.classList.add("hidden");
        callback();
      }
    }, 800);
  }

  window.resumeGame = function () {
    if (gameState !== "paused") return;
    pauseOverlay.classList.add("hidden");
    runCountdown(function () {
      gameState = "playing";
      lastTime = 0;
      startBGM();
      animFrame = requestAnimationFrame(gameLoop);
    });
  };

  window.restartGame = function () {
    pauseOverlay.classList.add("hidden");
    // Reset all game state
    score = 0; combo = 0; maxCombo = 0;
    judgmentCount = { perfect: 0, great: 0, good: 0, bad: 0, miss: 0 };
    noteIndex = 0;
    for (var i = 0; i < 4; i++) { lanePressed[i] = false; laneHeld[i] = false; }
    audio.currentTime = 0;
    runCountdown(function () {
      gameState = "playing";
      lastTime = 0;
      startBGM();
      animFrame = requestAnimationFrame(gameLoop);
    });
  };

  // ─── Options/Advanced ─────────────────────────────
  var optionsOverlay = document.getElementById("optionsOverlay");
  var gameOptions = {
    speed: 10, dim: 70, volume: 80,
    blurBg: false, fps: false, lowGfx: false
  };

  // FPS counter
  var fpsDisplay = null;
  var fpsFrames = 0;
  var fpsLastTime = performance.now();

  function updateFPS() {
    if (!gameOptions.fps || !fpsDisplay) return;
    fpsFrames++;
    var now = performance.now();
    if (now - fpsLastTime >= 1000) {
      fpsDisplay.textContent = fpsFrames + " FPS";
      fpsFrames = 0;
      fpsLastTime = now;
    }
  }

  function loadOptions() {
    try {
      var saved = JSON.parse(localStorage.getItem("mania_options") || "null");
      if (saved) Object.assign(gameOptions, saved);
    } catch(e) {}
    document.getElementById("optSpeed").value = gameOptions.speed;
    document.getElementById("optDim").value = gameOptions.dim;
    document.getElementById("optVolume").value = gameOptions.volume;
    document.getElementById("optBlurBg").checked = gameOptions.blurBg;
    document.getElementById("optFPS").checked = gameOptions.fps;
    document.getElementById("optLowGfx").checked = gameOptions.lowGfx;
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
    gameOptions.speed = parseInt(document.getElementById("optSpeed").value);
    gameOptions.dim = parseInt(document.getElementById("optDim").value);
    gameOptions.volume = parseInt(document.getElementById("optVolume").value);
    gameOptions.blurBg = document.getElementById("optBlurBg").checked;
    gameOptions.fps = document.getElementById("optFPS").checked;
    gameOptions.lowGfx = document.getElementById("optLowGfx").checked;
    localStorage.setItem("mania_options", JSON.stringify(gameOptions));
    applyOptions();
    optionsOverlay.classList.add("hidden");
    pauseOverlay.classList.remove("hidden");
  };

  function applyOptions() {
    // FPS display
    if (gameOptions.fps && !fpsDisplay) {
      fpsDisplay = document.getElementById("fpsCounter");
      if (fpsDisplay) fpsDisplay.classList.remove("hidden");
    } else if (!gameOptions.fps && fpsDisplay) {
      fpsDisplay.classList.add("hidden");
    }
    // Background image
    var bgEl = document.getElementById("bgImage");
    if (bgEl) {
      if (gameOptions.blurBg && song && song.cover_url) {
        bgEl.style.backgroundImage = "url('" + song.cover_url + "')";
        bgEl.classList.remove("hidden");
      } else {
        bgEl.classList.add("hidden");
      }
    }
    // Dim overlay — only active when blur bg is on
    var dimEl = document.getElementById("dimOverlay");
    if (dimEl) {
      if (gameOptions.blurBg && song && song.cover_url) {
        dimEl.classList.add("active");
        dimEl.style.opacity = gameOptions.dim / 100;
      } else {
        dimEl.classList.remove("active");
      }
    }
  }

  // Load options on init
  loadOptions();

  window.quitToLobby = function () {
    gameState = "start";
    stopBGM();
    if (animFrame) cancelAnimationFrame(animFrame);
    window.location.href = "./";
  };

  function showResults() {
    gameState = "results";
    stopBGM();
    if (animFrame) cancelAnimationFrame(animFrame);

    // Save score to localStorage
    try {
      const saved = JSON.parse(localStorage.getItem("mania_scores")) || {};
      const key = String(songId);
      const isNew = !saved[key] || score > saved[key];
      if (isNew) saved[key] = score;
      localStorage.setItem("mania_scores", JSON.stringify(saved));
      highScore = saved[key];
      document.getElementById("newHighScoreBanner").classList.toggle("hidden", !isNew);
    } catch (e) {}

    // Calculate rank & accuracy
    const total = judgmentCounts.perfect + judgmentCounts.great + judgmentCounts.good + judgmentCounts.bad + judgmentCounts.miss;
    const acc = total > 0
      ? (judgmentCounts.perfect * ACC_WEIGHT.perfect + judgmentCounts.great * ACC_WEIGHT.great + judgmentCounts.good * ACC_WEIGHT.good + judgmentCounts.bad * ACC_WEIGHT.bad) / total * 100
      : 0;
    let rank = "D";
    if (acc >= 95) rank = "S";
    else if (acc >= 90) rank = "A";
    else if (acc >= 80) rank = "B";
    else if (acc >= 70) rank = "C";

    document.getElementById("resultsRank").textContent = rank;
    document.getElementById("resultsScore").textContent = pad6(score);
    document.getElementById("resPerfect").textContent = judgmentCounts.perfect;
    document.getElementById("resGreat").textContent = judgmentCounts.great;
    document.getElementById("resGood").textContent = judgmentCounts.good;
    document.getElementById("resBad").textContent = judgmentCounts.bad;
    document.getElementById("resMiss").textContent = judgmentCounts.miss;
    document.getElementById("resMaxCombo").textContent = maxCombo;
    document.getElementById("resultsAcc").textContent = acc.toFixed(1) + "%";

    resultsOverlay.classList.remove("hidden");
    hud.classList.add("hidden");
    touchLanes.classList.add("hidden");
  }

  /* ═══════════════════════════════════════════════════════
     INPUT: KEYBOARD
     ═══════════════════════════════════════════════════════ */
  document.addEventListener("keydown", (e) => {
    const key = e.key.toLowerCase();

    if (gameState === "start" && (key === " " || key === "enter")) {
      e.preventDefault();
      startGame();
      return;
    }
    if (gameState === "playing" && key === "escape") {
      e.preventDefault();
      pauseGame();
      return;
    }
    if (gameState === "paused" && (key === " " || key === "enter" || key === "escape")) {
      e.preventDefault();
      resumeGame();
      return;
    }
    if (gameState === "playing" && key in KEY_MAP) {
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

  /* ═══════════════════════════════════════════════════════
     INPUT: TOUCH
     ═══════════════════════════════════════════════════════ */
  function isMobile() {
    return "ontouchstart" in window || navigator.maxTouchPoints > 0;
  }

  document.querySelectorAll(".touch-btn").forEach((btn) => {
    const lane = parseInt(btn.dataset.lane);
    const onDown = (e) => {
      e.preventDefault();
      if (gameState === "start") { startGame(); return; }
      if (gameState === "playing") hitLane(lane);
    };
    btn.addEventListener("touchstart", onDown, { passive: false });
    btn.addEventListener("mousedown", onDown);
    btn.addEventListener("touchend", () => { releaseLane(lane); });
    btn.addEventListener("mouseup", () => { releaseLane(lane); });
  });

  canvas.addEventListener("touchstart", (e) => {
    e.preventDefault();
    if (gameState === "start") { startGame(); return; }
    if (gameState !== "playing") return;
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
    if (gameState === "start") { startGame(); return; }
    if (gameState !== "playing") return;
    const rect = canvas.getBoundingClientRect();
    const scaleX = getW() / rect.width;
    const x = (e.clientX - rect.left) * scaleX;
    const lane = Math.floor((x - playfieldX()) / laneWidth());
    if (lane >= 0 && lane < LANE_COUNT) hitLane(lane);
  });

  canvas.addEventListener("mouseup", () => {
    for (let i = 0; i < LANE_COUNT; i++) releaseLane(i);
  });

  startOverlay.addEventListener("click", () => { if (gameState === "start") startGame(); });
  document.getElementById("btnQuit").addEventListener("click", quitToLobby);
  document.getElementById("btnRetry").addEventListener("click", () => {
    resultsOverlay.classList.add("hidden");
    startGame();
  });
  document.getElementById("btnBackLobby").addEventListener("click", quitToLobby);

  /* ═══════════════════════════════════════════════════════
     INIT
     ═══════════════════════════════════════════════════════ */
  async function init() {
    resizeCanvas();
    window.addEventListener("resize", resizeCanvas);
    await loadSongData();
  }

  init();
})();
