/** MEeL - Media Hub Platform
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 */
/*
 * upload/upload.js — JS halaman video/upload.php: drop-zone handler,
 * overlay upload (progress manual), drag-and-drop, dan @keyframes spin.
 * Depends on: shared/upload-progress.js (meelUploadProgress)
 * */
function handleVideoFile(input) {
  const file = input.files[0];
  if (!file) return;
  const ext = file.name.split(".").pop().toLowerCase();
  const allowed = ["mp4", "webm", "mkv"];
  if (!allowed.includes(ext)) {
    meelAlert({
      title: "Format Ditolak",
      text: "Gunakan MP4, WEBM, atau MKV.",
      icon: "error",
    });
    input.value = "";
    return;
  }
  const zone = document.getElementById("video-zone");
  const label = document.getElementById("video-label");
  label.textContent = file.name;
  zone.classList.add("has-file");
}
function handleSubtitleFile(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  const ext = file.name.split(".").pop().toLowerCase();
  const allowed = ["vtt", "srt"];
  if (!allowed.includes(ext)) {
    meelAlert({
      title: "Format Ditolak",
      text: "Gunakan VTT atau SRT.",
      icon: "error",
    });
    input.value = "";
    return;
  }
  const zone = document.getElementById("subtitle-zone");
  const label = document.getElementById("subtitle-label");
  const sub = document.getElementById("subtitle-sub");
  label.textContent = file.name;
  sub.textContent = ext === "srt" ? "SRT · akan dikonversi otomatis" : "VTT";
  zone.classList.add("has-file");
}
function handleThumbFile(input) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = function (e) {
    const preview = document.getElementById("thumb-preview");
    const iconWrap = document.getElementById("thumb-icon-wrap");
    const label = document.getElementById("thumb-label");
    const sub = document.getElementById("thumb-sub");
    const zone = document.getElementById("thumb-zone");
    preview.src = e.target.result;
    preview.style.display = "block";
    iconWrap.style.display = "none";
    label.textContent = input.files[0].name;
    sub.textContent = "";
    zone.classList.add("has-file");
  };
  reader.readAsDataURL(input.files[0]);
}
function handleSubmit() {
  const videoInput = document.getElementById("video-input");
  const titleInput = document.getElementById("f-title");
  const overlay = document.getElementById("upload-overlay");
  const fname = document.getElementById("overlay-filename");
  const btn = document.getElementById("btn-upload");
  if (videoInput.files[0]) {
    fname.textContent = videoInput.files[0].name;
  } else if (titleInput.value) {
    fname.textContent = titleInput.value;
  }
  btn.style.opacity = ".5";
  btn.style.pointerEvents = "none";
  overlay.classList.add("active");
  const fileSizeMB = videoInput.files[0]
    ? videoInput.files[0].size / 1024 / 1024
    : 50;
  const baseDelay = Math.max(3000, Math.min(fileSizeMB * 120, 20000)); // 3s–20s
  window.meelUploadProgress({
    phases: [
      {
        msg: "Mengirim file ke server…",
        pctVal: 5,
      },
      {
        msg: "File sedang diproses…",
        pctVal: 30,
      },
      {
        msg: "Menyimpan ke library…",
        pctVal: 60,
      },
      {
        msg: "Menyelesaikan proses…",
        pctVal: 85,
      },
    ],
    baseDelay: baseDelay,
  });
}
const videoZone = document.getElementById("video-zone");
const videoInput = document.getElementById("video-input");
videoZone.addEventListener("dragover", (e) => {
  e.preventDefault();
  videoZone.classList.add("drag-over");
});
videoZone.addEventListener("dragleave", () =>
  videoZone.classList.remove("drag-over"),
);
videoZone.addEventListener("drop", (e) => {
  e.preventDefault();
  videoZone.classList.remove("drag-over");
  const files = e.dataTransfer.files;
  if (files[0]) {
    const dt = new DataTransfer();
    dt.items.add(files[0]);
    videoInput.files = dt.files;
    handleVideoFile(videoInput);
  }
});
const subtitleZone = document.getElementById("subtitle-zone");
const subtitleInput = document.getElementById("subtitle-input");
subtitleZone.addEventListener("dragover", (e) => {
  e.preventDefault();
  subtitleZone.classList.add("drag-over");
});
subtitleZone.addEventListener("dragleave", () =>
  subtitleZone.classList.remove("drag-over"),
);
subtitleZone.addEventListener("drop", (e) => {
  e.preventDefault();
  subtitleZone.classList.remove("drag-over");
  const files = e.dataTransfer.files;
  if (files[0]) {
    const dt = new DataTransfer();
    dt.items.add(files[0]);
    subtitleInput.files = dt.files;
    handleSubtitleFile(subtitleInput);
  }
});
const thumbZone = document.getElementById("thumb-zone");
const thumbInput = document.getElementById("thumb-input");
thumbZone.addEventListener("dragover", (e) => {
  e.preventDefault();
  thumbZone.classList.add("drag-over");
});
thumbZone.addEventListener("dragleave", () =>
  thumbZone.classList.remove("drag-over"),
);
thumbZone.addEventListener("drop", (e) => {
  e.preventDefault();
  thumbZone.classList.remove("drag-over");
  const files = e.dataTransfer.files;
  if (files[0] && files[0].type.startsWith("image/")) {
    const dt = new DataTransfer();
    dt.items.add(files[0]);
    thumbInput.files = dt.files;
    handleThumbFile(thumbInput);
  }
});
const style = document.createElement("style");
style.textContent = "@keyframes spin { to { transform:rotate(360deg); } }";
document.head.appendChild(style);
