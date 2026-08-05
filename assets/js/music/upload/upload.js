/** MEeL - Media Hub Platform
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 */
/* ────────────────────────────────────────────────────────────────
 * upload/upload.js — JS halaman music/upload.php: drop-zone handler (audio & cover), overlay upload (progress manual), drag-and-drop, dan auto-fill metadata dari file audio via auto_metadata.php.
 * Depends on: shared/upload-progress.js (meelUploadProgress)
 * ──────────────────────────────────────────────────────────────── */
function handleAudioFile(input) {
  if (!input.files || !input.files[0]) return;
  const zone = document.getElementById("audio-zone");
  const label = document.getElementById("audio-label");
  label.textContent = input.files[0].name;
  zone.classList.add("has-file");
}
function handleCoverFile(input) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = function (e) {
    const preview = document.getElementById("cover-preview");
    const iconWrap = document.getElementById("cover-icon-wrap");
    const label = document.getElementById("cover-label");
    const sub = document.getElementById("cover-sub");
    const zone = document.getElementById("cover-zone");
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
  const audioInput = document.getElementById("audio-input");
  const titleInput = document.getElementById("f-title");
  const overlay = document.getElementById("upload-overlay");
  const fname = document.getElementById("overlay-filename");
  const btn = document.getElementById("btn-upload");
  if (audioInput.files[0]) {
    fname.textContent = audioInput.files[0].name;
  } else if (titleInput.value) {
    fname.textContent = titleInput.value;
  }
  btn.style.opacity = ".5";
  btn.style.pointerEvents = "none";
  overlay.classList.add("active");
  // Animasi progress-bar via helper bersama shared/upload-progress.js
  const fileSizeMB = audioInput.files[0]
    ? audioInput.files[0].size / 1024 / 1024
    : 20;
  const baseDelay = Math.max(2000, Math.min(fileSizeMB * 200, 18000)); // 2s–18s
  window.meelUploadProgress({
    phases: [
      {
        msg: "Mengirim file ke server…",
        pctVal: 8,
      },
      {
        msg: "Memproses audio…",
        pctVal: 35,
      },
      {
        msg: "Transcode ke Opus…",
        pctVal: 65,
      },
      {
        msg: "Menyimpan ke library…",
        pctVal: 88,
      },
    ],
    baseDelay: baseDelay,
  });
}
// Drag-and-drop audio
const audioZone = document.getElementById("audio-zone");
const audioInput = document.getElementById("audio-input");
audioZone.addEventListener("dragover", (e) => {
  e.preventDefault();
  audioZone.classList.add("drag-over");
});
audioZone.addEventListener("dragleave", () =>
  audioZone.classList.remove("drag-over"),
);
audioZone.addEventListener("drop", (e) => {
  e.preventDefault();
  audioZone.classList.remove("drag-over");
  const files = e.dataTransfer.files;
  if (files[0]) {
    const dt = new DataTransfer();
    dt.items.add(files[0]);
    audioInput.files = dt.files;
    handleAudioFile(audioInput);
  }
});
// Drag-and-drop cover
const coverZone = document.getElementById("cover-zone");
const coverInput = document.getElementById("cover-input");
coverZone.addEventListener("dragover", (e) => {
  e.preventDefault();
  coverZone.classList.add("drag-over");
});
coverZone.addEventListener("dragleave", () =>
  coverZone.classList.remove("drag-over"),
);
coverZone.addEventListener("drop", (e) => {
  e.preventDefault();
  coverZone.classList.remove("drag-over");
  const files = e.dataTransfer.files;
  if (files[0] && files[0].type.startsWith("image/")) {
    const dt = new DataTransfer();
    dt.items.add(files[0]);
    coverInput.files = dt.files;
    handleCoverFile(coverInput);
  }
});
/** Auto-fill metadata dari file audio via ffprobe di server.
 * Upload file ke auto_metadata.php → parse response → isi form + cover. */
function autoFillMetadata() {
  const audioInput = document.getElementById("audio-input");
  if (!audioInput.files || !audioInput.files[0]) {
    Swal.fire({
      title: "Pilih file dulu!",
      text: "Silakan pilih file audio terlebih dahulu sebelum menggunakan Auto-fill.",
      icon: "warning",
      confirmButtonColor: "#f97316",
      background: "#0e1118",
      color: "#fff",
    });
    return;
  }
  const btn = document.getElementById("btn-auto-meta");
  btn.disabled = true;
  btn.innerHTML = '<div class="auto-spinner"></div> Memproses...';
  const formData = new FormData();
  formData.append("audio", audioInput.files[0]);
  fetch("../controllers/api/auto_metadata.php", {
    method: "POST",
    body: formData,
  })
    .then((res) => res.json())
    .then((data) => {
      if (data.status === "success") {
        const hasTitle = data.title && data.title.trim() !== "";
        const hasArtist = data.artist && data.artist.trim() !== "";
        const hasAlbum = data.album && data.album.trim() !== "";
        if (hasTitle)
          document.getElementById("f-title").value = data.title.trim();
        if (hasArtist)
          document.getElementById("f-artist").value = data.artist.trim();
        if (hasAlbum)
          document.getElementById("f-album").value = data.album.trim();
        // Isi cover art dari metadata
        if (data.cover && data.cover.length > 0) {
          const preview = document.getElementById("cover-preview");
          const iconWrap = document.getElementById("cover-icon-wrap");
          const label = document.getElementById("cover-label");
          const sub = document.getElementById("cover-sub");
          const zone = document.getElementById("cover-zone");
          preview.src = "data:image/jpeg;base64," + data.cover;
          preview.style.display = "block";
          iconWrap.style.display = "none";
          label.textContent = "Cover dari metadata";
          sub.textContent = "";
          zone.classList.add("has-file");
        }
        if (!hasTitle && !hasArtist && !hasAlbum && !data.cover) {
          Swal.fire({
            title: "Metadata tidak ditemukan",
            text: "File ini tidak memiliki metadata ID3/FLAC yang bisa dibaca.",
            icon: "info",
            confirmButtonColor: "#f97316",
            background: "#0e1118",
            color: "#fff",
          });
        } else {
          Swal.fire({
            title: "Metadata ditemukan!",
            text: "Formulir telah diisi otomatis dari metadata file audio.",
            icon: "success",
            confirmButtonColor: "#f97316",
            background: "#0e1118",
            color: "#fff",
            timer: 2000,
            showConfirmButton: false,
          });
        }
      } else {
        Swal.fire({
          title: "Gagal",
          text: data.message || "Tidak dapat membaca metadata dari file ini.",
          icon: "error",
          confirmButtonColor: "#f97316",
          background: "#0e1118",
          color: "#fff",
        });
      }
    })
    .catch((err) => {
      console.error("Auto-metadata error:", err);
      Swal.fire({
        title: "Error",
        text: "Terjadi kesalahan koneksi saat memproses metadata.",
        icon: "error",
        confirmButtonColor: "#f97316",
        background: "#0e1118",
        color: "#fff",
      });
    })
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML =
        '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 4V2"/><path d="M15 16V8"/><path d="M9 10V2"/><path d="M9 22V16"/><path d="M12 10h.01"/><path d="M12 16h.01"/></svg> Auto';
    });
}
