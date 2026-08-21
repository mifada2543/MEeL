/** MEeL - Media Hub Platform
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 */
/* engine/result.js — Fase selesai & error overlay + penangkap error */

// NAVIGASI FINAL (upload music → post_encode.php)
// Dipanggil dari dokumen penutup upload_advanced.php SETELAH seluruh streaming
// selesai (meta refresh sebagai fallback tanpa JS). Guard mencegah eksekusi
// ganda; location.replace() (bukan location.href) membuat halaman POST tidak
// masuk history sehingga tombol back tidak me-resubmit form upload.
// CATATAN: guard ini hanya mencegah double-execution dalam satu dokumen —
// duplikat di level jaringan ditangani di server (flock single-flight +
// idempotensi di Transcoder::encodeMusic); perbaikan server itu TIDAK boleh dihapus.
var _meelRedirectFired = false;
window.meelRedirect = function (url) {
  if (_meelRedirectFired) return;
  _meelRedirectFired = true;
  window.location.replace(url);
};

window.meelDone = function (title, homeUrl) {
  meelPhase("done");
  var el = document.getElementById("meel-done-title");
  if (el && title) el.textContent = title;
  if (homeUrl) {
    var btn = document.getElementById("meel-btn-home");
    if (btn) btn.href = homeUrl;
  }
};
window.meelError = function (log) {
  if (_errorTimeout) clearTimeout(_errorTimeout);
  meelPhase("error");
  var overlay = document.getElementById("meel-overlay");
  if (overlay) {
    overlay.classList.add("error-state");
  }
  var el = document.getElementById("meel-error-log");
  if (el) el.textContent = log;
  console.error("MEeL Error:", log);
};
window.addEventListener("error", function (event) {
  console.error("Global JavaScript Error:", event.error);
  meelError("Kesalahan sistem: " + (event.error?.message || "Unknown error"));
});
window.addEventListener("unhandledrejection", function (event) {
  console.error("Unhandled Promise:", event.reason);
  meelError(
    "Kesalahan sistem: " + (event.reason?.message || String(event.reason)),
  );
});
window.meelDoneTranscode = function (title, downloadUrl) {
  meelPhase("done");
  var titleEl = document.getElementById("meel-done-title");
  if (titleEl) titleEl.textContent = title;
  var navBtns = document.getElementById("meel-nav-btns");
  if (navBtns) {
    var btns = navBtns.getElementsByTagName("a");
    if (btns.length >= 2) {
      btns[1].innerHTML =
        '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Simpan File';
      btns[1].href = downloadUrl;
      btns[1].setAttribute("download", title);
      btns[1].style.color = "#3b82f6";
      btns[1].style.borderColor = "rgba(59,130,246,0.3)";
    }
    var closeBtn = document.createElement("a");
    closeBtn.className = "meel-nav-btn";
    closeBtn.style.cssText =
      "color:rgba(255,255,255,0.5); border-color:rgba(255,255,255,0.1); background:transparent;";
    closeBtn.innerHTML = "Tutup";
    closeBtn.onclick = function () {
      document.getElementById("meel-overlay").style.display = "none";
    };
    navBtns.appendChild(closeBtn);
  }
};
