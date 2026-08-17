<?php
require_once '../auth/auth.php';
require_once '../auth/config.php';
require_once '../modules/media/SearchEngine.php';

$repo = new BookRepository($conn);
$u_id = (int)($_SESSION['user_id'] ?? 0);
$role = $repo->getUserRole($u_id);

$q      = SearchEngine::sanitizeQuery($_GET['search'] ?? '');
$type   = $_GET['type'] ?? 'all';
$type   = in_array($type, ['manga', 'pdf'], true) ? $type : 'all';
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$limit  = 24;

$result = $repo->searchBooks($q, $type, $offset, $limit + 1);

$rows    = [];
$hasMore = false;
if ($result) {
    while ($row = $result->fetch_assoc()) {
        if (count($rows) >= $limit) {
            $hasMore = true;
            break;
        }
        $rows[] = $row;
    }
}

if (count($rows) > 0) {
    foreach ($rows as $book) {
        include 'book_card.php';
    }

    if ($hasMore) {
        ?>
        <div id="load-more-book-area"
            class="col-span-full py-8 flex items-center justify-center bg-white/[.02] border border-dashed border-white/[.06] rounded-2xl cursor-pointer hover:border-green-500/30 hover:bg-white/[.03] transition-all group"
            hx-get="search?search=<?= urlencode($q) ?>&type=<?= $type ?>&offset=<?= $offset + $limit ?>"
            hx-target="#load-more-book-area"
            hx-swap="outerHTML"
            title="Muat lebih banyak buku">
            <span class="text-[10px] font-bold uppercase tracking-[.2em] text-gray-700 group-hover:text-green-500 transition-colors">
                Muat Lebih Banyak
            </span>
        </div>
        <?php
    }
} elseif ($offset === 0) {

    echo '<div class="col-span-full py-12 text-center text-[10px] text-gray-700 uppercase tracking-widest">Buku tidak ditemukan.</div>';
}
