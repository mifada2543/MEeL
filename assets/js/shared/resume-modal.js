/* ============================================================
 * resume-modal.js — Modal "Lanjutkan Sesi?" bersama untuk video
 * & music watch. Satu sumber kebenaran untuk logika resume:
 * cek posisi tersimpan (>10s & < duration-margin), countdown 15s
 * auto-restart, tombol Lanjut/Ulangi.
 *
 * API: window.meelResumeModal({
 *   storageKey,         // key localStorage posisi tersimpan
 *   durationMargin,     // margin detik vs player.duration (video:10, music:5)
 *   countdownPrefix,    // teks countdown, mis. "Otomatis ulang dari awal dalam"
 *   countdownDoneText,  // teks saat countdown habis (music); null/omit utk video
 *   onResume(pos),      // dipanggil saat klik "Lanjut" (pos = parseFloat(saved))
 *   onRestart(),        // dipanggil saat klik "Ulangi" ATAU auto-restart 15s
 *   onShow(),           // opsional: dipanggil setelah modal tampil (mis. matikan autoplay + pre-seek)
 *   skipOnce(),         // opsional: truthy → skip modal sekali (clear flag sendiri)
 * })
 *
 * Mengembalikan true jika modal ditampilkan, false jika tidak
 * (caller lanjut ke play normal + detector). Elemen yang dipakai:
 * #resume-modal, #btn-resume, #btn-restart, #resume-time,
 * #resume-countdown (dibuat otomatis jika belum ada — pola music).
 * Depends on: shared/format-time.js (formatTime)
 * ============================================================ */

window.meelResumeModal = function (options) {
  const o = options || {};
  const modal = document.getElementById("resume-modal"),
    btnResume = document.getElementById("btn-resume"),
    btnRestart = document.getElementById("btn-restart"),
    timeEl = document.getElementById("resume-time");
  if (!modal || !btnResume || !btnRestart || !timeEl) return false;
  if (o.skipOnce && o.skipOnce()) return false;

  const saved = localStorage.getItem(o.storageKey);
  if (!saved || parseFloat(saved) <= 10) return false;
  if (
    window.player &&
    window.player.duration &&
    parseFloat(saved) >= window.player.duration - (o.durationMargin ?? 5)
  )
    return false;

  // Elemen countdown — pakai yang sudah ada di HTML (video), atau buat
  // baru setelah parent #resume-time (pola music/watch.php).
  let countdownEl = document.getElementById("resume-countdown");
  if (!countdownEl) {
    countdownEl = document.createElement("p");
    countdownEl.id = "resume-countdown";
    countdownEl.className = "text-[9px] text-gray-500 italic mb-4";
    const anchor = timeEl.parentNode || modal;
    anchor.after(countdownEl);
  }

  // Format waktu mm:ss — util bersama shared/format-time.js
  timeEl.innerText = formatTime(parseFloat(saved));
  modal.classList.remove("hidden");
  o.onShow && o.onShow();

  let userChose = false;
  const prefix = o.countdownPrefix || "Otomatis putar dari awal dalam",
    doneText = o.countdownDoneText || null;
  let sec = 15;
  countdownEl.innerText = `${prefix} ${sec}s...`;
  const countdownTimer = setInterval(() => {
    sec--;
    if (sec > 0) countdownEl.innerText = `${prefix} ${sec}s...`;
    else if (doneText) {
      countdownEl.innerText = doneText;
      clearInterval(countdownTimer);
    } else clearInterval(countdownTimer);
  }, 1e3);
  const autoRestartTimer = setTimeout(() => {
    if (userChose || modal.classList.contains("hidden")) return;
    clearInterval(countdownTimer);
    modal.classList.add("hidden");
    o.onRestart && o.onRestart();
  }, 15e3);

  const cleanup = () => {
    userChose = true;
    clearInterval(countdownTimer);
    clearTimeout(autoRestartTimer);
    modal.classList.add("hidden");
  };
  // Pakai onclick property (bukan addEventListener) agar tidak menumpuk
  // jika helper dipanggil ulang (mis. setelah recovery re-init).
  btnResume.onclick = () => {
    cleanup();
    o.onResume && o.onResume(parseFloat(saved));
  };
  btnRestart.onclick = () => {
    cleanup();
    o.onRestart && o.onRestart();
  };

  return true;
};
