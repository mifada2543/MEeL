/**
 * MEeL!Mania Game — Song Data Loader
 * Loads song metadata and beatmap from PHP globals or filesystem fallback.
 */
import { S, songId, phpSong, phpBeatmap, speedMult, audioElement } from "./state.js";

const SONGS_BASE = "/MEeL/arcade/rhythm/songs";

export async function loadSongData() {
  if (phpSong) {
    S.song = {
      id: phpSong.id,
      title: phpSong.title,
      artist: phpSong.artist,
      bpm: phpSong.bpm,
      difficulty: phpSong.difficulty,
      difficultyLabel: phpSong.difficulty_label,
      color: phpSong.color,
      emoji: phpSong.emoji,
      duration: phpSong.duration,
      noteCount: phpSong.note_count,
      type: phpSong.type,
      audioUrl: phpSong.audio_url,
      coverUrl: phpSong.cover_url,
    };
    S.beatmapData = phpBeatmap || { notes: [], duration: 0 };

    if (phpSong.audio_url) {
      audioElement.src = phpSong.audio_url;
      audioElement.load();
    }
  } else {
    await loadFromFiles();
  }

  // Load high score
  try {
    const saved = JSON.parse(localStorage.getItem("mania_scores")) || {};
    S.highScore = saved[String(songId)] || 0;
  } catch (e) { S.highScore = 0; }

  // Setup start overlay
  document.getElementById("overlayEmoji").textContent = S.song.emoji;
  document.getElementById("overlayTitle").textContent = S.song.title;
  document.getElementById("overlaySub").textContent =
    `${S.song.artist} \u00b7 ${S.song.bpm} BPM \u00b7 ${S.beatmapData.notes.length} notes \u00b7 Speed ${speedMult}\u00d7`;
}

async function loadFromFiles() {
  try {
    const idxResp = await fetch(SONGS_BASE + "/_index.json");
    if (!idxResp.ok) throw new Error("No _index.json");
    const index = await idxResp.json();
    const meta = (index || []).find((s) => s.id === songId);
    if (!meta) throw new Error("Song not in index");

    S.song = {
      id: meta.id,
      title: meta.title,
      artist: meta.artist,
      bpm: meta.bpm,
      difficulty: meta.difficulty,
      difficultyLabel: meta.difficultyLabel || "Normal",
      color: meta.color || ["#ec4899", "#a855f7"],
      emoji: meta.emoji || "\u266a",
      duration: meta.duration || 60,
      noteCount: meta.noteCount || 0,
      type: "builtin",
      audioUrl: null,
      coverUrl: SONGS_BASE + "/" + meta.id + "/cover.svg",
    };

    const bmResp = await fetch(SONGS_BASE + "/" + songId + "/beatmap.json");
    if (!bmResp.ok) throw new Error("No beatmap.json");
    S.beatmapData = await bmResp.json();
  } catch (e) {
    console.error("Filesystem fallback failed:", e);
    S.song = {
      id: songId, title: songId, artist: "Unknown",
      bpm: 120, difficulty: 2, difficultyLabel: "Normal",
      color: ["#ec4899", "#a855f7"], emoji: "\u266a",
      duration: 60, noteCount: 0, type: "builtin",
    };
    S.beatmapData = { notes: [], duration: 0 };
  }
}
