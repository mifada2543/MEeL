/* MEeL Admin — Dashboard (index.php) */
(function () {
  "use strict";

  // Interval polling server stats — bisa diatur admin lewat dropdown
  // #stats-poll-interval (1/3/5/10 detik) dan tersimpan di localStorage.
  var DEFAULT_POLL_INTERVAL_MS = 3000;
  var POLL_OPTIONS = [1000, 3000, 5000, 10000];
  var STORAGE_KEY = "meel_admin_poll_interval";
  var pollTimer = null;

  // SSE (Server-Sent Events) — jalur utama push realtime; fallback ke polling
  // bila stream macet/gagal (mis. proxy buffering).
  var source = null;
  var streamMode = "sse"; // "sse" | "polling"
  var lastSseMessage = 0;
  var sseErrorCount = 0;
  var sseWatchdog = null;
  var connStartedAt = 0; // awal percobaan koneksi SSE (untuk latensi handshake)

  // Tick status online/offline Live Activity Monitor (klien, dari data-sec-since).
  var MONITOR_TICK_MS = 1000;
  // Ambang offline = 5 menit tanpa aktivitas (sama dengan logika PHP).
  var ONLINE_WINDOW_SEC = 300;

  function setText(id, value) {
    var el = document.getElementById(id);
    if (el) el.textContent = value;
  }

  function formatBytes(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + " GB";
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + " MB";
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + " KB";
    return bytes + " B";
  }

  // Kecepatan transfer (byte/detik) untuk kartu Network.
  function formatSpeed(bps) {
    if (bps >= 1048576) return (bps / 1048576).toFixed(2) + " MB/s";
    if (bps >= 1024) return (bps / 1024).toFixed(1) + " KB/s";
    return Math.round(bps) + " B/s";
  }

  // Baseline polling sebelumnya untuk menghitung kecepatan network
  // (delta bytes antar polling / delta waktu).
  var netPrev = { rx: null, tx: null, t: 0 };
  // Grafik riwayat kecepatan network (line chart di kartu Network).
  var netChart = null;
  var NET_HISTORY_MAX = 60; // sampel terakhir (60 × interval polling)

  // ─── GRAFIK RIWAYAT KECEPATAN NETWORK ───
  function setupNetChart() {
    var canvas = document.getElementById("netChart");
    if (!canvas || typeof Chart === "undefined") return;
    netChart = new Chart(canvas, {
      type: "line",
      data: {
        labels: [],
        datasets: [
          {
            label: "↓ Download",
            data: [],
            borderColor: "#3b82f6",
            backgroundColor: "rgba(59, 130, 246, 0.08)",
            fill: true,
            tension: 0.3,
            pointRadius: 0,
            borderWidth: 1.5,
          },
          {
            label: "↑ Upload",
            data: [],
            borderColor: "#f59e0b",
            backgroundColor: "rgba(245, 158, 11, 0.08)",
            fill: true,
            tension: 0.3,
            pointRadius: 0,
            borderWidth: 1.5,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        interaction: { mode: "index", intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: "rgba(17, 24, 39, 0.95)",
            borderColor: "rgba(255,255,255,0.1)",
            borderWidth: 1,
            titleColor: "#9ca3af",
            bodyColor: "#e5e7eb",
            padding: 8,
            titleFont: { size: 9 },
            bodyFont: { size: 10 },
            callbacks: {
              label: function (ctx) {
                return ctx.dataset.label + ": " + formatSpeed(ctx.parsed.y);
              },
            },
          },
        },
        scales: {
          x: {
            grid: { color: "rgba(255,255,255,0.03)" },
            ticks: { color: "#6b7280", font: { size: 8 }, maxTicksLimit: 5, maxRotation: 0 },
          },
          y: {
            beginAtZero: true,
            grid: { color: "rgba(255,255,255,0.03)" },
            ticks: {
              color: "#6b7280",
              font: { size: 8 },
              maxTicksLimit: 4,
              callback: function (value) {
                return formatSpeed(value);
              },
            },
          },
        },
      },
    });
  }

  function pushNetSample(rxRate, txRate) {
    if (!netChart) return;
    netChart.data.labels.push(new Date().toLocaleTimeString("id-ID", { hour12: false }));
    netChart.data.datasets[0].data.push(Math.round(rxRate));
    netChart.data.datasets[1].data.push(Math.round(txRate));
    if (netChart.data.labels.length > NET_HISTORY_MAX) {
      netChart.data.labels.shift();
      netChart.data.datasets[0].data.shift();
      netChart.data.datasets[1].data.shift();
    }
    netChart.update();
  }

  // Ambang warna identik dengan PHP di admin/index.php.
  function barClass(card, perc) {
    switch (card) {
      case "cpu":
        return perc > 80 ? "bg-red-500" : perc > 50 ? "bg-yellow-500" : "bg-green-500";
      case "ram":
        return perc > 80 ? "bg-red-500" : perc > 50 ? "bg-yellow-500" : "bg-cyan-500";
      case "swap":
        return perc > 50 ? "bg-red-500" : "bg-gray-500";
    }
    return "";
  }

  function updateBar(card, perc) {
    var bar = document.getElementById("stat-" + card + "-bar");
    if (!bar) return;
    var pct = Math.min(100, Math.max(0, perc));
    bar.style.width = pct + "%";
    var cls = barClass(card, pct);
    if (cls) {
      bar.className = bar.className.replace(/bg-(red|yellow|green|cyan|blue|gray)-500/g, cls);
    }
    var icon = document.getElementById("stat-" + card + "-icon");
    if (icon && cls) {
      var tcls = cls.replace("bg-", "text-").replace("-500", "-400");
      icon.className = icon.className.replace(/text-(red|yellow|green|cyan|blue|gray)-400/g, tcls);
    }
  }

  function setLiveStatus(ok) {
    var badge = document.getElementById("stats-live");
    if (!badge) return;
    var trio = /text-\w+-500\s+bg-\w+-500\/10\s+border-\w+-500\/25/;
    if (ok) {
      badge.className = badge.className.replace(trio, "text-green-500 bg-green-500/10 border-green-500/25");
      var dot = badge.querySelector("span");
      if (dot) dot.className = "h-1.5 w-1.5 rounded-full bg-green-500 animate-pulse";
      setText("stats-updated", "Diperbarui " + new Date().toLocaleTimeString("id-ID"));
    } else {
      badge.className = badge.className.replace(trio, "text-red-500 bg-red-500/10 border-red-500/25");
      var dotOff = badge.querySelector("span");
      if (dotOff) dotOff.className = "h-1.5 w-1.5 rounded-full bg-red-500";
      setText("stats-updated", "Koneksi terputus");
    }
  }

  // Latensi (RTT) koneksi admin → server, diukur dari tiap polling.
  // Warna: hijau < 50ms, kuning < 150ms, merah ≥ 150ms.
  function setLatency(ms) {
    var el = document.getElementById("stat-net-ping");
    if (!el) return;
    el.textContent = ms + " ms";
    el.classList.remove("text-gray-500", "text-green-400", "text-yellow-400", "text-red-400");
    el.classList.add(ms < 50 ? "text-green-400" : ms < 150 ? "text-yellow-400" : "text-red-400");
  }

  function applyServerStats(s) {
    var cpu = s.cpu, ram = s.ram, swap = s.swap, net = s.network, info = s.info, up = s.uptime;

    // CPU
    setText("stat-cpu-value", cpu.load_1m);
    setText("stat-cpu-sub", cpu.cores + " Cores • " + cpu.usage_perc + "%");
    updateBar("cpu", cpu.usage_perc);

    // RAM
    setText("stat-ram-value", formatBytes(ram.used));
    setText("stat-ram-sub", formatBytes(ram.total) + " Total • " + ram.usage_perc + "%");
    updateBar("ram", ram.usage_perc);

    // Swap
    setText("stat-swap-value", formatBytes(swap.used));
    setText("stat-swap-sub", formatBytes(swap.total) + " Total • " + swap.usage_perc + "%");
    updateBar("swap", swap.usage_perc);

    // Network — kecepatan download/upload (delta antar polling) + total kumulatif
    var nowMs = Date.now();
    if (netPrev.rx !== null && net.rx >= netPrev.rx && net.tx >= netPrev.tx) {
      var dt = Math.max(0.001, (nowMs - netPrev.t) / 1000);
      var rxRate = (net.rx - netPrev.rx) / dt;
      var txRate = (net.tx - netPrev.tx) / dt;
      setText("stat-net-value", "↓ " + formatSpeed(rxRate));
      setText("stat-net-sub", "↑ " + formatSpeed(txRate) + " · ↓ " + formatBytes(net.rx) + " / ↑ " + formatBytes(net.tx));
      pushNetSample(rxRate, txRate);
    } else {
      // Polling pertama atau counter di-reset (reboot) — butuh sampel kedua.
      setText("stat-net-value", "↓ —");
      setText("stat-net-sub", "↑ — · ↓ " + formatBytes(net.rx) + " / ↑ " + formatBytes(net.tx));
    }
    netPrev = { rx: net.rx, tx: net.tx, t: nowMs };

    // Uptime & info
    setText("stat-uptime", up.text);
    setText("stat-load", cpu.load_1m + " / " + cpu.load_5m + " / " + cpu.load_15m);
    setText("stat-procs", info.processes);

    setLiveStatus(true);
  }

  function refreshServerStats() {
    if (!window.serverStatsUrl) return;
    var t0 = Date.now();
    fetch(window.serverStatsUrl, { cache: "no-store", credentials: "same-origin" })
      .then(function (r) {
        if (!r.ok) throw new Error("HTTP " + r.status);
        return r.json();
      })
      .then(function (data) {
        if (data && data.status === "success" && data.server_stats) {
          applyServerStats(data.server_stats);
          setLatency(Date.now() - t0);
        } else {
          setLiveStatus(false);
        }
      })
      .catch(function () {
        setLiveStatus(false);
      });
  }

  // ─── PENGATURAN INTERVAL POLLING ───

  function loadPref(key, fallback) {
    try {
      var v = localStorage.getItem(key);
      return v === null ? fallback : v;
    } catch (e) {
      return fallback; // mode privat / localStorage tidak tersedia
    }
  }

  function savePref(key, value) {
    try {
      localStorage.setItem(key, value);
    } catch (e) {
      /* abaikan */
    }
  }

  function currentPollInterval() {
    var v = parseInt(loadPref(STORAGE_KEY, String(DEFAULT_POLL_INTERVAL_MS)), 10);
    return POLL_OPTIONS.indexOf(v) !== -1 ? v : DEFAULT_POLL_INTERVAL_MS;
  }

  function startStatsPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(refreshServerStats, currentPollInterval());
  }

  // ─── SSE: PUSH REALTIME DARI SERVER ───

  function startStatsStream() {
    if (source) return;
    if (!window.serverStatsSseUrl) {
      fallbackToPolling();
      return;
    }
    streamMode = "sse";
    sseErrorCount = 0;
    connStartedAt = Date.now();
    source = new EventSource(window.serverStatsSseUrl + "?interval=" + currentPollInterval());

    source.addEventListener("open", function () {
      lastSseMessage = Date.now();
      setLiveStatus(true);
      setLatency(Date.now() - connStartedAt); // latensi handshake SSE
      startWatchdog();
    });

    source.addEventListener("message", function (ev) {
      sseErrorCount = 0;
      lastSseMessage = Date.now();
      try {
        var data = JSON.parse(ev.data);
        if (data && data.status === "success" && data.server_stats) {
          applyServerStats(data.server_stats);
        } else {
          setLiveStatus(false);
        }
      } catch (e) {
        /* pesan rusak — abaikan */
      }
    });

    source.addEventListener("error", function () {
      connStartedAt = Date.now(); // percobaan reconnect dimulai dari sini
      sseErrorCount++;
      setLiveStatus(false);
      // EventSource auto-reconnect, tapi kalau gagal terus → fallback polling.
      if (sseErrorCount >= 3) {
        fallbackToPolling();
      }
    });
  }

  function stopStatsStream() {
    if (source) {
      source.close();
      source = null;
    }
    stopWatchdog();
  }

  function restartStatsStream() {
    stopStatsStream();
    startStatsStream();
  }

  function fallbackToPolling() {
    stopStatsStream();
    streamMode = "polling";
    refreshServerStats();
    startStatsPolling();
  }

  // Watchdog: bila tidak ada pesan SSE dalam waktu lama (stream macet karena
  // buffering proxy), pindah ke polling agar data tetap mengalir.
  function startWatchdog() {
    stopWatchdog();
    var interval = currentPollInterval();
    var stallLimit = Math.max(20000, interval * 3);
    sseWatchdog = setInterval(function () {
      if (streamMode !== "sse" || !source) return;
      if (lastSseMessage > 0 && Date.now() - lastSseMessage > stallLimit) {
        fallbackToPolling();
      }
    }, 10000);
  }

  function stopWatchdog() {
    if (sseWatchdog) {
      clearInterval(sseWatchdog);
      sseWatchdog = null;
    }
  }

  function updateLiveBadgeTitle(ms) {
    var badge = document.getElementById("stats-live");
    if (badge) badge.title = "Data diperbarui otomatis setiap " + (ms / 1000) + " detik";
  }

  function setupIntervalControl() {
    var sel = document.getElementById("stats-poll-interval");
    if (!sel) return;

    // Sinkronkan dropdown dengan preferensi tersimpan.
    sel.value = String(currentPollInterval());
    updateLiveBadgeTitle(currentPollInterval());

    sel.addEventListener("change", function () {
      var v = parseInt(sel.value, 10);
      if (POLL_OPTIONS.indexOf(v) === -1) v = DEFAULT_POLL_INTERVAL_MS;
      savePref(STORAGE_KEY, String(v));
      updateLiveBadgeTitle(v);
      if (streamMode === "sse") {
        restartStatsStream(); // interval baru = koneksi SSE baru
      } else {
        startStatsPolling();
      }
    });
  }

  // Status Online/Offline Live Activity Monitor — dihitung ulang di sisi
  // klien dari data-sec-since (detik sejak aktivitas terakhir) tiap detik.
  function startMonitorTicking() {
    var rows = document.querySelectorAll("#monitor tr[data-sec-since]");
    if (!rows.length) return;
    var last = Date.now();
    setInterval(function () {
      var now = Date.now();
      var delta = Math.max(1, Math.round((now - last) / 1000));
      last = now;
      rows.forEach(function (row) {
        var sec = parseInt(row.getAttribute("data-sec-since"), 10) + delta;
        row.setAttribute("data-sec-since", sec);
        var online = sec < ONLINE_WINDOW_SEC;
        var cell = row.querySelector(".monitor-status");
        if (!cell) return;
        var dot = cell.querySelector(".monitor-dot");
        var label = cell.querySelector(".monitor-label");
        if (online) {
          cell.classList.add("text-green-500");
          cell.classList.remove("text-gray-600");
          if (dot) {
            dot.classList.add("bg-green-500", "animate-pulse");
            dot.classList.remove("bg-gray-700");
          }
          if (label) label.textContent = "Online";
        } else {
          cell.classList.remove("text-green-500");
          cell.classList.add("text-gray-600");
          if (dot) {
            dot.classList.remove("bg-green-500", "animate-pulse");
            dot.classList.add("bg-gray-700");
          }
          if (label) label.textContent = "Offline";
        }
      });
    }, MONITOR_TICK_MS);
  }

  document.addEventListener("DOMContentLoaded", function () {
    if (typeof lucide !== "undefined") lucide.createIcons();
    // ─── 7-DAY ACTIVITY BAR CHART ───
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

    // ─── REALTIME SERVER STATS (SSE push, fallback polling otomatis) ───
    setupNetChart();
    startStatsStream();
    setupIntervalControl();

    // ─── LIVE ACTIVITY MONITOR (status online/offline tiap detik) ───
    startMonitorTicking();
  });
})();
