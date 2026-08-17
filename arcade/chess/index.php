<?php
// Chess game - PHP wrapper
require_once __DIR__ . '/../../auth/config.php';
require_once __DIR__ . '/../../modules/core/helpers.php';
$title = "Arena Catur Pintar";
$description = "Arena Catur Pintar - Main catur dengan teman atau AI";
$chess_csrf = $_SESSION['csrf_token'] ?? '';
?>
<!doctype html>
<html lang="en">

<head>
<?php
$_META_TITLE = $title;
$_META_DESC  = $description;
include __DIR__ . '/../../partials/link.php';
$scripts_root = '../../';
include __DIR__ . '/../../partials/scripts.php';
?>
  <!-- Cache-busting (?v=filemtime): service worker cache-first TIDAK boleh
       mengunci versi lama aset ini — lihat kasus hard-refresh 2026-08-05. -->
  <link href="assets/css/chess.css?v=<?= @filemtime(__DIR__ . '/assets/css/chess.css') ?>" rel="stylesheet" />
</head>

<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col font-sans selection:bg-emerald-500 selection:text-white">
  <header class="border-b border-slate-800/80 bg-slate-900/80 backdrop-blur-md sticky top-0 z-50 px-4 py-3 shadow-lg shadow-slate-900/20">
    <div class="max-w-6xl mx-auto flex justify-between items-center">
      <div class="flex items-center gap-3">
        <img src="../../assets/MEeL.png" class="w-6 h-6 cursor-pointer" alt="logo catur" onclick="window.location.href='../'" title="Kembali ke arcade" />
        <div>
          <h1 class="text-xl font-extrabold tracking-tight bg-gradient-to-r from-emerald-400 to-teal-200 bg-clip-text text-transparent">MEeL-Chess</h1>
          <p class="text-[10px] uppercase tracking-widest text-slate-400 font-semibold">Klasik & Interaktif</p>
        </div>
      </div>
      <div class="flex gap-2">
        <button id="btn-restart" class="flex items-center gap-2 bg-slate-800 hover:bg-slate-700 active:scale-95 transition-all text-sm px-4 py-2 rounded-xl font-medium border border-slate-700 hover:border-emerald-500/50 hover:text-emerald-300 shadow-sm" title="Mulai ulang permainan">
          <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
          <span class="hidden sm:inline">Mula Semula</span>
        </button>
      </div>
    </div>
  </header>
  <main class="flex-1 max-w-6xl w-full mx-auto p-4 flex flex-col lg:flex-row gap-6 items-start justify-center mt-4">
    <!-- Left Panel: Game Modes & Status -->
    <section class="w-full lg:w-80 flex flex-col gap-4 shrink-0 order-2 lg:order-1">
      <!-- Mode Selection -->
      <div class="bg-slate-900 border border-slate-800 p-5 rounded-2xl shadow-xl">
        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-300 mb-4 flex items-center gap-2">
          <i data-lucide="settings-2" class="w-4 h-4 text-emerald-400"></i> Mode Permainan
        </h2>
        <div class="flex flex-col gap-3">
          <button id="mode-vs-local" class="flex items-center justify-between p-3.5 rounded-xl border text-left transition-all duration-200 active:scale-[0.98] border-emerald-500/40 bg-emerald-950/30 text-emerald-300 shadow-inner" title="Main catur dua pemain secara lokal">
            <div class="flex items-center gap-3">
              <i data-lucide="users" class="w-5 h-5"></i>
              <div>
                <p class="text-sm font-bold">Lawan Rakan</p>
                <p class="text-xs text-emerald-500/70">Dua pemain (Lokal)</p>
              </div>
            </div>
            <div class="mode-indicator w-3.5 h-3.5 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.6)] flex items-center justify-center">
              <div class="w-1.5 h-1.5 rounded-full bg-white"></div>
            </div>
          </button>
          <button id="mode-vs-ai" class="flex items-center justify-between p-3.5 rounded-xl border border-slate-700/80 hover:border-slate-500 hover:bg-slate-800/50 text-left transition-all duration-200 active:scale-[0.98] group" title="Main catur melawan kecerdasan buatan">
            <div class="flex items-center gap-3 text-slate-300 group-hover:text-indigo-300 transition-colors">
              <i data-lucide="cpu" class="w-5 h-5 text-indigo-400"></i>
              <div>
                <p class="text-sm font-bold">Lawan Komputer</p>
                <p class="text-xs text-slate-500 group-hover:text-indigo-400/70">Kecerdasan Buatan (AI)</p>
              </div>
            </div>
            <div class="mode-indicator w-3.5 h-3.5 rounded-full border-2 border-slate-600 transition-colors"></div>
          </button>
          <button id="mode-vs-online" class="flex items-center justify-between p-3.5 rounded-xl border border-slate-700/80 hover:border-slate-500 hover:bg-slate-800/50 text-left transition-all duration-200 active:scale-[0.98] group" title="Main catur dengan pemain lain melalui jaringan">
            <div class="flex items-center gap-3 text-slate-300 group-hover:text-cyan-300 transition-colors">
              <i data-lucide="globe" class="w-5 h-5 text-cyan-400"></i>
              <div>
                <p class="text-sm font-bold">Multiplayer LAN</p>
                <p class="text-xs text-slate-500 group-hover:text-cyan-400/70">Main beda device (LAMPP)</p>
              </div>
            </div>
            <div class="mode-indicator w-3.5 h-3.5 rounded-full border-2 border-slate-600 transition-colors"></div>
          </button>
        </div>
        <div id="ai-difficulty-container" class="mt-4 pt-4 border-t border-slate-800 hidden animate-pop">
          <label class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Tahap Kesukaran AI</label>
          <div class="grid grid-cols-3 gap-2 mt-2.5">
            <button data-level="easy" class="py-2 text-xs font-bold rounded-lg border border-indigo-500/40 bg-indigo-950/30 text-indigo-300 transition-all" title="Tingkat kesukaran AI: Mudah">Mudah</button>
            <button data-level="medium" class="py-2 text-xs font-bold rounded-lg border border-slate-700 hover:border-slate-500 text-slate-400 hover:text-slate-200 transition-all" title="Tingkat kesukaran AI: Normal">Normal</button>
            <button data-level="hard" class="py-2 text-xs font-bold rounded-lg border border-slate-700 hover:border-slate-500 text-slate-400 hover:text-slate-200 transition-all" title="Tingkat kesukaran AI: Sukar">Sukar</button>
          </div>
        </div>
      </div>
      <!-- Multiplayer Panel -->
      <div id="multiplayer-panel" class="bg-slate-900 border border-slate-800 p-5 rounded-2xl flex flex-col gap-4 shadow-xl hidden">
        <div class="flex justify-between items-center border-b border-slate-800 pb-3">
          <span class="text-sm text-slate-300 font-bold flex items-center gap-2"><i data-lucide="globe" class="w-4 h-4 text-cyan-400"></i> Multiplayer</span>
          <span id="room-status-badge" class="px-3 py-1 text-[10px] font-bold rounded-full bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 uppercase tracking-widest">Offline</span>
        </div>
        <div class="space-y-2">
          <div class="bg-slate-950 border border-slate-800 rounded-xl p-3">
            <p class="text-[10px] text-slate-500 uppercase tracking-wider font-bold">Room Code</p>
            <p id="room-code-display" class="text-lg font-extrabold text-cyan-300 tracking-[0.2em]">-</p>
          </div>
          <div class="grid grid-cols-2 gap-2 text-xs">
            <div class="bg-slate-950 border border-slate-800 rounded-xl p-3">
              <p class="text-[10px] text-slate-500 uppercase tracking-wider font-bold">Anda</p>
              <p id="room-color" class="font-bold text-slate-200">-</p>
            </div>
            <div class="bg-slate-950 border border-slate-800 rounded-xl p-3">
              <p class="text-[10px] text-slate-500 uppercase tracking-wider font-bold">Status</p>
              <p id="room-status" class="font-bold text-slate-200">Belum masuk room.</p>
            </div>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <button id="btn-offer-draw" class="px-3 py-2 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-300 text-sm font-bold transition-all active:scale-95 border border-amber-500/30 disabled:opacity-40 disabled:cursor-not-allowed" title="Tawarkan hasil seri kepada lawan">Tawarkan Seri</button>
          <button id="btn-resign" class="px-3 py-2 rounded-xl bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 text-sm font-bold transition-all active:scale-95 border border-rose-500/30 disabled:opacity-40 disabled:cursor-not-allowed" title="Mengalah — akhiri permainan dan menangkan lawan">Mengalah</button>
        </div>
        <button id="btn-leave-room" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-100 text-sm font-bold transition-all active:scale-95 border border-slate-700" title="Keluar dari mode multiplayer">Keluar</button>
      </div>
      <!-- Status Panel -->
      <div class="bg-slate-900 border border-slate-800 p-5 rounded-2xl flex flex-col gap-4 shadow-xl">
        <div class="flex justify-between items-center border-b border-slate-800 pb-3">
          <span class="text-sm text-slate-300 font-bold flex items-center gap-2"><i data-lucide="activity" class="w-4 h-4 text-emerald-400"></i> Status</span>
          <span id="game-status-badge" class="px-3 py-1 text-[10px] font-bold rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 uppercase tracking-widest shadow-[0_0_10px_rgba(16,185,129,0.1)]">Aktif</span>
        </div>
        <div class="flex items-center justify-between p-3.5 bg-slate-950 border border-slate-800 rounded-xl shadow-inner">
          <div class="flex items-center gap-3">
            <div id="turn-indicator-color" class="w-4 h-4 rounded-full bg-white border-2 border-slate-300 transition-all duration-300 shadow-[0_0_10px_rgba(255,255,255,0.2)]"></div>
            <div>
              <p class="text-[10px] text-slate-500 font-bold uppercase tracking-wider">Giliran Semasa</p>
              <p id="turn-indicator-text" class="text-sm font-bold text-slate-200">Putih (Anda)</p>
            </div>
          </div>
        </div>
        <div class="flex flex-col gap-3 mt-1">
          <div class="flex items-center justify-between bg-slate-950/50 p-2.5 rounded-xl border border-slate-800/50">
            <i data-lucide="swords" class="w-4 h-4 text-slate-600"></i>
            <div id="captured-white" class="flex flex-wrap justify-end gap-1 text-lg min-h-[28px] w-full ml-2"></div>
          </div>
          <div class="flex items-center justify-between bg-slate-950/50 p-2.5 rounded-xl border border-slate-800/50">
            <i data-lucide="swords" class="w-4 h-4 text-slate-600"></i>
            <div id="captured-black" class="flex flex-wrap justify-end gap-1 text-lg min-h-[28px] w-full ml-2"></div>
          </div>
        </div>
      </div>
    </section>
    <!-- Center: Chess Board -->
    <section class="flex-1 flex flex-col items-center justify-center w-full max-w-[520px] order-1 lg:order-2 self-center">
      <div class="w-full flex justify-between items-center mb-3 px-2">
        <div id="check-alert-black" class="hidden px-3 py-1 bg-rose-500/10 text-rose-400 border border-rose-500/30 rounded-lg text-xs font-bold uppercase tracking-wider animate-pulse flex items-center gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i> Check
        </div>
      </div>
      <div class="relative w-full aspect-square bg-[#4a6b2a] rounded-lg shadow-2xl shadow-black/50" style="padding:3px;border:3px solid #3b5a1e">
        <div id="chess-board" class="grid grid-cols-8 grid-rows-8 w-full h-full overflow-hidden cursor-pointer select-none transition-transform duration-500 ease-in-out"></div>
        <div id="game-over-overlay" class="absolute inset-0 bg-slate-950/90 backdrop-blur-sm rounded-xl flex flex-col items-center justify-center p-6 text-center z-40 hidden opacity-0 transition-opacity duration-300">
          <div class="bg-slate-900 border border-slate-700 p-8 rounded-2xl max-w-sm w-full shadow-2xl animate-pop">
            <div class="w-16 h-16 bg-gradient-to-br from-amber-400 to-amber-600 text-white rounded-full flex items-center justify-center mx-auto mb-5 shadow-lg shadow-amber-500/20 ring-4 ring-amber-500/10">
              <i data-lucide="trophy" class="w-8 h-8"></i>
            </div>
            <h3 class="text-2xl font-extrabold text-slate-100 mb-2 tracking-tight">Tamat Permainan</h3>
            <p id="game-over-result" class="text-sm font-medium text-slate-400 mb-6 bg-slate-950 py-2 px-4 rounded-lg inline-block border border-slate-800">Pemain Putih memenangi perlawanan!</p>
            <!-- Aksi pasca-game multiplayer: tanding ulang atau keluar ke mode Lawan Rakan -->
            <div id="rematch-actions" class="hidden flex flex-col gap-2 w-full">
              <button id="btn-rematch" class="w-full bg-emerald-600 hover:bg-emerald-500 active:scale-[0.98] transition-all text-white font-bold py-3 px-4 rounded-xl shadow-lg shadow-emerald-900/20 flex justify-center items-center gap-2 disabled:opacity-40 disabled:cursor-not-allowed" title="Minta tanding ulang kepada lawan">
                <i data-lucide="refresh-ccw" class="w-4 h-4"></i> Tanding Lagi?
              </button>
              <button id="btn-exit-game" class="w-full bg-slate-800 hover:bg-slate-700 active:scale-[0.98] transition-all text-slate-200 font-bold py-3 px-4 rounded-xl border border-slate-700 flex justify-center items-center gap-2" title="Akhiri sesi dan kembali ke mode Lawan Rakan">
                <i data-lucide="log-out" class="w-4 h-4"></i> Keluar
              </button>
            </div>
            <button id="btn-restart-overlay" class="w-full bg-emerald-600 hover:bg-emerald-500 active:scale-[0.98] transition-all text-white font-bold py-3 px-4 rounded-xl shadow-lg shadow-emerald-900/20 flex justify-center items-center gap-2" title="Mulai ulang permainan dari awal">
              <i data-lucide="play" class="w-4 h-4"></i> Main Semula
            </button>
          </div>
        </div>
        <!-- COLOR PICKER OVERLAY (multiplayer: pilih warna sebelum game dimulai) -->
        <div id="color-picker-overlay" class="absolute inset-0 bg-slate-950/95 backdrop-blur-sm rounded-xl flex flex-col items-center justify-center p-4 text-center z-40 hidden opacity-0 transition-opacity duration-300">
          <!-- State 1: pilih warna -->
          <div id="cp-pick" class="bg-slate-900 border border-slate-700 p-6 rounded-2xl max-w-sm w-full shadow-2xl animate-pop">
            <div class="w-14 h-14 bg-gradient-to-br from-cyan-500 to-indigo-600 text-white rounded-full flex items-center justify-center mx-auto mb-4 shadow-lg ring-4 ring-cyan-500/10">
              <i data-lucide="globe" class="w-7 h-7"></i>
            </div>
            <h3 class="text-lg font-extrabold text-slate-100 mb-1 tracking-tight">Pilih Warna</h3>
            <p class="text-xs text-slate-400 mb-5">Putih = buat room &amp; tunggu lawan.<br>Hitam = gabung pakai Room Code.</p>
            <div class="grid grid-cols-2 gap-3">
              <button id="btn-pick-white" class="flex flex-col items-center gap-1.5 py-3.5 rounded-xl bg-slate-100 hover:bg-white text-slate-900 font-bold transition-all active:scale-95 shadow-lg" title="Main sebagai Putih — buat room baru">
                <i data-lucide="circle" class="w-6 h-6 fill-white stroke-slate-400"></i>
                <span class="text-sm">Putih</span>
                <span class="text-[10px] font-medium text-slate-500">Buat Room</span>
              </button>
              <button id="btn-pick-black" class="flex flex-col items-center gap-1.5 py-3.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-white font-bold transition-all active:scale-95 border border-slate-600 shadow-lg" title="Main sebagai Hitam — gabung pakai Room Code">
                <i data-lucide="circle" class="w-6 h-6 fill-slate-900 stroke-slate-500"></i>
                <span class="text-sm">Hitam</span>
                <span class="text-[10px] font-medium text-slate-400">Join Room</span>
              </button>
            </div>
          </div>
          <!-- State 2: menunggu lawan (setelah pilih Putih & buat room) -->
          <div id="cp-waiting" class="bg-slate-900 border border-slate-700 p-6 rounded-2xl max-w-sm w-full shadow-2xl animate-pop hidden">
            <div class="w-10 h-10 mx-auto mb-4 rounded-full border-2 border-cyan-400 border-t-transparent animate-spin"></div>
            <h3 class="text-lg font-extrabold text-slate-100 mb-1 tracking-tight">Menunggu Lawan</h3>
            <p class="text-xs text-slate-400 mb-3">Bagikan Room Code ini ke rakan:</p>
            <p id="cp-room-code" class="text-2xl font-extrabold text-cyan-300 tracking-[0.2em] mb-5">-</p>
            <button id="btn-cancel-waiting" class="w-full py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-sm font-bold transition-all active:scale-95 border border-slate-600" title="Batal — kembali ke pilihan warna">BATAL</button>
          </div>
        </div>
      </div>
      <div class="w-full flex justify-between items-center mt-3 px-2">
        <div id="check-alert-white" class="hidden px-3 py-1 bg-rose-500/10 text-rose-400 border border-rose-500/30 rounded-lg text-xs font-bold uppercase tracking-wider animate-pulse flex items-center gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i> Check
        </div>
      </div>
    </section>
    <!-- Right Panel: Move History -->
    <section class="w-full lg:w-72 flex flex-col gap-4 shrink-0 order-3">
      <div class="bg-slate-900 border border-slate-800 p-5 rounded-2xl flex flex-col h-[350px] lg:h-[580px] shadow-xl">
        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-300 mb-4 flex items-center gap-2 border-b border-slate-800 pb-3">
          <i data-lucide="list-ordered" class="w-4 h-4 text-emerald-400"></i> Sejarah Langkah
        </h2>
        <div class="flex-1 overflow-y-auto custom-scrollbar pr-2" id="move-history-container">
          <table class="w-full text-sm text-slate-300">
            <thead class="sticky top-0 bg-slate-900 z-10">
              <tr class="border-b border-slate-800 text-slate-500 font-bold text-xs uppercase tracking-wider">
                <th class="py-2 text-left w-12">#</th>
                <th class="py-2 text-left">Putih</th>
                <th class="py-2 text-left">Hitam</th>
              </tr>
            </thead>
            <tbody id="move-history-list" class="divide-y divide-slate-800/50"></tbody>
          </table>
          <div id="no-moves-placeholder" class="h-full flex flex-col items-center justify-center text-center py-10 opacity-50">
            <div class="w-12 h-12 rounded-full bg-slate-800 flex items-center justify-center mb-3 border border-slate-700">
              <i data-lucide="git-commit" class="w-6 h-6 text-slate-400"></i>
            </div>
            <p class="text-xs font-medium text-slate-400">Papan masih bersih.<br />Lakukan langkah pertama!</p>
          </div>
        </div>
      </div>
    </section>
  </main>
  <!-- Promotion Modal -->
  <div id="promotion-modal" class="fixed inset-0 bg-slate-950/90 backdrop-blur-md flex items-center justify-center z-[100] hidden">
    <div class="bg-slate-900 border border-slate-700 p-6 rounded-2xl max-w-xs w-full text-center shadow-2xl animate-pop">
      <div class="w-12 h-12 bg-indigo-500/10 text-indigo-400 rounded-full flex items-center justify-center mx-auto mb-3">
        <i data-lucide="star" class="w-6 h-6"></i>
      </div>
      <h3 class="text-lg font-bold text-slate-100 mb-1">Promosi Pajurit</h3>
      <p class="text-xs text-slate-400 mb-5">Pilih kepingan gantian untuk pajurit anda:</p>
      <div class="grid grid-cols-2 gap-3" id="promotion-choices"></div>
    </div>
  </div>

  <footer class="border-t border-slate-800/80 bg-slate-950 py-6 text-center text-xs text-slate-500 mt-auto">
    <p class="font-medium tracking-wide">© 2026 Arena Catur Pintar. Diperhalusi untuk Pengalaman Terbaik.</p>
  </footer>
  <script type="module" src="assets/js/main.js?v=<?= @filemtime(__DIR__ . '/assets/js/main.js') ?>"></script>
  <script>
    // Bridge PHP → JS: token CSRF untuk endpoint multiplayer
    // (status login ditangani api.js: respon 401 dari controller → redirect ke login)
    window.MEEL_CSRF = <?= json_encode($chess_csrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  </script>
</body>

</html>
