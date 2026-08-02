<?php
$games = [
  ["id" => 1, "title" => "Miku & Teto Run", "developer" => "MEeL Teams", "year" => 2026, "category" => "Action / Beat 'em up", "controls" => "Up Arrow to Jump & Down Arrow to Slide", "maxPlayers" => "1 Players", "rating" => 4.9, "description" => "Kendalikan Miku/Teto di track panjang yang menyenangkan dengan rintangan unik dan power-up menarik. Cocok untuk semua usia, game ini menggabungkan elemen puzzle sederhana dengan kecepatan arcade yang seru. Tantang temanmu untuk skor tertinggi atau nikmati mode solo untuk mengasah refleksmu!", "image" => "assets/img/dino.png", "favorite" => false, "play_url" => "dino/"],
  ["id" => 2, "title" => "Chess", "developer" => "MEeL Teams", "year" => 2026, "category" => "Strategy", "controls" => "Pawn, Knight, Rook, Bishop, Queen, King", "maxPlayers" => "2 Players", "rating" => 5.0, "description" => "Game strategi klasik yang membutuhkan pemikiran taktis dan perencanaan jangka panjang. Susun bidak-bidakmu dengan cermat untuk mengalahkan lawan dan menguasai papan catur.", "image" => "assets/img/catur.png", "favorite" => false, "play_url" => "chess/"],
  ["id" => 3, "title" => "Snake", "developer" => "MEeL Teams", "year" => 2026, "category" => "Action", "controls" => "Arrow & WASD Keys", "maxPlayers" => "1 Players", "rating" => 4.8, "description" => "Game ular klasik yang tak pernah mati. Kendalikan ular hijau, kumpulkan apel merah, dan hindari menabrak dinding atau tubuh sendiri. Semakin banyak makan, semakin cepat! Cocok untuk mengisi waktu luang dan melatih refleks.", "image" => "assets/img/snake.png", "favorite" => false, "play_url" => "snake/"]
];
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8" />
  <meta name="description" content="Koleksi game arcade klasik yang ikonik, lengkap dengan detail spesifikasi kabinet dan fitur favorit untuk para penggemar nostalgia." />
  <meta property="og:title" content="Koleksi Game Arcade Klasik — MEeL Arcade" />
  <meta property="og:description" content="Koleksi game arcade klasik untuk para penggemar nostalgia. Mainkan Miku & Teto Run, Chess, dan Snake!" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Koleksi Game Arcade Klasik</title>
  <?php include '../partials/link.php'; ?>
</head>

<body class="bg-slate-950 text-slate-100 font-sans min-h-screen flex flex-col selection:bg-pink-500 selection:text-white">
  <header class="border-b border-slate-800 bg-slate-900/80 backdrop-blur-md sticky top-0 z-40 transition-all duration-300">
    <div class="max-w-7xl mx-auto px-4 py-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row justify-between items-center gap-4">
      <div class="flex items-center gap-3">
        <div class="p-2 bg-pink-500/10 rounded-lg border border-pink-500/30 animate-pulse cursor-pointer" onclick="window.location.href='../index.php'" title="Kembali ke MEeL Hub">
          <img src="../assets/MEeL.png" alt="Logo MEeL-Arcade" class="w-10 h-10 object-contain" />
        </div>
        <div>
          <h1 class="text-xl sm:text-2xl font-bold tracking-wider uppercase text-transparent bg-clip-text bg-gradient-to-r from-pink-500 to-blue-500">MEeL-Arcade</h1>
          <p class="text-xs text-slate-400 tracking-widest uppercase">Koleksi Game & Kabinet Klasik</p>
        </div>
      </div>
      <div class="flex gap-4 text-sm">
        <div class="bg-slate-950/60 border border-slate-800 px-3 py-1.5 rounded-lg flex items-center gap-2">
          <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-ping"></span>
          <span class="text-slate-400">Total Game:</span>
          <span id="stat-total" class="font-bold text-pink-400"><?= count($games) ?></span>
        </div>
        <div class="bg-slate-950/60 border border-slate-800 px-3 py-1.5 rounded-lg flex items-center gap-2">
          <i data-lucide="star" class="w-4 h-4 text-yellow-500 fill-yellow-500"></i>
          <span class="text-slate-400">Favorit:</span>
          <span id="stat-fav" class="font-bold text-blue-400">0</span>
        </div>
      </div>
    </div>
  </header>

  <main class="flex-grow max-w-7xl w-full mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <section class="mb-8 bg-slate-900/40 border border-slate-800/80 rounded-2xl p-6 backdrop-blur-sm">
      <div class="flex flex-col md:flex-row gap-4 items-center justify-between">
        <div class="relative w-full md:max-w-md">
          <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none"><i data-lucide="search" class="h-5 w-5 text-slate-500"></i></span>
          <input type="text" id="search-input" placeholder="Cari judul game atau developer..." class="w-full pl-10 pr-4 py-2.5 bg-slate-950 border border-slate-800 focus:border-pink-500 rounded-xl text-slate-200 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-pink-500/20 transition-all duration-200" />
        </div>
        <div class="flex flex-wrap gap-3 w-full md:w-auto justify-start md:justify-end">
          <select id="category-filter" class="px-4 py-2.5 bg-slate-950 border border-slate-800 focus:border-blue-500 rounded-xl text-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500/20 transition-all duration-200 cursor-pointer">
            <option value="all">Semua Kategori</option>
            <option value="Action">Action / Beat 'em up</option>
            <option value="Shooter">Space Shooter / Shmup</option>
            <option value="Fighting">Fighting</option>
            <option value="Platformer">Platformer</option>
            <option value="Puzzle">Puzzle</option>
            <option value="Racing">Racing</option>
          </select>
          <select id="sort-filter" class="px-4 py-2.5 bg-slate-950 border border-slate-800 focus:border-blue-500 rounded-xl text-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500/20 transition-all duration-200 cursor-pointer">
            <option value="newest">Tahun (Terbaru)</option>
            <option value="oldest">Tahun (Terlama)</option>
            <option value="alphabetical">Nama (A - Z)</option>
            <option value="rating">Rating Tertinggi</option>
          </select>
          <button id="fav-toggle-btn" class="flex items-center gap-2 px-4 py-2.5 bg-slate-950 border border-slate-800 hover:border-pink-500 hover:text-pink-500 rounded-xl text-slate-300 transition-all duration-200">
            <i data-lucide="heart" class="w-5 h-5"></i><span>Hanya Favorit</span>
          </button>
        </div>
      </div>
    </section>

    <section>
      <div id="game-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($games as $game): ?>
          <div class="bg-slate-900 border border-slate-800/80 rounded-2xl overflow-hidden hover:border-pink-500/50 transition-all duration-300 group flex flex-col h-full hover:scale-[1.02] hover:shadow-lg hover:shadow-pink-500/5 cursor-pointer game-card-item"
            data-id="<?= $game['id'] ?>"
            data-title="<?= htmlspecialchars(strtolower($game['title'])) ?>"
            data-developer="<?= htmlspecialchars(strtolower($game['developer'])) ?>"
            data-category="<?= htmlspecialchars($game['category']) ?>"
            data-year="<?= $game['year'] ?>"
            data-rating="<?= $game['rating'] ?>"
            onclick="openModal(<?= $game['id'] ?>)">
            <div class="relative h-48 overflow-hidden bg-slate-950">
              <img src="<?= htmlspecialchars($game['image']) ?>" alt="<?= htmlspecialchars($game['title']) ?>" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110 opacity-85 group-hover:opacity-100" />
              <div class="absolute top-3 left-3 bg-slate-950/80 backdrop-blur-md border border-slate-800 px-2.5 py-1 rounded-lg text-xs font-semibold text-slate-300"><?= $game['year'] ?></div>
              <button onclick="toggleFavorite(event, <?= $game['id'] ?>)" class="fav-btn absolute top-3 right-3 p-2 rounded-lg backdrop-blur-md border transition-all duration-200 bg-slate-950/80 border-slate-800 text-slate-400 hover:text-pink-500 hover:border-pink-500/50" data-game-id="<?= $game['id'] ?>"><i data-lucide="heart" class="w-4 h-4"></i></button>
            </div>
            <div class="p-5 flex flex-col flex-grow">
              <div class="flex items-center justify-between gap-2 mb-2">
                <span class="text-xs font-bold text-blue-400 tracking-wider uppercase"><?= htmlspecialchars($game['category']) ?></span>
                <div class="flex items-center gap-1 text-xs text-amber-400"><i data-lucide="star" class="w-3.5 h-3.5 fill-amber-400"></i><span><?= number_format($game['rating'], 1) ?></span></div>
              </div>
              <h3 class="text-lg font-bold text-white group-hover:text-pink-400 transition-colors duration-200 line-clamp-1 mb-1"><?= htmlspecialchars($game['title']) ?></h3>
              <p class="text-xs text-slate-500 font-semibold mb-4"><?= htmlspecialchars($game['developer']) ?></p>
              <p class="text-sm text-slate-400 line-clamp-2 mb-4 flex-grow"><?= htmlspecialchars($game['description']) ?></p>
              <div class="pt-4 border-t border-slate-800 flex items-center justify-between text-xs text-slate-500">
                <span class="flex items-center gap-1"><i data-lucide="users" class="w-3.5 h-3.5 text-slate-600"></i><?= explode(' ', $game['maxPlayers'])[0] ?> Pemain</span>
                <span class="flex items-center gap-1"><i data-lucide="sliders" class="w-3.5 h-3.5 text-slate-600"></i><?= explode(' ', $game['controls'])[0] ?></span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div id="empty-state" class="hidden flex-col items-center justify-center py-16 text-center">
        <div class="p-4 bg-slate-900 rounded-full border border-slate-800 mb-4"><i data-lucide="frown" class="w-12 h-12 text-slate-600"></i></div>
        <h3 class="text-xl font-bold text-slate-300">Game Tidak Ditemukan</h3>
        <p class="text-slate-500 mt-1">Coba sesuaikan kata kunci pencarian atau filter kategori Anda.</p>
        <button onclick="resetFilters()" class="mt-4 px-4 py-2 bg-pink-500 hover:bg-pink-600 active:scale-95 text-white font-semibold rounded-xl transition-all">Reset Filter</button>
      </div>
    </section>
  </main>

  <div id="detail-modal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 w-full max-w-2xl rounded-2xl overflow-hidden shadow-2xl relative transform scale-95 opacity-0 transition-all duration-300" id="modal-container">
      <div class="relative h-48 sm:h-64 bg-slate-950">
        <img id="modal-banner" src="" alt="Banner Game" class="w-full h-full object-cover opacity-60" />
        <div class="absolute inset-0 bg-gradient-to-t from-slate-900 via-slate-900/40 to-transparent"></div>
        <button onclick="closeModal()" class="absolute top-4 right-4 p-2 bg-slate-900/80 hover:bg-slate-800 text-slate-400 hover:text-white rounded-full border border-slate-800 transition-all"><i data-lucide="x" class="w-5 h-5"></i></button>
      </div>
      <div class="p-6 sm:p-8">
        <div class="flex flex-wrap items-center gap-2 mb-3">
          <span id="modal-category" class="px-2.5 py-1 text-xs font-semibold bg-blue-500/10 text-blue-400 border border-blue-500/20 rounded-full">Kategori</span>
          <span id="modal-year" class="px-2.5 py-1 text-xs font-semibold bg-slate-800 text-slate-300 rounded-full">Tahun</span>
        </div>
        <h2 id="modal-title" class="text-2xl sm:text-3xl font-bold text-white mb-2">Nama Game</h2>
        <p id="modal-developer" class="text-sm text-pink-400 mb-6 font-semibold">Developer</p>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6 bg-slate-950/50 border border-slate-800 p-4 rounded-xl">
          <div><span class="block text-xs text-slate-500">Tipe Kontrol</span><span id="modal-control" class="text-sm font-semibold text-slate-300">-</span></div>
          <div><span class="block text-xs text-slate-500">Maks. Pemain</span><span id="modal-players" class="text-sm font-semibold text-slate-300">-</span></div>
          <div><span class="block text-xs text-slate-500">Rating</span>
            <div class="flex items-center gap-1 mt-0.5"><i data-lucide="star" class="w-4 h-4 text-yellow-500 fill-yellow-500"></i><span id="modal-rating" class="text-sm font-semibold text-slate-300">-</span></div>
          </div>
        </div>
        <div class="mb-6">
          <h3 class="text-sm font-bold uppercase tracking-wider text-slate-400 mb-2">Sinopsis / Deskripsi</h3>
          <p id="modal-description" class="text-slate-300 leading-relaxed text-sm sm:text-base">Deskripsi lengkap dari game arcade.</p>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-800 pt-6">
          <button id="modal-fav-btn" class="flex items-center gap-2 px-4 py-2 border border-slate-800 hover:border-pink-500 rounded-xl text-slate-300 transition-all duration-200"><i data-lucide="heart" class="w-5 h-5"></i><span>Tambah Favorit</span></button>
          <button id="modal-play-btn" type="button" class="px-5 py-2 bg-slate-800 hover:bg-slate-700 text-white rounded-xl transition-all">Play</button>
        </div>
      </div>
    </div>
  </div>

  <?php include "../partials/footer.php"; ?>

  <script>
    const games = (() => {
      const init = <?= json_encode($games) ?>,
        saved = JSON.parse(localStorage.getItem("arcade_games")) || [],
        map = {};
      saved.forEach(g => map[g.id] = g.favorite);
      const merged = init.map(ig => ({
        ...ig,
        favorite: map[ig.id] !== undefined ? map[ig.id] : ig.favorite
      }));
      localStorage.setItem("arcade_games", JSON.stringify(merged));
      return merged;
    })();
    let favOnly = false,
      filter, sort, search;
    const $ = id => document.getElementById(id);
    const grid = $("game-grid"),
      empty = $("empty-state"),
      statFav = $("stat-fav"),
      favBtn = $("fav-toggle-btn");
    const modal = $("detail-modal"),
      mc = $("modal-container");
    const mb = {},
      mIds = ["banner", "category", "year", "title", "developer", "control", "players", "rating", "description", "fav-btn", "play-btn"];
    mIds.forEach(k => mb[k] = $(`modal-${k}`));
    function save() {
      localStorage.setItem("arcade_games", JSON.stringify(games));
      updateStats();
      render();
    }
    function updateStats() {
      $("stat-total").textContent = games.length;
      statFav.textContent = games.filter(g => g.favorite).length;
      games.forEach(g => {
        const btn = document.querySelector(`.fav-btn[data-game-id="${g.id}"]`);
        if (!btn) return;
        btn.className = g.favorite ?
          "fav-btn absolute top-3 right-3 p-2 rounded-lg backdrop-blur-md border transition-all duration-200 bg-pink-500/20 border-pink-500 text-pink-500" :
          "fav-btn absolute top-3 right-3 p-2 rounded-lg backdrop-blur-md border transition-all duration-200 bg-slate-950/80 border-slate-800 text-slate-400 hover:text-pink-500 hover:border-pink-500/50";
        btn.innerHTML = g.favorite ? '<i data-lucide="heart" class="w-4 h-4 fill-pink-500"></i>' : '<i data-lucide="heart" class="w-4 h-4"></i>';
      });
      lucide.createIcons();
    }
    function render() {
      const term = (search.value || "").toLowerCase().trim(),
        cat = filter.value,
        srt = sort.value;
      const ids = games.filter(g =>
        (g.title.toLowerCase().includes(term) || g.developer.toLowerCase().includes(term)) &&
        (cat === "all" || g.category === cat) && (!favOnly || g.favorite)
      ).map(g => g.id);
      const cards = Array.from(document.querySelectorAll(".game-card-item"));
      let visible = 0;
      cards.forEach(c => {
        const m = ids.includes(parseInt(c.dataset.id));
        c.classList.toggle("hidden", !m);
        if (m) visible++;
      });
      if (visible) {
        cards.sort((a, b) => {
          const ga = games.find(g => g.id === parseInt(a.dataset.id)),
            gb = games.find(g => g.id === parseInt(b.dataset.id));
          if (srt === "newest") return gb.year - ga.year;
          if (srt === "oldest") return ga.year - gb.year;
          if (srt === "alphabetical") return ga.title.localeCompare(gb.title);
          if (srt === "rating") return gb.rating - ga.rating;
          return 0;
        });
        cards.forEach(c => grid.appendChild(c));
        grid.classList.remove("hidden");
        empty.classList.add("hidden");
        empty.classList.remove("flex");
      } else {
        grid.classList.add("hidden");
        empty.classList.remove("hidden");
        empty.classList.add("flex");
      }
      lucide.createIcons();
    }
    function toggleFavorite(e, id) {
      if (e) e.stopPropagation();
      games = games.map(g => g.id === id ? {
        ...g,
        favorite: !g.favorite
      } : g);
      save();
      const cur = modal.dataset.activeId;
      if (cur && parseInt(cur) === id) updateModalFavBtn(id);
    }
    function updateModalFavBtn(id) {
      const g = games.find(g => g.id === id);
      if (!g) return;
      (mb["fav-btn"]).className = g.favorite ?
        "flex items-center gap-2 px-4 py-2 border border-pink-500 bg-pink-500/10 text-pink-500 rounded-xl transition-all duration-200" :
        "flex items-center gap-2 px-4 py-2 border border-slate-800 hover:border-pink-500 rounded-xl text-slate-300 transition-all duration-200";
      mb["fav-btn"].innerHTML = g.favorite ? '<i data-lucide="heart" class="w-5 h-5 fill-pink-500"></i> <span>Hapus Favorit</span>' : '<i data-lucide="heart" class="w-5 h-5"></i> <span>Tambah Favorit</span>';
      mb["fav-btn"].onclick = e => toggleFavorite(e, id);
      lucide.createIcons();
    }
    function openModal(id) {
      const g = games.find(g => g.id === id);
      if (!g) return;
      modal.dataset.activeId = id;
      mb.banner.src = g.image;
      mb.banner.onerror = function() {
        this.src = "https://images.unsplash.com/photo-1538481199705-c710c4e965fc?auto=format&fit=crop&w=800&q=80";
      };
      mb.category.textContent = g.category;
      mb.year.textContent = g.year;
      mb.title.textContent = g.title;
      mb.developer.textContent = g.developer;
      mb.control.textContent = g.controls;
      mb.players.textContent = g.maxPlayers;
      mb.rating.textContent = g.rating.toFixed(1);
      mb.description.textContent = g.description;
      mb["play-btn"].onclick = () => {
        window.location.href = g.play_url || `${g.id}.html`;
      };
      updateModalFavBtn(id);
      modal.classList.remove("hidden");
      setTimeout(() => {
        mc.classList.remove("scale-95", "opacity-0");
        mc.classList.add("scale-100", "opacity-100");
      }, 50);
      document.body.style.overflow = "hidden";
    }
    function closeModal() {
      mc.classList.remove("scale-100", "opacity-100");
      mc.classList.add("scale-95", "opacity-0");
      setTimeout(() => {
        modal.classList.add("hidden");
        modal.removeAttribute("data-active-id");
        document.body.style.overflow = "";
      }, 200);
    }
    function resetFilters() {
      search.value = "";
      filter.value = "all";
      sort.value = "newest";
      favOnly = false;
      updateFavStyle();
      render();
    }
    function updateFavStyle() {
      favBtn.classList.toggle("bg-slate-950", !favOnly);
      favBtn.classList.toggle("border-slate-800", !favOnly);
      favBtn.classList.toggle("text-slate-300", !favOnly);
      favBtn.classList.toggle("bg-pink-500/20", favOnly);
      favBtn.classList.toggle("border-pink-500", favOnly);
      favBtn.classList.toggle("text-pink-500", favOnly);
    }
    search = $("search-input");
    filter = $("category-filter");
    sort = $("sort-filter");
    search.addEventListener("input", render);
    filter.addEventListener("change", render);
    sort.addEventListener("change", render);
    favBtn.addEventListener("click", () => {
      favOnly = !favOnly;
      updateFavStyle();
      render();
    });
    modal.addEventListener("click", e => {
      if (e.target === modal) closeModal();
    });
    document.addEventListener("keydown", e => {
      if (e.key === "Escape" && !modal.classList.contains("hidden")) closeModal();
    });
    window.onload = () => {
      updateStats();
      render();
    };
  </script>
</body>

</html>