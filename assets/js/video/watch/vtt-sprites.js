
























const _vttSpriteCache = {};

const _vttState = {
  lastSrc: null,
  
  status: 'idle',
  attempts: 0,
  
  token: 0,
};

let _vttVisibilityHooked = false;

const MAX_ATTEMPTS = 3;
const WATCHDOG_DELAY = 8000;
const SPRITE_VERIFY_TIMEOUT = 15000;






function _isPlyrReady() {
  const pt = player && player.previewThumbnails;
  return Boolean(
    pt &&
      pt.loaded &&
      Array.isArray(pt.thumbnails) &&
      pt.thumbnails.length > 0,
  );
}


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






function _parseFirstFrameText(vttText) {
  const timeLineRe = /(\d{2})?:?\d{2}:\d{2}[.,]\d{2,3}\s*-->/;
  const blocks = vttText.split(/\r\n\r\n|\n\n|\r\r/);
  for (const block of blocks) {
    if (/^\s*(WEBVTT|NOTE)/.test(block)) continue;
    const lines = block.split(/\r\n|\n|\r/);
    for (let i = 0; i < lines.length; i++) {
      if (!timeLineRe.test(lines[i])) continue;
      
      for (let j = i + 1; j < lines.length; j++) {
        const text = lines[j].trim();
        if (!text) continue;
        return text.split("#xywh=")[0];
      }
    }
  }
  return null;
}






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
      
      await _verifyImage(spriteUrl);
      if (token !== _vttState.token) return;
      _vttSpriteCache[vttUrl] = spriteUrl;
    }

    




    if (!_isPlyrReady()) _applySprite(spriteUrl);
  } catch (_) {
    
  }
}





function _startLoad(vttUrl) {
  
  
  if (!player || !player.config) {
    _vttState.status = "failed";
    _vttState.attempts = MAX_ATTEMPTS;
    return;
  }
  const token = ++_vttState.token;
  _vttState.lastSrc = vttUrl;
  _vttState.attempts += 1;
  _vttState.status = "loading";

  player.config.previewThumbnails.src = vttUrl;
  player.config.previewThumbnails.enabled = true;

  const pt = player.previewThumbnails;
  if (pt) {
    
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
    
  }

  
  _fallbackApply(vttUrl, token);

  
  _scheduleWatchdog(vttUrl, token);
}







function _scheduleWatchdog(vttUrl, token) {
  setTimeout(() => {
    if (token !== _vttState.token) return; 

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





function _hookVisibilityOnce() {
  if (_vttVisibilityHooked) return;
  _vttVisibilityHooked = true;

  document.addEventListener("visibilitychange", () => {
    if (document.hidden || !_vttState.lastSrc) return;

    
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





function refreshVttSprites(e) {
  if (!player || !e) return;

  _hookVisibilityOnce();

  const sameSrc = _vttState.lastSrc === e;

  


  if (sameSrc && _vttState.status === "ready" && _isPlyrReady()) {
    const cached = _vttSpriteCache[e];
    if (cached) _applySprite(cached);
    return;
  }

  


  if (sameSrc && _vttState.status === "loading") return;

  
  if (!sameSrc || _vttState.status === "failed") _vttState.attempts = 0;

  _adoptOrStart(e);
}
