/**
 * MEeL!Mania Game — Hit Detection & Scoring
 * Lane hit/release detection, judgment, scoring, combo.
 *
 * Key improvements:
 * - Hold buffer: press early → auto-grab when note arrives
 * - Generous release window scaled by hold duration
 * - Hold sustain penalty instead of instant miss
 */
import {
  S, TIMING, SCORE_VALUES, GOLD_MULTIPLIER,
  HOLD_BUFFER, HOLD_RELEASE_SCALE,
} from "./state.js";
import { playSFX } from "./audio.js";
import { updateHUD, showJudgment } from "./renderer.js";

/* HIT LANE */
export function hitLane(lane) {
  if (S.gameState !== "playing") return;
  S.laneFlashes[lane] = 1.0;
  S.lanePressed[lane] = true;

  // Already holding a note in this lane — don't interrupt
  if (S.holdNotes[lane]) return;

  // Check if there's a pending hold buffer from earlier press
  if (S.holdPending[lane]) {
    const pending = S.holdPending[lane];
    // Auto-grab the pending note if it's now within the hit window
    const diffMs = Math.abs(pending.time - S.songTime);
    if (diffMs <= TIMING.bad * HOLD_RELEASE_SCALE) {
      _activateHold(lane, pending, diffMs);
      delete S.holdPending[lane];
      return;
    }
    // Too far — clear pending
    delete S.holdPending[lane];
  }

  // Find the best unhit note in this lane
  let best = null,
    bestDiff = Infinity;

  // For hold notes, use a wider input window
  const holdWindow = TIMING.bad * HOLD_RELEASE_SCALE;

  for (const n of S.activeNotes) {
    if (n.lane !== lane || n.hit || n.missed) continue;
    const diffMs = Math.abs(n.time - S.songTime);

    // Click notes: standard window
    if (!n.endTime) {
      if (diffMs < bestDiff) {
        bestDiff = diffMs;
        best = n;
      }
    } else {
      // Hold notes: wider window for start
      if (diffMs < bestDiff && diffMs <= holdWindow) {
        bestDiff = diffMs;
        best = n;
      }
    }
  }

  if (!best) {
    // No note found — but if there's a hold note coming up soon, buffer it
    for (const n of S.activeNotes) {
      if (n.lane !== lane || n.hit || n.missed || !n.endTime) continue;
      const diffMs = n.time - S.songTime;
      // Note is in the future but within hold buffer range
      if (diffMs > 0 && diffMs <= HOLD_BUFFER) {
        S.holdPending[lane] = n;
        S.laneFlashes[lane] = 0.5; // Visual feedback
        break;
      }
    }
    return;
  }

  if (best.endTime) {
    // Hit a hold note — activate it
    _activateHold(lane, best, bestDiff);
  } else {
    // Hit a click note
    let type;
    if (bestDiff <= TIMING.perfect) type = "perfect";
    else if (bestDiff <= TIMING.great) type = "great";
    else if (bestDiff <= TIMING.good) type = "good";
    else type = "bad";

    best.hit = true;

    S.judgmentCounts[type]++;
    S.combo++;
    if (S.combo > S.maxCombo) S.maxCombo = S.combo;
    let noteScore = SCORE_VALUES[type] * (1 + Math.floor(S.combo / 10) * 0.1);
    if (best.gold) noteScore *= GOLD_MULTIPLIER;
    S.score += Math.floor(noteScore);

    updateHUD();
    showJudgment(type, best.gold);
    playSFX(type);
  }
}

/* ACTIVATE HOLD NOTE */
function _activateHold(lane, note, diffMs) {
  let type;
  if (diffMs <= TIMING.perfect) type = "perfect";
  else if (diffMs <= TIMING.great) type = "great";
  else if (diffMs <= TIMING.good) type = "good";
  else type = "bad";

  note.holding = true;
  note.holdType = type;
  note.holdStartTime = S.songTime;
  S.holdNotes[lane] = note;

  // Score the start of the hold
  S.judgmentCounts[type]++;
  S.combo++;
  if (S.combo > S.maxCombo) S.maxCombo = S.combo;
  let noteScore = SCORE_VALUES[type] * (1 + Math.floor(S.combo / 10) * 0.1);
  if (note.gold) noteScore *= GOLD_MULTIPLIER;
  S.score += Math.floor(noteScore);

  updateHUD();
  showJudgment(type, note.gold);
  playSFX(type);
}

/* RELEASE LANE */
export function releaseLane(lane) {
  if (S.gameState !== "playing") return;
  S.lanePressed[lane] = false;

  // Cancel any pending hold buffer
  delete S.holdPending[lane];

  const hold = S.holdNotes[lane];
  if (!hold) return;

  const holdDuration = hold.endTime - hold.time;
  // Scale release tolerance: longer holds get more generous release window
  // Minimum TIMING.bad (115ms), up to 2x for long holds
  const releaseTolerance = Math.min(
    TIMING.bad * 2,
    Math.max(TIMING.bad, TIMING.bad + holdDuration * 0.05)
  );

  const diffMs = Math.abs(hold.endTime - S.songTime);
  let releaseType;
  if (diffMs <= TIMING.perfect) releaseType = "perfect";
  else if (diffMs <= TIMING.great) releaseType = "great";
  else if (diffMs <= TIMING.good) releaseType = "good";
  else if (diffMs <= releaseTolerance) releaseType = "bad";
  else releaseType = "miss";

  const types = ["miss", "bad", "good", "great", "perfect"];
  const startIdx = types.indexOf(hold.holdType);
  const releaseIdx = types.indexOf(releaseType);
  let finalType;

  if (releaseType === "miss") {
    // If held for at least 50% of the duration, don't count as full miss
    const heldMs = S.songTime - hold.time;
    const heldRatio = heldMs / holdDuration;
    if (heldRatio >= 0.8) {
      // Held most of it — just bad
      finalType = types[Math.max(startIdx, 1)];
    } else if (heldRatio >= 0.5) {
      // Held half — good
      finalType = "good";
    } else {
      finalType = types[Math.max(startIdx, 1)];
    }
  } else {
    finalType = types[Math.max(startIdx, releaseIdx)];
  }

  hold.hit = true;
  hold.holding = false;
  delete S.holdNotes[lane];

  S.judgmentCounts[finalType]++;
  S.combo++;
  if (S.combo > S.maxCombo) S.maxCombo = S.combo;

  let holdScore = SCORE_VALUES[finalType] * 1.5 * (1 + Math.floor(S.combo / 10) * 0.1);
  if (hold.gold) holdScore *= GOLD_MULTIPLIER;
  S.score += Math.floor(holdScore);

  updateHUD();
  showJudgment(finalType, hold.gold);
  playSFX(finalType);
}

/* HOLD SUSTAIN CHECK (called from game loop) */
export function checkHoldSustain(lane) {
  const hold = S.holdNotes[lane];
  if (!hold || !hold.holding) return;

  // Check if key is still pressed
  if (!S.lanePressed[lane]) {
    releaseLane(lane);
    return;
  }

  // Check if hold end time passed (with extra grace period)
  if (S.songTime > hold.endTime + TIMING.bad * 1.5) {
    // Auto-release as completed (generous — player was still holding)
    hold.hit = true;
    hold.holding = false;
    delete S.holdNotes[lane];

    // Grade based on how well they held
    const heldMs = hold.endTime - hold.time;
    const type = hold.holdType || "good";
    S.judgmentCounts[type]++;
    S.combo++;
    if (S.combo > S.maxCombo) S.maxCombo = S.combo;

    let holdScore = SCORE_VALUES[type] * 1.5 * (1 + Math.floor(S.combo / 10) * 0.1);
    if (hold.gold) holdScore *= GOLD_MULTIPLIER;
    S.score += Math.floor(holdScore);

    updateHUD();
    showJudgment(type, hold.gold);
    playSFX(type);
  }
}
