/* ============================================================
 * load-more.js — Observasi .lm-meta di <main> untuk update URL
 * tombol "Load More" + auto-scroll ke bawah saat konten playlist
 * ditambahkan via load_more_music.php.
 * (tanpa recovery — loadPlaylistById sudah handle save/restore sendiri)
 * Depends on: htmx (window.htmx)
 * ============================================================ */

(function(){
    var _main = document.querySelector('main');
    if (!_main) return;

    var _obs = new MutationObserver(function(muts) {
        for (var i = 0; i < muts.length; i++) {
            var added = muts[i].addedNodes;
            for (var j = 0; j < added.length; j++) {
                var n = added[j];
                if (n.nodeType !== 1 || !n.classList || !n.classList.contains('lm-meta')) continue;

                var nextUrl = n.getAttribute('data-next-url');
                var isEnd   = n.getAttribute('data-end');
                if (n.parentNode) n.parentNode.removeChild(n);

                var btn = document.getElementById('load-more-btn');
                var ld  = document.getElementById('load-more-music');

                if (nextUrl && btn) {
                    btn.setAttribute('hx-get', nextUrl);
                    // Update button text with current page
                    var pg = n.getAttribute('data-page') || '';
                    var tt = n.getAttribute('data-total') || '';
                    if (pg && tt) {
                        var btnText = btn.querySelector('span') || btn;
                        btnText.textContent = 'Load More \u00b7 ' + pg + '/' + tt;
                    }
                    if (typeof htmx !== 'undefined') htmx.process(btn);

                    // Auto-scroll: HANYA scroll ke BAWAH.
                    // Gunakan requestAnimationFrame agar posisi elemen sudah final
                    // setelah browser selesai reflow/rendering.
                    if (ld) {
                        requestAnimationFrame(function(){
                            var _r2 = ld.getBoundingClientRect();
                            if (_r2.bottom > window.innerHeight) {
                                window.scrollBy({ top: _r2.bottom - window.innerHeight + 20, behavior: 'smooth' });
                            }
                        });
                    }
                } else if (isEnd && ld) {
                    ld.outerHTML = '<div class="py-10 text-center text-[9px] text-gray-800 uppercase tracking-[.4em]">End of Collection</div>';
                }
                return;
            }
        }
    });
    _obs.observe(_main, { childList: true, subtree: true });
})();
