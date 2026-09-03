/**
 * MEeL!Mania — Visual Beatmap Editor
 * Canvas-based editor for placing notes on a timeline grid
 */
(function () {
  "use strict";

  /* TOAST NOTIFICATION (fallback when Swal unavailable) */
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

  /* DOM */
  var canvas = document.getElementById("editorCanvas");
  if (!canvas) return;
  var ctx = canvas.getContext("2d");
  var wrap = document.getElementById("canvasWrap");
  var audio = document.getElementById("audioPlayer");
  var audioInput = document.getElementById("f-audio");
  var coverInput = document.getElementById("f-cover");
  var coverPreview = document.getElementById("cover-preview");

  /* CONSTANTS */
  var LANE_COUNT = 4;
  var COLOR_CLICK = "#3b82f6";    // blue for tap/click notes
  var COLOR_HOLD = "#22c55e";     // green for hold notes
  var GOLD_COLOR_EDITOR = "#fbbf24"; // gold for bonus notes
  var LANE_COLORS = [COLOR_CLICK, COLOR_CLICK, COLOR_CLICK, COLOR_CLICK];
  var LANE_KEYS = ["A", "S", "K", "L"];
  var ROW_HEIGHT = 30; // pixels per beat row — more spacious
  var LANE_WIDTH_MIN = 80;
  var LANE_WIDTH_MAX = 140;

  /* STATE */
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
  var GOLD_COLOR = GOLD_COLOR_EDITOR;
  var laneWidth = 100; // dynamic, recalculated on resize
  var hasAudio = false; // track if audio is loaded
  var gridCanvas = null; // offscreen grid buffer
  var gridDirty = true; // needs redraw
  var lastUIUpdate = 0; // throttle DOM updates during playback
  var isDraggingCursor = false; // dragging playback cursor from left handle
  var isMovingNote = false; // dragging an existing note to new position
  var moveNoteIdx = -1;
  var moveNoteOrigT = 0;
  var moveNoteOrigL = -1;
  var moveNoteOrigE = null;

  /* LOCALSTORAGE SAVE / LOAD */
  function getStorageKey() {
    // Use song title + artist as key, or fall back to URL param
    var titleInput = document.getElementById("songTitle");
    var artistInput = document.getElementById("songArtist");
    var key = "editor_";
    if (titleInput && titleInput.value) {
      key += titleInput.value.replace(/[^a-z0-9]/gi, "_").toLowerCase();
      if (artistInput && artistInput.value) {
        key += "_" + artistInput.value.replace(/[^a-z0-9]/gi, "_").toLowerCase();
      }
    } else {
      key += window.location.search.replace(/[^a-z0-9]/gi, "_");
    }
    return key;
  }

  function saveNotesToStorage() {
    try {
      var data = {
        notes: notes,
        bpm: parseInt(document.getElementById("bpmInput").value) || 120,
        title: document.getElementById("songTitle").value || "",
        artist: document.getElementById("songArtist").value || "",
        difficulty: document.getElementById("difficultySelect").value || "normal",
        savedAt: Date.now()
      };
      localStorage.setItem(getStorageKey(), JSON.stringify(data));
    } catch (e) { /* quota exceeded or other error */ }
  }

  function loadNotesFromStorage() {
    try {
      var raw = localStorage.getItem(getStorageKey());
      if (!raw) return false;
      var data = JSON.parse(raw);
      if (data && Array.isArray(data.notes)) {
        notes = data.notes;
        if (data.bpm) {
          var bpmInput = document.getElementById("bpmInput");
          if (bpmInput) bpmInput.value = data.bpm;
        }
        if (data.title) {
          var titleInput = document.getElementById("songTitle");
          if (titleInput) titleInput.value = data.title;
        }
        if (data.artist) {
          var artistInput = document.getElementById("songArtist");
          if (artistInput) artistInput.value = data.artist;
        }
        if (data.difficulty) {
          var diffSelect = document.getElementById("difficultySelect");
          if (diffSelect) diffSelect.value = data.difficulty;
        }
        notes.sort(function (a, b) { return a.t - b.t; });
        gridDirty = true;
        updateNoteInfo();
        return true;
      }
    } catch (e) {}
    return false;
  }

  /* AUDIO */
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

  /* CANVAS — RESPONSIVE LANE WIDTH */
  var virtualHeight = 600;
  var MAX_CANVAS_H = 16384;
  function resizeCanvas() {
    var availW = wrap.clientWidth - 20;
    laneWidth = Math.max(LANE_WIDTH_MIN, Math.min(LANE_WIDTH_MAX, (availW - 60) / LANE_COUNT));
    var w = Math.max(LANE_COUNT * laneWidth + 60, 380);
    virtualHeight = 600;
    if (audioDuration > 0) {
      virtualHeight = Math.max(800, audioDuration * ROW_HEIGHT * zoom * getBPM() / 60 + 100);
    }
    // Canvas height capped at browser limit
    var h = Math.min(virtualHeight, MAX_CANVAS_H);
    canvas.width = w;
    canvas.height = h;
    canvas.style.width = w + "px";
    canvas.style.height = h + "px";
    // Add a spacer div so the wrapper can scroll to full virtual height
    var spacer = document.getElementById("canvasSpacer");
    if (!spacer) {
      spacer = document.createElement("div");
      spacer.id = "canvasSpacer";
      spacer.style.width = "1px";
      wrap.appendChild(spacer);
    }
    // Spacer only fills the gap beyond the capped canvas
    spacer.style.height = (virtualHeight - h) + "px";
    gridDirty = true;
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

  /* OFFSCREEN GRID BUFFER */
  function buildGridBuffer() {
    var w = canvas.width;
    var h = Math.min(virtualHeight, MAX_CANVAS_H);
    var lw = laneWidth;
    var off = 50;

    gridCanvas = document.createElement("canvas");
    gridCanvas.width = w;
    gridCanvas.height = h;
    var gc = gridCanvas.getContext("2d");

    gc.fillStyle = "#08080f";
    gc.fillRect(0, 0, w, h);

    var bpm = getBPM();
    var beatMs = 60000 / bpm;
    var totalMs = audioDuration * 1000;

    // Bar grid lines
    for (var ms = 0; ms <= totalMs; ms += beatMs) {
      var y = msToY(ms);
      if (y > h) break;
      var beatNum = Math.round(ms / beatMs);
      var isBar = beatNum % 4 === 0;

      gc.strokeStyle = isBar ? "rgba(255,255,255,0.12)" : "rgba(255,255,255,0.04)";
      gc.lineWidth = isBar ? 1.5 : 0.5;
      gc.beginPath();
      gc.moveTo(off, y);
      gc.lineTo(off + lw * LANE_COUNT, y);
      gc.stroke();

      if (isBar) {
        gc.fillStyle = "rgba(255,255,255,0.2)";
        gc.font = "9px 'JetBrains Mono', monospace";
        gc.textAlign = "right";
        gc.fillText(Math.round(ms / 1000) + "s", off - 4, y + 3);
      }
    }

    // Sub-beat grid
    if (snapDiv > 0) {
      var snap = beatMs / snapDiv;
      gc.strokeStyle = "rgba(255,255,255,0.02)";
      gc.lineWidth = 0.5;
      for (var ms2 = 0; ms2 <= totalMs; ms2 += snap) {
        var y2 = msToY(ms2);
        if (y2 > h) break;
        if (Math.round(ms2 / beatMs * snapDiv) % snapDiv !== 0) {
          gc.beginPath();
          gc.moveTo(off, y2);
          gc.lineTo(off + lw * LANE_COUNT, y2);
          gc.stroke();
        }
      }
    }

    // Lane backgrounds
    for (var i = 0; i < LANE_COUNT; i++) {
      var x = off + i * lw;
      var alpha = (i % 2 === 0) ? 0.03 : 0.01;
      gc.fillStyle = LANE_COLORS[i] + Math.round(alpha * 255).toString(16).padStart(2, "0");
      gc.fillRect(x, 0, lw, h);

      gc.fillStyle = LANE_COLORS[i] + "40";
      gc.fillRect(x, 0, lw, 28);

      gc.fillStyle = LANE_COLORS[i];
      gc.font = "bold 13px 'JetBrains Mono', monospace";
      gc.textAlign = "center";
      gc.fillText(LANE_KEYS[i], x + lw / 2, 19);

      gc.strokeStyle = "rgba(255,255,255,0.04)";
      gc.lineWidth = 1;
      gc.beginPath();
      gc.moveTo(x, 0);
      gc.lineTo(x, h);
      gc.stroke();
    }

    gridDirty = false;
  }

  /* DRAW (optimized: grid from buffer, viewport culling) */
  function draw() {
    var w = canvas.width;
    var h = canvas.height;
    var lw = laneWidth;
    var offset = 50;
    var scrollTop = wrap ? wrap.scrollTop : 0;

    // Rebuild grid buffer if needed (use virtual content size)
    var gw = w;
    var gh = Math.min(virtualHeight, 16384); // cap for grid buffer
    if (gridDirty || !gridCanvas || gridCanvas.width !== gw || gridCanvas.height !== gh) {
      buildGridBuffer();
    }

    // Clear canvas and draw grid buffer offset by scroll
    ctx.clearRect(0, 0, w, h);
    ctx.drawImage(gridCanvas, 0, scrollTop, w, h, 0, 0, w, h);

    // Translate so notes are drawn relative to scroll position
    ctx.save();
    ctx.translate(0, -scrollTop);

    // Viewport bounds for culling during playback only
    var viewTop = -99999;
    var viewBot = 99999;
    if (isPlaying) {
      var scrollEl = wrap;
      viewTop = scrollEl.scrollTop - 100;
      viewBot = viewTop + scrollEl.clientHeight + 200;
    }

    // Hold note trails (viewport culled)
    for (var ni = 0; ni < notes.length; ni++) {
      var note = notes[ni];
      if (!note.e) continue;
      var cx = offset + note.l * lw + lw / 2;
      var yStart = msToY(note.t);
      var yEnd = msToY(note.e);
      // Skip notes outside viewport
      if (yEnd > viewBot && yStart > viewBot) continue;
      if (yStart < viewTop && yEnd < viewTop) continue;

      var trailW = lw * 0.55;
      var isSelected = ni === selectedNoteIdx;
      var isGold = note.g;
      var color = isGold ? GOLD_COLOR : COLOR_HOLD;

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

    // Tap & hold head notes (viewport culled)
    for (var ni2 = 0; ni2 < notes.length; ni2++) {
      var note2 = notes[ni2];
      var y3 = msToY(note2.t);
      var x2 = offset + note2.l * lw;
      // Skip notes outside viewport
      if (y3 < viewTop - 30 || y3 > viewBot) continue;

      var noteW = lw * 0.82;
      var noteH = 14;
      var noteX = x2 + (lw - noteW) / 2;
      var noteY = y3 - noteH / 2;
      var isSelected2 = ni2 === selectedNoteIdx;
      var isGold2 = note2.g;
      var noteColor = isGold2 ? GOLD_COLOR : (note2.e ? COLOR_HOLD : COLOR_CLICK);

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

    // Draw playback cursor (viewport culled)
    if (audio.currentTime > 0) {
      var cursorY = msToY(audio.currentTime * 1000);
      if (cursorY >= viewTop - 20 && cursorY <= viewBot) {
        ctx.strokeStyle = "#fbbf24";
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(offset, cursorY);
        ctx.lineTo(offset + lw * LANE_COUNT, cursorY);
        ctx.stroke();

        // Draggable handle on left side
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

    // Restore context after scroll translation
    ctx.restore();

    // Update UI (throttled — only every 200ms during playback)
    var now = performance.now();
    if (!isPlaying || now - lastUIUpdate > 200) {
      lastUIUpdate = now;
      document.getElementById("noteCount").textContent = notes.length;
      document.getElementById("durationDisplay").textContent = formatTime(audioDuration);
      document.getElementById("positionDisplay").textContent = formatTime(audio.currentTime || 0);
      var pct = audioDuration > 0 ? (audio.currentTime / audioDuration * 100) : 0;
      document.getElementById("timelineProgress").style.width = pct + "%";
      document.getElementById("timelineCursor").style.left = pct + "%";
    }
  }

  /* INPUT: CLICK TO PLACE/REMOVE NOTES */
  function getCanvasPos(e) {
    var wrapRect = wrap.getBoundingClientRect();
    return {
      x: e.clientX - wrapRect.left + wrap.scrollLeft,
      y: e.clientY - wrapRect.top + wrap.scrollTop,
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

    // Check if clicking cursor handle (left side, near cursor Y)
    if (audio.currentTime > 0) {
      var cursorY = msToY(audio.currentTime * 1000);
      if (pos.x < 50 && Math.abs(pos.y - cursorY) < 15) {
        isDraggingCursor = true;
        e.preventDefault();
        return;
      }
    }

    var info = getLaneAndTime(pos);
    if (!info) return;

    lastDragPos = pos;

    // Ctrl+Right-click = seek (handled by contextmenu), skip mousedown
    if (e.button === 2 && e.ctrlKey) return;

    // Right click = delete (only when Ctrl is NOT held)
    if (e.button === 2 && !e.ctrlKey) {
      e.preventDefault();
      var idx = findNoteAt(info.lane, info.ms);
      if (idx >= 0) {
        undoStack.push(JSON.parse(JSON.stringify(notes)));
        notes.splice(idx, 1);
        if (selectedNoteIdx === idx) selectedNoteIdx = -1;
        else if (selectedNoteIdx > idx) selectedNoteIdx--;
        draw();
        updateNoteInfo();
        saveNotesToStorage();
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
      // Start move-note mode: drag to reposition
      isMovingNote = true;
      moveNoteIdx = idx2;
      moveNoteOrigT = notes[idx2].t;
      moveNoteOrigL = notes[idx2].l;
      moveNoteOrigE = notes[idx2].e || null;
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
    var pos = getCanvasPos(e);
    if (isDraggingCursor) {
      var ms = yToMs(pos.y);
      audio.currentTime = Math.max(0, Math.min(audioDuration, ms / 1000));
      draw();
      return;
    }
    // Moving an existing note
    if (isMovingNote && moveNoteIdx >= 0) {
      var info2 = getLaneAndTime(pos);
      if (info2) {
        var dt = info2.ms - moveNoteOrigT;
        var dl = info2.lane - moveNoteOrigL;
        notes[moveNoteIdx].t = Math.max(0, Math.round(moveNoteOrigT + dt));
        notes[moveNoteIdx].l = Math.max(0, Math.min(LANE_COUNT - 1, moveNoteOrigL + dl));
        if (moveNoteOrigE !== null) {
          notes[moveNoteIdx].e = Math.round(moveNoteOrigE + dt);
        }
        draw();
      }
      return;
    }
    if (!isDragging || dragLane < 0) return;
    lastDragPos = pos;
    draw();
  });

  canvas.addEventListener("mouseup", function (e) {
    if (isDraggingCursor) {
      isDraggingCursor = false;
      return;
    }
    // Finish moving a note
    if (isMovingNote && moveNoteIdx >= 0) {
      undoStack.push(JSON.parse(JSON.stringify(notes)));
      notes.sort(function (a, b) { return a.t - b.t; });
      isMovingNote = false;
      moveNoteIdx = -1;
      draw();
      updateNoteInfo();
      saveNotesToStorage();
      return;
    }
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
    saveNotesToStorage();
  });

  canvas.addEventListener("mouseleave", function () {
    isDragging = false;
    isDraggingCursor = false;
    dragLane = -1;
    dragNoteIdx = -1;
  });

  // Cursor handle hover cursor + time tooltip on canvas
  var canvasTooltip = null;
  canvas.addEventListener("mousemove", function (e) {
    if (isDragging || isDraggingCursor) {
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
    // Time tooltip near cursor
    if (audioDuration > 0) {
      var ms = yToMs(pos.y);
      if (ms >= 0 && ms <= audioDuration * 1000) {
        if (!canvasTooltip) {
          canvasTooltip = document.createElement("div");
          canvasTooltip.style.cssText = "position:fixed;z-index:9999;background:#1e1e2e;color:#e2e8f0;font:12px 'JetBrains Mono',monospace;padding:3px 8px;border-radius:4px;pointer-events:none;border:1px solid rgba(255,255,255,0.1);box-shadow:0 2px 8px rgba(0,0,0,0.4);";
          document.body.appendChild(canvasTooltip);
        }
        var sec = (ms / 1000).toFixed(1);
        canvasTooltip.textContent = sec + "s";
        canvasTooltip.style.display = "block";
        canvasTooltip.style.left = (e.clientX + 14) + "px";
        canvasTooltip.style.top = (e.clientY - 10) + "px";
      } else {
        if (canvasTooltip) canvasTooltip.style.display = "none";
      }
    }
  });
  canvas.addEventListener("mouseleave", function () {
    if (canvasTooltip) canvasTooltip.style.display = "none";
  });

  // Ctrl+Right-click on canvas = seek to that time position
  canvas.addEventListener("contextmenu", function (e) {
    e.preventDefault();
    if (!e.ctrlKey || !audioDuration) return;
    var rect = canvas.getBoundingClientRect();
    var scaleY = canvas.height / rect.height;
    var canvasY = (e.clientY - rect.top) * scaleY + wrap.scrollTop;
    var ms = yToMs(canvasY);
    if (ms < 0) ms = 0;
    if (ms > audioDuration * 1000) ms = audioDuration * 1000;
    audio.currentTime = ms / 1000;
    draw();
    var sec = (ms / 1000).toFixed(1);
    showToast("Seek: " + sec + "s", "info");
  });

  /* TIMELINE CLICK + DRAG */
  var timelineDragging = false;
  var timelineBar = document.getElementById("timelineBar");
  function seekTimeline(e) {
    var rect = timelineBar.getBoundingClientRect();
    var pct = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
    audio.currentTime = pct * audioDuration;
    draw();
  }
  timelineBar.addEventListener("mousedown", function (e) {
    timelineDragging = true;
    seekTimeline(e);
  });
  document.addEventListener("mousemove", function (e) {
    if (!timelineDragging) return;
    seekTimeline(e);
  });
  document.addEventListener("mouseup", function () { timelineDragging = false; });

  /* KEYBOARD SHORTCUTS */
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
        saveNotesToStorage();
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
      saveNotesToStorage();
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

  /* NOTE INFO PANEL */
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
    undoStack.push(JSON.parse(JSON.stringify(notes)));      notes.splice(selectedNoteIdx, 1);
      selectedNoteIdx = -1;
      draw();
      updateNoteInfo();
      saveNotesToStorage();
    };

  window.editorConvertToHold = function () {
    if (selectedNoteIdx < 0) return;
    undoStack.push(JSON.parse(JSON.stringify(notes)));
    var note = notes[selectedNoteIdx];
    if (!note.e) {
      note.e = note.t + 1000;
      draw();
      updateNoteInfo();
      saveNotesToStorage();
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
      saveNotesToStorage();
    }
  };

  /* PLAYBACK */
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

  /* ZOOM & SNAP */
  window.setZoom = function (val) {
    zoom = parseInt(val);
    var pctEl = document.getElementById("zoomPercent");
    if (pctEl) pctEl.textContent = Math.round(zoom / 3 * 100) + "%";
    gridDirty = true;
    resizeCanvas();
    draw();
  };
  // Init display
  (function() {
    var pctEl = document.getElementById("zoomPercent");
    if (pctEl) pctEl.textContent = Math.round(3 / 3 * 100) + "%";
  })();

  window.setSnap = function (val) {
    snapDiv = parseInt(val);
    gridDirty = true;
    draw();
  };

  // Rebuild grid when BPM changes
  document.getElementById("f-bpm").addEventListener("input", function () {
    gridDirty = true;
    resizeCanvas();
    draw();
  });

  window.clearNotes = function () {
    if (notes.length === 0) return;
    if (!confirm("Hapus semua notes?")) return;
    undoStack.push(JSON.parse(JSON.stringify(notes)));
    notes = [];
    selectedNoteIdx = -1;
    draw();
    saveNotesToStorage();
    updateNoteInfo();
  };

  /* UPLOAD */
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
    if (typeof EDIT_SONG !== "undefined" && EDIT_SONG) {
      formData.set("song_id", EDIT_SONG.id);
    }

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

  /* DELETE SONG */
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

  /* HELPERS */
  function formatTime(sec) {
    var m = Math.floor(sec / 60);
    var s = Math.floor(sec % 60);
    return m + ":" + String(s).padStart(2, "0");
  }

  /* SCROLL SYNC */
  var canvasScroll = canvas.parentElement;
  canvasScroll.addEventListener("scroll", function () {
    if (isPlaying) return;
    draw();
  });

  /* INIT */
  function init() {
    resizeCanvas();
    // Load saved notes from localStorage if available
    var loaded = loadNotesFromStorage();
    if (loaded) {
      showToast("Notes loaded from cache", "success");
    }
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
