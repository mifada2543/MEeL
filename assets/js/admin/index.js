/** MEeL Admin — Dashboard (index.php)
 * Chart.js 7-Day Activity Chart + Auto-refresh
 * Dependencies:
 *   - compatibilitas/lucide.js
 *   - compatibilitas/chart.umd.min.js
 *   - admin/main.js (this file should load after main.js)
 * Global data:
 *   - window.activityData (JSON dari PHP: chart_activity) **/
(function () {
  "use strict";
  document.addEventListener("DOMContentLoaded", function () {
    if (typeof lucide !== "undefined") lucide.createIcons();
    // ── 7-DAY ACTIVITY BAR CHART ──
    var ctx2 = document.getElementById("activityChart");
    if (
      ctx2 &&
      typeof window.activityData !== "undefined" &&
      window.activityData.length > 0
    ) {
      if (typeof Chart !== "undefined") {
        new Chart(ctx2, {
          type: "bar",
          data: {
            labels: window.activityData.map(function (d) {
              return d.label;
            }),
            datasets: [
              {
                label: "Views",
                data: window.activityData.map(function (d) {
                  return d.views;
                }),
                backgroundColor: "rgba(59, 130, 246, 0.7)",
                borderColor: "#3b82f6",
                borderWidth: 1,
                borderRadius: 4,
                order: 1,
              },
              {
                label: "Uploads",
                data: window.activityData.map(function (d) {
                  return d.uploads;
                }),
                backgroundColor: "rgba(34, 197, 94, 0.7)",
                borderColor: "#22c55e",
                borderWidth: 1,
                borderRadius: 4,
                order: 2,
              },
              {
                label: "Active Users",
                data: window.activityData.map(function (d) {
                  return d.users;
                }),
                type: "line",
                borderColor: "#a855f7",
                backgroundColor: "rgba(168, 85, 247, 0.1)",
                pointBackgroundColor: "#a855f7",
                pointRadius: 3,
                borderWidth: 2,
                fill: true,
                tension: 0.3,
                order: 0,
              },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: "index", intersect: false },
            plugins: {
              legend: {
                labels: {
                  color: "#9ca3af",
                  font: { size: 9 },
                  boxWidth: 12,
                  padding: 8,
                },
              },
            },
            scales: {
              x: {
                grid: { color: "rgba(255,255,255,0.03)" },
                ticks: { color: "#6b7280", font: { size: 9 } },
              },
              y: {
                beginAtZero: true,
                grid: { color: "rgba(255,255,255,0.03)" },
                ticks: { color: "#6b7280", font: { size: 9 } },
              },
            },
          },
        });
      }
    }
    // ── Auto-refresh every 60 seconds ──
    setTimeout(function () {
      location.reload();
    }, 60000);
  });
})();
