/**
 * MEeL!Mania — Input handling
 * Canvas mouse interaction, timeline scrubbing, keyboard shortcuts,
 * and note-editing actions triggered from the note-info panel.
 */
import { S, DOM, CONST } from "./state.js";
import { msToY, yToMs, snapMs, getCanvasPos, getLaneAndTime, findNoteAt } from "./canvas.js";
import { draw, updateNoteInfo } from "./renderer.js";
import { saveNotesToStorage } from "./storage.js";
import { showToast } from "./toast.js";
import { togglePlayback } from "./playback.js";

var canvas = DOM.canvas;
var wrap = DOM.wrap;
var audio = DOM.audio;

/* ─── Note edit actions (called from note-info panel buttons) ─── */
export function toggleGold() {
  if (S.selectedNoteIdx < 0) { showToast("Pilih note dulu!", "warning"); return; }
  S.undoStack.push(JSON.parse(JSON.stringify(S.notes)));
  S.notes[S.selectedNoteIdx].g = !S.notes[S.selectedNoteIdx].g;
  draw();
  updateNoteInfo();
  showToast(S.notes[S.selectedNoteIdx].g ? "⭐ Gold note!" : "Gold removed", "success");
}

export function deleteSelected() {
  if (S.selectedNoteIdx < 0) return;
  S.undoStack.push(JSON.parse(JSON.stringify(S.notes)));
  S.notes.splice(S.selectedNoteIdx, 1);
  S.selectedNoteIdx = -1;
  draw();
  updateNoteInfo();
  saveNotesToStorage();
}

export function convertToHold() {
  if (S.selectedNoteIdx < 0) return;
  S.undoStack.push(JSON.parse(JSON.stringify(S.notes)));
  var note = S.notes[S.selectedNoteIdx];
  if (!note.e) {
    note.e = note.t + 1000;
    draw();
    updateNoteInfo();
    saveNotesToStorage();
  }
}

export function convertToTap() {
  if (S.selectedNoteIdx < 0) return;
  S.undoStack.push(JSON.parse(JSON.stringify(S.notes)));
  var note = S.notes[S.selectedNoteIdx];
  if (note.e) {
    delete note.e;
    draw();
    updateNoteInfo();
    saveNotesToStorage();
  }
}

/* ─── Canvas mouse: place / drag / move / delete notes ─── */
canvas.addEventListener("mousedown", function (e) {
  var pos = getCanvasPos(e);

  if (audio.currentTime > 0) {
    var cursorY = msToY(audio.currentTime * 1000);
    if (pos.x < 50 && Math.abs(pos.y - cursorY) < 15) {
      S.isDraggingCursor = true;
      e.preventDefault();
      return;
    }
  }

  var info = getLaneAndTime(pos);
  if (!info) return;

  S.lastDragPos = pos;

  if (e.button === 2 && e.ctrlKey) return; // Ctrl+Right-click = seek (contextmenu)

  if (e.button === 2 && !e.ctrlKey) {
    e.preventDefault();
    var idx = findNoteAt(info.lane, info.ms);
    if (idx >= 0) {
      S.undoStack.push(JSON.parse(JSON.stringify(S.notes)));
      S.notes.splice(idx, 1);
      if (S.selectedNoteIdx === idx) S.selectedNoteIdx = -1;
      else if (S.selectedNoteIdx > idx) S.selectedNoteIdx--;
      draw();
      updateNoteInfo();
      saveNotesToStorage();
    }
    return;
  }

  var idx2 = findNoteAt(info.lane, info.ms);
  if (idx2 >= 0) {
    S.selectedNoteIdx = idx2;

    if (S.notes[idx2].e) {
      var noteEndMs = S.notes[idx2].e;
      var noteStartMs = S.notes[idx2].t;
      var noteRange = noteEndMs - noteStartMs;
      var clickDistFromEnd = Math.abs(info.ms - noteEndMs);
      if (clickDistFromEnd < noteRange * 0.3 || clickDistFromEnd < 200) {
        S.isDragging = true;
        S.dragLane = info.lane;
        S.dragNoteIdx = idx2;
        S.dragStartMs = S.notes[idx2].t;
        draw();
        updateNoteInfo();
        return;
      }
    }
    S.isMovingNote = true;
    S.moveNoteIdx = idx2;
    S.moveNoteOrigT = S.notes[idx2].t;
    S.moveNoteOrigL = S.notes[idx2].l;
    S.moveNoteOrigE = S.notes[idx2].e || null;
    draw();
    updateNoteInfo();
    return;
  }

  S.selectedNoteIdx = -1;
  updateNoteInfo();

  S.isDragging = true;
  S.dragLane = info.lane;
  S.dragStartMs = info.ms;
  S.dragNoteIdx = -1;
});

canvas.addEventListener("mousemove", function (e) {
  var pos = getCanvasPos(e);
  if (S.isDraggingCursor) {
    var ms = yToMs(pos.y);
    audio.currentTime = Math.max(0, Math.min(S.audioDuration, ms / 1000));
    draw();
    return;
  }
  if (S.isMovingNote && S.moveNoteIdx >= 0) {
    var info2 = getLaneAndTime(pos);
    if (info2) {
      var dt = info2.ms - S.moveNoteOrigT;
      var dl = info2.lane - S.moveNoteOrigL;
      S.notes[S.moveNoteIdx].t = Math.max(0, Math.round(S.moveNoteOrigT + dt));
      S.notes[S.moveNoteIdx].l = Math.max(0, Math.min(CONST.LANE_COUNT - 1, S.moveNoteOrigL + dl));
      if (S.moveNoteOrigE !== null) {
        S.notes[S.moveNoteIdx].e = Math.round(S.moveNoteOrigE + dt);
      }
      draw();
    }
    return;
  }
  if (!S.isDragging || S.dragLane < 0) return;
  S.lastDragPos = pos;
  draw();
});

canvas.addEventListener("mouseup", function (e) {
  if (S.isDraggingCursor) {
    S.isDraggingCursor = false;
    return;
  }
  if (S.isMovingNote && S.moveNoteIdx >= 0) {
    S.undoStack.push(JSON.parse(JSON.stringify(S.notes)));
    S.notes.sort(function (a, b) { return a.t - b.t; });
    S.isMovingNote = false;
    S.moveNoteIdx = -1;
    draw();
    updateNoteInfo();
    saveNotesToStorage();
    return;
  }
  if (!S.isDragging || S.dragLane < 0) {
    S.isDragging = false;
    S.dragLane = -1;
    return;
  }

  var pos = getCanvasPos(e);
  var info = getLaneAndTime(pos);
  if (!info || info.lane !== S.dragLane) {
    S.isDragging = false;
    S.dragLane = -1;
    return;
  }

  S.undoStack.push(JSON.parse(JSON.stringify(S.notes)));

  var endMs = info.ms;
  var minHold = 100;

  if (S.dragNoteIdx >= 0) {
    if (endMs > S.dragStartMs + minHold) {
      S.notes[S.dragNoteIdx].e = Math.round(endMs);
    } else {
      delete S.notes[S.dragNoteIdx].e;
    }
  } else {
    if (endMs > S.dragStartMs + minHold) {
      S.notes.push({ t: Math.round(S.dragStartMs), e: Math.round(endMs), l: S.dragLane });
    } else {
      S.notes.push({ t: Math.round(S.dragStartMs), l: S.dragLane });
    }
  }

  S.notes.sort(function (a, b) { return a.t - b.t; });
  S.isDragging = false;
  S.dragLane = -1;
  S.dragNoteIdx = -1;
  draw();
  saveNotesToStorage();
});

canvas.addEventListener("mouseleave", function () {
  S.isDragging = false;
  S.isDraggingCursor = false;
  S.dragLane = -1;
  S.dragNoteIdx = -1;
});

/* ─── Cursor handle hover + time tooltip ─── */
var canvasTooltip = null;
canvas.addEventListener("mousemove", function (e) {
  if (S.isDragging || S.isDraggingCursor) {
    if (canvasTooltip) canvasTooltip.style.display = "none";
    return;
  }
  var pos = getCanvasPos(e);
  if (audio.currentTime > 0) {
    var cursorY = msToY(audio.currentTime * 1000);
    if (pos.x < 50 && Math.abs(pos.y - cursorY) < 15) {
      canvas.style.cursor = "ew-resize";
      return;
    }
  }
  canvas.style.cursor = "crosshair";
  if (S.audioDuration > 0) {
    var ms = yToMs(pos.y);
    if (ms >= 0 && ms <= S.audioDuration * 1000) {
      if (!canvasTooltip) {
        canvasTooltip = document.createElement("div");
        canvasTooltip.style.cssText =
          "position:fixed;z-index:9999;background:#1e1e2e;color:#e2e8f0;font:12px 'JetBrains Mono',monospace;" +
          "padding:3px 8px;border-radius:4px;pointer-events:none;border:1px solid rgba(255,255,255,0.1);" +
          "box-shadow:0 2px 8px rgba(0,0,0,0.4);";
        document.body.appendChild(canvasTooltip);
      }
      var sec = (ms / 1000).toFixed(1);
      canvasTooltip.textContent = sec + "s";
      canvasTooltip.style.display = "block";
      canvasTooltip.style.left = e.clientX + 14 + "px";
      canvasTooltip.style.top = e.clientY - 10 + "px";
    } else if (canvasTooltip) {
      canvasTooltip.style.display = "none";
    }
  }
});
canvas.addEventListener("mouseleave", function () {
  if (canvasTooltip) canvasTooltip.style.display = "none";
});

/* ─── Ctrl+Right-click = seek ─── */
canvas.addEventListener("contextmenu", function (e) {
  e.preventDefault();
  if (!e.ctrlKey || !S.audioDuration) return;
  var rect = canvas.getBoundingClientRect();
  var scaleY = canvas.height / rect.height;
  var canvasY = (e.clientY - rect.top) * scaleY + wrap.scrollTop;
  var ms = yToMs(canvasY);
  if (ms < 0) ms = 0;
  if (ms > S.audioDuration * 1000) ms = S.audioDuration * 1000;
  audio.currentTime = ms / 1000;
  draw();
  showToast("Seek: " + (ms / 1000).toFixed(1) + "s", "info");
});

/* ─── Timeline click + drag ─── */
function seekTimeline(e) {
  var rect = DOM.timelineBar.getBoundingClientRect();
  var pct = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
  audio.currentTime = pct * S.audioDuration;
  draw();
}
DOM.timelineBar.addEventListener("mousedown", function (e) {
  S.timelineDragging = true;
  seekTimeline(e);
});
document.addEventListener("mousemove", function (e) {
  if (!S.timelineDragging) return;
  seekTimeline(e);
});
document.addEventListener("mouseup", function () { S.timelineDragging = false; });

/* ─── Keyboard shortcuts ─── */
document.addEventListener("keydown", function (e) {
  var isInput = e.target.tagName === "INPUT" || e.target.tagName === "SELECT" || e.target.tagName === "TEXTAREA";

  if ((e.ctrlKey || e.metaKey) && e.key === "z") {
    e.preventDefault();
    if (S.undoStack.length > 0) {
      S.notes = S.undoStack.pop();
      S.selectedNoteIdx = -1;
      draw();
      updateNoteInfo();
      saveNotesToStorage();
    }
    return;
  }

  if (e.code === "KeyG" && !isInput && S.selectedNoteIdx >= 0) {
    e.preventDefault();
    toggleGold();
    return;
  }

  if (e.code === "KeyG" && !isInput && S.selectedNoteIdx < 0) {
    showToast("Klik note dulu, lalu tekan G untuk toggle gold", "info");
    return;
  }

  if ((e.key === "Delete" || e.key === "Backspace") && !isInput && S.selectedNoteIdx >= 0) {
    e.preventDefault();
    S.undoStack.push(JSON.parse(JSON.stringify(S.notes)));
    S.notes.splice(S.selectedNoteIdx, 1);
    S.selectedNoteIdx = -1;
    draw();
    updateNoteInfo();
    return;
  }

  if (e.key === "Escape") {
    S.selectedNoteIdx = -1;
    draw();
    updateNoteInfo();
    return;
  }

  if (e.code === "Space" && !isInput) {
    e.preventDefault();
    togglePlayback();
  }

  if (e.key === "ArrowUp" && !isInput) {
    e.preventDefault();
    audio.currentTime = Math.min(S.audioDuration, audio.currentTime + 1);
    draw();
  }
  if (e.key === "ArrowDown" && !isInput) {
    e.preventDefault();
    audio.currentTime = Math.max(0, audio.currentTime - 1);
    draw();
  }
});

/* ─── Scroll sync (redraw when scrolling while paused) ─── */
var canvasScroll = canvas.parentElement;
canvasScroll.addEventListener("scroll", function () {
  if (S.isPlaying) return;
  draw();
});