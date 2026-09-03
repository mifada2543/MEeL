
function initPlayer() {
  

  if (window.playerConfig) {
    videoSrc = window.playerConfig.videoSrc || videoSrc;
    isHls = window.playerConfig.isHls || !1;
    vttSrc = window.playerConfig.vttSrc || "";
    videoId = window.playerConfig.id || videoId;
    storageKeyVideo = `video_pos_${videoId}`;
  }
  ((videoElement = document.getElementById("main-video")),
    videoElement &&
      (isHls && window.Hls && Hls.isSupported()
        ? ((hls = new Hls(HLS_CONFIG)),
          registerHlsErrorListener(hls),
          hls.loadSource(videoSrc),
          hls.attachMedia(videoElement),
          hls.on(Hls.Events.MANIFEST_PARSED, function () {
            const e = hls.levels.map((e) => e.bitrate);
            (e.length > 1 &&
              ((plyrOptions.quality = {
                default: e[0],
                options: e,
                forced: !0,
                onChange: (e) => {
                  const t = hls.levels.findIndex((t) => t.bitrate === e);
                  hls.currentLevel = t;
                },
              }),
              (plyrOptions.i18n = { ...plyrOptions.i18n, qualityLabel: {} }),
              hls.levels.forEach((e) => {
                const t = e.name
                  ? e.name
                  : `${e.height}p (${Math.round(e.bitrate / 1e3)}kbps)`;
                plyrOptions.i18n.qualityLabel[e.bitrate] = t;
              })),
              player ||
                ((player = new Plyr(videoElement, plyrOptions)),
                setupMeelPlayerEvents()));
          }))
        : ((player = new Plyr(videoElement, plyrOptions)),
          isHls && (videoElement.src = videoSrc),
          setupMeelPlayerEvents()),
      registerVideoListeners()));
}
function registerVideoListeners() {
  videoElement && registerVideoErrorListener(videoElement);
}
