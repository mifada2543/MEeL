/**
 * MEeL Admin — Shared: Table Row & Action Button Hover Effects **/
(function () {
  "use strict";
  function initHoverEffects() {
    // ── Table row hover effect ──
    document.querySelectorAll(".admin-table tbody tr").forEach(function (row) {
      row.addEventListener("mouseenter", function () {
        this.style.background = "rgba(255, 255, 255, 0.02)";
      });
      row.addEventListener("mouseleave", function () {
        this.style.background = "transparent";
      });
    });
    // ── Action button hover effects ──
    document.querySelectorAll(".action-btn-edit").forEach(function (btn) {
      btn.addEventListener("mouseenter", function () {
        this.style.background = "#2563eb";
        this.style.color = "#fff";
      });
      btn.addEventListener("mouseleave", function () {
        this.style.background = "rgba(37, 99, 235, 0.1)";
        this.style.color = "#60a5fa";
      });
    });
    document.querySelectorAll(".action-btn-delete").forEach(function (btn) {
      btn.addEventListener("mouseenter", function () {
        this.style.background = "#ef4444";
        this.style.color = "#fff";
      });
      btn.addEventListener("mouseleave", function () {
        this.style.background = "rgba(239, 68, 68, 0.08)";
        this.style.color = "#f87171";
      });
    });
  }
  document.addEventListener("DOMContentLoaded", initHoverEffects);
  if (window.htmx) {
    document.body.addEventListener("htmx:afterSwap", initHoverEffects);
  }
})();
