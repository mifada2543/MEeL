/**
 * MEeL!Mania — localStorage autosave
 * Note: keeps the original field ids (songTitle/songArtist/bpmInput/
 * difficultySelect) as used by the legacy code — unrelated to the f-*
 * form ids used elsewhere in the editor.
 */
import { S } from "./state.js";

export function getStorageKey() {
  var titleInput = document.getElementById("songTitle");
  var artistInput = document.getElementById("songArtist");
  var key = "editor_";
  if (titleInput && titleInput.value) {
    key += titleInput.value.replace(/[^a-z0-9]/gi, "_").toLowerCase();
    if (artistInput && artistInput.value) {
      key += "_" + artistInput.value.replace(/[^a-z0-9]/gi, "_").toLowerCase();
    }
  } else {
    key += window.location.search.replace(/[^a-z0-9]/gi, "_");
  }
  return key;
}

export function saveNotesToStorage() {
  try {
    var data = {
      notes: S.notes,
      bpm: parseInt(document.getElementById("bpmInput") && document.getElementById("bpmInput").value) || 120,
      title: (document.getElementById("songTitle") && document.getElementById("songTitle").value) || "",
      artist: (document.getElementById("songArtist") && document.getElementById("songArtist").value) || "",
      difficulty: (document.getElementById("difficultySelect") && document.getElementById("difficultySelect").value) || "normal",
      savedAt: Date.now(),
    };
    localStorage.setItem(getStorageKey(), JSON.stringify(data));
  } catch (e) { /* quota exceeded or other error */ }
}

/**
 * Loads notes from localStorage into S.notes if present.
 * Caller is responsible for refreshing the UI (draw/updateNoteInfo)
 * afterwards — keeps this module free of renderer dependencies.
 */
export function loadNotesFromStorage() {
  try {
    var raw = localStorage.getItem(getStorageKey());
    if (!raw) return false;
    var data = JSON.parse(raw);
    if (data && Array.isArray(data.notes)) {
      S.notes = data.notes;
      if (data.bpm) {
        var bpmInput = document.getElementById("bpmInput");
        if (bpmInput) bpmInput.value = data.bpm;
      }
      if (data.title) {
        var titleInput = document.getElementById("songTitle");
        if (titleInput) titleInput.value = data.title;
      }
      if (data.artist) {
        var artistInput = document.getElementById("songArtist");
        if (artistInput) artistInput.value = data.artist;
      }
      if (data.difficulty) {
        var diffSelect = document.getElementById("difficultySelect");
        if (diffSelect) diffSelect.value = data.difficulty;
      }
      S.notes.sort(function (a, b) { return a.t - b.t; });
      S.gridDirty = true;
      return true;
    }
  } catch (e) {}
  return false;
}