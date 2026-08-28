/**
 * MEeL!Mania — Upload / Delete API calls
 * CSRF_TOKEN and EDIT_SONG come from the inline <script> block in
 * edit.php (top-level const in a classic script is visible to module
 * scripts in the same document/realm).
 */
import { S } from "./state.js";
import { showToast } from "./toast.js";

export function uploadBeatmap() {
  var form = document.getElementById("beatmapForm");
  var formData = new FormData(form);

  var title = formData.get("title");
  if (!title || title.trim() === "") {
    showToast("Judul wajib diisi!", "error");
    return;
  }
  if (!formData.get("audio") || formData.get("audio").size === 0) {
    showToast("File audio wajib diupload!", "error");
    return;
  }
  if (S.notes.length < 10) {
    showToast("Minimal 10 notes dalam beatmap! (saat ini: " + S.notes.length + ")", "error");
    return;
  }

  S.notes.sort(function (a, b) { return a.t - b.t; });
  formData.set("beatmap_json", JSON.stringify({ notes: S.notes }));
  if (typeof EDIT_SONG !== "undefined" && EDIT_SONG) {
    formData.set("song_id", EDIT_SONG.id);
  }

  var overlay = document.getElementById("uploadOverlay");
  overlay.classList.remove("hidden");
  document.getElementById("uploadStatus").textContent = "Mengirim ke server...";

  var xhr = new XMLHttpRequest();
  xhr.open("POST", "../api/upload", true);

  xhr.upload.onprogress = function (e) {
    if (e.lengthComputable) {
      var pct = Math.round((e.loaded / e.total) * 100);
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
}

export function deleteSong(id) {
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
}