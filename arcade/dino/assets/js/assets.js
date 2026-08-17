const MIKU_COLORS = {
  hair: "#39C5BB",
  hairDark: "#1d968f",
  hairLight: "#85ECE5",
  skin: "#FFE0D2",
  blush: "#FF8A80",
  outfit: "#2A2F35",
  socks: "#111417",
  tie: "#39C5BB",
  pink: "#FF4081",
  eye: "#1A857D",
};
const TETO_COLORS = {
  hair: "#FF5E7E",
  hairDark: "#C2185B",
  hairLight: "#FF8DA1",
  skin: "#FFE0D2",
  blush: "#FF8A80",
  outfit: "#2D3238",
  socks: "#1A1D20",
  tie: "#FF5E7E",
  pink: "#FFD700",
  eye: "#C2185B",
};
// ─── DEFINISI SVG ASSET TEMA MIKU & TETO ───
const mikuRun1Svg = `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="64" height="80" viewBox="0 0 64 80">
  <path d="M 16,24 C 6,12 -8,22 -3,42 C 0,55 8,58 13,46 C 16,36 17,28 16,24 Z" fill="${MIKU_COLORS.hairDark}" />
  <path d="M 12,25 C 5,16 -4,24 0,38 C 2,47 8,49 11,40 C 13,32 13,27 12,25 Z" fill="${MIKU_COLORS.hair}" />
  <rect x="23" y="55" width="7" height="18" fill="${MIKU_COLORS.socks}" rx="2" />
  <rect x="23" y="70" width="11" height="7" fill="${MIKU_COLORS.outfit}" rx="1" />
  <rect x="33" y="55" width="7" height="15" fill="${MIKU_COLORS.socks}" rx="2" />
  <rect x="35" y="66" width="11" height="7" fill="${MIKU_COLORS.outfit}" rx="1" />
  <path d="M 20,35 L 43,35 L 39,56 L 24,56 Z" fill="${MIKU_COLORS.outfit}" />
  <path d="M 23,55 L 40,55 L 43,62 L 20,62 Z" fill="${MIKU_COLORS.socks}" />
  <rect x="23" y="55" width="17" height="2" fill="${MIKU_COLORS.pink}" />
  <path d="M 27,35 L 30,47 L 33,35 Z" fill="${MIKU_COLORS.tie}" />
  <circle cx="30" cy="38" r="1.5" fill="${MIKU_COLORS.pink}" />
  <rect x="16" y="37" width="5" height="14" fill="${MIKU_COLORS.socks}" rx="1" />
  <rect x="16" y="47" width="5" height="5" fill="${MIKU_COLORS.hair}" />
  <rect x="42" y="37" width="5" height="14" fill="${MIKU_COLORS.socks}" rx="1" />
  <rect x="42" y="47" width="5" height="5" fill="${MIKU_COLORS.hair}" />
  <rect x="22" y="16" width="19" height="19" fill="${MIKU_COLORS.skin}" rx="6" />
  <circle cx="26" cy="28" r="2.5" fill="${MIKU_COLORS.blush}" opacity="0.7" />
  <circle cx="37" cy="28" r="2.5" fill="${MIKU_COLORS.blush}" opacity="0.7" />
  <path d="M 21,15 L 42,15 L 40,24 L 37,20 L 30,24 L 24,20 L 21,24 Z" fill="${MIKU_COLORS.hair}" />
  <path d="M 21,15 L 24,32 L 21,30 Z" fill="${MIKU_COLORS.hairDark}" />
  <path d="M 42,15 L 39,32 L 42,30 Z" fill="${MIKU_COLORS.hairDark}" />
  <rect x="26" y="22" width="3.5" height="5.5" fill="${MIKU_COLORS.eye}" rx="1.5" />
  <circle cx="27" cy="23.5" r="1" fill="white" />
  <rect x="34" y="22" width="3.5" height="5.5" fill="${MIKU_COLORS.eye}" rx="1.5" />
  <circle cx="35" cy="23.5" r="1" fill="white" />
  <rect x="19" y="21" width="4" height="9" fill="${MIKU_COLORS.pink}" rx="1.5" />
  <rect x="40" y="21" width="4" height="9" fill="${MIKU_COLORS.pink}" rx="1.5" />
  <path d="M 22,17 Q 31,12 39,17" stroke="${MIKU_COLORS.pink}" stroke-width="2.5" fill="none" />
  <path d="M 41,21 C 53,15 67,23 62,43 C 58,54 51,52 46,43 C 43,37 41,28 41,21 Z" fill="${MIKU_COLORS.hair}" />
  <path d="M 45,23 C 54,18 63,24 60,38 C 57,46 51,45 48,38 C 46,33 45,28 45,23 Z" fill="${MIKU_COLORS.hairLight}" />
</svg>`)}`;

const mikuRun2Svg = `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="64" height="80" viewBox="0 0 64 80">
  <path d="M 16,24 C 4,14 -6,26 -2,45 C 1,57 9,54 13,43 C 16,34 17,28 16,24 Z" fill="${MIKU_COLORS.hairDark}" />
  <path d="M 12,25 C 4,17 -3,26 1,40 C 3,49 8,47 11,38 C 13,31 13,27 12,25 Z" fill="${MIKU_COLORS.hair}" />
  <rect x="21" y="55" width="7" height="15" fill="${MIKU_COLORS.socks}" rx="2" />
  <rect x="19" y="66" width="11" height="7" fill="${MIKU_COLORS.outfit}" rx="1" />
  <rect x="31" y="55" width="7" height="18" fill="${MIKU_COLORS.socks}" rx="2" />
  <rect x="31" y="70" width="11" height="7" fill="${MIKU_COLORS.outfit}" rx="1" />
  <path d="M 20,35 L 43,35 L 39,56 L 24,56 Z" fill="${MIKU_COLORS.outfit}" />
  <path d="M 23,55 L 40,55 L 43,62 L 20,62 Z" fill="${MIKU_COLORS.socks}" />
  <rect x="23" y="55" width="17" height="2" fill="${MIKU_COLORS.pink}" />
  <path d="M 27,35 L 30,47 L 33,35 Z" fill="${MIKU_COLORS.tie}" />
  <circle cx="30" cy="38" r="1.5" fill="${MIKU_COLORS.pink}" />
  <rect x="16" y="37" width="5" height="14" fill="${MIKU_COLORS.socks}" rx="1" />
  <rect x="16" y="47" width="5" height="5" fill="${MIKU_COLORS.hair}" />
  <rect x="42" y="37" width="5" height="14" fill="${MIKU_COLORS.socks}" rx="1" />
  <rect x="42" y="47" width="5" height="5" fill="${MIKU_COLORS.hair}" />
  <rect x="22" y="16" width="19" height="19" fill="${MIKU_COLORS.skin}" rx="6" />
  <circle cx="26" cy="28" r="2.5" fill="${MIKU_COLORS.blush}" opacity="0.7" />
  <circle cx="37" cy="28" r="2.5" fill="${MIKU_COLORS.blush}" opacity="0.7" />
  <path d="M 21,15 L 42,15 L 40,24 L 37,20 L 30,24 L 24,20 L 21,24 Z" fill="${MIKU_COLORS.hair}" />
  <path d="M 21,15 L 24,32 L 21,30 Z" fill="${MIKU_COLORS.hairDark}" />
  <path d="M 42,15 L 39,32 L 42,30 Z" fill="${MIKU_COLORS.hairDark}" />
  <rect x="26" y="22" width="3.5" height="5.5" fill="${MIKU_COLORS.eye}" rx="1.5" />
  <circle cx="27" cy="23.5" r="1" fill="white" />
  <rect x="34" y="22" width="3.5" height="5.5" fill="${MIKU_COLORS.eye}" rx="1.5" />
  <circle cx="35" cy="23.5" r="1" fill="white" />
  <rect x="19" y="21" width="4" height="9" fill="${MIKU_COLORS.pink}" rx="1.5" />
  <rect x="40" y="21" width="4" height="9" fill="${MIKU_COLORS.pink}" rx="1.5" />
  <path d="M 22,17 Q 31,12 39,17" stroke="${MIKU_COLORS.pink}" stroke-width="2.5" fill="none" />
  <path d="M 41,21 C 51,11 65,17 61,38 C 58,49 51,47 46,39 C 43,34 41,27 41,21 Z" fill="${MIKU_COLORS.hair}" />
  <path d="M 45,22 C 53,14 62,19 59,34 C 56,42 51,41 48,35 C 46,31 45,27 45,22 Z" fill="${MIKU_COLORS.hairLight}" />
</svg>`)}`;

const mikuJumpSvg = `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="64" height="80" viewBox="0 0 64 80">
  <path d="M 16,22 C 6,32 -6,45 2,52 C 8,56 12,48 13,38 Z" fill="${MIKU_COLORS.hairDark}" />
  <path d="M 41,21 C 51,32 63,45 55,52 C 49,56 45,48 44,38 Z" fill="${MIKU_COLORS.hair}" />
  <path d="M 43,24 C 50,33 59,42 53,48 C 48,51 45,45 44,38 Z" fill="${MIKU_COLORS.hairLight}" />
  <rect x="21" y="55" width="7" height="12" fill="${MIKU_COLORS.socks}" rx="2" transform="rotate(25, 21, 55)" />
  <rect x="33" y="55" width="7" height="12" fill="${MIKU_COLORS.socks}" rx="2" transform="rotate(-25, 33, 55)" />
  <path d="M 20,35 L 43,35 L 39,56 L 24,56 Z" fill="${MIKU_COLORS.outfit}" />
  <path d="M 21,55 L 42,55 L 45,60 L 18,60 Z" fill="${MIKU_COLORS.socks}" />
  <rect x="14" y="26" width="5" height="14" fill="${MIKU_COLORS.socks}" rx="1" transform="rotate(-40, 14, 26)" />
  <rect x="44" y="26" width="5" height="14" fill="${MIKU_COLORS.socks}" rx="1" transform="rotate(40, 44, 26)" />
  <rect x="22" y="16" width="19" height="19" fill="${MIKU_COLORS.skin}" rx="6" />
  <circle cx="26" cy="28" r="2.5" fill="${MIKU_COLORS.blush}" opacity="0.7" />
  <circle cx="37" cy="28" r="2.5" fill="${MIKU_COLORS.blush}" opacity="0.7" />
  <path d="M 21,15 L 42,15 L 40,24 L 37,20 L 30,24 L 24,20 L 21,24 Z" fill="${MIKU_COLORS.hair}" />
  <path d="M 25,24 Q 28,21 30,24" stroke="${MIKU_COLORS.eye}" stroke-width="2.5" fill="none" stroke-linecap="round" />
  <path d="M 33,24 Q 36,21 38,24" stroke="${MIKU_COLORS.eye}" stroke-width="2.5" fill="none" stroke-linecap="round" />
  <ellipse cx="31.5" cy="29.5" rx="2" ry="3" fill="${MIKU_COLORS.pink}" />
  <rect x="19" y="21" width="4" height="9" fill="${MIKU_COLORS.pink}" rx="1.5" />
  <rect x="40" y="21" width="4" height="9" fill="${MIKU_COLORS.pink}" rx="1.5" />
</svg>`)}`;

const mikuDuckSvg = `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="75" height="55" viewBox="0 0 75 55">
  <path d="M 18,25 C 2,16 -8,22 0,34 C 4,40 12,34 16,28 Z" fill="${MIKU_COLORS.hairDark}" />
  <g transform="rotate(18, 35, 25)">
      <rect x="12" y="32" width="13" height="7" fill="${MIKU_COLORS.socks}" rx="1.5" />
      <rect x="25" y="32" width="13" height="7" fill="${MIKU_COLORS.socks}" rx="1.5" />
      <rect x="18" y="14" width="24" height="18" fill="${MIKU_COLORS.outfit}" rx="3" />
      <rect x="18" y="14" width="24" height="2" fill="${MIKU_COLORS.pink}" />
      <rect x="40" y="8" width="17" height="17" fill="${MIKU_COLORS.skin}" rx="5" />
      <circle cx="44" cy="20" r="2" fill="${MIKU_COLORS.blush}" opacity="0.6" />
      <circle cx="53" cy="20" r="2" fill="${MIKU_COLORS.blush}" opacity="0.6" />
      <path d="M 39,6 C 45,4 52,4 58,6 C 58,9 39,9 39,6 Z" fill="${MIKU_COLORS.hair}" />
      <rect x="47" y="12" width="4.5" height="3" fill="${MIKU_COLORS.eye}" rx="1" />
      <circle cx="48" cy="13" r="0.8" fill="white" />
      <rect x="38" y="11" width="3.5" height="8" fill="${MIKU_COLORS.pink}" rx="1" />
      <rect x="54" y="11" width="3.5" height="8" fill="${MIKU_COLORS.pink}" rx="1" />
  </g>
  <path d="M 38,15 C 22,2 10,5 12,18 C 14,24 22,21 28,17 Z" fill="${MIKU_COLORS.hair}" />
  <path d="M 34,14 C 20,3 11,6 13,15 C 15,20 22,18 26,15 Z" fill="${MIKU_COLORS.hairLight}" />
</svg>`)}`;

const tetoRun1Svg = `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="64" height="80" viewBox="0 0 64 80">
  <ellipse cx="10" cy="26" rx="7" ry="5" fill="${TETO_COLORS.hairDark}" transform="rotate(15, 10, 26)" />
  <ellipse cx="8" cy="33" rx="5" ry="4" fill="${TETO_COLORS.hair}" transform="rotate(10, 8, 33)" />
  <ellipse cx="10" cy="39" rx="4" ry="3" fill="${TETO_COLORS.hairDark}" transform="rotate(5, 10, 39)" />
  <ellipse cx="11" cy="44" rx="3" ry="2" fill="${TETO_COLORS.hair}" />
  <rect x="23" y="55" width="7" height="18" fill="${TETO_COLORS.socks}" rx="2" />
  <rect x="23" y="70" width="11" height="7" fill="${TETO_COLORS.outfit}" rx="1" />
  <rect x="33" y="55" width="7" height="15" fill="${TETO_COLORS.socks}" rx="2" />
  <rect x="35" y="66" width="11" height="7" fill="${TETO_COLORS.outfit}" rx="1" />
  <path d="M 20,35 L 43,35 L 39,56 L 24,56 Z" fill="${TETO_COLORS.outfit}" />
  <path d="M 23,55 L 40,55 L 43,62 L 20,62 Z" fill="${TETO_COLORS.hairDark}" />
  <rect x="23" y="55" width="17" height="2.5" fill="${TETO_COLORS.pink}" />
  <path d="M 27,35 L 30,47 L 33,35 Z" fill="${TETO_COLORS.tie}" />
  <circle cx="30" cy="38" r="1.5" fill="${TETO_COLORS.pink}" />
  <rect x="16" y="37" width="5" height="14" fill="${TETO_COLORS.socks}" rx="1" />
  <rect x="16" y="47" width="5" height="5" fill="${TETO_COLORS.hair}" />
  <rect x="42" y="37" width="5" height="14" fill="${TETO_COLORS.socks}" rx="1" />
  <rect x="42" y="47" width="5" height="5" fill="${TETO_COLORS.hair}" />
  <rect x="22" y="16" width="19" height="19" fill="${TETO_COLORS.skin}" rx="6" />
  <circle cx="26" cy="28" r="2.5" fill="${TETO_COLORS.blush}" opacity="0.7" />
  <circle cx="37" cy="28" r="2.5" fill="${TETO_COLORS.blush}" opacity="0.7" />
  <path d="M 21,15 L 42,15 L 40,24 L 37,20 L 30,24 L 24,20 L 21,24 Z" fill="${TETO_COLORS.hair}" />
  <path d="M 21,15 L 24,32 L 21,30 Z" fill="${TETO_COLORS.hairDark}" />
  <path d="M 42,15 L 39,32 L 42,30 Z" fill="${TETO_COLORS.hairDark}" />
  <rect x="26" y="22" width="3.5" height="5.5" fill="${TETO_COLORS.eye}" rx="1.5" />
  <circle cx="27" cy="23.5" r="1" fill="white" />
  <rect x="34" y="22" width="3.5" height="5.5" fill="${TETO_COLORS.eye}" rx="1.5" />
  <circle cx="35" cy="23.5" r="1" fill="white" />
  <rect x="19" y="21" width="4" height="9" fill="${TETO_COLORS.pink}" rx="1.5" />
  <rect x="40" y="21" width="4" height="9" fill="${TETO_COLORS.pink}" rx="1.5" />
  <path d="M 22,17 Q 31,12 39,17" stroke="${TETO_COLORS.pink}" stroke-width="2.5" fill="none" />
  <ellipse cx="49" cy="26" rx="8" ry="6" fill="${TETO_COLORS.hair}" transform="rotate(-15, 49, 26)" />
  <ellipse cx="51" cy="33" rx="6" ry="5" fill="${TETO_COLORS.hairLight}" transform="rotate(-10, 51, 33)" />
  <ellipse cx="49" cy="40" rx="4" ry="3.5" fill="${TETO_COLORS.hair}" transform="rotate(-5, 49, 40)" />
  <ellipse cx="48" cy="45" rx="3" ry="2" fill="${TETO_COLORS.hairLight}" />
</svg>`)}`;

const tetoRun2Svg = `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="64" height="80" viewBox="0 0 64 80">
  <ellipse cx="9" cy="24" rx="7" ry="5" fill="${TETO_COLORS.hairDark}" transform="rotate(20, 9, 24)" />
  <ellipse cx="7" cy="31" rx="5" ry="4" fill="${TETO_COLORS.hair}" transform="rotate(15, 7, 31)" />
  <ellipse cx="8" cy="37" rx="4" ry="3" fill="${TETO_COLORS.hairDark}" transform="rotate(10, 8, 37)" />
  <ellipse cx="10" cy="42" rx="3" ry="2" fill="${TETO_COLORS.hair}" />
  <rect x="21" y="55" width="7" height="15" fill="${TETO_COLORS.socks}" rx="2" />
  <rect x="19" y="66" width="11" height="7" fill="${TETO_COLORS.outfit}" rx="1" />
  <rect x="31" y="55" width="7" height="18" fill="${TETO_COLORS.socks}" rx="2" />
  <rect x="31" y="70" width="11" height="7" fill="${TETO_COLORS.outfit}" rx="1" />
  <path d="M 20,35 L 43,35 L 39,56 L 24,56 Z" fill="${TETO_COLORS.outfit}" />
  <path d="M 23,55 L 40,55 L 43,62 L 20,62 Z" fill="${TETO_COLORS.hairDark}" />
  <rect x="23" y="55" width="17" height="2.5" fill="${TETO_COLORS.pink}" />
  <path d="M 27,35 L 30,47 L 33,35 Z" fill="${TETO_COLORS.tie}" />
  <circle cx="30" cy="38" r="1.5" fill="${TETO_COLORS.pink}" />
  <rect x="16" y="37" width="5" height="14" fill="${TETO_COLORS.socks}" rx="1" />
  <rect x="16" y="47" width="5" height="5" fill="${TETO_COLORS.hair}" />
  <rect x="42" y="37" width="5" height="14" fill="${TETO_COLORS.socks}" rx="1" />
  <rect x="42" y="47" width="5" height="5" fill="${TETO_COLORS.hair}" />
  <rect x="22" y="16" width="19" height="19" fill="${TETO_COLORS.skin}" rx="6" />
  <circle cx="26" cy="28" r="2.5" fill="${TETO_COLORS.blush}" opacity="0.7" />
  <circle cx="37" cy="28" r="2.5" fill="${TETO_COLORS.blush}" opacity="0.7" />
  <path d="M 21,15 L 42,15 L 40,24 L 37,20 L 30,24 L 24,20 L 21,24 Z" fill="${TETO_COLORS.hair}" />
  <path d="M 21,15 L 24,32 L 21,30 Z" fill="${TETO_COLORS.hairDark}" />
  <path d="M 42,15 L 39,32 L 42,30 Z" fill="${TETO_COLORS.hairDark}" />
  <rect x="26" y="22" width="3.5" height="5.5" fill="${TETO_COLORS.eye}" rx="1.5" />
  <circle cx="27" cy="23.5" r="1" fill="white" />
  <rect x="34" y="22" width="3.5" height="5.5" fill="${TETO_COLORS.eye}" rx="1.5" />
  <circle cx="35" cy="23.5" r="1" fill="white" />
  <rect x="19" y="21" width="4" height="9" fill="${TETO_COLORS.pink}" rx="1.5" />
  <rect x="40" y="21" width="4" height="9" fill="${TETO_COLORS.pink}" rx="1.5" />
  <path d="M 22,17 Q 31,12 39,17" stroke="${TETO_COLORS.pink}" stroke-width="2.5" fill="none" />
  <ellipse cx="50" cy="23" rx="8" ry="6" fill="${TETO_COLORS.hair}" transform="rotate(-20, 50, 23)" />
  <ellipse cx="52" cy="30" rx="6" ry="5" fill="${TETO_COLORS.hairLight}" transform="rotate(-15, 52, 30)" />
  <ellipse cx="50" cy="37" rx="4" ry="3.5" fill="${TETO_COLORS.hair}" transform="rotate(-10, 50, 37)" />
  <ellipse cx="49" cy="42" rx="3" ry="2" fill="${TETO_COLORS.hairLight}" />
</svg>`)}`;

const tetoJumpSvg = `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="64" height="80" viewBox="0 0 64 80">
  <ellipse cx="14" cy="30" rx="6" ry="5.5" fill="${TETO_COLORS.hairDark}" transform="rotate(35, 14, 30)" />
  <ellipse cx="11" cy="37" rx="5" ry="4.5" fill="${TETO_COLORS.hair}" transform="rotate(25, 11, 37)" />
  <ellipse cx="42" cy="30" rx="6" ry="5.5" fill="${TETO_COLORS.hair}" transform="rotate(-35, 42, 30)" />
  <ellipse cx="45" cy="37" rx="5" ry="4.5" fill="${TETO_COLORS.hairLight}" transform="rotate(-25, 45, 37)" />
  <rect x="21" y="55" width="7" height="12" fill="${TETO_COLORS.socks}" rx="2" transform="rotate(25, 21, 55)" />
  <rect x="33" y="55" width="7" height="12" fill="${TETO_COLORS.socks}" rx="2" transform="rotate(-25, 33, 55)" />
  <path d="M 20,35 L 43,35 L 39,56 L 24,56 Z" fill="${TETO_COLORS.outfit}" />
  <path d="M 21,55 L 42,55 L 45,60 L 18,60 Z" fill="${TETO_COLORS.socks}" />
  <rect x="14" y="26" width="5" height="14" fill="${TETO_COLORS.socks}" rx="1" transform="rotate(-40, 14, 26)" />
  <rect x="44" y="26" width="5" height="14" fill="${TETO_COLORS.socks}" rx="1" transform="rotate(40, 44, 26)" />
  <rect x="22" y="16" width="19" height="19" fill="${TETO_COLORS.skin}" rx="6" />
  <circle cx="26" cy="28" r="2.5" fill="${TETO_COLORS.blush}" opacity="0.7" />
  <circle cx="37" cy="28" r="2.5" fill="${TETO_COLORS.blush}" opacity="0.7" />
  <path d="M 21,15 L 42,15 L 40,24 L 37,20 L 30,24 L 24,20 L 21,24 Z" fill="${TETO_COLORS.hair}" />
  <path d="M 25,24 Q 28,21 30,24" stroke="${TETO_COLORS.eye}" stroke-width="2.5" fill="none" stroke-linecap="round" />
  <path d="M 33,24 Q 36,21 38,24" stroke="${TETO_COLORS.eye}" stroke-width="2.5" fill="none" stroke-linecap="round" />
  <ellipse cx="31.5" cy="29.5" rx="2" ry="3" fill="${TETO_COLORS.hairDark}" />
  <rect x="19" y="21" width="4" height="9" fill="${TETO_COLORS.pink}" rx="1.5" />
  <rect x="40" y="21" width="4" height="9" fill="${TETO_COLORS.pink}" rx="1.5" />
</svg>`)}`;

const tetoDuckSvg = `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="75" height="55" viewBox="0 0 75 55">
  <ellipse cx="12" cy="26" rx="7" ry="5" fill="${TETO_COLORS.hairDark}" transform="rotate(10, 12, 26)" />
  <ellipse cx="6" cy="30" rx="5" ry="4" fill="${TETO_COLORS.hair}" transform="rotate(5, 6, 30)" />
  <g transform="rotate(18, 35, 25)">
      <rect x="12" y="32" width="13" height="7" fill="${TETO_COLORS.socks}" rx="1.5" />
      <rect x="25" y="32" width="13" height="7" fill="${TETO_COLORS.socks}" rx="1.5" />
      <rect x="18" y="14" width="24" height="18" fill="${TETO_COLORS.outfit}" rx="3" />
      <rect x="18" y="14" width="24" height="2" fill="${TETO_COLORS.pink}" />
      <rect x="40" y="8" width="17" height="17" fill="${TETO_COLORS.skin}" rx="5" />
      <circle cx="44" cy="20" r="2" fill="${TETO_COLORS.blush}" opacity="0.6" />
      <circle cx="53" cy="20" r="2" fill="${TETO_COLORS.blush}" opacity="0.6" />
      <path d="M 39,6 C 45,4 52,4 58,6 C 58,9 39,9 39,6 Z" fill="${TETO_COLORS.hair}" />
      <rect x="47" y="12" width="4.5" height="3" fill="${TETO_COLORS.eye}" rx="1" />
      <circle cx="48" cy="13" r="0.8" fill="white" />
      <rect x="38" y="11" width="3.5" height="8" fill="${TETO_COLORS.pink}" rx="1" />
      <rect x="54" y="11" width="3.5" height="8" fill="${TETO_COLORS.pink}" rx="1" />
  </g>
  <ellipse cx="24" cy="16" rx="8" ry="6" fill="${TETO_COLORS.hair}" transform="rotate(-10, 24, 16)" />
  <ellipse cx="18" cy="12" rx="6" ry="5" fill="${TETO_COLORS.hairLight}" transform="rotate(-5, 18, 12)" />
</svg>`)}`;

const negiSvg = `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="30" height="60" viewBox="0 0 30 60">
  <rect x="11" y="35" width="8" height="25" fill="#FFFFFF" rx="2" />
  <rect x="10" y="20" width="10" height="15" fill="#A1E9E4" />
  <path d="M 12,20 L 4,2 L 10,12 L 15,2 L 20,12 L 26,2 L 18,20 Z" fill="#4CAF50" />
  <rect x="13" y="25" width="2" height="30" fill="#E0F2F1" opacity="0.5"/>
</svg>`)}`;

const baguetteSvg = `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="30" height="60" viewBox="0 0 30 60">
  <rect x="7" y="4" width="16" height="52" fill="#E5A65d" rx="8" stroke="#8B5A2B" stroke-width="2" />
  <rect x="10" y="6" width="3" height="48" fill="#FCE6C9" opacity="0.4" rx="1.5" />
  <path d="M 9,16 L 21,21" stroke="#8B5A2B" stroke-width="3" stroke-linecap="round" />
  <path d="M 9,26 L 21,31" stroke="#8B5A2B" stroke-width="3" stroke-linecap="round" />
  <path d="M 9,36 L 21,41" stroke="#8B5A2B" stroke-width="3" stroke-linecap="round" />
  <path d="M 9,46 L 21,51" stroke="#8B5A2B" stroke-width="3" stroke-linecap="round" />
</svg>`)}`;

const speakerMikuSvg = `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40">
  <rect x="4" y="4" width="32" height="32" fill="#2D3748" rx="4" stroke="#39C5BB" stroke-width="2" />
  <circle cx="20" cy="20" r="11" fill="#1A202C" stroke="#FF4081" stroke-width="2" />
  <circle cx="20" cy="20" r="5" fill="#39C5BB" />
  <circle cx="20" cy="20" r="2" fill="#FFFFFF" />
</svg>`)}`;

const speakerTetoSvg = `data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40">
  <rect x="4" y="4" width="32" height="32" fill="#2D3748" rx="4" stroke="#FF5E7E" stroke-width="2" />
  <circle cx="20" cy="20" r="11" fill="#1A202C" stroke="#FFD700" stroke-width="2" />
  <circle cx="20" cy="20" r="5" fill="#FF5E7E" />
  <circle cx="20" cy="20" r="2" fill="#FFFFFF" />
</svg>`)}`;

const imgMikuRun1 = new Image();
imgMikuRun1.src = mikuRun1Svg;
const imgMikuRun2 = new Image();
imgMikuRun2.src = mikuRun2Svg;
const imgMikuJump = new Image();
imgMikuJump.src = mikuJumpSvg;
const imgMikuDuck = new Image();
imgMikuDuck.src = mikuDuckSvg;
const imgNegi = new Image();
imgNegi.src = negiSvg;
const imgSpeakerMiku = new Image();
imgSpeakerMiku.src = speakerMikuSvg;

const imgTetoRun1 = new Image();
imgTetoRun1.src = tetoRun1Svg;
const imgTetoRun2 = new Image();
imgTetoRun2.src = tetoRun2Svg;
const imgTetoJump = new Image();
imgTetoJump.src = tetoJumpSvg;
const imgTetoDuck = new Image();
imgTetoDuck.src = tetoDuckSvg;
const imgBaguette = new Image();
imgBaguette.src = baguetteSvg;
const imgSpeakerTeto = new Image();
imgSpeakerTeto.src = speakerTetoSvg;
