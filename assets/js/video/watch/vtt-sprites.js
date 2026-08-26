/**
 * Manajemen preview thumbnail (VTT sprites) untuk Plyr.
 *
 * Latar belakang: thumbnail preview kadang muncul hitam, terutama jika
 * transisi video terjadi saat tab berada di background. Akar masalah:
 *  - Reset destruktif `thumbnails = []` dieksekusi berulang (retry 300/500/
 *    1500ms) sehingga berlomba dengan proses load yang masih berjalan.
 *  - Timer di tab background di-throttle browser (>=1 detik, bahkan 1x/menit
 *    pada intensive throttling) sehingga urutan retry menjadi tak terduga.
 *  - Plyr tidak memasang `onerror` pada `getThumbnail()` — satu request
 *    gambar gagal membuat promise menggantung selamanya (loaded = false).
 *  - Tidak ada verifikasi bahwa sprite benar-benar bisa dimuat.
 *
 * Strategi perbaikan di file ini:
 *  1. Idempoten & anti-race: panggilan berulang dengan sumber yang sama saat
 *     load masih berjalan DIABAIKAN (tidak lagi me-reset state di tengah jalan).
 *  2. Watchdog: jika load internal Plyr menggantung/gagal, ulangi terbatas.
 *  3. Fallback terverifikasi: VTT di-fetch sekali (cek response.ok), cue
 *     diparse secara proper, dan URL sprite diverifikasi via Image decode
 *     SEBELUM dipasang ke DOM / disimpan ke cache. Hanya diterapkan bila
 *     jalur utama Plyr belum berhasil, agar tidak saling menimpa.
 *  4. Health-check `visibilitychange`: begitu tab kembali aktif, periksa
 *     sekali apakah thumbnail sehat; jika tidak, muat ulang.
 */

const _vttSpriteCache = {};

const _vttState = {
  lastSrc: null,
  /* idle | loading | ready | failed */
  status: 'idle',
  attempts: 0,
  /* Penanda siklus; callback lama diabaikan bila token sudah berganti. */
  token: 0,
};

let _vttVisibilityHooked = false;

const MAX_ATTEMPTS = 3;
const WATCHDOG_DELAY = 8000;
const SPRITE_VERIFY_TIMEOUT = 15000;

/* ------------------------------------------------------------------ */
/* Util                                                                */
/* ------------------------------------------------------------------ */

/** Apakah pipeline internal Plyr sudah selesai dan punya data thumbnail? */
function _isPlyrReady() {
  const pt = player && player.previewThumbnails;
  return Boolean(
    pt &&
      pt.loaded &&
      Array.isArray(pt.thumbnails) &&
      pt.thumbnails.length > 0,
  );
}

/** Terapkan URL sprite ke elemen preview yang sudah ada di DOM. */
function _applySprite(spriteUrl) {
  document
    .querySelectorAll(".plyr__preview-thumb__image-container")
    .forEach((el) => {
      el.style.backgroundImage = `url("${spriteUrl}")`;
    });
  document
    .querySelectorAll(
      ".plyr__preview-thumb__image-container img, .plyr__preview-scrubbing img",
    )
    .forEach((el) => {
      el.src = spriteUrl;
    });
}

/** Pastikan sebuah URL benar-benar bisa dimuat sebagai gambar (dengan timeout). */
function _verifyImage(url) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const timer = setTimeout(() => {
      img.onload = img.onerror = null;
      reject(new Error("[VTT] Timeout verifikasi sprite: " + url));
    }, SPRITE_VERIFY_TIMEOUT);
    img.onload = () => {
      clearTimeout(timer);
      resolve(img);
    };
    img.onerror = () => {
      clearTimeout(timer);
      reject(new Error("[VTT] Sprite gagal dimuat: " + url));
    };
    img.src = url;
  });
}

/**
 * Ambil teks gambar dari cue pertama VTT (pengganti regex mentah).
 * Melewati blok WEBVTT header dan NOTE komentar, serta membuang
 * koordinat sprite `#xywh=` bila ada.
 */
function _parseFirstFrameText(vttText) {
  const timeLineRe = /(\d{2})?:?\d{2}:\d{2}[.,]\d{2,3}\s*-->/;
  const blocks = vttText.split(/\r\n\r\n|\n\n|\r\r/);
  for (const block of blocks) {
    if (/^\s*(WEBVTT|NOTE)/.test(block)) continue;
    const lines = block.split(/\r\n|\n|\r/);
    for (let i = 0; i < lines.length; i++) {
      if (!timeLineRe.test(lines[i])) continue;
      /* Baris pertama non-kosong setelah baris waktu = teks/frame gambar. */
      for (let j = i + 1; j < lines.length; j++) {
        const text = lines[j].trim();
        if (!text) continue;
        return text.split("#xywh=")[0];
      }
    }
  }
  return null;
}

/**
 * Selesaikan URL sprite relatif terhadap lokasi VTT.
 * URL absolut (http/https/protocol-relative), berawalan "/", atau data URI
 * dipakai apa adanya (mengikuti perilaku resolver Plyr).
 */
function _resolveSpriteUrl(frameText, vttUrl) {
  if (
    /^(https?:)?\/\//i.test(frameText) ||
    frameText.startsWith("/") ||
    frameText.startsWith("data:")
  ) {
    return frameText;
  }
  return vttUrl.substring(0, vttUrl.lastIndexOf("/") + 1) + frameText;
}

/* ------------------------------------------------------------------ */
/* Fallback terverifikasi                                              */
/* ------------------------------------------------------------------ */

/**
 * Jalur cadangan: fetch VTT sekali, ekstrak sprite pertama, verifikasi
 * gambarnya bisa dimuat, baru pasang ke DOM (dan cache hasilnya).
 * Batal diam-diam bila siklusnya sudah kedaluwarsa (token berganti).
 */
async function _fallbackApply(vttUrl, token) {
  try {
    let spriteUrl = _vttSpriteCache[vttUrl];

    if (!spriteUrl) {
      const res = await fetch(vttUrl);
      if (!res.ok) throw new Error(`HTTP ${res.status} saat fetch VTT`);
      if (token !== _vttState.token) return;

      const frameText = _parseFirstFrameText(await res.text());
      if (!frameText) throw new Error("Tidak ada frame gambar di dalam VTT");
      if (token !== _vttState.token) return;

      spriteUrl = _resolveSpriteUrl(frameText, vttUrl);
      /* Verifikasi dulu SEBELUM dicache — nilai jelek tidak boleh ikut cache. */
      await _verifyImage(spriteUrl);
      if (token !== _vttState.token) return;
      _vttSpriteCache[vttUrl] = spriteUrl;
    }

    /*
     * Hanya pasang bila jalur utama Plyr BELUM berhasil, supaya hack
     * background-image ini tidak berlomba dengan pipeline <img> internal
     * Plyr (dua penulis pada elemen yang sama tanpa koordinasi).
     */
    if (!_isPlyrReady()) _applySprite(spriteUrl);
  } catch (_) {
    /* Kegagalan fallback dibiarkan senyap — jalur utama Plyr tetap berjalan. */
  }
}

/* ------------------------------------------------------------------ */
/* Siklus muat utama                                                   */
/* ------------------------------------------------------------------ */

function _startLoad(vttUrl) {
  const token = ++_vttState.token;
  _vttState.lastSrc = vttUrl;
  _vttState.attempts += 1;
  _vttState.status = "loading";

  player.config.previewThumbnails.src = vttUrl;
  player.config.previewThumbnails.enabled = true;

  const pt = player.previewThumbnails;
  if (pt) {
    /* Hancurkan container hasil render() sebelumnya agar tidak duplikat. */
    if (typeof pt.destroy === "function") {
      try {
        pt.destroy();
      } catch (_) {}
    }
    pt.thumbnails = [];
    pt.loaded = false;
  }

  try {
    if (pt && typeof pt.load === "function") pt.load();
  } catch (_) {
    /* Ditangani oleh watchdog. */
  }

  /* Jalankan fallback terverifikasi secara paralel (bukan pengganti). */
  _fallbackApply(vttUrl, token);

  /* Watchdog dijelaskan di bawah. */
  _scheduleWatchdog(vttUrl, token);
}

/*
 * Watchdog: internal Plyr tidak memasang onerror pada getThumbnail(), jadi
 * satu request gambar yang gagal membuat promise-nya menggantung selamanya
 * (loaded tetap false tanpa error apa pun). Deteksi kondisi menggantung ini
 * dan muat ulang secara terbatas.
 */
function _scheduleWatchdog(vttUrl, token) {
  setTimeout(() => {
    if (token !== _vttState.token) return; /* Siklus lebih baru sudah jalan. */

    if (_isPlyrReady()) {
      _vttState.status = "ready";
      _vttState.attempts = 0;
      return;
    }

    if (_vttState.attempts < MAX_ATTEMPTS) {
      _startLoad(vttUrl);
    } else {
      _vttState.status = "failed";
    }
  }, WATCHDOG_DELAY);
}

/*
 * Adopsi load awal: state.js sudah mengeset previewThumbnails.src saat player
 * dibuat, sehingga konstruktor Plyr menjalankan load() sendiri. Panggilan
 * refreshVttSprites pertama tidak perlu menghancurkan proses itu — cukup
 * pantau lewat watchdog dan jalankan fallback.
 */
function _adoptOrStart(vttUrl) {
  const pt = player.previewThumbnails;
  const isInitialLoad =
    _vttState.lastSrc === null &&
    pt &&
    player.config.previewThumbnails.src === vttUrl;

  if (isInitialLoad) {
    const token = ++_vttState.token;
    _vttState.lastSrc = vttUrl;
    _vttState.attempts = 1;
    _vttState.status = "loading";

    _fallbackApply(vttUrl, token);
    _scheduleWatchdog(vttUrl, token);
    return;
  }

  _startLoad(vttUrl);
}

/* ------------------------------------------------------------------ */
/* Health-check saat tab kembali aktif                                 */
/* ------------------------------------------------------------------ */

function _hookVisibilityOnce() {
  if (_vttVisibilityHooked) return;
  _vttVisibilityHooked = true;

  document.addEventListener("visibilitychange", () => {
    if (document.hidden || !_vttState.lastSrc) return;

    /* Beri jeda singkat agar browser selesai melanjutkan task yang tertunda. */
    setTimeout(() => {
      if (document.hidden) return;

      const hasContainer = document.querySelector(
        ".plyr__preview-thumb__image-container",
      );
      const unhealthy =
        !hasContainer || !_isPlyrReady() || _vttState.status === "failed";

      if (unhealthy && _vttState.status !== "loading") {
        _vttState.attempts = 0;
        _startLoad(_vttState.lastSrc);
      }
    }, 300);
  });
}

/* ------------------------------------------------------------------ */
/* API publik                                                          */
/* ------------------------------------------------------------------ */

function refreshVttSprites(e) {
  if (!player || !e) return;

  _hookVisibilityOnce();

  const sameSrc = _vttState.lastSrc === e;

  /* Sudah sehat dengan sumber yang sama -> tidak perlu load ulang.
     Cukup pastikan sprite tercache tetap terpasang (misal setelah
     masuk/keluar fullscreen yang mengganti style DOM). */
  if (sameSrc && _vttState.status === "ready" && _isPlyrReady()) {
    const cached = _vttSpriteCache[e];
    if (cached) _applySprite(cached);
    return;
  }

  /* Sedang dimuat dengan sumber yang sama -> BIARKAN selesai.
     Ini kunci anti-race: pemanggilan retry (300/500/1500ms) yang
     bertumpuk tidak lagi me-reset state di tengah proses. */
  if (sameSrc && _vttState.status === "loading") return;

  /* Sumber baru, atau permintaan eksplisit setelah kegagalan. */
  if (!sameSrc || _vttState.status === "failed") _vttState.attempts = 0;

  _adoptOrStart(e);
}
