/* ============================================================
 * shared/comment.js — JS bersama untuk section komentar.
 * Dipakai oleh video/watch.php & music/watch.php.
 * Berisi: toggleReply (form balasan), meelConfirmHtmx (konfirmasi
 * SweetAlert2 untuk hx-confirm), dan toggleCommentSection
 * (expand/collapse section + preview komentar terbaru saat mini).
 * Depends on: htmx, meelConfirm (dari script.min.js)
 * ============================================================ */

// Toggle form balasan (muncul/sembunyi) + fokus input
window.toggleReply = function (e) {
    const t = document.getElementById(e);
    if (!t) return;
    t.classList.toggle("hidden");
    const n = t.querySelector('input[type="text"]');
    n && !t.classList.contains("hidden") && n.focus();
};

// Konfirmasi SweetAlert2 untuk hx-confirm (mis. hapus komentar).
// Intercept event htmx:confirm; jika elemen punya data-meel-confirm,
// tampilkan meelConfirm() dulu, lalu issueRequest(true) jika disetujui.
window.meelConfirmHtmx = function (e) {
    const el = e && e.detail && e.detail.elt;
    const cfg = el && el.getAttribute("data-meel-confirm");
    if (!cfg) return;
    e.preventDefault();
    let opts = {};
    try { opts = JSON.parse(cfg); } catch (err) {}
    meelConfirm(opts).then((ok) => {
        if (ok && e.detail.issueRequest) e.detail.issueRequest(true);
    });
};
(function () {
    if (!window.htmx) return;
    document.body.addEventListener("htmx:confirm", window.meelConfirmHtmx);
})();

// Toggle section komentar: expand (tampilkan form + daftar) / collapse (mini).
// Animasi diatur lewat class .collapsed (grid-rows 0fr) & .preview-closed.
// Saat collapse, preview menampilkan komentar terbaru dari DOM.
window.toggleCommentSection = (function () {
    let focusTimer = null;
    return function () {
        const body = document.getElementById("comment-body"),
            chevron = document.getElementById("comment-chevron"),
            preview = document.getElementById("comment-preview"),
            toggle = document.getElementById("comment-toggle");
        if (!body) return;
        const closed = body.classList.toggle("collapsed"); // true = kembali mini
        if (closed) clearTimeout(focusTimer); // batalkan fokus yang tertunda
        if (chevron) chevron.classList.toggle("rotate-180", !closed);
        if (toggle) toggle.setAttribute("aria-expanded", closed ? "false" : "true");
        if (preview) preview.classList.toggle("preview-closed", !closed);
        if (closed) {
            // Preview menampilkan komentar terbaru (data-id terbesar) di DOM
            const list = document.getElementById("comment-list"),
                txt = document.getElementById("comment-preview-text");
            if (txt && list) {
                const rows = list.querySelectorAll(".comment-row");
                if (rows.length > 0) {
                    let best = rows[0];
                    rows.forEach((r) => {
                        const rid = parseInt(r.getAttribute("data-id") || "0", 10);
                        if (rid > parseInt(best.getAttribute("data-id") || "0", 10)) best = r;
                    });
                    const nameEl = best.querySelector("span[class*='font-bold']"),
                        bodyEl = best.querySelector("p");
                    const name = nameEl ? nameEl.textContent.trim() : "Guest";
                    // Buang badge @parent dari komentar balasan agar preview
                    // konsisten dengan versi server-side (@author: teks).
                    let body = "";
                    if (bodyEl) {
                        const clone = bodyEl.cloneNode(true);
                        const badge = clone.querySelector("span");
                        if (badge) badge.remove();
                        body = clone.textContent.trim().replace(/\s+/g, " ");
                    }
                    txt.textContent = name + ": " + body;
                    txt.title = txt.textContent;
                    txt.classList.remove("italic");
                } else {
                    txt.textContent = "Jadilah komentar pertama";
                    txt.title = "Jadilah komentar pertama";
                    txt.classList.add("italic");
                }
            }
        } else {
            // Fokus ke textarea setelah animasi selesai (~0.35s) agar tidak jank
            const ta = body.querySelector('textarea[name="comments"]');
            ta && (focusTimer = setTimeout(() => ta.focus(), 360));
        }
    };
})();
