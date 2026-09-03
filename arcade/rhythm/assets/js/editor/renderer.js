



import { S, DOM, CONST } from "./state.js";
import { getBPM, msToY, yToMs, snapMs, formatTime } from "./canvas.js";

export function buildGridBuffer() {
  var canvas = DOM.canvas;
  var w = canvas.width;
  var h = Math.min(S.virtualHeight, CONST.MAX_CANVAS_H);
  var lw = S.laneWidth;
  var off = 50;

  S.gridCanvas = document.createElement("canvas");
  S.gridCanvas.width = w;
  S.gridCanvas.height = h;
  var gc = S.gridCanvas.getContext("2d");

  gc.fillStyle = "#08080f";
  gc.fillRect(0, 0, w, h);

  var bpm = getBPM();
  var beatMs = 60000 / bpm;
  var totalMs = S.audioDuration * 1000;

  
  for (var ms = 0; ms <= totalMs; ms += beatMs) {
    var y = msToY(ms);
    if (y > h) break;
    var beatNum = Math.round(ms / beatMs);
    var isBar = beatNum % 4 === 0;

    gc.strokeStyle = isBar ? "rgba(255,255,255,0.12)" : "rgba(255,255,255,0.04)";
    gc.lineWidth = isBar ? 1.5 : 0.5;
    gc.beginPath();
    gc.moveTo(off, y);
    gc.lineTo(off + lw * CONST.LANE_COUNT, y);
    gc.stroke();

    if (isBar) {
      gc.fillStyle = "rgba(255,255,255,0.2)";
      gc.font = "9px 'JetBrains Mono', monospace";
      gc.textAlign = "right";
      gc.fillText(Math.round(ms / 1000) + "s", off - 4, y + 3);
    }
  }

  
  if (S.snapDiv > 0) {
    var snap = beatMs / S.snapDiv;
    gc.strokeStyle = "rgba(255,255,255,0.02)";
    gc.lineWidth = 0.5;
    for (var ms2 = 0; ms2 <= totalMs; ms2 += snap) {
      var y2 = msToY(ms2);
      if (y2 > h) break;
      if (Math.round((ms2 / beatMs) * S.snapDiv) % S.snapDiv !== 0) {
        gc.beginPath();
        gc.moveTo(off, y2);
        gc.lineTo(off + lw * CONST.LANE_COUNT, y2);
        gc.stroke();
      }
    }
  }

  
  for (var i = 0; i < CONST.LANE_COUNT; i++) {
    var x = off + i * lw;
    var alpha = i % 2 === 0 ? 0.03 : 0.01;
    gc.fillStyle = CONST.LANE_COLORS[i] + Math.round(alpha * 255).toString(16).padStart(2, "0");
    gc.fillRect(x, 0, lw, h);

    gc.fillStyle = CONST.LANE_COLORS[i] + "40";
    gc.fillRect(x, 0, lw, 28);

    gc.fillStyle = CONST.LANE_COLORS[i];
    gc.font = "bold 13px 'JetBrains Mono', monospace";
    gc.textAlign = "center";
    gc.fillText(CONST.LANE_KEYS[i], x + lw / 2, 19);

    gc.strokeStyle = "rgba(255,255,255,0.04)";
    gc.lineWidth = 1;
    gc.beginPath();
    gc.moveTo(x, 0);
    gc.lineTo(x, h);
    gc.stroke();
  }

  S.gridDirty = false;
}

export function draw() {
  var canvas = DOM.canvas, ctx = DOM.ctx, wrap = DOM.wrap, audio = DOM.audio;
  var w = canvas.width;
  var h = canvas.height;
  var lw = S.laneWidth;
  var offset = 50;
  var scrollTop = wrap ? wrap.scrollTop : 0;

  var gw = w;
  var gh = Math.min(S.virtualHeight, 16384);
  if (S.gridDirty || !S.gridCanvas || S.gridCanvas.width !== gw || S.gridCanvas.height !== gh) {
    buildGridBuffer();
  }

  ctx.clearRect(0, 0, w, h);
  ctx.drawImage(S.gridCanvas, 0, scrollTop, w, h, 0, 0, w, h);

  ctx.save();
  ctx.translate(0, -scrollTop);

  var viewTop = -99999;
  var viewBot = 99999;
  if (S.isPlaying) {
    viewTop = wrap.scrollTop - 100;
    viewBot = viewTop + wrap.clientHeight + 200;
  }

  
  for (var ni = 0; ni < S.notes.length; ni++) {
    var note = S.notes[ni];
    if (!note.e) continue;
    var cx = offset + note.l * lw + lw / 2;
    var yStart = msToY(note.t);
    var yEnd = msToY(note.e);
    if (yEnd > viewBot && yStart > viewBot) continue;
    if (yStart < viewTop && yEnd < viewTop) continue;

    var trailW = lw * 0.55;
    var isSelected = ni === S.selectedNoteIdx;
    var isGold = note.g;
    var color = isGold ? CONST.GOLD_COLOR : CONST.COLOR_HOLD;

    ctx.globalAlpha = isSelected ? 0.5 : 0.3;
    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.roundRect(cx - trailW / 2, yEnd, trailW, yStart - yEnd, 4);
    ctx.fill();

    ctx.globalAlpha = isSelected ? 0.25 : 0.15;
    ctx.shadowColor = color;
    ctx.shadowBlur = isSelected ? 14 : 8;
    ctx.beginPath();
    ctx.roundRect(cx - trailW / 2 - 2, yEnd - 2, trailW + 4, yStart - yEnd + 4, 6);
    ctx.fill();
    ctx.shadowBlur = 0;

    if (isSelected) {
      ctx.fillStyle = "#fff";
      ctx.beginPath();
      ctx.arc(cx, yEnd, 6, 0, Math.PI * 2);
      ctx.fill();
      ctx.fillStyle = color;
      ctx.beginPath();
      ctx.arc(cx, yEnd, 4, 0, Math.PI * 2);
      ctx.fill();
    }

    ctx.globalAlpha = 1;
  }

  
  for (var ni2 = 0; ni2 < S.notes.length; ni2++) {
    var note2 = S.notes[ni2];
    var y3 = msToY(note2.t);
    var x2 = offset + note2.l * lw;
    if (y3 < viewTop - 30 || y3 > viewBot) continue;

    var noteW = lw * 0.82;
    var noteH = 14;
    var noteX = x2 + (lw - noteW) / 2;
    var noteY = y3 - noteH / 2;
    var isSelected2 = ni2 === S.selectedNoteIdx;
    var isGold2 = note2.g;
    var noteColor = isGold2 ? CONST.GOLD_COLOR : note2.e ? CONST.COLOR_HOLD : CONST.COLOR_CLICK;

    if (isSelected2) {
      ctx.strokeStyle = "#fff";
      ctx.lineWidth = 3;
      ctx.shadowColor = "#fff";
      ctx.shadowBlur = 12;
      ctx.beginPath();
      ctx.roundRect(noteX - 4, noteY - 4, noteW + 8, noteH + 8, 6);
      ctx.stroke();
      ctx.shadowBlur = 0;
    }

    ctx.shadowColor = noteColor;
    ctx.shadowBlur = note2.e ? 14 : 10;
    ctx.fillStyle = noteColor;
    ctx.beginPath();
    ctx.roundRect(noteX, noteY, noteW, noteH, 5);
    ctx.fill();
    ctx.shadowBlur = 0;

    ctx.fillStyle = "rgba(255,255,255,0.22)";
    ctx.beginPath();
    ctx.roundRect(noteX + 2, noteY + 1, noteW - 4, noteH * 0.4, 3);
    ctx.fill();

    if (note2.e) {
      ctx.fillStyle = "rgba(255,255,255,0.6)";
      ctx.beginPath();
      ctx.moveTo(x2 + lw / 2, noteY - 4);
      ctx.lineTo(x2 + lw / 2 + 5, noteY + 2);
      ctx.lineTo(x2 + lw / 2, noteY + 8);
      ctx.lineTo(x2 + lw / 2 - 5, noteY + 2);
      ctx.closePath();
      ctx.fill();
    }

    if (isGold2) {
      ctx.fillStyle = "#fff";
      ctx.shadowColor = CONST.GOLD_COLOR;
      ctx.shadowBlur = 6;
      ctx.font = "bold 11px sans-serif";
      ctx.textAlign = "center";
      ctx.fillText("★", x2 + lw / 2, noteY - 7);
      ctx.shadowBlur = 0;
    }
  }

  
  if (S.isDragging && S.dragLane >= 0 && S.dragStartMs >= 0) {
    var cx2 = offset + S.dragLane * lw + lw / 2;
    var currentMs = snapMs(yToMs(S.lastDragPos.y));
    if (currentMs > S.dragStartMs + 50) {
      var y1 = msToY(S.dragStartMs);
      var y2d = msToY(currentMs);
      var trailW2 = lw * 0.55;
      ctx.globalAlpha = 0.4;
      ctx.fillStyle = CONST.LANE_COLORS[S.dragLane];
      ctx.beginPath();
      ctx.roundRect(cx2 - trailW2 / 2, y2d, trailW2, y1 - y2d, 4);
      ctx.fill();
      ctx.globalAlpha = 1;
    }
  }

  
  if (audio.currentTime > 0) {
    var cursorY = msToY(audio.currentTime * 1000);
    if (cursorY >= viewTop - 20 && cursorY <= viewBot) {
      ctx.strokeStyle = "#fbbf24";
      ctx.lineWidth = 2;
      ctx.beginPath();
      ctx.moveTo(offset, cursorY);
      ctx.lineTo(offset + lw * CONST.LANE_COUNT, cursorY);
      ctx.stroke();

      ctx.fillStyle = "#fbbf24";
      ctx.beginPath();
      ctx.moveTo(offset - 10, cursorY - 8);
      ctx.lineTo(offset - 2, cursorY);
      ctx.lineTo(offset - 10, cursorY + 8);
      ctx.closePath();
      ctx.fill();
      ctx.strokeStyle = "#000";
      ctx.lineWidth = 1;
      ctx.stroke();
    }
  }

  ctx.restore();

  
  var now = performance.now();
  if (!S.isPlaying || now - S.lastUIUpdate > 200) {
    S.lastUIUpdate = now;
    document.getElementById("noteCount").textContent = S.notes.length;
    document.getElementById("durationDisplay").textContent = formatTime(S.audioDuration);
    document.getElementById("positionDisplay").textContent = formatTime(audio.currentTime || 0);
    var pct = S.audioDuration > 0 ? (audio.currentTime / S.audioDuration) * 100 : 0;
    document.getElementById("timelineProgress").style.width = pct + "%";
    document.getElementById("timelineCursor").style.left = pct + "%";
  }
}

export function updateNoteInfo() {
  var el = document.getElementById("noteInfoPanel");
  if (!el) return;
  if (S.selectedNoteIdx < 0 || S.selectedNoteIdx >= S.notes.length) {
    el.innerHTML = '<p class="empty-text">Klik note untuk melihat info</p>';
    return;
  }
  var note = S.notes[S.selectedNoteIdx];
  var type = note.e ? (note.g ? "Hold ⭐ Gold" : "Hold") : note.g ? "Tap ⭐ Gold" : "Tap";
  var laneKeys = CONST.LANE_KEYS;
  var dur = note.e ? note.e - note.t + "ms" : "-";

  var html = "";
  html += '<div class="note-info-row"><span>Type:</span><span class="note-info-val">' + type + "</span></div>";
  html += '<div class="note-info-row"><span>Lane:</span><span class="note-info-val">' + laneKeys[note.l] + " (" + note.l + ")</span></div>";
  html += '<div class="note-info-row"><span>Start:</span><span class="note-info-val">' + note.t + "ms</span></div>";
  if (note.e) html += '<div class="note-info-row"><span>End:</span><span class="note-info-val">' + note.e + "ms</span></div>";
  if (note.e) html += '<div class="note-info-row"><span>Duration:</span><span class="note-info-val">' + dur + "</span></div>";
  html += '<div class="note-info-row"><span>Gold:</span><span class="note-info-val">' + (note.g ? "⭐ Yes" : "No") + "</span></div>";
  html += '<div class="note-actions">';
  html += '<button class="btn btn-sm" onclick="window.editorToggleGold()">' + (note.g ? "Remove Gold" : "Make Gold ⭐") + "</button>";
  html += '<button class="btn btn-sm" onclick="window.editorDeleteSelected()" style="color:var(--danger);">Delete</button>';
  if (note.e) html += '<button class="btn btn-sm" onclick="window.editorConvertToTap()">Convert to Tap</button>';
  if (!note.e) html += '<button class="btn btn-sm" onclick="window.editorConvertToHold()">Convert to Hold</button>';
  html += "</div>";
  el.innerHTML = html;
}