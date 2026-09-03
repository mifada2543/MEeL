function saveAudioState() {
  
  
  
  
  if (window.__meelCurrentView !== "watch") return;
  if (!window.MEEL_MUSIC_CONFIG) return;
  const e = window.MEEL_MUSIC_CONFIG,
    t = e.playlistId || 0;
  
  
  
  
  const url =
    (typeof watchUrl === "string" && watchUrl ? watchUrl : "") ||
    `watch?id=${e.id}`;
  (sessionStorage.setItem(
    MEEL_KEYS.AUDIO_STATE,
    JSON.stringify({
      id: e.id,
      musicId: e.id,
      playlistId: t,
      watchUrl: url,
      currentTime: player ? player.currentTime : 0,
      isPlaying: !!player && !player.paused,
      isLooping: !!player && player.loop,
      title: e.title,
      artist: e.artist,
      thumbnail: e.thumbnail,
      thumbnailUrl: e.thumbnailUrl || "",
      filename: e.filename,
    }),
  ),
    t > 0
      ? localStorage.setItem(MEEL_KEYS.LAST_PLAYLIST_ID, String(t))
      : localStorage.removeItem(MEEL_KEYS.LAST_PLAYLIST_ID));
}
