/* resume-modal.js — Modal "Lanjutkan Sesi?" bersama untuk video */
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
  let countdownEl = document.getElementById("resume-countdown");
  if (!countdownEl) {
    countdownEl = document.createElement("p");
    countdownEl.id = "resume-countdown";
    countdownEl.className = "text-[9px] text-gray-500 italic mb-4";
    const anchor = timeEl.parentNode || modal;
    anchor.after(countdownEl);
  }
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
