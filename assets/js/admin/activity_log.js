/** MEeL Admin — Activity Log (activity_log.php)
 * Action dropdown toggle, filter submission, pill buttons
 * Dependencies:
 *   - admin/main.js
 *   - compatibilitas/lucide.js
 *   - compatibilitas/sweetalert2.all.min.js **/
(function () {
  "use strict";
  document.addEventListener("DOMContentLoaded", function () {
    if (typeof lucide !== "undefined") lucide.createIcons();
    // ── Action Dropdown ──
    var trigger = document.getElementById("action-dropdown-trigger");
    var panel = document.getElementById("action-dropdown-panel");
    var actionInput = document.getElementById("action-input");
    var actionLabel = document.getElementById("action-dropdown-label");
    if (trigger && panel) {
      trigger.addEventListener("click", function (e) {
        e.stopPropagation();
        panel.classList.toggle("hidden");
      });
      // Option clicks
      panel.querySelectorAll(".action-dropdown-option").forEach(function (opt) {
        opt.addEventListener("click", function () {
          var val = this.dataset.value || "";
          if (actionInput) actionInput.value = val;
          if (actionLabel) actionLabel.textContent = val || "Semua Aksi";
          panel
            .querySelectorAll(".action-dropdown-option")
            .forEach(function (o) {
              o.classList.toggle("active", o.dataset.value === val);
            });
          panel.classList.add("hidden");
        });
      });
      // Close on outside click
      document.addEventListener("click", function () {
        if (!panel.classList.contains("hidden")) {
          panel.classList.add("hidden");
        }
      });
    }
    // ── Search Enter key ──
    var searchInput = document.getElementById("search-input");
    if (searchInput) {
      searchInput.addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          submitFilters();
        }
      });
    }
  });
  // ── Days Pill Buttons ──
  window.selectDays = function (val) {
    var input = document.getElementById("days-input");
    if (input) input.value = val;
    document.querySelectorAll(".pill-btn[data-days]").forEach(function (btn) {
      btn.classList.toggle("active-blue", parseInt(btn.dataset.days) === val);
    });
  }
  // ── Clear Days Pill Buttons (Maintenance) ──
  window.selectClearDays = function (val) {
    var input = document.getElementById("clear-days-input");
    if (input) input.value = val;
    document.querySelectorAll("[data-clear-days]").forEach(function (btn) {
      btn.classList.toggle(
        "active-red",
        parseInt(btn.dataset.clearDays) === val,
      );
    });
  };
  // ── Submit Filters ──
  window.submitFilters = function () {
    var action = document.getElementById("action-input");
    var q = document.getElementById("search-input");
    var days = document.getElementById("days-input");
    var params = new URLSearchParams();
    if (action && action.value) params.set("action", action.value);
    if (q && q.value.trim()) params.set("q", q.value.trim());
    if (days && days.value) params.set("days", days.value);

    window.location.href = "activity_log.php?" + params.toString();
  };
  // ── Action Dropdown Toggle ──
  window.toggleActionDropdown = function () {
    var panel = document.getElementById("action-dropdown-panel");
    if (panel) panel.classList.toggle("hidden");
  };
  // ── Action Select ──
  window.selectAction = function (val) {
    var input = document.getElementById("action-input");
    var label = document.getElementById("action-dropdown-label");
    if (input) input.value = val;
    if (label) label.textContent = val || "Semua Aksi";
    var panel = document.getElementById("action-dropdown-panel");
    if (panel) {
      panel.querySelectorAll(".action-dropdown-option").forEach(function (o) {
        o.classList.toggle("active", o.dataset.value === val);
      });
      panel.classList.add("hidden");
    }
  };
  // ── Preview Export ──
  /**
   * Preview export data di SweetAlert2 modal sebelum didownload.
   * @param {string} format - 'csv', 'json', atau 'xls'
   */
  window.previewExport = function (format) {
    var params = new URLSearchParams(window.location.search);
    params.set("preview", "1");
    params.set("format", format);
    var previewUrl = "?" + params.toString();
    params.delete("preview");
    params.set("export", format);
    var downloadUrl = "?" + params.toString();
    var formatLabels = { csv: "CSV", json: "JSON", xls: "Excel (XLS)" };
    var formatIcons = {
      csv: "file-down",
      json: "file-code",
      xls: "file-spreadsheet",
    };
    var accentColors = { csv: "#10b981", json: "#0ea5e9", xls: "#8b5cf6" };
    fetch(previewUrl)
      .then(function (res) {
        if (!res.ok) throw new Error("Gagal memuat preview");
        return res.json();
      })
      .then(function (data) {
        var escaped = data.content
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;");

        Swal.fire({
          title: "Preview — " + formatLabels[format],
          html:
            '<div style="text-align:left">' +
            '<div style="display:flex;align-items:center;justify-content:space-between;font-size:10px;color:#6b7280;text-transform:uppercase;letter-spacing:.1em;font-weight:800;margin-bottom:10px">' +
            '<span><svg style="width:12px;height:12px;display:inline;margin-right:4px;vertical-align:middle" data-lucide="list" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>Menampilkan ' +
            data.preview_count +
            " dari " +
            data.total +
            " baris</span>" +
            '<span style="font-family:ui-monospace,monospace;color:' +
            accentColors[format] +
            '">.' +
            format +
            "</span>" +
            "</div>" +
            '<pre style="max-height:420px;overflow-y:auto;background:#090c12;border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:14px;font-size:11px;line-height:1.7;font-family:ui-monospace,monospace;color:#d1d5db;white-space:pre-wrap;word-break:break-all;margin:0 0 12px 0">' +
            escaped +
            "</pre>" +
            '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px">' +
            '<span style="font-size:9px;color:#4b5563">Total ' +
            data.total +
            " baris</span>" +
            '<button id="swal-download-btn" style="background:#1e293b;border:1px solid rgba(255,255,255,.08);color:#fff;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;padding:8px 18px;border-radius:10px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s" data-href="' +
            downloadUrl +
            '">' +
            '<svg style="width:14px;height:14px" data-lucide="' +
            formatIcons[format] +
            '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v14"/><path d="m19 9-7 7-7-7"/><path d="M5 21h14"/></svg> Download ' +
            formatLabels[format] +
            "</button>" +
            "</div>" +
            "</div>",
          background: "#0e1118",
          color: "#fff",
          width: "800px",
          showConfirmButton: false,
          showCloseButton: true,
          customClass: {
            closeButton: "text-gray-500 hover:text-white text-lg",
          },
          didOpen: function () {
            if (typeof lucide !== "undefined") lucide.createIcons();
            var btn = document.getElementById("swal-download-btn");
            if (btn) {
              btn.addEventListener("mouseenter", function () {
                this.style.background = "#334155";
              });
              btn.addEventListener("mouseleave", function () {
                this.style.background = "#1e293b";
              });
              btn.addEventListener("click", function () {
                window.location.href = this.dataset.href;
              });
            }
          },
        });
      })
      .catch(function (err) {
        Swal.fire({
          title: "Gagal!",
          text: "Tidak dapat memuat preview: " + err.message,
          icon: "error",
          background: "#0e1118",
          color: "#fff",
          confirmButtonColor: "#3b82f6",
        });
      });
  };
})();
