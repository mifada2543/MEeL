function saveAudioState() {
  if (!window.MEEL_MUSIC_CONFIG) return;
  const e = window.MEEL_MUSIC_CONFIG,
    t = e.playlistId || 0;
  (sessionStorage.setItem(
    "meel_audio_state",
    JSON.stringify({
      id: e.id,
      musicId: e.id,
      playlistId: t,
      watchUrl: `watch.php?id=${e.id}`,
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
      ? localStorage.setItem("meel_last_playlist_id", String(t))
      : localStorage.removeItem("meel_last_playlist_id"));
}
