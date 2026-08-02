/**
 * MEeL - Media Hub Platform
 *
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */
/* ============================================================
 * health-reminder.js — Mode Sehat 20-20-20 (pengingat istirahat mata)
 *
 * Aturan 20-20-20: setiap 20 menit menatap layar, istirahatlah
 * dengan memandang objek sejauh ≥ 6 meter (20 kaki) selama 20 detik.
 *
 * Sejarah: logika ini sebelumnya terbenam dalam satu baris minified
 * di `assets/js/compatibilitas/script.min.js`. Diekstrak ke file
 * terpisah agar mudah dibaca, dirawat, dan diuji. PERILAKU
 * dipertahankan identik dengan versi aslinya (catatan "Asli
 * (minified): <nama fungsi>" pada tiap fungsi), KECUALI satu
 * perbaikan yang disengaja: cabang auto-dismiss 5 menit kini ikut
 * menjadwalkan ulang alarm, konsisten dengan cabang lainnya.
 *
 * State disimpan di localStorage agar timer bertahan lintas halaman:
 *   - `health_reminder`    → "true" = mode aktif
 *   - `health_target_time` → timestamp (ms) kapan alarm berikutnya muncul
 *
 * Dependencies:
 *   - SweetAlert2 (global `Swal`) — menampilkan modal jeda
 *   - lucide                     — ikon di dalam modal
 *   - Pemutar media: Plyr (`window.player`) atau elemen `<video>` /
 *     `<audio>` native (#main-video / #main-player / lainnya)
 *
 * Dimuat otomatis di setiap halaman yang memuat `script.min.js`
 * (via `partials/scripts.php` atau include langsung).
 *
 * Koordinasi antar-tab (cegah alarm ganda saat 2+ tab terbuka):
 *   - Web Locks API      → hanya satu tab yang menampilkan alarm; lock
 *                          ditahan selama rangkaian modal berjalan.
 *   - BroadcastChannel   → tab lain ikut masuk mode jeda (kunci keyboard
 *                          & tolak pemutaran) lalu di-resume saat selesai.
 *   - event 'storage'    → tab lain menyelaraskan timer saat target
 *                          diperbarui (atau berhenti jika mode dimatikan).
 *
 * Mode agresif selama jeda (perilaku diperketat):
 *   - SEMUA input keyboard diblokir (kecuali mengetik di kolom teks),
 *     termasuk Escape, tombol next (n), loop (l), auto-next (a), dst.
 *   - SEMUA pemutaran media ditolak: event play/playing di-intercept di
 *     level document + watchdog berkala mem-pause media apa pun.
 *   - Auto-next & loop di player music/video dijaga langsung di
 *     assets/js/music/* dan assets/js/video/watch/*.
 * ============================================================ */

/* ── State global (variabel global agar konsisten antar fungsi) ─────────── */
var healthReminderTimer = null;          // handle setTimeout untuk alarm
var HEALTH_INTERVAL_MS  = 12e5;          // 1.200.000 ms = 20 menit

/* ── Log debug ───────────────────────────────────────────────────────────
 * Dipakai untuk debugging sinkronisasi timer. Format durasi sisa yang
 * mudah dibaca dari selisih milidetik (mis. "19 menit 42 detik").
 * Hanya memengaruhi console — tidak mengubah perilaku fitur sama sekali. */
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

// true selama modal jeda terbuka — dipakai player musik (mini-player.js)
// untuk memblokir kontrol play/seek/next/prev selama jeda berlangsung.
window.meelHealthAlertActive = false;

// true jika countdown sudah dijadwalkan pada sesi halaman ini (cegah ganda).
window.meelHealthReminderStarted = false;

/* ============================================================
 * 1) TOGGLE — tombol "Mode 20-20-20" di halaman hub (index.php)
 * ============================================================ */

/**
 * Balik status mode sehat ON/OFF lalu simpan ke localStorage.
 * Asli (minified): toggleHealth
 */
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

/**
 * Sinkronkan tampilan tombol #healthToggle (pill hijau "ON" / merah "OFF")
 * dan pasang handler klik pada tombol tersebut.
 * Asli (minified): updateHealthToggleButton
 */
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

/* ============================================================
 * 2) PENJADWALAN — hitung mundur menuju alarm berikutnya
 * ============================================================ */

/**
 * Jadwalkan alarm berikutnya: target = sekarang + 20 menit.
 * Asli (minified): scheduleNextHealthAlert
 */
function scheduleNextHealthAlert() {
  const target = Date.now() + HEALTH_INTERVAL_MS;
  localStorage.setItem("health_target_time", String(target));
  logHealthDebug(
    `Alarm dijadwalkan: target ${new Date(target).toLocaleTimeString()} ` +
    `(+${formatHealthRemaining(HEALTH_INTERVAL_MS)})`
  );
  startHealthCountdown();
}

/**
 * Pasang setTimeout hingga target waktu tercapai. Target tersimpan di
 * localStorage sehingga countdown bertahan walau pindah halaman.
 * Asli (minified): startHealthCountdown
 */
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

/**
 * Inisialisasi reminder pada tiap halaman (dipanggil saat DOM ready).
 * Hanya aktif jika mode sedang ON di localStorage, dan hanya dijalankan
 * sekali per sesi halaman (guard `meelHealthReminderStarted`).
 * Asli (minified): startHealthReminder
 */
function startHealthReminder() {
  if (window.meelHealthReminderStarted) return;
  if (localStorage.getItem("health_reminder") !== "true") return;

  window.meelHealthReminderStarted = true;
  startHealthCountdown();
}

/* ============================================================
 * 3) DETEKSI PLAYER AKTIF
 * ============================================================ */

/**
 * Cek apakah ada media (video/audio) yang sedang diputar.
 * Alarm TIDAK muncul jika tidak ada yang sedang ditonton/didengar —
 * ini menghindari gangguan saat user sekadar browsing.
 * Asli (minified): isPlayerActive
 */
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

/* ============================================================
 * 3b) KOORDINASI ANTAR-TAB — cegah alarm ganda
 * ============================================================ */

// Saluran antar-tab: memberitahu tab lain untuk pause/resume pemutaran saat
// alarm tampil (BroadcastChannel). Jika tidak didukung → diabaikan dengan
// aman (perilaku lama: hanya tab pemenang yang dijeda).
var healthAlertChannel = null;
if (typeof BroadcastChannel !== "undefined") {
  try {
    healthAlertChannel = new BroadcastChannel("meel_health_alert");
    healthAlertChannel.onmessage = function (event) {
      if (event.data === "pause") {
        // Tab lain ikut masuk "mode jeda": kunci keyboard & tolak semua
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

// Media di tab ini yang di-pause karena perintah antar-tab (untuk di-resume
// setelah jeda selesai). null = tidak ada media yang di-pause perintah ini.
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

/**
 * Amankan "lock" antar-tab via Web Locks API: hanya satu tab yang boleh
 * menampilkan alarm pada satu waktu. Lock ditahan selama Promise yang
 * dikembalikan `task` belum selesai (seluruh rangkaian modal).
 * Fallback: browser tanpa Web Locks → `task` langsung dijalankan
 * (perilaku lama / tab tunggal).
 */
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

/* ── Penegakan mode jeda: tolak SEMUA pemutaran media ──
 * Dipakai oleh tab pemenang (saat alarm tampil) dan oleh tab lain
 * (via pesan BroadcastChannel 'pause'). Cara kerja:
 *   1. Intercept event 'play'/'playing' di level document (capture) —
 *      elemen media apa pun yang mencoba berputar langsung di-pause.
 *   2. Watchdog tiap 500 ms — mem-pause ulang media yang lolos
 *      (loop, autoplay programatik, resume otomatis, dsb).
 * Berhenti otomatis saat flag meelHealthAlertActive menjadi false. */
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

/* ============================================================
 * 4) ALARM UTAMA — pause, tampilkan modal 20-20-20, countdown 20s
 * ============================================================ */

/**
 * Pemicu utama mode sehat:
 *   1. Pause pemutar & blokir kontrol selama jeda.
 *   2. Keluar dari fullscreen agar user bisa memandang ke kejauhan.
 *   3. Tampilkan modal instruksi 20-20-20 (auto-dismiss 5 menit).
 *   4. Jika user memilih "SAYA MAU JEDA" → countdown 20 detik.
 *   5. Resume pemutaran + kembali fullscreen + jadwalkan alarm berikutnya.
 *
 * Asli (minified): triggerPremiumHealthAlert
 */
function triggerPremiumHealthAlert() {
  if (typeof Swal === "undefined") {
    console.warn("SweetAlert2 belum ter-load.");
    return;
  }

  // Jika target alarm sudah bergeser ke masa depan, berarti tab lain sudah
  // menangani siklus ini → cukup sinkronkan timer, JANGAN tampilkan alarm
  // lagi (mencegah alarm ganda saat beberapa tab terbuka).
  const targetRaw = localStorage.getItem("health_target_time");
  if (targetRaw && parseInt(targetRaw, 10) - Date.now() > 0) {
    startHealthCountdown();
    return;
  }

  // Tidak ada media yang diputar → tunda 30 detik, coba lagi.
  if (!isPlayerActive()) {
    healthReminderTimer = setTimeout(triggerPremiumHealthAlert, 3e4); // 30.000 ms
    return;
  }

  // Lock antar-tab (Web Locks API): hanya SATU tab yang boleh menampilkan
  // alarm pada satu waktu. Tab yang kalah tidak menampilkan apa pun — ia
  // akan disinkronkan ulang lewat event 'storage' saat tab pemenang selesai.
  acquireHealthAlertLock(() => {
    runHealthAlertFlow();
  });
}

/**
 * Rangkaian alarm lengkap (pause → modal 20-20-20 → countdown → resume +
 * jadwalkan ulang). Hanya dipanggil oleh tab PEMENANG lock. Mengembalikan
 * Promise agar lock ditahan selama proses berlangsung.
 * Asli (minified): isi dari triggerPremiumHealthAlert
 */
function runHealthAlertFlow() {
  // Cari elemen media aktif (urutan: video utama, audio utama, fullscreen, lainnya).
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

  // Tandai jeda aktif (player lain memakai flag ini untuk memblokir kontrol).
  window.meelHealthAlertActive = true;

  // Tolak SEMUA bentuk pemutaran selama jeda (play/playing dari elemen
  // media mana pun, loop, autoplay, dsb.) via listener capture + watchdog.
  startBreakEnforcement();

  // Beri tahu tab lain agar ikut masuk mode jeda (tanpa menampilkan modal).
  broadcastHealthCommand("pause");

  // Play-trap: jika ada upaya play selama modal terbuka, langsung pause lagi.
  const pauseTrap = function () {
    if (window.meelHealthAlertActive) {
      if (window.player) window.player.pause();
      else if (media) media.pause();
    }
  };
  if (window.player) window.player.on("play", pauseTrap);
  else if (media) media.addEventListener("play", pauseTrap);

  // Pause sekarang — catat apakah tadi sedang diputar agar bisa di-resume.
  let wasPlaying = false;
  if (window.player) {
    if (!window.player.paused) { window.player.pause(); wasPlaying = true; }
  } else if (media && !media.paused) {
    media.pause();
    wasPlaying = true;
  }

  // Helper resume: lepas play-trap, lanjutkan putar bila tadi diputar.
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

  // Apakah sedang fullscreen? (keluar saat jeda, masuk lagi setelah selesai)
  const wasFullscreen =
    !!document.fullscreenElement ||
    (window.player && window.player.fullscreen && window.player.fullscreen.active);

  // Helper re-enter fullscreen (dipakai di semua cabang penyelesaian).
  const reenterFullscreen = () => {
    if (!wasFullscreen) return;
    if (window.player && window.player.fullscreen) {
      window.player.fullscreen.enter();
    } else if (media && media.requestFullscreen) {
      media.requestFullscreen().catch((err) => console.log("Fullscreen re-entry:", err));
    }
  };

  // ── Modal 1: instruksi 20-20-20 ──
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
    timer: 3e5, // auto-tutup setelah 5 menit
    customClass: {
      popup: "border border-red-600/25 border-t-2 border-t-red-600 rounded-2xl shadow-2xl",
      title: "text-sm font-black uppercase tracking-wider pt-4 text-red-500",
      htmlContainer: "mt-1 mb-4",
      actions: "flex gap-2 w-full mt-4 px-3",
      confirmButton: "flex-1 bg-red-600 hover:bg-red-500 text-white text-xs font-black uppercase tracking-wider py-2.5 rounded-xl transition-all border-none cursor-pointer",
      cancelButton: "flex-1 bg-white/5 hover:bg-white/10 text-gray-400 text-xs font-black uppercase tracking-wider py-2.5 rounded-xl border border-white/10 cursor-pointer transition-all",
    },
    didOpen: () => {
      if (typeof lucide !== "undefined") lucide.createIcons();
      // Keluar dari fullscreen agar user benar-benar bisa memandang ke kejauhan.
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
      // ── Modal 2: countdown 20 detik relaksasi ──
      let countdownInterval;
      Swal.fire({
        title: "RELAKSASI DIMULAI",
        html: `
                    <div class="text-center space-y-3 py-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-widest">Arahkan pandangan Anda ke kejauhan sekarang!</p>
                        <div class="text-4xl font-mono font-black text-green-500 tracking-wider">
                            <span id="countdown-sec">20</span>s
                        </div>
                        <div class="w-full bg-white/[0.04] h-1.5 rounded-full overflow-hidden">
                            <div id="countdown-bar" class="bg-green-500 h-full w-full transition-all duration-1000 ease-linear"></div>
                        </div>
                    </div>
                `,
        background: "#141820",
        color: "#ffffff",
        timer: 2e4, // auto-tutup setelah 20 detik
        timerProgressBar: false,
        showConfirmButton: false,
        allowOutsideClick: false,
        customClass: {
          popup: "border border-green-600/25 border-t-2 border-t-green-500 rounded-2xl shadow-2xl",
          title: "text-xs font-black uppercase tracking-widest pt-4 text-green-400",
        },
        didOpen: () => {
          let sec = 20;
          const secEl = document.getElementById("countdown-sec");
          const barEl = document.getElementById("countdown-bar");
          countdownInterval = setInterval(() => {
            sec--;
            if (secEl) secEl.innerText = sec;
            if (barEl) barEl.style.width = (sec / 20) * 100 + "%";
          }, 1000);
        },
        willClose: () => {
          clearInterval(countdownInterval);
        },
      }).then(() => {
        // ── Modal 3: konfirmasi selesai, lalu resume ──
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
      // Auto-dismiss 5 menit (user tidak merespons) → resume & jadwalkan
      // alarm berikutnya, konsisten dengan cabang confirm/cancel.
      // (Perbaikan disengaja: versi minified asli TIDAK menjadwalkan ulang
      // di cabang ini, sehingga alarm berhenti total sampai halaman di-reload.)
      resume();
      reenterFullscreen();
      scheduleNextHealthAlert();
    }
  });
}

/* ============================================================
 * 5) BLOKIR SHORTCUT PLAYER — selama modal jeda terbuka
 * ============================================================ */

// Mode agresif: blokir SEMUA input keyboard selama modal jeda terbuka.
// - Tombol shortcut player apa pun (spasi, k, j, l, m, f, n, panah, dst.)
// - Escape (mencegah keluar paksa dari modal sebelum istirahat selesai)
// - Tombol lain apa pun yang bisa memicu auto-next / loop / expand player
// Kecuali: mengetik di kolom teks (INPUT/TEXTAREA/contenteditable) agar
// user tidak terkunci saat mengetik komentar di tab lain.
// Dipasang di window (capture) DAN document (capture) agar menang lebih
// dulu dari handler player mana pun — health-reminder.js dimuat lebih awal
// daripada script player di halaman music/video.
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

/* ============================================================
 * 6) INIT — jalankan saat DOM siap di tiap halaman
 * ============================================================ */
document.addEventListener("DOMContentLoaded", () => {
  startHealthReminder();      // lanjutkan countdown dari localStorage (jika mode ON)
  updateHealthToggleButton(); // sinkronkan tombol toggle (halaman hub)
});

// Sinkronisasi antar-tab: event 'storage' hanya terpicu di tab LAIN ketika
// sebuah tab menulis ke localStorage. Tab yang kalah lock memakai ini untuk
// menyelaraskan timer begitu tab pemenang selesai (target diperbarui), atau
// berhenti total jika mode dimatikan dari tab lain.
window.addEventListener("storage", function (e) {
  if (e.key !== "health_target_time" && e.key !== "health_reminder") return;
  if (localStorage.getItem("health_reminder") === "true") {
    window.meelHealthReminderStarted = true;
    startHealthCountdown();
  } else {
    clearTimeout(healthReminderTimer);
    window.meelHealthReminderStarted = false;
  }
});
