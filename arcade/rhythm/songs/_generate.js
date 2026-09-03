#!/usr/bin/env node








const fs = require("fs");
const path = require("path");

const SONGS = [
  { id: "starlight", bpm: 128, difficulty: 2, duration: 60, color: ["#ec4899", "#a855f7"], emoji: "✨" },
  { id: "neon_pulse", bpm: 140, difficulty: 3, duration: 60, color: ["#22d3ee", "#6366f1"], emoji: "💜" },
  { id: "cyber_dash", bpm: 160, difficulty: 4, duration: 65, color: ["#f97316", "#ef4444"], emoji: "⚡" },
  { id: "moonlight", bpm: 110, difficulty: 1, duration: 55, color: ["#a3e635", "#22d3ee"], emoji: "🌙" },
  { id: "thunder_strike", bpm: 175, difficulty: 5, duration: 70, color: ["#fbbf24", "#f97316"], emoji: "⚡" },
  { id: "crystal_waves", bpm: 120, difficulty: 2, duration: 55, color: ["#67e8f9", "#a78bfa"], emoji: "💎" },
  { id: "dark_matter", bpm: 185, difficulty: 5, duration: 70, color: ["#f43f5e", "#7c3aed"], emoji: "🌀" },
  { id: "pixel_dreams", bpm: 132, difficulty: 2, duration: 60, color: ["#c084fc", "#fb7185"], emoji: "🎮" },
  { id: "velocity", bpm: 195, difficulty: 5, duration: 75, color: ["#f43f5e", "#fbbf24"], emoji: "🔥" },
  { id: "gentle_rain", bpm: 95, difficulty: 1, duration: 50, color: ["#2dd4bf", "#6366f1"], emoji: "🌧️" },
];

function generateBeatmap(song) {
  const notes = [];
  const beatMs = 60000 / song.bpm;
  const dur = song.duration * 1000;
  const diff = song.difficulty;

  
  const singleChance = Math.max(0.15, 0.6 - diff * 0.08);
  const doubleChance = Math.min(0.25, 0.08 + diff * 0.03);
  const holdChance = Math.min(0.25, 0.05 + diff * 0.04); 
  const goldChance = 0.08; 

  let time = beatMs * 4;
  let lastLane = -1;
  let streamLen = 0;

  while (time < dur) {
    const roll = Math.random();
    let noteLanes = [];

    if (streamLen > 0) {
      
      streamLen--;
      const avail = [0, 1, 2, 3].filter((l) => l !== lastLane);
      noteLanes.push(avail[Math.floor(Math.random() * avail.length)]);
      time += beatMs / 2;
    } else if (roll < holdChance && diff >= 2) {
      
      const holdBeats = 1 + Math.floor(Math.random() * Math.min(3, diff));
      const holdDuration = holdBeats * beatMs;
      const lane = Math.floor(Math.random() * 4);
      const endMs = Math.min(time + holdDuration, dur - beatMs);
      const holdNote = { t: Math.round(time), e: Math.round(endMs), l: lane };
      if (Math.random() < goldChance) holdNote.g = true;
      notes.push(holdNote);
      lastLane = lane;
      time += holdDuration + beatMs; 
    } else if (roll < singleChance + holdChance) {
      
      let l;
      do {
        l = Math.floor(Math.random() * 4);
      } while (l === lastLane && Math.random() > 0.25);
      noteLanes.push(l);
      time += beatMs;
    } else if (roll < singleChance + holdChance + doubleChance) {
      
      const pool = [0, 1, 2, 3];
      noteLanes.push(pool.splice(Math.floor(Math.random() * pool.length), 1)[0]);
      noteLanes.push(pool.splice(Math.floor(Math.random() * pool.length), 1)[0]);
      time += beatMs;
    } else {
      
      streamLen = 2 + Math.floor(Math.random() * (diff + 1));
      noteLanes.push(Math.floor(Math.random() * 4));
      time += beatMs / 2;
    }

    for (const l of noteLanes) {
      const isGold = Math.random() < goldChance;
      const note = { t: Math.round(time), l };
      if (isGold) note.g = true;
      notes.push(note);
    }
    if (noteLanes.length > 0) lastLane = noteLanes[noteLanes.length - 1];
  }

  
  notes.sort((a, b) => a.t - b.t);
  return { notes, duration: Math.round(time) };
}

function generateCover(song) {
  const [c1, c2] = song.color;
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="200" height="200">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:${c1};stop-opacity:1"/>
      <stop offset="100%" style="stop-color:${c2};stop-opacity:1"/>
    </linearGradient>
  </defs>
  <rect width="200" height="200" rx="28" fill="url(#bg)"/>
  <rect width="200" height="200" rx="28" fill="rgba(0,0,0,0.25)"/>
  <line x1="50" y1="30" x2="50" y2="155" stroke="rgba(255,255,255,0.15)" stroke-width="1.5"/>
  <line x1="83" y1="30" x2="83" y2="155" stroke="rgba(255,255,255,0.15)" stroke-width="1.5"/>
  <line x1="117" y1="30" x2="117" y2="155" stroke="rgba(255,255,255,0.15)" stroke-width="1.5"/>
  <line x1="150" y1="30" x2="150" y2="155" stroke="rgba(255,255,255,0.15)" stroke-width="1.5"/>
  <line x1="32" y1="148" x2="168" y2="148" stroke="rgba(255,255,255,0.4)" stroke-width="2" stroke-linecap="round"/>
  <rect x="35" y="50" width="28" height="10" rx="4" fill="${c1}" opacity="0.9"/>
  <rect x="68" y="75" width="28" height="10" rx="4" fill="${c2}" opacity="0.9"/>
  <rect x="102" y="60" width="28" height="10" rx="4" fill="${c1}" opacity="0.9"/>
  <rect x="135" y="90" width="28" height="10" rx="4" fill="${c2}" opacity="0.9"/>
  <rect x="52" y="110" width="28" height="10" rx="4" fill="${c1}" opacity="0.9"/>
  <rect x="118" y="120" width="28" height="10" rx="4" fill="${c2}" opacity="0.9"/>
  <text x="100" y="185" font-size="28" text-anchor="middle" dominant-baseline="middle">${song.emoji}</text>
</svg>`;
}


const baseDir = path.join(__dirname);

for (const song of SONGS) {
  const dir = path.join(baseDir, song.id);

  
  const map = generateBeatmap(song);
  const beatmapPath = path.join(dir, "beatmap.json");
  fs.writeFileSync(beatmapPath, JSON.stringify(map, null, 2));

  const tapCount = map.notes.filter(n => !n.e).length;
  const holdCount = map.notes.filter(n => n.e).length;
  const goldCount = map.notes.filter(n => n.g).length;
  console.log(`✓ ${song.id}/beatmap.json (${map.notes.length} notes: ${tapCount} tap, ${holdCount} hold, ${goldCount} gold)`);

  
  const coverPath = path.join(dir, "cover.svg");
  fs.writeFileSync(coverPath, generateCover(song));
  console.log(`✓ ${song.id}/cover.svg`);
}

console.log("\nDone! All beatmaps and covers generated.");
