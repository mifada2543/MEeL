/**
 * MEeL!Mania Game — Main Entry Point
 * Imports all modules and initializes the game engine.
 */
import { S } from "./state.js";
import { resizeCanvas } from "./canvas.js";
import { loadSongData } from "./loader.js";
import { loadOptions } from "./gameFlow.js";
import { initKeyboard, initTouch, initButtons } from "./input.js";

/* INIT */
async function init() {
  try { resizeCanvas(); } catch (e) { console.error("[Game] resizeCanvas failed:", e); }
  window.addEventListener("resize", () => { try { resizeCanvas(); } catch (e) {} });
  try { initKeyboard(); } catch (e) { console.error("[Game] initKeyboard failed:", e); }
  try { initTouch(); } catch (e) { console.error("[Game] initTouch failed:", e); }
  try { initButtons(); } catch (e) { console.error("[Game] initButtons failed:", e); }
  try { loadOptions(); } catch (e) { console.error("[Game] loadOptions failed:", e); }
  try { await loadSongData(); } catch (e) { console.error("[Game] loadSongData failed:", e); }
  S.gameState = "start";
}

init();
