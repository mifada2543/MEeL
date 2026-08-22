/**
 * MEeL!Mania — Visual Beatmap Editor
 * Canvas-based editor for placing notes on a timeline grid
 */
(function () {
  "use strict";

  /* ═══════════════════════════════════════════════════════
     TOAST NOTIFICATION (fallback when Swal unavailable)
     ═══════════════════════════════════════════════════════ */
  function showToast(message, type) {
    type = type || "info";
    // Try SweetAlert2 first
    if (typeof Swal !== "undefined") {
      var bg = "#0e1118";
      Swal.fire({ title: type === "error" ? "Error" : type === "success" ? "Berhasil!" : "Info", text: message, icon: type === "error" ? "error" : type === "success" ? "success" : "info", confirmButtonColor: "#f43f7a", background: bg, color: "#fff", timer: type === "success" ? 3000 : undefined });
      return;
    }
    // Fallback: custom toast
    var existing = document.getElementById("editorToast");
    if (existing) existing.remove();
    var toast = document.createElement("div");
    toast.id = "editorToast";
    toast.style.cssText = "position:fixed;top:20px;right:20px;z-index:9999;padding:14px 24px;border-radius:10px;font-size:13px;font-weight:600;color:#fff;backdrop-filter:blur(12px);animation:toastIn .3s ease;max-width:360px;";
    var colors = { error: "rgba(239,68,68,0.9)", success: "rgba(34,197,94,0.9)", info: "rgba(99,102,241,0.9)", warning: "rgba(251,191,36,0.9)" };
    toast.style.background = colors[type] || colors.info;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(function () { toast.style.opacity = "0"; toast.style.transition = "opacity .3s"; setTimeout(function () { toast.remove(); }, 300); }, type === "success" ? 3000 : 5000);
  }

  // Add toast animation
  if (!document.getElementById("toastStyle")) {
    var st = document.createElement("style");
    st.id = "toastStyle";
    st.textContent = "@keyframes toastIn{from{opacity:0;transform:translateY(-12px)}to{opacity:1;transform:translateY(0)}}";
    document.head.appendChild(st);
  }

  /* ═══════════════════════════════════════════════════════
     DOM
     ═══════════════════════════════════════════════════════ */
  var canvas = document.getElementById("editorCanvas");
  if (!canvas) return;
  var ctx = canvas.getContext("2d");
  var wrap = document.getElementById("canvasWrap");
  var audio = document.getElementById("audioPlayer");
  var audioInput = document.getElementById("f-audio");
  var coverInput = document.getElementById("f-cover");
  var coverPreview = document.getElementById("cover-preview");

  /* ═══════════════════════════════════════════════════════
     CONSTANTS
     ═══════════════════════════════════════════════════════ */
  var LANE_COUNT = 4;
  var LANE_COLORS = ["#f43f7a", "#a855f7", "#6366f1", "#818cf8"];
  var LANE_KEYS = ["A", "S", "K", "L"];
  var ROW_HEIGHT = 30; // pixels per beat row — more spacious
  var LANE_WIDTH_MIN = 80;
  var LANE_WIDTH_MAX = 140;

  /* ═══════════════════════════════════════════════════════
     STATE
     ═══════════════════════════════════════════════════════ */
  var notes = []; // [{t, l}, {t, e, l}, {t, l, g}, {t, e, l, g}]
  var undoStack = [];
  var zoom = 3;
  var snapDiv = 8; // snap to 1/8 beat
  var isPlaying = false;
  var audioDuration = 0;
  var animFrame = null;
  var isDragging = false;
  var dragLane = -1;
  var dragStartMs = -1;
  var dragNoteIdx = -1;
  var selectedNoteIdx = -1;
  var GOLD_COLOR = "#fbbf24";
  var laneWidth = 100; // dynamic, recalculated on resize
  var hasAudio = false; // track if audio is loaded

  /* ═══════════════════════════════════════════════════════
     AUDIO
     ═══════════════════════════════════════════════════════ */
  audioInput.addEventListener("change", function () {
    if (this.files && this.files[0]) {
      var file = this.files[0];
      var url = URL.createObjectURL(file);
      audio.src = url;
      audio.load();
      audio.addEventListener("loadedmetadata", function () {
        audioDuration = audio.duration;
        hasAudio = true;
        document.getElementById("durationDisplay").textContent = formatTime(audioDuration);
        document.getElementById("audio-info").textContent =
          file.name + " · " + formatTime(audioDuration) + " · " + (file.size / 1024 / 1024).toFixed(1) + "MB";
        // Hide the audio prompt overlay
        var prompt = document.getElementById("audioPromptOverlay");
        if (prompt) prompt.style.display = "none";
        resizeCanvas();
        draw();
      }, { once: true });
    }
  });

  coverInput.addEventListener("change", function () {
    if (this.files && this.files[0]) {
      var reader = new FileReader();
      reader.onload = function (e) {
        coverPreview.src = e.target.result;
        coverPreview.classList.add("visible");
      };
      reader.readAsDataURL(this.files[0]);
    }
  });

  /* ═══════════════════════════════════════════════════════
     CANVAS — RESPONSIVE LANE WIDTH
     ═══════════════════════════════════════════════════════ */
  function resizeCanvas() {
    // Fill available width
    var availW = wrap.clientWidth - 20;
    laneWidth = Math.max(LANE_WIDTH_MIN, Math.min(LANE_WIDTH_MAX, (availW - 60) / LANE_COUNT));
    var w = Math.max(LANE_COUNT * laneWidth + 60, 380);
    // Full height based on song duration — NO cap, scrollable
    var h = 600;
    if (audioDuration > 0) {
      h = Math.max(800, audioDuration * ROW_HEIGHT * zoom * getBPM() / 60 + 100);
    }
    canvas.width = w;
    canvas.height = h;
    canvas.style.width = w + "px";
    // Height is set directly — scrollable via wrapper overflow
    canvas.style.height = h + "px";
  }

  function getBPM() {
    return parseInt(document.getElementById("f-bpm").value) || 120;
  }

  function msToY(ms) {
    var bpm = getBPM();
    var beatMs = 60000 / bpm;
    var pxPerMs = ROW_HEIGHT * zoom / beatMs;
    return ms * pxPerMs;
  }

  function yToMs(y) {
    var bpm = getBPM();
    var beatMs = 60000 / bpm;
    var pxPerMs = ROW_HEIGHT * zoom / beatMs;
    return y / pxPerMs;
  }

  function snapMs(ms) {
    if (snapDiv <= 0) return ms;
    var bpm = getBPM();
    var beatMs = 60000 / bpm;
    var snap = beatMs / snapDiv;
    return Math.round(ms / snap) * snap;
  }

  /* ═══════════════════════════════════════════════════════
     DRAW
     ═══════════════════════════════════════════════════════ */
  function draw() {
    var w = canvas.width;
    var h = canvas.height;
    var lw = laneWidth;
    var offset = 50; // left padding for time labels

    ctx.clearRect(0, 0, w, h);
    ctx.fillStyle = "#08080f";
    ctx.fillRect(0, 0, w, h);

    var bpm = getBPM();
    var beatMs = 60000 / bpm;
    var totalMs = audioDuration * 1000;

    // Draw grid
    for (var ms = 0; ms <= totalMs; ms += beatMs) {
      var y = msToY(ms);
      if (y > h) break;
      var beatNum = Math.round(ms / beatMs);
      var isBar = beatNum % 4 === 0;

      ctx.strokeStyle = isBar ? "rgba(255,255,255,0.12)" : "rgba(255,255,255,0.04)";
      ctx.lineWidth = isBar ? 1.5 : 0.5;
      ctx.beginPath();
      ctx.moveTo(offset, y);
      ctx.lineTo(offset + lw * LANE_COUNT, y);
      ctx.stroke();

      if (isBar) {
        ctx.fillStyle = "rgba(255,255,255,0.2)";
        ctx.font = "9px 'JetBrains Mono', monospace";
        ctx.textAlign = "right";
        ctx.fillText(Math.round(ms / 1000) + "s", offset - 4, y + 3);
      }
    }

    // Draw sub-beat grid
    if (snapDiv > 0) {
      var snap = beatMs / snapDiv;
      ctx.strokeStyle = "rgba(255,255,255,0.02)";
      ctx.lineWidth = 0.5;
      for (var ms2 = 0; ms2 <= totalMs; ms2 += snap) {
        var y2 = msToY(ms2);
        if (y2 > h) break;
        if (Math.round(ms2 / beatMs * snapDiv) % snapDiv !== 0) {
          ctx.beginPath();
          ctx.moveTo(offset, y2);
          ctx.lineTo(offset + lw * LANE_COUNT, y2);
          ctx.stroke();
        }
      }
    }

    // Draw lane backgrounds
    for (var i = 0; i < LANE_COUNT; i++) {
      var x = offset + i * lw;
      var alpha = (i % 2 === 0) ? 0.03 : 0.01;
      ctx.fillStyle = LANE_COLORS[i] + Math.round(alpha * 255).toString(16).padStart(2, "0");
      ctx.fillRect(x, 0, lw, h);

      // Lane header
      ctx.fillStyle = LANE_COLORS[i] + "40";
      ctx.fillRect(x, 0, lw, 28);

      // Lane label
      ctx.fillStyle = LANE_COLORS[i];
      ctx.font = "bold 13px 'JetBrains Mono', monospace";
      ctx.textAlign = "center";
      ctx.fillText(LANE_KEYS[i], x + lw / 2, 19);

      // Lane separator
      ctx.strokeStyle = "rgba(255,255,255,0.04)";
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(x, 0);
      ctx.lineTo(x, h);
      ctx.stroke();
    }

    // Hold note trails
    for (var ni = 0; ni < notes.length; ni++) {
      var note = notes[ni];
      if (!note.e) continue;
      var cx = offset + note.l * lw + lw / 2;
      var yStart = msToY(note.t);
      var yEnd = msToY(note.e);
      if (yStart > h && yEnd > h) continue;
      if (yStart < 0 && yEnd < 0) continue;

      var trailW = lw * 0.55;
      var isSelected = ni === selectedNoteIdx;
      var isGold = note.g;
      var color = isGold ? GOLD_COLOR : LANE_COLORS[note.l];

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

      // Selected: draw drag handles
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

    // Tap & hold head notes
    for (var ni2 = 0; ni2 < notes.length; ni2++) {
      var note2 = notes[ni2];
      var y3 = msToY(note2.t);
      var x2 = offset + note2.l * lw;
      if (y3 < 0 || y3 > h) continue;

      var noteW = lw * 0.82;
      var noteH = 14;
      var noteX = x2 + (lw - noteW) / 2;
      var noteY = y3 - noteH / 2;
      var isSelected2 = ni2 === selectedNoteIdx;
      var isGold2 = note2.g;
      var noteColor = isGold2 ? GOLD_COLOR : LANE_COLORS[note2.l];

      // Selection highlight — BOLD white border
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

      // Glossy highlight
      ctx.fillStyle = "rgba(255,255,255,0.22)";
      ctx.beginPath();
      ctx.roundRect(noteX + 2, noteY + 1, noteW - 4, noteH * 0.4, 3);
      ctx.fill();

      // Hold indicator triangle
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

      // Gold star indicator — BIGGER
      if (isGold2) {
        ctx.fillStyle = "#fff";
        ctx.shadowColor = GOLD_COLOR;
        ctx.shadowBlur = 6;
        ctx.font = "bold 11px sans-serif";
        ctx.textAlign = "center";
        ctx.fillText("★", x2 + lw / 2, noteY - 7);
        ctx.shadowBlur = 0;
      }
    }

    // Drag preview
    if (isDragging && dragLane >= 0 && dragStartMs >= 0) {
      var cx2 = offset + dragLane * lw + lw / 2;
      var currentMs = snapMs(yToMs(lastDragPos.y));
      if (currentMs > dragStartMs + 50) {
        var y1 = msToY(dragStartMs);
        var y2d = msToY(currentMs);
        var trailW2 = lw * 0.55;
        ctx.globalAlpha = 0.4;
        ctx.fillStyle = LANE_COLORS[dragLane];
        ctx.beginPath();
        ctx.roundRect(cx2 - trailW2 / 2, y2d, trailW2, y1 - y2d, 4);
        ctx.fill();
        ctx.globalAlpha = 1;
      }
    }

    // Draw playback cursor
    if (audio.currentTime > 0) {
      var cursorY = msToY(audio.currentTime * 1000);
      ctx.strokeStyle = "#fbbf24";
      ctx.lineWidth = 2;
      ctx.beginPath();
      ctx.moveTo(offset, cursorY);
      ctx.lineTo(offset + lw * LANE_COUNT, cursorY);
      ctx.stroke();

      ctx.fillStyle = "#fbbf24";
      ctx.beginPath();
      ctx.moveTo(offset - 6, cursorY - 4);
      ctx.lineTo(offset, cursorY);
      ctx.lineTo(offset - 6, cursorY + 4);
      ctx.fill();
    }

    // Update UI
    document.getElementById("noteCount").textContent = notes.length;
    document.getElementById("durationDisplay").textContent = formatTime(audioDuration);
    document.getElementById("positionDisplay").textContent = formatTime(audio.currentTime || 0);

    var pct = audioDuration > 0 ? (audio.currentTime / audioDuration * 100) : 0;
    document.getElementById("timelineProgress").style.width = pct + "%";
    document.getElementById("timelineCursor").style.left = pct + "%";
  }

  /* ═══════════════════════════════════════════════════════
     INPUT: CLICK TO PLACE/REMOVE NOTES
     ═══════════════════════════════════════════════════════ */
  function getCanvasPos(e) {
    var rect = canvas.getBoundingClientRect();
    return {
      x: e.clientX - rect.left + canvas.parentElement.scrollLeft,
      y: e.clientY - rect.top + canvas.parentElement.scrollTop,
    };
  }

  function getLaneAndTime(pos) {
    var offset = 50; // left padding for time labels
    var lane = Math.floor((pos.x - offset) / laneWidth);
    if (lane < 0 || lane >= LANE_COUNT) return null;
    var ms = snapMs(yToMs(pos.y));
    return { lane: lane, ms: Math.max(0, ms) };
  }

  function findNoteAt(lane, ms, tolerance) {
    tolerance = tolerance || 60;
    for (var i = 0; i < notes.length; i++) {
      if (notes[i].l === lane && Math.abs(notes[i].t - ms) < tolerance) return i;
    }
    return -1;
  }

  var lastDragPos = { x: 0, y: 0 };

  canvas.addEventListener("mousedown", function (e) {
    var pos = getCanvasPos(e);
    var info = getLaneAndTime(pos);
    if (!info) return;

    lastDragPos = pos;

    // Right click = delete
    if (e.button === 2) {
      e.preventDefault();
      var idx = findNoteAt(info.lane, info.ms);
      if (idx >= 0) {
        undoStack.push(JSON.parse(JSON.stringify(notes)));
        notes.splice(idx, 1);
        if (selectedNoteIdx === idx) selectedNoteIdx = -1;
        else if (selectedNoteIdx > idx) selectedNoteIdx--;
        draw();
        updateNoteInfo();
      }
      return;
    }

    // Left click: check if clicking existing note head
    var idx2 = findNoteAt(info.lane, info.ms);
    if (idx2 >= 0) {
      // Select the note
      selectedNoteIdx = idx2;

      // If it's a hold note, allow extending by dragging its tail
      if (notes[idx2].e) {
        var noteEndMs = notes[idx2].e;
        var noteStartMs = notes[idx2].t;
        var noteRange = noteEndMs - noteStartMs;
        var clickDistFromEnd = Math.abs(info.ms - noteEndMs);
        if (clickDistFromEnd < noteRange * 0.3 || clickDistFromEnd < 200) {
          isDragging = true;
          dragLane = info.lane;
          dragNoteIdx = idx2;
          dragStartMs = notes[idx2].t;
          draw();
          updateNoteInfo();
          return;
        }
      }
      draw();
      updateNoteInfo();
      return;
    }

    // Click on empty: deselect
    selectedNoteIdx = -1;
    updateNoteInfo();

    // Start drag for new note
    isDragging = true;
    dragLane = info.lane;
    dragStartMs = info.ms;
    dragNoteIdx = -1;
  });

  canvas.addEventListener("mousemove", function (e) {
    if (!isDragging || dragLane < 0) return;
    lastDragPos = getCanvasPos(e);
    draw();
  });

  canvas.addEventListener("mouseup", function (e) {
    if (!isDragging || dragLane < 0) {
      isDragging = false;
      dragLane = -1;
      return;
    }

    var pos = getCanvasPos(e);
    var info = getLaneAndTime(pos);
    if (!info || info.lane !== dragLane) {
      isDragging = false;
      dragLane = -1;
      return;
    }

    undoStack.push(JSON.parse(JSON.stringify(notes)));

    var endMs = info.ms;
    var minHold = 100;

    if (dragNoteIdx >= 0) {
      if (endMs > dragStartMs + minHold) {
        notes[dragNoteIdx].e = Math.round(endMs);
      } else {
        delete notes[dragNoteIdx].e;
      }
    } else {
      if (endMs > dragStartMs + minHold) {
        notes.push({ t: Math.round(dragStartMs), e: Math.round(endMs), l: dragLane });
      } else {
        notes.push({ t: Math.round(dragStartMs), l: dragLane });
      }
    }

    notes.sort(function (a, b) { return a.t - b.t; });
    isDragging = false;
    dragLane = -1;
    dragNoteIdx = -1;
    draw();
  });

  canvas.addEventListener("mouseleave", function () {
    isDragging = false;
    dragLane = -1;
    dragNoteIdx = -1;
  });

  canvas.addEventListener("contextmenu", function (e) { e.preventDefault(); });

  /* ═══════════════════════════════════════════════════════
     TIMELINE CLICK
     ═══════════════════════════════════════════════════════ */
  document.getElementById("timelineBar").addEventListener("click", function (e) {
    var rect = e.currentTarget.getBoundingClientRect();
    var pct = (e.clientX - rect.left) / rect.width;
    audio.currentTime = pct * audioDuration;
    draw();
  });

  /* ═══════════════════════════════════════════════════════
     KEYBOARD SHORTCUTS
     ═══════════════════════════════════════════════════════ */
  document.addEventListener("keydown", function (e) {
    var isInput = e.target.tagName === "INPUT" || e.target.tagName === "SELECT" || e.target.tagName === "TEXTAREA";

    // Ctrl+Z = undo
    if ((e.ctrlKey || e.metaKey) && e.key === "z") {
      e.preventDefault();
      if (undoStack.length > 0) {
        notes = undoStack.pop();
        selectedNoteIdx = -1;
        draw();
        updateNoteInfo();
      }
      return;
    }

    // G/g = toggle gold on selected note — case-insensitive using e.code
    if (e.code === "KeyG" && !isInput && selectedNoteIdx >= 0) {
      e.preventDefault();
      undoStack.push(JSON.parse(JSON.stringify(notes)));
      notes[selectedNoteIdx].g = !notes[selectedNoteIdx].g;
      draw();
      updateNoteInfo();
      showToast(notes[selectedNoteIdx].g ? "⭐ Gold note!" : "Gold removed", "success");
      return;
    }

    // G without selection — show hint
    if (e.code === "KeyG" && !isInput && selectedNoteIdx < 0) {
      showToast("Klik note dulu, lalu tekan G untuk toggle gold", "info");
      return;
    }

    // Delete/Backspace = delete selected note
    if ((e.key === "Delete" || e.key === "Backspace") && !isInput && selectedNoteIdx >= 0) {
      e.preventDefault();
      undoStack.push(JSON.parse(JSON.stringify(notes)));
      notes.splice(selectedNoteIdx, 1);
      selectedNoteIdx = -1;
      draw();
      updateNoteInfo();
      return;
    }

    // Escape = deselect
    if (e.key === "Escape") {
      selectedNoteIdx = -1;
      draw();
      updateNoteInfo();
      return;
    }

    // Space = play/pause
    if (e.code === "Space" && !isInput) {
      e.preventDefault();
      togglePlayback();
    }

    // Arrow keys = seek
    if (e.key === "ArrowUp" && !isInput) {
      e.preventDefault();
      audio.currentTime = Math.min(audioDuration, audio.currentTime + 1);
      draw();
    }
    if (e.key === "ArrowDown" && !isInput) {
      e.preventDefault();
      audio.currentTime = Math.max(0, audio.currentTime - 1);
      draw();
    }
  });

  /* ═══════════════════════════════════════════════════════
     NOTE INFO PANEL
     ═══════════════════════════════════════════════════════ */
  function updateNoteInfo() {
    var el = document.getElementById("noteInfoPanel");
    if (!el) return;
    if (selectedNoteIdx < 0 || selectedNoteIdx >= notes.length) {
      el.innerHTML = '<p class="empty-text">Klik note untuk melihat info</p>';
      return;
    }
    var note = notes[selectedNoteIdx];
    var type = note.e ? (note.g ? "Hold ⭐ Gold" : "Hold") : (note.g ? "Tap ⭐ Gold" : "Tap");
    var laneKeys = ["A", "S", "K", "L"];
    var dur = note.e ? ((note.e - note.t) + "ms") : "-";

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

  window.editorToggleGold = function () {
    if (selectedNoteIdx < 0) { showToast("Pilih note dulu!", "warning"); return; }
    undoStack.push(JSON.parse(JSON.stringify(notes)));
    notes[selectedNoteIdx].g = !notes[selectedNoteIdx].g;
    draw();
    updateNoteInfo();
    showToast(notes[selectedNoteIdx].g ? "⭐ Gold note!" : "Gold removed", "success");
  };

  window.editorDeleteSelected = function () {
    if (selectedNoteIdx < 0) return;
    undoStack.push(JSON.parse(JSON.stringify(notes)));
    notes.splice(selectedNoteIdx, 1);
    selectedNoteIdx = -1;
    draw();
    updateNoteInfo();
  };

  window.editorConvertToHold = function () {
    if (selectedNoteIdx < 0) return;
    undoStack.push(JSON.parse(JSON.stringify(notes)));
    var note = notes[selectedNoteIdx];
    if (!note.e) {
      note.e = note.t + 1000;
      draw();
      updateNoteInfo();
    }
  };

  window.editorConvertToTap = function () {
    if (selectedNoteIdx < 0) return;
    undoStack.push(JSON.parse(JSON.stringify(notes)));
    var note = notes[selectedNoteIdx];
    if (note.e) {
      delete note.e;
      draw();
      updateNoteInfo();
    }
  };

  /* ═══════════════════════════════════════════════════════
     PLAYBACK
     ═══════════════════════════════════════════════════════ */
  window.togglePlayback = function () {
    if (!audio.src) { showToast("Pilih file audio dulu!", "warning"); return; }
    if (isPlaying) {
      audio.pause();
      isPlaying = false;
      document.getElementById("btnPlayPause").textContent = "▶ Play";
      cancelAnimationFrame(animFrame);
    } else {
      audio.play();
      isPlaying = true;
      document.getElementById("btnPlayPause").textContent = "⏸ Pause";
      animatePlayback();
    }
  };

  window.stopPlayback = function () {
    audio.pause();
    audio.currentTime = 0;
    isPlaying = false;
    document.getElementById("btnPlayPause").textContent = "▶ Play";
    cancelAnimationFrame(animFrame);
    draw();
  };

  audio.addEventListener("ended", function () {
    isPlaying = false;
    document.getElementById("btnPlayPause").textContent = "▶ Play";
    cancelAnimationFrame(animFrame);
  });

  function animatePlayback() {
    if (!isPlaying) return;
    draw();
    animFrame = requestAnimationFrame(animatePlayback);
  }

  /* ═══════════════════════════════════════════════════════
     ZOOM & SNAP
     ═══════════════════════════════════════════════════════ */
  window.setZoom = function (val) {
    zoom = parseInt(val);
    resizeCanvas();
    draw();
  };

  window.setSnap = function (val) {
    snapDiv = parseInt(val);
    draw();
  };

  window.clearNotes = function () {
    if (notes.length === 0) return;
    if (!confirm("Hapus semua notes?")) return;
    undoStack.push(JSON.parse(JSON.stringify(notes)));
    notes = [];
    selectedNoteIdx = -1;
    draw();
    updateNoteInfo();
  };

  /* ═══════════════════════════════════════════════════════
     UPLOAD
     ═══════════════════════════════════════════════════════ */
  window.uploadBeatmap = function () {
    var form = document.getElementById("beatmapForm");
    var formData = new FormData(form);

    // Validate
    var title = formData.get("title");
    if (!title || title.trim() === "") {
      showToast("Judul wajib diisi!", "error");
      return;
    }
    if (!formData.get("audio") || formData.get("audio").size === 0) {
      showToast("File audio wajib diupload!", "error");
      return;
    }
    if (notes.length < 10) {
      showToast("Minimal 10 notes dalam beatmap! (saat ini: " + notes.length + ")", "error");
      return;
    }

    // Sort notes
    notes.sort(function (a, b) { return a.t - b.t; });
    formData.set("beatmap_json", JSON.stringify({ notes: notes }));

    // Show overlay
    var overlay = document.getElementById("uploadOverlay");
    overlay.classList.remove("hidden");
    document.getElementById("uploadStatus").textContent = "Mengirim ke server...";

    var xhr = new XMLHttpRequest();
    xhr.open("POST", "../api/upload", true);

    xhr.upload.onprogress = function (e) {
      if (e.lengthComputable) {
        var pct = Math.round(e.loaded / e.total * 100);
        document.getElementById("uploadProgress").style.width = pct + "%";
        document.getElementById("uploadStatus").textContent = "Mengirim... " + pct + "%";
      }
    };

    xhr.onload = function () {
      overlay.classList.add("hidden");
      try {
        var res = JSON.parse(xhr.responseText);
        if (res.success) {
          showToast("Beatmap berhasil diupload! 🎉", "success");
          setTimeout(function () { window.location.reload(); }, 1500);
        } else {
          showToast(res.error || "Upload gagal", "error");
        }
      } catch (ex) {
        showToast("Response tidak valid dari server (HTTP " + xhr.status + ")", "error");
      }
    };

    xhr.onerror = function () {
      overlay.classList.add("hidden");
      showToast("Koneksi gagal! Periksa jaringan.", "error");
    };

    xhr.send(formData);
  };

  /* ═══════════════════════════════════════════════════════
     DELETE SONG
     ═══════════════════════════════════════════════════════ */
  window.deleteSong = function (id) {
    if (!confirm("Hapus beatmap ini? Tindakan ini tidak dapat dibatalkan.")) return;

    var fd = new FormData();
    fd.append("song_id", id);
    fd.append("csrf_token", CSRF_TOKEN);

    fetch("../api/delete", { method: "POST", body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) {
          showToast("Beatmap terhapus!", "success");
          setTimeout(function () { window.location.reload(); }, 1000);
        } else {
          showToast(res.error || "Gagal menghapus", "error");
        }
      })
      .catch(function () {
        showToast("Gagal menghapus beatmap", "error");
      });
  };

  /* ═══════════════════════════════════════════════════════
     HELPERS
     ═══════════════════════════════════════════════════════ */
  function formatTime(sec) {
    var m = Math.floor(sec / 60);
    var s = Math.floor(sec % 60);
    return m + ":" + String(s).padStart(2, "0");
  }

  /* ═══════════════════════════════════════════════════════
     SCROLL SYNC
     ═══════════════════════════════════════════════════════ */
  var canvasScroll = canvas.parentElement;
  canvasScroll.addEventListener("scroll", function () {
    if (isPlaying) return;
    draw();
  });

  /* ═══════════════════════════════════════════════════════
     INIT
     ═══════════════════════════════════════════════════════ */
  function init() {
    resizeCanvas();
    draw();
    window.addEventListener("resize", function () { resizeCanvas(); draw(); });
    // If no audio loaded yet, show the prompt overlay
    if (!hasAudio) {
      var prompt = document.getElementById("audioPromptOverlay");
      if (prompt) prompt.style.display = "flex";
    }
  }

  init();
})();
