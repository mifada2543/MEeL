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
  // Tampilkan dropdown bahasa subtitle
  const langWrap = document.getElementById("subtitle-lang-wrap");
  if (langWrap) langWrap.style.display = "";
}
function handleThumbFile(input) {
  if (!input.files || !input.files[0]) return;
  thumbManual = true; // pilihan manual — Auto-fill tidak boleh menimpa
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
// Flag: apakah user sudah pilih thumbnail manual
let thumbManual = false;

/** Auto-fill metadata dari file video via ffprobe di server. */
function autoFillMetadata() {
  const videoInput = document.getElementById("video-input");
  if (!videoInput.files || !videoInput.files[0]) {
    Swal.fire({
      title: "Pilih file dulu!",
      text: "Silakan pilih file video terlebih dahulu sebelum menggunakan Auto-fill.",
      icon: "warning",
      confirmButtonColor: "#ef4444",
      background: "#0e1118",
      color: "#fff",
    });
    return;
  }
  const btn = document.getElementById("btn-auto-meta");
  btn.disabled = true;
  btn.innerHTML = '<div class="auto-spinner"></div> Memproses...';
  const formData = new FormData();
  formData.append("video", videoInput.files[0]);
  const csrfInput = document.querySelector('input[name="csrf_token"]');
  if (csrfInput && csrfInput.value) {
    formData.append("csrf_token", csrfInput.value);
  }
  fetch("../api/auto-metadata", {
    method: "POST",
    body: formData,
  })
    .then((res) => res.json())
    .then((data) => {
      if (data.status === "success") {
        const hasTitle = data.title && data.title.trim() !== "";
        const hasDesc = data.description && data.description.trim() !== "";
        if (hasTitle)
          document.getElementById("f-title").value = data.title.trim();
        if (hasDesc)
          document.getElementById("f-desc").value = data.description.trim();
        // Isi thumbnail dari metadata (hanya jika user belum pilih manual)
        if (data.cover && data.cover.length > 0 && !thumbManual) {
          const preview = document.getElementById("thumb-preview");
          const iconWrap = document.getElementById("thumb-icon-wrap");
          const label = document.getElementById("thumb-label");
          const sub = document.getElementById("thumb-sub");
          const zone = document.getElementById("thumb-zone");
          preview.src = "data:image/jpeg;base64," + data.cover;
          preview.style.display = "block";
          iconWrap.style.display = "none";
          label.textContent = "Thumbnail dari metadata";
          sub.textContent = "";
          zone.classList.add("has-file");
          // Convert base64 ke File agar ikut terupload saat submit
          const binary = atob(data.cover);
          const bytes = new Uint8Array(binary.length);
          for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
          const file = new File([bytes], "thumb-from-metadata.jpg", { type: "image/jpeg" });
          const dt = new DataTransfer();
          dt.items.add(file);
          document.getElementById("thumb-input").files = dt.files;
        }
        if (!hasTitle && !hasDesc && !data.cover) {
          Swal.fire({
            title: "Metadata tidak ditemukan",
            text: "File ini tidak memiliki metadata yang bisa dibaca.",
            icon: "info",
            confirmButtonColor: "#ef4444",
            background: "#0e1118",
            color: "#fff",
          });
        } else {
          Swal.fire({
            title: "Metadata ditemukan!",
            text: "Formulir telah diisi otomatis dari metadata file video.",
            icon: "success",
            confirmButtonColor: "#ef4444",
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
          confirmButtonColor: "#ef4444",
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
        confirmButtonColor: "#ef4444",
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

const style = document.createElement("style");
style.textContent = "@keyframes spin { to { transform:rotate(360deg); } }";
document.head.appendChild(style);
