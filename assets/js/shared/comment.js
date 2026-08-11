/* shared/comment.js — JS bersama untuk section komentar. */
window.toggleReply = function (e) {
  const t = document.getElementById(e);
  if (!t) return;
  t.classList.toggle("hidden");
  const n = t.querySelector('input[type="text"]');
  n && !t.classList.contains("hidden") && n.focus();
};
window.meelConfirmHtmx = function (e) {
  const el = e && e.detail && e.detail.elt;
  const cfg = el && el.getAttribute("data-meel-confirm");
  if (!cfg) return;
  e.preventDefault();
  let opts = {};
  try {
    opts = JSON.parse(cfg);
  } catch (err) {}
  const row = el.closest(".comment-row");
  if (!row) {
    meelConfirm(opts).then((ok) => {
      if (ok && e.detail.issueRequest) e.detail.issueRequest(true);
    });
    return;
  }
  document.querySelectorAll(".meel-inline-confirm").forEach((n) => n.remove());
  const isVideo = !!row.querySelector("[class*='from-red-600']");
  const barCls = isVideo
    ? "border-red-500/30 bg-red-500/10"
    : "border-orange-500/30 bg-orange-500/10";
  const yesCls = isVideo
    ? "bg-red-600 hover:bg-red-500 text-white"
    : "bg-orange-500 hover:bg-orange-400 text-black";
  const bar = document.createElement("div");
  bar.className =
    "meel-inline-confirm flex items-center justify-between gap-2 mt-2 px-3 py-2 rounded-xl border " +
    barCls;
  const msg = document.createElement("span");
  msg.className =
    "text-[10px] text-gray-400 uppercase tracking-wider leading-tight";
  msg.textContent = opts.text || "Lanjutkan aksi ini?";
  const actions = document.createElement("span");
  actions.className = "flex items-center gap-1.5 flex-shrink-0";
  const yes = document.createElement("button");
  yes.type = "button";
  yes.className =
    yesCls +
    " text-[10px] font-black uppercase tracking-wider px-3 py-1.5 rounded-lg border-none cursor-pointer transition-all";
  yes.textContent = opts.confirmButtonText || "HAPUS";
  yes.setAttribute("aria-label", "Konfirmasi hapus komentar");
  yes.addEventListener("click", function () {
    bar.remove();
    if (e.detail.issueRequest) e.detail.issueRequest(true);
  });
  const no = document.createElement("button");
  no.type = "button";
  no.className =
    "bg-white/5 hover:bg-white/10 text-gray-400 text-[10px] font-black uppercase tracking-wider px-3 py-1.5 rounded-lg border border-white/10 cursor-pointer transition-all";
  no.textContent = "BATAL";
  no.setAttribute("aria-label", "Batalkan hapus komentar");
  no.addEventListener("click", function () {
    bar.remove();
  });
  actions.appendChild(yes);
  actions.appendChild(no);
  bar.appendChild(msg);
  bar.appendChild(actions);
  const content = row.querySelector(".flex-1.min-w-0") || row;
  content.appendChild(bar);
  yes.focus();
};
(function () {
  if (!window.htmx) return;
  document.body.addEventListener("htmx:confirm", window.meelConfirmHtmx);
})();
window.toggleCommentSection = (function () {
  let focusTimer = null;
  return function () {
    const body = document.getElementById("comment-body"),
      chevron = document.getElementById("comment-chevron"),
      preview = document.getElementById("comment-preview"),
      toggle = document.getElementById("comment-toggle");
    if (!body) return;
    const closed = body.classList.toggle("collapsed");
    if (closed) clearTimeout(focusTimer);
    if (chevron) chevron.classList.toggle("rotate-180", !closed);
    if (toggle) toggle.setAttribute("aria-expanded", closed ? "false" : "true");
    if (preview) preview.classList.toggle("preview-closed", !closed);
    if (closed) {
      const list = document.getElementById("comment-list"),
        txt = document.getElementById("comment-preview-text");
      if (txt && list) {
        const rows = Array.from(list.querySelectorAll(".comment-row"));
        if (rows.length > 0) {
          rows.sort((a, b) => {
            const aid = parseInt(a.getAttribute("data-id") || "0", 10),
              bid = parseInt(b.getAttribute("data-id") || "0", 10);
            return bid - aid;
          });
          const top = rows.slice(0, 4);
          txt.replaceChildren();
          txt.classList.remove("italic");
          top.forEach((row) => {
            const nameEl = row.querySelector("span[class*='font-bold']"),
              bodyEl = row.querySelector("p");
            const name = nameEl ? nameEl.textContent.trim() : "Guest";
            const colorMatch = nameEl
              ? nameEl.className.match(
                  /text-(red|orange|gray|blue|green|yellow|white|purple)-\d+/,
                )
              : null;
            const nameColor = colorMatch ? colorMatch[0] : "";
            let body = "";
            if (bodyEl) {
              const clone = bodyEl.cloneNode(true);
              const badge = clone.querySelector("span");
              if (badge) badge.remove();
              body = clone.textContent.trim().replace(/\s+/g, " ");
            }
            const line = document.createElement("div");
            line.className = "text-[10px] text-gray-500 line-clamp-1";
            const nm = document.createElement("span");
            nm.className = "font-bold " + nameColor;
            nm.textContent = name + ": ";
            line.appendChild(nm);
            line.append(body);
            line.title = line.textContent;
            txt.appendChild(line);
          });
        } else {
          txt.replaceChildren();
          txt.classList.add("italic");
          const empty = document.createElement("span");
          empty.textContent = "Jadilah komentar pertama";
          txt.appendChild(empty);
        }
      }
    } else {
      const ta = body.querySelector('textarea[name="comments"]');
      ta && (focusTimer = setTimeout(() => ta.focus(), 360));
    }
  };
})();
