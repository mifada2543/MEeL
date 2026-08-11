/** MEeL - Media Hub Platform
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 */
/* health-reminder.js — Mode Sehat 20-20-20 (pengingat istirahat mata) */
/* ─── State global (variabel global agar konsisten antar fungsi) ─── */
var healthReminderTimer = null;
var HEALTH_INTERVAL_MS  = 12e5;          // 1.200.000 ms = 20 menit
function formatHealthRemaining(ms) {
  const totalSec = Math.max(0, Math.floor(ms / 1000));
  const m = Math.floor(totalSec / 60);
  const s = totalSec % 60;
  return m > 0 ? `${m} menit ${s} detik` : `${s} detik`;
}

function logHealthDebug(msg) {
  if (typeof console !== "undefined" && console.log) {
    console.log("[health-reminder]", msg);
  }
}
window.meelHealthAlertActive = false;
window.meelHealthReminderStarted = false;
/* 1) TOGGLE — tombol "Mode 20-20-20" di halaman hub (index.php) */
/* Balik status mode sehat ON/OFF lalu simpan ke localStorage. */
function toggleHealth() {
  const enabled = !(localStorage.getItem("health_reminder") === "true");
  localStorage.setItem("health_reminder", String(enabled));
  updateHealthToggleButton();
  if (enabled) {
    // ON → jadwalkan alarm pertama 20 menit lagi.
    scheduleNextHealthAlert();
  } else {
    // OFF → batalkan timer & bersihkan target waktu.
    clearTimeout(healthReminderTimer);
    localStorage.removeItem("health_target_time");
    window.meelHealthReminderStarted = false;
  }
}
/* Sinkronkan tampilan tombol #healthToggle (pill hijau "ON" / merah "OFF") */
function updateHealthToggleButton() {
  const btn = document.getElementById("healthToggle");
  if (!btn) return;
  btn.onclick = toggleHealth;
  const on = localStorage.getItem("health_reminder") === "true";
  btn.classList.remove(
    "bg-green-500/20", "text-green-500",
    "bg-red-500/20",   "text-red-500",
    "text-gray-700"
  );
  if (on) {
    btn.classList.add("bg-green-500/20", "text-green-500");
    btn.innerText = "ON";
  } else {
    btn.classList.add("bg-red-500/20", "text-red-500");
    btn.innerText = "OFF";
  }
}
/* 2) PENJADWALAN — hitung mundur menuju alarm berikutnya */

/* Jadwalkan alarm berikutnya: target = sekarang + 20 menit. */
function scheduleNextHealthAlert() {
  const target = Date.now() + HEALTH_INTERVAL_MS;
  localStorage.setItem("health_target_time", String(target));
  logHealthDebug(
    `Alarm dijadwalkan: target ${new Date(target).toLocaleTimeString()} ` +
    `(+${formatHealthRemaining(HEALTH_INTERVAL_MS)})`
  );
  startHealthCountdown();
}

function startHealthCountdown() {
  clearTimeout(healthReminderTimer);

  const targetRaw = localStorage.getItem("health_target_time");
  if (!targetRaw) {
    scheduleNextHealthAlert(); // tidak ada target → buat target baru
    return;
  }

  const remaining = parseInt(targetRaw, 10) - Date.now();
  if (remaining <= 0) {
    logHealthDebug("Target sudah lewat → langsung menampilkan alarm.");
    triggerPremiumHealthAlert(); // sudah lewat → langsung munculkan alarm
  } else {
    logHealthDebug(
      `Anda akan diingatkan health-reminder dalam ${formatHealthRemaining(remaining)} ` +
      `(target ${new Date(parseInt(targetRaw, 10)).toLocaleTimeString()})`
    );
    healthReminderTimer = setTimeout(triggerPremiumHealthAlert, remaining);
  }
}

/* Inisialisasi reminder pada tiap halaman (dipanggil saat DOM ready). */
function startHealthReminder() {
  if (window.meelHealthReminderStarted) return;
  if (localStorage.getItem("health_reminder") !== "true") return;

  window.meelHealthReminderStarted = true;
  startHealthCountdown();
}

/* 3) DETEKSI PLAYER AKTIF */

/* Cek apakah ada media (video/audio) yang sedang diputar. */
function isPlayerActive() {
  const hasAnyMedia =
    window.player ||
    document.getElementById("main-video") ||
    document.getElementById("main-player") ||
    document.querySelectorAll("video, audio").length > 0;
  if (!hasAnyMedia) return false;

  // Player Plyr aktif & tidak paused.
  if (window.player && !window.player.paused) return true;

  // Elemen media utama halaman (termasuk di dalam fullscreen).
  const main =
    document.getElementById("main-video") ||
    document.getElementById("main-player") ||
    (document.fullscreenElement &&
      document.fullscreenElement.querySelector("video, audio"));
  if (main && !main.paused) return true;

  // Elemen media lain mana pun di halaman.
  const media = document.querySelectorAll("video, audio");
  for (const el of media) {
    if (!el.paused) return true;
  }

  return false;
}

/* 3b) KOORDINASI ANTAR-TAB — cegah alarm ganda */

var healthAlertChannel = null;
if (typeof BroadcastChannel !== "undefined") {
  try {
    healthAlertChannel = new BroadcastChannel("meel_health_alert");
    healthAlertChannel.onmessage = function (event) {
      if (event.data === "pause") {
        // pemutaran di tab ini juga (bukan hanya pause sekali).
        window.meelHealthAlertActive = true;
        startBreakEnforcement();
        pauseMediaForHealthBreak();
      } else if (event.data === "resume") {
        stopBreakEnforcement();
        window.meelHealthAlertActive = false;
        resumeMediaAfterHealthBreak();
      }
    };
  } catch (e) {
    healthAlertChannel = null;
  }
}

var healthPausedMedia = null;

function broadcastHealthCommand(cmd) {
  if (healthAlertChannel) {
    try { healthAlertChannel.postMessage(cmd); } catch (e) { /* abaikan */ }
  }
}

function pauseMediaForHealthBreak() {
  if (healthPausedMedia) return;
  if (window.player && !window.player.paused) {
    window.player.pause();
    healthPausedMedia = { player: true };
    return;
  }
  const all = document.querySelectorAll("video, audio");
  for (const el of all) {
    if (!el.paused) {
      el.pause();
      healthPausedMedia = { el: el };
      return;
    }
  }
}

function resumeMediaAfterHealthBreak() {
  if (!healthPausedMedia) return;
  const m = healthPausedMedia;
  healthPausedMedia = null;
  if (m.player && window.player) window.player.play().catch(() => {});
  else if (m.el) m.el.play().catch(() => {});
}

/* Amankan "lock" antar-tab via Web Locks API: hanya satu tab yang boleh */
function acquireHealthAlertLock(task) {
  if (
    typeof navigator !== "undefined" &&
    navigator.locks &&
    typeof navigator.locks.request === "function"
  ) {
    var ran = false;
    navigator.locks
      .request("meel_health_alert", { ifAvailable: true }, function (lock) {
        if (!lock) return; // tab lain sedang menangani alarm → lewati
        ran = true;
        return task(); // Promise dari task → lock ditahan sampai selesai
      })
      .catch(function () {
        // Error tak terduga pada API lock → fallback perilaku lama,
        // tapi hanya jika lock memang belum pernah dijalankan.
        if (!ran) task();
      });
    return;
  }
  task(); // tanpa Web Locks → perilaku lama
}

/* ─── Penegakan mode jeda: tolak SEMUA pemutaran media ─── */
var breakEnforceTimer = null;
var breakPlayBlock = null;

function startBreakEnforcement() {
  if (breakEnforceTimer) return; // sudah berjalan di tab ini
  breakPlayBlock = function (e) {
    if (!window.meelHealthAlertActive) return;
    const t = e && e.target;
    if (t && (t.tagName === "VIDEO" || t.tagName === "AUDIO") && !t.paused) {
      t.pause();
    }
    if (window.player && !window.player.paused) window.player.pause();
  };
  document.addEventListener("play", breakPlayBlock, true);
  document.addEventListener("playing", breakPlayBlock, true);
  breakEnforceTimer = setInterval(function () {
    if (!window.meelHealthAlertActive) {
      stopBreakEnforcement();
      return;
    }
    document.querySelectorAll("video, audio").forEach(function (el) {
      if (!el.paused) el.pause();
    });
    if (window.player && !window.player.paused) window.player.pause();
  }, 500);
}

function stopBreakEnforcement() {
  if (breakEnforceTimer) {
    clearInterval(breakEnforceTimer);
    breakEnforceTimer = null;
  }
  if (breakPlayBlock) {
    document.removeEventListener("play", breakPlayBlock, true);
    document.removeEventListener("playing", breakPlayBlock, true);
    breakPlayBlock = null;
  }
}

/* 3c) MODE MEMBACA (books) — BANNER 20-20-20 yang lembut */

var healthReadingLastActivity = 0;    // ts (ms) aktivitas baca terakhir
var HEALTH_READING_IDLE_MS = 6e4;     // 60.000 ms = 1 menit idle
var healthBannerEl = null;            // elemen banner yang sedang tampil
var healthBannerHideTimer = null;     // timer auto-hide banner (20 detik)
var healthBannerResolve = null;       // resolve Promise lock banner
var healthBannerRemoving = false;     // true saat banner sedang fade-out
var HEALTH_BANNER_MS = 2e4;           // 20.000 ms banner tampil

/* * Mode membaca aktif? (diset halaman books via window flag). */
function isReadingModeActive() {
  return window.meelHealthActivityMode === "reading";
}

/* * Tandai aktivitas baca terakhir (dipanggil tiap scroll/klik/ketik). */
function markHealthReadingActivity() {
  healthReadingLastActivity = Date.now();
}

/* Apakah user sedang aktif membaca? (aktivitas dalam 60 detik terakhir */
function isReadingActivityActive() {
  if (document.hidden) return false; // tab tidak aktif → jangan ganggu
  if (!healthReadingLastActivity) return true;
  return Date.now() - healthReadingLastActivity <= HEALTH_READING_IDLE_MS;
}

/* * Pasang pendeteksi aktivitas baca (hanya jika mode reading). */
function setupReadingActivityTracking() {
  if (!isReadingModeActive()) return;
  var mark = markHealthReadingActivity;
  document.addEventListener("scroll", mark, { passive: true });
  document.addEventListener("keydown", mark, { passive: true });
  document.addEventListener("click", mark, { passive: true });
  markHealthReadingActivity();
}

/* Tampilkan banner 20-20-20 (mode membaca). Mengembalikan Promise agar */
function runReadingHealthBanner() {
  if (healthBannerEl || healthBannerRemoving) return Promise.resolve(); // sudah ada banner

  // Suntikkan animasi slide (sekali saja).
  if (!document.getElementById("meel-health-banner-style")) {
    var st = document.createElement("style");
    st.id = "meel-health-banner-style";
    st.textContent =
      "@keyframes meelBannerSlideIn{from{transform:translateX(-50%) translateY(-130%);opacity:0}" +
      "to{transform:translateX(-50%) translateY(0);opacity:1}}" +
      "@keyframes meelBannerFadeOut{to{transform:translateX(-50%) translateY(-130%);opacity:0}}";
    (document.head || document.body).appendChild(st);
  }

  var banner = document.createElement("div");
  banner.id = "meel-health-banner";
  banner.setAttribute("role", "status");
  banner.style.cssText =
    "position:fixed;top:16px;left:50%;transform:translateX(-50%) translateY(-130%);" +
    "z-index:2147483000;width:min(92vw,430px);background:#141820;" +
    "border:1px solid rgba(16,185,129,.35);border-top:2px solid #10b981;" +
    "border-radius:14px;box-shadow:0 14px 44px rgba(0,0,0,.6);" +
    "padding:14px 14px 12px;display:flex;align-items:flex-start;gap:12px;" +
    "overflow:hidden;" + // garis progress di top:0 tidak keluar dari sudut membulat
    "color:#fff;font-family:ui-sans-serif,system-ui,sans-serif;";
  banner.innerHTML =
    // transisi linear berjalan dari penuh → kosong.
    '<div id="meel-health-banner-progress" style="position:absolute;top:0;left:0;' +
    'height:3px;width:100%;background:#10b981;' +
    'transition:width ' + (HEALTH_BANNER_MS / 1000) + 's linear;"></div>' +
    '<div style="flex-shrink:0;width:34px;height:34px;border-radius:10px;' +
    'background:rgba(16,185,129,.15);display:flex;align-items:center;justify-content:center;">' +
    '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" ' +
    'stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
    '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>' +
    '</svg></div>' +
    '<div style="flex:1;min-width:0;">' +
    '<div style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.12em;' +
    'color:#10b981;">20-20-20 &middot; Istirahat Mata</div>' +
    '<div style="font-size:12px;line-height:1.5;color:#cbd5e1;margin-top:3px;">' +
    'Anda telah membaca 20 menit. Pandang objek sejauh &plusmn;6 meter (20 kaki) ' +
    'selama 20 detik untuk menyegarkan mata.</div>' +
    '</div>' +
    '<button class="meel-health-banner-close" aria-label="Tutup" ' +
    'style="flex-shrink:0;background:rgba(255,255,255,.06);border:none;color:#94a3b8;' +
    'width:26px;height:26px;border-radius:8px;cursor:pointer;font-size:13px;line-height:1;">' +
    '&times;</button>';

  (document.body || document.head).appendChild(banner);
  healthBannerEl = banner;

  if (typeof requestAnimationFrame !== "undefined") {
    requestAnimationFrame(function () {
      banner.style.animation = "meelBannerSlideIn .35s ease forwards";
      // 20 detik — transisi linear, sinkron dengan timer auto-hide.
      var prog = banner.querySelector("#meel-health-banner-progress");
      if (prog) prog.style.width = "0%";
    });
  } else {
    banner.style.animation = "meelBannerSlideIn .35s ease forwards";
    var progFallback = banner.querySelector("#meel-health-banner-progress");
    if (progFallback) progFallback.style.width = "0%";
  }

  healthBannerHideTimer = setTimeout(dismissHealthReadingBanner, HEALTH_BANNER_MS);
  var closeBtn = banner.querySelector(".meel-health-banner-close");
  if (closeBtn) closeBtn.addEventListener("click", dismissHealthReadingBanner);

  logHealthDebug("Banner 20-20-20 tampil (mode membaca).");
  return new Promise(function (resolve) {
    healthBannerResolve = resolve;
  });
}

/* Tutup/hilangkan banner, lalu jadwalkan alarm berikutnya. */
function dismissHealthReadingBanner(doSchedule) {
  if (!healthBannerEl || healthBannerRemoving) return;
  clearTimeout(healthBannerHideTimer);
  healthBannerHideTimer = null;
  var b = healthBannerEl;
  healthBannerEl = null;
  healthBannerRemoving = true; // cegah banner baru menumpuk saat fade-out
  b.style.animation = "meelBannerFadeOut .3s ease forwards";
  setTimeout(function () {
    if (b.parentNode) b.parentNode.removeChild(b);
    healthBannerRemoving = false;
  }, 300);
  if (doSchedule !== false) scheduleNextHealthAlert();
  logHealthDebug("Banner ditutup.");
  if (healthBannerResolve) {
    var r = healthBannerResolve;
    healthBannerResolve = null;
    r();
  }
}

/* Jalur ramping untuk pagehide: halaman sedang dibongkar, jadi animasi */
function finalizeReadingBannerOnLeave() {
  if (!healthBannerEl || healthBannerRemoving) return;
  clearTimeout(healthBannerHideTimer);
  healthBannerHideTimer = null;
  var b = healthBannerEl;
  healthBannerEl = null;
  healthBannerRemoving = false;
  if (b.parentNode) b.parentNode.removeChild(b);
  scheduleNextHealthAlert();
  logHealthDebug("Banner dilewati (pindah halaman) → jadwal ulang +20 menit.");
  if (healthBannerResolve) {
    var r = healthBannerResolve;
    healthBannerResolve = null;
    r();
  }
}

/* 4) ALARM UTAMA — pause, tampilkan modal 20-20-20, countdown 20s */

/* Pemicu utama mode sehat: */
function triggerPremiumHealthAlert() {
  // lagi (mencegah alarm ganda saat beberapa tab terbuka).
  const targetRaw = localStorage.getItem("health_target_time");
  if (targetRaw && parseInt(targetRaw, 10) - Date.now() > 0) {
    startHealthCountdown();
    return;
  }

  // Tidak ada media yang diputar:
  if (!isPlayerActive()) {
    // tampilkan BANNER lembut jika user sedang aktif membaca.
    if (isReadingModeActive() && isReadingActivityActive()) {
      acquireHealthAlertLock(() => runReadingHealthBanner());
      return;
    }

    healthReminderTimer = setTimeout(triggerPremiumHealthAlert, 3e4); // 30.000 ms
    return;
  }

  // Ada media diputar → jalur modal butuh SweetAlert2.
  if (typeof Swal === "undefined") {
    console.warn("SweetAlert2 belum ter-load.");
    return;
  }

  acquireHealthAlertLock(() => {
    runHealthAlertFlow();
  });
}

/* Rangkaian alarm lengkap (pause → modal 20-20-20 → countdown → resume + */
function runHealthAlertFlow() {

  let media = document.getElementById("main-video") || document.getElementById("main-player");
  if (!media && document.fullscreenElement) {
    media = document.fullscreenElement.querySelector("video, audio");
  }
  if (!media) {
    const all = document.querySelectorAll("video, audio");
    for (const el of all) {
      if (!el.paused) { media = el; break; }
    }
  }

  window.meelHealthAlertActive = true;

  startBreakEnforcement();

  broadcastHealthCommand("pause");

  const pauseTrap = function () {
    if (window.meelHealthAlertActive) {
      if (window.player) window.player.pause();
      else if (media) media.pause();
    }
  };
  if (window.player) window.player.on("play", pauseTrap);
  else if (media) media.addEventListener("play", pauseTrap);

  let wasPlaying = false;
  if (window.player) {
    if (!window.player.paused) { window.player.pause(); wasPlaying = true; }
  } else if (media && !media.paused) {
    media.pause();
    wasPlaying = true;
  }

  const resume = () => {
    window.meelHealthAlertActive = false;
    stopBreakEnforcement();
    if (window.player) window.player.off("play", pauseTrap);
    else if (media) media.removeEventListener("play", pauseTrap);
    if (wasPlaying) {
      if (window.player) window.player.play().catch(() => {});
      else if (media) media.play().catch(() => {});
    }
    // Tab lain ikut di-resume setelah jeda selesai.
    broadcastHealthCommand("resume");
  };

  const wasFullscreen =
    !!document.fullscreenElement ||
    (window.player && window.player.fullscreen && window.player.fullscreen.active);

  const reenterFullscreen = () => {
    if (!wasFullscreen) return;
    if (window.player && window.player.fullscreen) {
      window.player.fullscreen.enter();
    } else if (media && media.requestFullscreen) {
      media.requestFullscreen().catch((err) => console.log("Fullscreen re-entry:", err));
    }
  };

  // ─── Modal 1: instruksi 20-20-20 ───
  return Swal.fire({
    title: "WAKTUNYA ISTIRAHATKAN MATA!",
    html: `
            <div class="text-center space-y-4">
                <p class="text-[10px] text-gray-500 uppercase tracking-widest leading-relaxed">
                    Anda telah menonton selama <span class="text-red-400 font-bold font-mono">20 Menit</span>.
                    Mari cegah mata lelah dengan metode <span class="text-green-500 font-bold">20-20-20</span>.
                </p>
                <div class="bg-black/40 border border-white/[.04] p-3 rounded-xl text-left space-y-1.5">
                    <div class="flex items-center gap-2 text-xs text-gray-300 font-bold">
                        <i data-lucide="eye-off" class="w-4 h-4 text-red-500"></i>
                        <span>Langkah Istirahat:</span>
                    </div>
                    <ol class="list-decimal list-inside text-[11px] text-gray-400 space-y-1 pl-1">
                        <li>Hentikan tatapan ke layar perangkat.</li>
                        <li>Pandang objek sejauh minimal 6 meter (20 kaki).</li>
                        <li>Fokuskan mata Anda di sana selama 20 detik.</li>
                    </ol>
                </div>
            </div>
        `,
    icon: "warning",
    iconColor: "#dc2626",
    background: "#141820",
    color: "#ffffff",
    showCancelButton: true,
    confirmButtonText: "SAYA MAU JEDA",
    cancelButtonText: "LANJUT NONTON",
    reverseButtons: true,
    buttonsStyling: false,
    allowOutsideClick: false,
    timer: 3e5, // 300.000 ms = 5 menit (auto-tutup jika user tidak merespons)
    timerProgressBar: true,
    customClass: {
      popup: "border border-red-600/25 border-t-2 border-t-red-600 rounded-2xl shadow-2xl",
      title: "text-sm font-black uppercase tracking-wider pt-4 text-red-500",
      htmlContainer: "mt-1 mb-4",
      actions: "flex gap-2 w-full mt-4 px-3",
      confirmButton: "flex-1 bg-red-600 hover:bg-red-500 text-white text-xs font-black uppercase tracking-wider py-2.5 rounded-xl transition-all border-none cursor-pointer",
      cancelButton: "flex-1 bg-white/5 hover:bg-white/10 text-gray-400 text-xs font-black uppercase tracking-wider py-2.5 rounded-xl border border-white/10 cursor-pointer transition-all",
    },
    didOpen: () => {

      const tpBar = document.querySelector(".swal2-timer-progress-bar");
      if (tpBar) tpBar.style.background = "#dc2626";
      if (typeof lucide !== "undefined") lucide.createIcons();

      if (wasFullscreen) {
        if (window.player && window.player.fullscreen && window.player.fullscreen.active) {
          window.player.fullscreen.exit();
        } else if (document.fullscreenElement) {
          document.exitFullscreen().catch(() => {});
        }
      }
    },
  }).then((result) => {
    if (result.isConfirmed) {
      // ─── Modal 2: countdown 20 detik relaksasi ───
      let countdownInterval;
      Swal.fire({
        title: "RELAKSASI DIMULAI",
        html: `
                    <div class="text-center space-y-3 py-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-widest">Arahkan pandangan Anda ke kejauhan sekarang!</p>
                        <div class="text-4xl font-mono font-black text-green-500 tracking-wider">
                            <span id="countdown-sec">20</span>s
                        </div>
                    </div>
                `,
        background: "#141820",
        color: "#ffffff",
        timer: 2e4, // 20.000 ms = 20 detik (durasi relaksasi 20-20-20)
        timerProgressBar: true,
        showConfirmButton: false,
        allowOutsideClick: false,
        customClass: {
          popup: "border border-green-600/25 border-t-2 border-t-green-500 rounded-2xl shadow-2xl",
          title: "text-xs font-black uppercase tracking-widest pt-4 text-green-400",
        },
        didOpen: () => {
          // Warna progress bar bawaan mengikuti tema hijau relaksasi.
          const tpBar = document.querySelector(".swal2-timer-progress-bar");
          if (tpBar) tpBar.style.background = "#10b981";
          let sec = 20;
          const secEl = document.getElementById("countdown-sec");
          countdownInterval = setInterval(() => {
            sec--;
            if (secEl) secEl.innerText = sec;
          }, 1000);
        },
        willClose: () => {
          clearInterval(countdownInterval);
        },
      }).then(() => {
        // ─── Modal 3: konfirmasi selesai, lalu resume ───
        Swal.fire({
          title: "SELESAI!",
          text: "Mata Anda kembali bugar. Selamat menonton kembali!",
          icon: "success",
          iconColor: "#10b981",
          background: "#141820",
          color: "#ffffff",
          timer: 2e3,
          timerProgressBar: true,
          showConfirmButton: false,
          buttonsStyling: false,
          customClass: {
            popup: "border border-green-600/25 border-t-2 border-t-green-500 rounded-2xl shadow-2xl w-auto",
            title: "text-xs font-black uppercase tracking-widest pt-4 text-green-400",
            htmlContainer: "text-[11px] text-gray-400 uppercase tracking-wider",
          },
        }).then(() => {
          resume();
          reenterFullscreen();
          scheduleNextHealthAlert();
        });
      });
    } else if (result.dismiss === Swal.DismissReason.cancel) {
      // "LANJUT NONTON" → resume & jadwalkan alarm berikutnya.
      resume();
      reenterFullscreen();
      scheduleNextHealthAlert();
    } else if (result.dismiss === Swal.DismissReason.timer) {
      // alarm berikutnya, konsisten dengan cabang confirm/cancel.
      resume();
      reenterFullscreen();
      scheduleNextHealthAlert();
    }
  });
}

/* 5) BLOKIR SHORTCUT PLAYER — selama modal jeda terbuka */

function meelHealthKeydownBlock(e) {
  if (!window.meelHealthAlertActive) return;
  const t = e && e.target;
  if (
    t &&
    (t.tagName === "INPUT" ||
      t.tagName === "TEXTAREA" ||
      t.isContentEditable)
  ) {
    return; // jangan blokir pengetikan
  }
  e.preventDefault();
  e.stopPropagation();
}
window.addEventListener("keydown", meelHealthKeydownBlock, true);
document.addEventListener("keydown", meelHealthKeydownBlock, true);

/* 6) INIT — jalankan saat DOM siap di tiap halaman */
document.addEventListener("DOMContentLoaded", () => {
  startHealthReminder();      // lanjutkan countdown dari localStorage (jika mode ON)
  updateHealthToggleButton(); // sinkronkan tombol toggle (halaman hub)
  setupReadingActivityTracking(); // mode membaca (books): deteksi idle 60s
});

window.addEventListener("storage", function (e) {
  if (e.key !== "health_target_time" && e.key !== "health_reminder") return;
  if (localStorage.getItem("health_reminder") === "true") {
    window.meelHealthReminderStarted = true;
    startHealthCountdown();
  } else {
    clearTimeout(healthReminderTimer);
    window.meelHealthReminderStarted = false;

    dismissHealthReadingBanner(false);
  }
});

window.addEventListener("pagehide", function () {
  finalizeReadingBannerOnLeave();
});
