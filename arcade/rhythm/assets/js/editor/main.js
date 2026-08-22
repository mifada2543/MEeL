/**
 * MEeL!Mania — Visual Beatmap Editor (entry point)
 * Wires up all modules and exposes the handful of functions referenced
 * by inline onclick="..." attributes in edit.php (inline handlers run
 * in global/window scope, not module scope, so we must attach them
 * explicitly to window).
 */
import { S, DOM } from "./state.js";
import { resizeCanvas } from "./canvas.js";
import { draw, updateNoteInfo } from "./renderer.js";
import { loadNotesFromStorage } from "./storage.js";
import { showToast } from "./toast.js";
import * as Input from "./input.js";
import * as Playback from "./playback.js";
import * as Upload from "./upload.js";

// Bail out gracefully if the editor canvas isn't on this page.
if (DOM.canvas) {
  // Expose globals for inline onclick handlers (see edit.php)
  window.togglePlayback = Playback.togglePlayback;
  window.stopPlayback = Playback.stopPlayback;
  window.setZoom = Playback.setZoom;
  window.setSnap = Playback.setSnap;
  window.clearNotes = Playback.clearNotes;
  window.uploadBeatmap = Upload.uploadBeatmap;
  window.deleteSong = Upload.deleteSong;
  window.editorToggleGold = Input.toggleGold;
  window.editorDeleteSelected = Input.deleteSelected;
  window.editorConvertToHold = Input.convertToHold;
  window.editorConvertToTap = Input.convertToTap;

  init();
}

function init() {
  resizeCanvas();
  var loaded = loadNotesFromStorage();
  if (loaded) {
    showToast("Notes loaded from cache", "success");
    updateNoteInfo();
  }
  draw();
  window.addEventListener("resize", function () { resizeCanvas(); draw(); });

  if (!S.hasAudio) {
    var prompt = document.getElementById("audioPromptOverlay");
    if (prompt) prompt.style.display = "flex";
  }
}