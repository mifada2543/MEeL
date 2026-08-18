/* state.js — Konfigurasi player & seluruh state global (shared) */
const config = window.playerConfig || {};
let videoElement,
  player,
  hls,
  videoSrc = config.videoSrc || "",
  isHls = config.isHls || !1,
  vttSrc = config.vttSrc || "",
  videoId = config.id || "",
  videoTitle = config.title || "",
  videoUploader = config.uploader || "",
  storageKeyVideo = `video_pos_${videoId}`,
  isAutoRecovering = !1,
  isRecovering = !1,
  isCheckingStatus = !1,
  waitingTimeout = null,
  recoveryRetryCount = 0;
const MAX_RECOVERY_RETRIES = 20;
let lastSuccessfulRecovery = 0;
const POST_RECOVERY_COOLDOWN_MS = 5e3;
let hasEverPlayed = !1,
  playbackStartTimeout = null;
const PLAYBACK_START_TIMEOUT_MS = 2e4;
let recoveryDelay = 1e4,
  lastRecoveryTime = 0,
  recoveryTimeoutId = null,
  playbackStartTimestamp = 0,
  lastLocalStorageSave = 0;
const LOCAL_STORAGE_THROTTLE_MS = 5e3;
let lastPlayTime = -1,
  lastTimeUpdateTimestamp = Date.now(),
  stuckCheckInterval = null,
  isTransitioningNext = !1,
  nextVideoTransitionId = 0;
const isTouchDevice = "ontouchstart" in window || navigator.maxTouchPoints > 0;
let glowRAF = null,
  glowLastSampleTime = 0;
const GLOW_W = 16,
  GLOW_H = 9,
  GLOW_SAMPLE_INTERVAL = 120,
  GLOW_LERP_FACTOR = 0.1;
let glowTargetData = new Float32Array(GLOW_W * GLOW_H * 4),
  glowCurData = new Float32Array(GLOW_W * GLOW_H * 4),
  glowStartFn = null,
  glowStopFn = null,
  glowEnabled = "false" !== localStorage.getItem(MEEL_KEYS.GLOW_ENABLED),
  glowNavbar = null;
/* Auto Next   */
const _meelAutoNavFlag = sessionStorage.getItem(MEEL_KEYS.AUTONAV) === "1";
sessionStorage.removeItem(MEEL_KEYS.AUTONAV);
let autoNextEnabled = localStorage.getItem(MEEL_KEYS.AUTONEXT_ENABLED) === "true";
window.lucide && lucide.createIcons();
const plyrOptions = {
    ...MEEL_PLYR_COMMON,
    controls: [
      "play-large",
      "play",
      "progress",
      "current-time",
      "duration",
      "mute",
      "volume",
      "captions",
      "settings",
      "pip",
      "airplay",
      "fullscreen",
    ],
    settings: ["quality", "speed"],
    i18n: {
      play: "Putar video",
      pause: "Jeda video",
      restart: "Putar ulang",
      rewind: "Mundur 10 detik",
      forward: "Maju 10 detik",
      seek: "Cari posisi",
      currentTime: "Waktu saat ini",
      duration: "Durasi",
      volume: "Volume",
      mute: "Bisukan",
      unmute: "Suarakan",
      captions: "Teks",
      settings: "Pengaturan",
      fullscreen: "Layar penuh",
      exitFullscreen: "Keluar layar penuh",
      pip: "Gambar dalam gambar",
      airplay: "AirPlay",
      qualityLabel: {},
    },
    fullscreen: { enabled: !0, fallback: !0, iosNative: !1 },
    clickToPlay: !isTouchDevice,
    previewThumbnails: { enabled: "" !== vttSrc, src: vttSrc },
    mediaMetadata: {},
  },
  HLS_CONFIG = {
    maxBufferLength: 45,
    maxMaxBufferLength: 90,
    maxBufferHole: 0.5,
    nudgeMaxRetry: 5,
    nudgeOffset: 0.1,
    enableWorker: !0,
    backBufferLength: 10,
    lowLatencyMode: !1,
    startLevel: -1,
    abrEwmaDefaultEstimate: 5e5,
    fragLoadingTimeOut: 2e4,
    manifestLoadingTimeOut: 1e4,
  };
