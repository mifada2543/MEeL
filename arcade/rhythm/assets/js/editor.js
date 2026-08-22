/**
 * MEeL!Mania — Visual Beatmap Editor
 * Canvas-based editor for placing notes on a timeline grid
 */
(function () {
  "use strict";

  /* ═══════════════════════════════════════════════════════
     DOM
     ═══════════════════════════════════════════════════════ */
  const canvas = document.getElementById("editorCanvas");
  if (!canvas) return;
  const ctx = canvas.getContext("2d");
  const wrap = document.getElementById("canvasWrap");
  const audio = document.getElementById("audioPlayer");
  const audioInput = document.getElementById("f-audio");
  const coverInput = document.getElementById("f-cover");
  const coverPreview = document.getElementById("cover-preview");

  /* ═══════════════════════════════════════════════════════
     CONSTANTS
     ═══════════════════════════════════════════════════════ */
  const LANE_COUNT = 4;
  const LANE_COLORS = ["#f43f7a", "#a855f7", "#6366f1", "#818cf8"];
  const LANE_KEYS = ["A", "S", "K", "L"];
  const ROW_HEIGHT = 20; // pixels per beat row
  const LANE_WIDTH = 80;

  /* ═══════════════════════════════════════════════════════
     STATE
     ═══════════════════════════════════════════════════════ */
  let notes = []; // [{t, l}, {t, e, l}, {t, l, g}, {t, e, l, g}]
  let undoStack = [];
  let zoom = 3;
  let snapDiv = 8; // snap to 1/8 beat
  let isPlaying = false;
  let audioDuration = 0;
  let audioStartTime = 0;
  let animFrame = null;
  let isDragging = false;
  let dragLane = -1;
  let dragStartMs = -1; // for hold note creation
  let dragNoteIdx = -1; // index of note being extended
  let selectedNoteIdx = -1; // currently selected note index
  const GOLD_COLOR = "#fbbf24";

  /* ═══════════════════════════════════════════════════════
     AUDIO
     ═══════════════════════════════════════════════════════ */
  audioInput.addEventListener("change", function () {
    if (this.files && this.files[0]) {
      const file = this.files[0];
      const url = URL.createObjectURL(file);
      audio.src = url;
      audio.load();
      audio.addEventListener("loadedmetadata", function () {
        audioDuration = audio.duration;
        document.getElementById("durationDisplay").textContent = formatTime(audioDuration);
        document.getElementById("audio-info").textContent =
          `${file.name} · ${formatTime(audioDuration)} · ${(file.size / 1024 / 1024).toFixed(1)}MB`;
        resizeCanvas();
        draw();
      }, { once: true });
    }
  });

  coverInput.addEventListener("change", function () {
    if (this.files && this.files[0]) {
      const reader = new FileReader();
      reader.onload = (e) => {
        coverPreview.src = e.target.result;
        coverPreview.classList.add("visible");
      };
      reader.readAsDataURL(this.files[0]);
    }
  });

  /* ═══════════════════════════════════════════════════════
     CANVAS
     ═══════════════════════════════════════════════════════ */
  function resizeCanvas() {
    const w = Math.max(LANE_WIDTH * LANE_COUNT + 40, wrap.clientWidth - 20);
    const h = Math.max(400, audioDuration * ROW_HEIGHT * zoom / 1000 * getBPM() / 60);
    canvas.width = w;
    canvas.height = h;
    canvas.style.width = w + "px";
    canvas.style.height = Math.min(h, wrap.clientHeight - 10) + "px";
  }

  function getBPM() {
    return parseInt(document.getElementById("f-bpm").value) || 120;
  }

  function msToY(ms) {
    const bpm = getBPM();
    const beatMs = 60000 / bpm;
    const pxPerMs = ROW_HEIGHT * zoom / beatMs;
    return ms * pxPerMs;
  }

  function yToMs(y) {
    const bpm = getBPM();
    const beatMs = 60000 / bpm;
    const pxPerMs = ROW_HEIGHT * zoom / beatMs;
    return y / pxPerMs;
  }

  function snapMs(ms) {
    if (snapDiv <= 0) return ms;
    const bpm = getBPM();
    const beatMs = 60000 / bpm;
    const snapMs = beatMs / snapDiv;
    return Math.round(ms / snapMs) * snapMs;
  }

  /* ═══════════════════════════════════════════════════════
     DRAW
     ═══════════════════════════════════════════════════════ */
  function draw() {
    const w = canvas.width;
    const h = canvas.height;
    const laneW = LANE_WIDTH;
    const offset = 20;

    ctx.clearRect(0, 0, w, h);
    ctx.fillStyle = "#08080f";
    ctx.fillRect(0, 0, w, h);

    const bpm = getBPM();
    const beatMs = 60000 / bpm;
    const totalMs = audioDuration * 1000;

    // Draw grid
    for (let ms = 0; ms <= totalMs; ms += beatMs) {
      const y = msToY(ms);
      if (y > h) break;
      const beatNum = Math.round(ms / beatMs);
      const isBar = beatNum % 4 === 0;

      ctx.strokeStyle = isBar ? "rgba(255,255,255,0.12)" : "rgba(255,255,255,0.04)";
      ctx.lineWidth = isBar ? 1.5 : 0.5;
      ctx.beginPath();
      ctx.moveTo(offset, y);
      ctx.lineTo(offset + laneW * LANE_COUNT, y);
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
      const snapMs = beatMs / snapDiv;
      ctx.strokeStyle = "rgba(255,255,255,0.02)";
      ctx.lineWidth = 0.5;
      for (let ms = 0; ms <= totalMs; ms += snapMs) {
        const y = msToY(ms);
        if (y > h) break;
        if (Math.round(ms / beatMs * snapDiv) % snapDiv !== 0) {
          ctx.beginPath();
          ctx.moveTo(offset, y);
          ctx.lineTo(offset + laneW * LANE_COUNT, y);
          ctx.stroke();
        }
      }
    }

    // Draw lane backgrounds
    for (let i = 0; i < LANE_COUNT; i++) {
      const x = offset + i * laneW;
      const alpha = (i % 2 === 0) ? 0.03 : 0.01;
      ctx.fillStyle = LANE_COLORS[i] + Math.round(alpha * 255).toString(16).padStart(2, "0");
      ctx.fillRect(x, 0, laneW, h);

      // Lane header
      ctx.fillStyle = LANE_COLORS[i] + "40";
      ctx.fillRect(x, 0, laneW, 24);

      // Lane label
      ctx.fillStyle = LANE_COLORS[i];
      ctx.font = "bold 12px 'JetBrains Mono', monospace";
      ctx.textAlign = "center";
      ctx.fillText(LANE_KEYS[i], x + laneW / 2, 16);

      // Lane separator
      ctx.strokeStyle = "rgba(255,255,255,0.04)";
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(x, 0);
      ctx.lineTo(x, h);
      ctx.stroke();
    }

    // Hold note trails
    for (let ni = 0; ni < notes.length; ni++) {
      const note = notes[ni];
      if (!note.e) continue;
      const cx = offset + note.l * laneW + laneW / 2;
      const yStart = msToY(note.t);
      const yEnd = msToY(note.e);
      if (yStart > h && yEnd > h) continue;
      if (yStart < 0 && yEnd < 0) continue;

      const trailW = laneW * 0.5;
      const isSelected = ni === selectedNoteIdx;
      const isGold = note.g;
      const color = isGold ? GOLD_COLOR : LANE_COLORS[note.l];

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
        ctx.arc(cx, yEnd, 5, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = color;
        ctx.beginPath();
        ctx.arc(cx, yEnd, 3, 0, Math.PI * 2);
        ctx.fill();
      }

      ctx.globalAlpha = 1;
    }

    // Tap & hold head notes
    for (let ni = 0; ni < notes.length; ni++) {
      const note = notes[ni];
      const y = msToY(note.t);
      const x = offset + note.l * laneW;
      if (y < 0 || y > h) continue;

      const noteW = laneW * 0.7;
      const noteH = 10;
      const noteX = x + (laneW - noteW) / 2;
      const noteY = y - noteH / 2;
      const isSelected = ni === selectedNoteIdx;
      const isGold = note.g;
      const noteColor = isGold ? GOLD_COLOR : LANE_COLORS[note.l];

      // Selection highlight
      if (isSelected) {
        ctx.strokeStyle = "#fff";
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.roundRect(noteX - 3, noteY - 3, noteW + 6, noteH + 6, 6);
        ctx.stroke();
      }

      ctx.shadowColor = noteColor;
      ctx.shadowBlur = note.e ? 12 : 8;
      ctx.fillStyle = noteColor;
      ctx.beginPath();
      ctx.roundRect(noteX, noteY, noteW, noteH, 4);
      ctx.fill();
      ctx.shadowBlur = 0;

      ctx.fillStyle = "rgba(255,255,255,0.2)";
      ctx.beginPath();
      ctx.roundRect(noteX + 2, noteY + 1, noteW - 4, noteH * 0.4, 2);
      ctx.fill();

      if (note.e) {
        ctx.fillStyle = "rgba(255,255,255,0.5)";
        ctx.beginPath();
        ctx.moveTo(x + laneW / 2, noteY - 3);
        ctx.lineTo(x + laneW / 2 + 4, noteY + 1);
        ctx.lineTo(x + laneW / 2, noteY + 5);
        ctx.lineTo(x + laneW / 2 - 4, noteY + 1);
        ctx.closePath();
        ctx.fill();
      }

      // Gold star indicator
      if (isGold) {
        ctx.fillStyle = "#fff";
        ctx.font = "8px sans-serif";
        ctx.textAlign = "center";
        ctx.fillText("★", x + laneW / 2, noteY - 5);
      }
    }

    // Drag preview
    if (isDragging && dragLane >= 0 && dragStartMs >= 0) {
      const cx = offset + dragLane * laneW + laneW / 2;
      const currentMs = snapMs(yToMs(lastDragPos.y));
      if (currentMs > dragStartMs + 50) {
        const y1 = msToY(dragStartMs);
        const y2 = msToY(currentMs);
        const trailW = laneW * 0.5;
        ctx.globalAlpha = 0.4;
        ctx.fillStyle = LANE_COLORS[dragLane];
        ctx.beginPath();
        ctx.roundRect(cx - trailW / 2, y2, trailW, y1 - y2, 4);
        ctx.fill();
        ctx.globalAlpha = 1;
      }
    }

    // Draw playback cursor
    if (audio.currentTime > 0) {
      const cursorY = msToY(audio.currentTime * 1000);
      ctx.strokeStyle = "#fbbf24";
      ctx.lineWidth = 2;
      ctx.beginPath();
      ctx.moveTo(offset, cursorY);
      ctx.lineTo(offset + laneW * LANE_COUNT, cursorY);
      ctx.stroke();

      // Triangle indicator
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

    const pct = audioDuration > 0 ? (audio.currentTime / audioDuration * 100) : 0;
    document.getElementById("timelineProgress").style.width = pct + "%";
    document.getElementById("timelineCursor").style.left = pct + "%";
  }

  /* ═══════════════════════════════════════════════════════
     INPUT: CLICK TO PLACE/REMOVE NOTES
     ═══════════════════════════════════════════════════════ */
  function getCanvasPos(e) {
    const rect = canvas.getBoundingClientRect();
    return {
      x: e.clientX - rect.left + canvas.parentElement.scrollLeft,
      y: e.clientY - rect.top + canvas.parentElement.scrollTop,
    };
  }

  function getLaneAndTime(pos) {
    const offset = 20;
    const lane = Math.floor((pos.x - offset) / LANE_WIDTH);
    if (lane < 0 || lane >= LANE_COUNT) return null;
    const ms = snapMs(yToMs(pos.y));
    return { lane, ms: Math.max(0, ms) };
  }

  function findNoteAt(lane, ms, tolerance) {
    tolerance = tolerance || 50;
    return notes.findIndex((n) => n.l === lane && Math.abs(n.t - ms) < tolerance);
  }

  let lastDragPos = { x: 0, y: 0 };
  function getLastDragPos() { return lastDragPos; }

  canvas.addEventListener("mousedown", (e) => {
    const pos = getCanvasPos(e);
    const info = getLaneAndTime(pos);
    if (!info) return;

    lastDragPos = pos;

    // Right click = delete
    if (e.button === 2) {
      e.preventDefault();
      const idx = findNoteAt(info.lane, info.ms);
      if (idx >= 0) {
        undoStack.push(JSON.parse(JSON.stringify(notes)));
        notes.splice(idx, 1);
        draw();
      }
      return;
    }

    // Left click: check if clicking existing note head
    const idx = findNoteAt(info.lane, info.ms);
    if (idx >= 0) {
      // Select the note
      selectedNoteIdx = idx;

      // If it's a hold note, allow extending by dragging its tail
      if (notes[idx].e) {
        // Check if clicking near the tail (bottom 30% of hold)
        const noteEndMs = notes[idx].e;
        const noteStartMs = notes[idx].t;
        const noteRange = noteEndMs - noteStartMs;
        const clickDistFromEnd = Math.abs(info.ms - noteEndMs);
        if (clickDistFromEnd < noteRange * 0.3 || clickDistFromEnd < 200) {
          // Drag tail
          isDragging = true;
          dragLane = info.lane;
          dragNoteIdx = idx;
          dragStartMs = notes[idx].t;
          draw();
          return;
        }
      }
      draw();
      return;
    }

    // Click on empty: deselect
    selectedNoteIdx = -1;

    // Start drag for new note
    isDragging = true;
    dragLane = info.lane;
    dragStartMs = info.ms;
    dragNoteIdx = -1;
  });

  canvas.addEventListener("mousemove", (e) => {
    if (!isDragging || dragLane < 0) return;
    lastDragPos = getCanvasPos(e);
    draw();
  });

  canvas.addEventListener("mouseup", (e) => {
    if (!isDragging || dragLane < 0) {
      isDragging = false;
      dragLane = -1;
      return;
    }

    const pos = getCanvasPos(e);
    const info = getLaneAndTime(pos);
    if (!info || info.lane !== dragLane) {
      isDragging = false;
      dragLane = -1;
      return;
    }

    undoStack.push(JSON.parse(JSON.stringify(notes)));

    const endMs = info.ms;
    const minHold = 100; // minimum hold duration in ms

    if (dragNoteIdx >= 0) {
      // Extending existing hold note
      if (endMs > dragStartMs + minHold) {
        notes[dragNoteIdx].e = Math.round(endMs);
      } else {
        // Too short: remove hold
        delete notes[dragNoteIdx].e;
      }
    } else {
      // New note
      if (endMs > dragStartMs + minHold) {
        // Create hold note
        notes.push({ t: Math.round(dragStartMs), e: Math.round(endMs), l: dragLane });
      } else {
        // Click only: create tap note
        notes.push({ t: Math.round(dragStartMs), l: dragLane });
      }
    }

    notes.sort((a, b) => a.t - b.t);
    isDragging = false;
    dragLane = -1;
    dragNoteIdx = -1;
    draw();
  });

  canvas.addEventListener("mouseleave", () => {
    isDragging = false;
    dragLane = -1;
    dragNoteIdx = -1;
  });

  canvas.addEventListener("contextmenu", (e) => e.preventDefault());

  /* ═══════════════════════════════════════════════════════
     TIMELINE CLICK
     ═══════════════════════════════════════════════════════ */
  document.getElementById("timelineBar").addEventListener("click", (e) => {
    const rect = e.currentTarget.getBoundingClientRect();
    const pct = (e.clientX - rect.left) / rect.width;
    audio.currentTime = pct * audioDuration;
    draw();
  });

  /* ═══════════════════════════════════════════════════════
     KEYBOARD SHORTCUTS
     ═══════════════════════════════════════════════════════ */
  document.addEventListener("keydown", (e) => {
    const isInput = e.target.tagName === "INPUT" || e.target.tagName === "SELECT" || e.target.tagName === "TEXTAREA";

    // Ctrl+Z = undo
    if ((e.ctrlKey || e.metaKey) && e.key === "z") {
      e.preventDefault();
      if (undoStack.length > 0) {
        notes = undoStack.pop();
        selectedNoteIdx = -1;
        draw();
      }
      return;
    }

    // G = toggle gold on selected note
    if (e.key === "g" && !isInput && selectedNoteIdx >= 0) {
      e.preventDefault();
      undoStack.push(JSON.parse(JSON.stringify(notes)));
      notes[selectedNoteIdx].g = !notes[selectedNoteIdx].g;
      draw();
      updateNoteInfo();
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
    if (e.key === " " && !isInput) {
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
    const el = document.getElementById("noteInfoPanel");
    if (!el) return;
    if (selectedNoteIdx < 0 || selectedNoteIdx >= notes.length) {
      el.innerHTML = '<p class="empty-text">Klik note untuk melihat info</p>';
      return;
    }
    const note = notes[selectedNoteIdx];
    const type = note.e ? (note.g ? "Hold Gold" : "Hold") : (note.g ? "Tap Gold" : "Tap");
    const laneKeys = ["A", "S", "K", "L"];
    const dur = note.e ? ((note.e - note.t) + "ms") : "-";
    el.innerHTML = `
      <div class="note-info-row"><span>Type:</span><span class="note-info-val">${type}</span></div>
      <div class="note-info-row"><span>Lane:</span><span class="note-info-val">${laneKeys[note.l]} (${note.l})</span></div>
      <div class="note-info-row"><span>Start:</span><span class="note-info-val">${note.t}ms</span></div>
      ${note.e ? `<div class="note-info-row"><span>End:</span><span class="note-info-val">${note.e}ms</span></div>` : ""}
      ${note.e ? `<div class="note-info-row"><span>Duration:</span><span class="note-info-val">${dur}</span></div>` : ""}
      <div class="note-info-row"><span>Gold:</span><span class="note-info-val">${note.g ? "⭐ Yes" : "No"}</span></div>
      <div class="note-actions">
        <button class="btn btn-sm" onclick="window.editorToggleGold()">${note.g ? "Remove Gold" : "Make Gold ⭐"}</button>
        <button class="btn btn-sm" onclick="window.editorDeleteSelected()" style="color:var(--danger);">Delete</button>
        ${note.e ? `<button class="btn btn-sm" onclick="window.editorConvertToTap()">Convert to Tap</button>` : ""}
        ${!note.e ? `<button class="btn btn-sm" onclick="window.editorConvertToHold()">Convert to Hold</button>` : ""}
      </div>
    `;
  }

  window.editorToggleGold = function () {
    if (selectedNoteIdx < 0) return;
    undoStack.push(JSON.parse(JSON.stringify(notes)));
    notes[selectedNoteIdx].g = !notes[selectedNoteIdx].g;
    draw();
    updateNoteInfo();
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
    const note = notes[selectedNoteIdx];
    if (!note.e) {
      note.e = note.t + 1000; // default 1 second hold
      draw();
      updateNoteInfo();
    }
  };

  window.editorConvertToTap = function () {
    if (selectedNoteIdx < 0) return;
    undoStack.push(JSON.parse(JSON.stringify(notes)));
    const note = notes[selectedNoteIdx];
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
    if (!audio.src) return;
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

  audio.addEventListener("ended", () => {
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
    undoStack.push([...notes]);
    notes = [];
    draw();
  };

  /* ═══════════════════════════════════════════════════════
     UPLOAD
     ═══════════════════════════════════════════════════════ */
  window.uploadBeatmap = async function () {
    const form = document.getElementById("beatmapForm");
    const formData = new FormData(form);

    // Validate
    const title = formData.get("title");
    const bpm = formData.get("bpm");
    if (!title || title.trim() === "") {
      Swal.fire({ title: "Error", text: "Judul wajib diisi!", icon: "warning", background: "#0e1118", color: "#fff" });
      return;
    }
    if (!formData.get("audio") || formData.get("audio").size === 0) {
      Swal.fire({ title: "Error", text: "File audio wajib diupload!", icon: "warning", background: "#0e1118", color: "#fff" });
      return;
    }
    if (notes.length < 10) {
      Swal.fire({ title: "Error", text: "Minimal 10 notes dalam beatmap!", icon: "warning", background: "#0e1118", color: "#fff" });
      return;
    }

    // Sort notes
    notes.sort((a, b) => a.t - b.t);
    formData.set("beatmap_json", JSON.stringify({ notes }));

    // Show overlay
    document.getElementById("uploadOverlay").classList.remove("hidden");
    document.getElementById("uploadStatus").textContent = "Mengirim ke server...";

    try {
      const xhr = new XMLHttpRequest();
      xhr.open("POST", "../api/upload.php", true);

      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
          const pct = Math.round(e.loaded / e.total * 100);
          document.getElementById("uploadProgress").style.width = pct + "%";
          document.getElementById("uploadStatus").textContent = `Mengirim... ${pct}%`;
        }
      };

      xhr.onload = function () {
        document.getElementById("uploadOverlay").classList.add("hidden");
        try {
          const res = JSON.parse(xhr.responseText);
          if (res.success) {
            Swal.fire({
              title: "Berhasil! 🎉",
              text: "Beatmap berhasil diupload!",
              icon: "success",
              confirmButtonColor: "#f43f7a",
              background: "#0e1118",
              color: "#fff",
            }).then(() => {
              window.location.reload();
            });
          } else {
            Swal.fire({ title: "Error", text: res.error || "Upload gagal", icon: "error", background: "#0e1118", color: "#fff" });
          }
        } catch (e) {
          Swal.fire({ title: "Error", text: "Response tidak valid", icon: "error", background: "#0e1118", color: "#fff" });
        }
      };

      xhr.onerror = function () {
        document.getElementById("uploadOverlay").classList.add("hidden");
        Swal.fire({ title: "Error", text: "Koneksi gagal", icon: "error", background: "#0e1118", color: "#fff" });
      };

      xhr.send(formData);
    } catch (e) {
      document.getElementById("uploadOverlay").classList.add("hidden");
      Swal.fire({ title: "Error", text: e.message, icon: "error", background: "#0e1118", color: "#fff" });
    }
  };

  /* ═══════════════════════════════════════════════════════
     DELETE SONG
     ═══════════════════════════════════════════════════════ */
  window.deleteSong = async function (id) {
    const confirmed = await Swal.fire({
      title: "Hapus Beatmap?",
      text: "Tindakan ini tidak dapat dibatalkan.",
      icon: "warning",
      showCancelButton: true,
      confirmButtonColor: "#ef4444",
      cancelButtonColor: "#475569",
      confirmButtonText: "Hapus",
      background: "#0e1118",
      color: "#fff",
    }).then((r) => r.isConfirmed);

    if (!confirmed) return;

    const fd = new FormData();
    fd.append("song_id", id);
    fd.append("csrf_token", CSRF_TOKEN);

    try {
      const resp = await fetch("../api/delete.php", { method: "POST", body: fd });
      const res = await resp.json();
      if (res.success) {
        Swal.fire({ title: "Terhapus!", text: res.message, icon: "success", background: "#0e1118", color: "#fff" })
          .then(() => window.location.reload());
      } else {
        Swal.fire({ title: "Error", text: res.error, icon: "error", background: "#0e1118", color: "#fff" });
      }
    } catch (e) {
      Swal.fire({ title: "Error", text: "Gagal menghapus", icon: "error", background: "#0e1118", color: "#fff" });
    }
  };

  /* ═══════════════════════════════════════════════════════
     HELPERS
     ═══════════════════════════════════════════════════════ */
  function formatTime(sec) {
    const m = Math.floor(sec / 60);
    const s = Math.floor(sec % 60);
    return m + ":" + String(s).padStart(2, "0");
  }

  /* ═══════════════════════════════════════════════════════
     SCROLL SYNC
     ═══════════════════════════════════════════════════════ */
  const canvasScroll = canvas.parentElement;
  canvasScroll.addEventListener("scroll", () => {
    if (isPlaying) return;
    draw();
  });

  /* ═══════════════════════════════════════════════════════
     INIT
     ═══════════════════════════════════════════════════════ */
  function init() {
    resizeCanvas();
    draw();
    window.addEventListener("resize", () => { resizeCanvas(); draw(); });
  }

  init();
})();
