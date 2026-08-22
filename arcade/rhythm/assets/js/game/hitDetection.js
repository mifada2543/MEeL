/**
 * MEeL!Mania Game — Hit Detection & Scoring
 * Lane hit/release detection, judgment, scoring, combo.
 */
import {
  S, TIMING, SCORE_VALUES, GOLD_MULTIPLIER,
} from "./state.js";
import { playSFX } from "./audio.js";
import { updateHUD, showJudgment } from "./renderer.js";

/* ═══════════════════════════════════════════════════════
   HIT LANE
   ═══════════════════════════════════════════════════════ */
export function hitLane(lane) {
  if (S.gameState !== "playing") return;
  S.laneFlashes[lane] = 1.0;
  S.lanePressed[lane] = true;

  if (S.holdNotes[lane]) return;

  let best = null, bestDiff = Infinity;
  for (const n of S.activeNotes) {
    if (n.lane !== lane || n.hit || n.missed) continue;
    const diffMs = Math.abs(n.time - S.songTime);
    if (diffMs < bestDiff) { bestDiff = diffMs; best = n; }
  }

  if (best && bestDiff <= TIMING.bad) {
    let type;
    if (bestDiff <= TIMING.perfect) type = "perfect";
    else if (bestDiff <= TIMING.great) type = "great";
    else if (bestDiff <= TIMING.good) type = "good";
    else type = "bad";

    if (best.endTime) {
      best.holding = true;
      best.holdType = type;
      S.holdNotes[lane] = best;
    } else {
      best.hit = true;
    }

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

/* ═══════════════════════════════════════════════════════
   RELEASE LANE
   ═══════════════════════════════════════════════════════ */
export function releaseLane(lane) {
  if (S.gameState !== "playing") return;
  S.lanePressed[lane] = false;

  const hold = S.holdNotes[lane];
  if (!hold) return;

  const diffMs = Math.abs(hold.endTime - S.songTime);
  let releaseType;
  if (diffMs <= TIMING.perfect) releaseType = "perfect";
  else if (diffMs <= TIMING.great) releaseType = "great";
  else if (diffMs <= TIMING.good) releaseType = "good";
  else if (diffMs <= TIMING.bad) releaseType = "bad";
  else releaseType = "miss";

  const types = ["miss", "bad", "good", "great", "perfect"];
  const startIdx = types.indexOf(hold.holdType);
  const releaseIdx = types.indexOf(releaseType);
  let finalType;

  if (releaseType === "miss") {
    finalType = types[Math.max(startIdx, 1)];
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
