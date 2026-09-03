



import { S, DOM } from "./state.js";
import { resizeCanvas, formatTime } from "./canvas.js";
import { draw } from "./renderer.js";
import { saveNotesToStorage } from "./storage.js";
import { showToast } from "./toast.js";

var audio = DOM.audio;

export function togglePlayback() {
  if (!audio.src) { showToast("Pilih file audio dulu!", "warning"); return; }
  if (S.isPlaying) {
    audio.pause();
    S.isPlaying = false;
    document.getElementById("btnPlayPause").textContent = "▶ Play";
    cancelAnimationFrame(S.animFrame);
  } else {
    audio.play();
    S.isPlaying = true;
    document.getElementById("btnPlayPause").textContent = "⏸ Pause";
    animatePlayback();
  }
}

export function stopPlayback() {
  audio.pause();
  audio.currentTime = 0;
  S.isPlaying = false;
  document.getElementById("btnPlayPause").textContent = "▶ Play";
  cancelAnimationFrame(S.animFrame);
  draw();
}

audio.addEventListener("ended", function () {
  S.isPlaying = false;
  document.getElementById("btnPlayPause").textContent = "▶ Play";
  cancelAnimationFrame(S.animFrame);
});

function animatePlayback() {
  if (!S.isPlaying) return;
  draw();
  S.animFrame = requestAnimationFrame(animatePlayback);
}


export function setZoom(val) {
  S.zoom = parseInt(val);
  var pctEl = document.getElementById("zoomPercent");
  if (pctEl) pctEl.textContent = Math.round((S.zoom / 3) * 100) + "%";
  S.gridDirty = true;
  resizeCanvas();
  draw();
}
(function initZoomDisplay() {
  var pctEl = document.getElementById("zoomPercent");
  if (pctEl) pctEl.textContent = Math.round((3 / 3) * 100) + "%";
})();

export function setSnap(val) {
  S.snapDiv = parseInt(val);
  S.gridDirty = true;
  draw();
}


var bpmInput = document.getElementById("f-bpm");
if (bpmInput) {
  bpmInput.addEventListener("input", function () {
    S.gridDirty = true;
    resizeCanvas();
    draw();
  });
}

export function clearNotes() {
  if (S.notes.length === 0) return;
  if (!confirm("Hapus semua notes?")) return;
  S.undoStack.push(JSON.parse(JSON.stringify(S.notes)));
  S.notes = [];
  S.selectedNoteIdx = -1;
  draw();
  saveNotesToStorage();
}


DOM.audioInput.addEventListener("change", function () {
  if (this.files && this.files[0]) {
    var file = this.files[0];
    var url = URL.createObjectURL(file);
    audio.src = url;
    audio.load();
    audio.addEventListener("loadedmetadata", function () {
      S.audioDuration = audio.duration;
      S.hasAudio = true;
      document.getElementById("durationDisplay").textContent = formatTime(S.audioDuration);
      document.getElementById("audio-info").textContent =
        file.name + " · " + formatTime(S.audioDuration) + " · " + (file.size / 1024 / 1024).toFixed(1) + "MB";
      var prompt = document.getElementById("audioPromptOverlay");
      if (prompt) prompt.style.display = "none";
      resizeCanvas();
      draw();
    }, { once: true });
  }
});


DOM.coverInput.addEventListener("change", function () {
  if (this.files && this.files[0]) {
    var reader = new FileReader();
    reader.onload = function (e) {
      DOM.coverPreview.src = e.target.result;
      DOM.coverPreview.classList.add("visible");
    };
    reader.readAsDataURL(this.files[0]);
  }
});