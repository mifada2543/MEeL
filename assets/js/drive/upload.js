
(function () {
  "use strict";
  var form = document.getElementById("uploadForm");
  if (!form) return;
  var card = document.getElementById("uploadProgressCard");
  var bar = document.getElementById("uploadProgBar");
  var pct = document.getElementById("uploadProgPercent");
  var statusEl = document.getElementById("uploadProgStatus");
  var speedEl = document.getElementById("uploadProgSpeed");
  var durationEl = document.getElementById("uploadProgDuration");
  var sizeEl = document.getElementById("uploadProgSize");
  var filenameEl = document.getElementById("uploadProgFilename");
  var doneEl = document.getElementById("uploadProgDone");
  var errorEl = document.getElementById("uploadProgError");
  var closeBtn = document.getElementById("uploadProgClose");
  var toggleBtn = document.getElementById("uploadProgToggle");
  var submitBtn = document.getElementById("uploadBtn");
  var startTime = 0;
  var lastLoaded = 0;
  var lastTime = 0;
  var autoHideTimer = null;
  var pendingProgress = null;
  var rafId = null;
  var currentXhr = null;
  
  function formatBytes(bytes) {
    if (bytes === 0) return "0 B";
    var units = ["B", "KB", "MB", "GB"];
    var i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), 3);
    return (
      (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1) + " " + units[i]
    );
  }
  
  function formatSpeed(bytesPerSec) {
    if (bytesPerSec < 0) return "\u2014";
    return formatBytes(bytesPerSec) + "/s";
  }
  
  function formatDuration(seconds) {
    if (!seconds || seconds < 0) return "\u2014";
    var h = Math.floor(seconds / 3600);
    var m = Math.floor((seconds % 3600) / 60);
    var s = Math.floor(seconds % 60);
    if (h > 0)
      return (
        h + ":" + String(m).padStart(2, "0") + ":" + String(s).padStart(2, "0")
      );
    return String(m).padStart(2, "0") + ":" + String(s).padStart(2, "0");
  }
  
  function commitProgress() {
    rafId = null;
    if (!pendingProgress) return;
    var pp = pendingProgress;
    pendingProgress = null;
    bar.style.width = pp.percent + "%";
    pct.textContent = pp.percent + "%";
    speedEl.textContent = pp.speed;
    durationEl.textContent = pp.duration;
  }
  
  function scheduleProgress(data) {
    pendingProgress = data;
    if (!rafId) {
      rafId = requestAnimationFrame(commitProgress);
    }
  }
  
  function showCard(filename) {
    if (rafId) cancelAnimationFrame(rafId);
    rafId = null;
    pendingProgress = null;
    card.classList.remove(
      "hidden",
      "upload-prog-success",
      "upload-prog-fail",
      "minimized",
    );
    card.classList.add("upload-prog-visible");
    filenameEl.textContent = filename;
    void card.offsetHeight;
    bar.style.width = "0%";
    pct.textContent = "0%";
    statusEl.textContent = "Mengunggah...";
    speedEl.textContent = "\u2014";
    durationEl.textContent = "\u2014";
    sizeEl.textContent = "\u2014";
    doneEl.classList.add("hidden");
    errorEl.classList.add("hidden");
    startTime = Date.now();
    lastLoaded = 0;
    lastTime = startTime;
    submitBtn.disabled = true;
    var fi = document.getElementById("fileInput");
    if (fi) fi.disabled = true;
    submitBtn.innerHTML =
      '<svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>';
    submitBtn.classList.remove("bg-blue-600", "hover:bg-blue-700");
    submitBtn.classList.add("bg-gray-600", "cursor-not-allowed");
    if (autoHideTimer) {
      clearTimeout(autoHideTimer);
      autoHideTimer = null;
    }
  }
  
  function resetButton() {
    var fi = document.getElementById("fileInput");
    submitBtn.disabled = false;
    if (fi) fi.disabled = false;
    submitBtn.innerHTML =
      'Unggah Berkas <i data-lucide="chevron-right" class="w-4 h-4"></i>';
    submitBtn.classList.remove("bg-gray-600", "cursor-not-allowed");
    submitBtn.classList.add("bg-blue-600", "hover:bg-blue-700");
    setTimeout(function () {
      try {
        if (window.lucide) lucide.createIcons();
      } catch (e) {}
    }, 50);
  }
  
  function spawnConfetti() {
    var container = document.getElementById("uploadConfetti");
    if (!container) return;
    container.classList.remove("hidden");
    var colors = [
      "#10b981",
      "#34d399",
      "#3b82f6",
      "#60a5fa",
      "#f59e0b",
      "#f97316",
      "#8b5cf6",
      "#ec4899",
      "#ef4444",
    ];
    var shapes = ["50%", "2px", "0"];
    var pieces = [];
    for (var i = 0; i < 20; i++) {
      var piece = document.createElement("div");
      piece.className = "upload-confetti-piece";
      piece.style.left = 30 + Math.random() * 40 + "%";
      piece.style.background =
        colors[Math.floor(Math.random() * colors.length)];
      piece.style.borderRadius =
        shapes[Math.floor(Math.random() * shapes.length)];
      piece.style.width = 4 + Math.random() * 6 + "px";
      piece.style.height = piece.style.width;
      var xSpread = (Math.random() - 0.5) * 120;
      piece.style.setProperty("--x-spread", xSpread + "px");
      piece.style.animationDuration = 1.2 + Math.random() * 1.5 + "s";
      piece.style.animationDelay = Math.random() * 0.6 + "s";
      piece.style.animationName = "confetti-burst";
      piece.style.animationTimingFunction = "cubic-bezier(0.4, 0, 0.2, 1)";
      piece.style.animationFillMode = "forwards";
      piece.style.opacity = "0";
      container.appendChild(piece);
      pieces.push(piece);
    }
    setTimeout(function () {
      pieces.forEach(function (p) {
        if (p.parentNode) p.parentNode.removeChild(p);
      });
      container.classList.add("hidden");
    }, 4000);
  }
  
  function minimizeCard() {
    card.classList.add("minimized");
    card.classList.remove("hidden");
    card.classList.add("upload-prog-visible");
    if (autoHideTimer) {
      clearTimeout(autoHideTimer);
      autoHideTimer = null;
    }
  }
  
  function toggleCard() {
    if (card.classList.contains("minimized")) {
      card.classList.remove("minimized");
    } else {
    if (card.classList.contains("upload-prog-complete")) {
        card.classList.add("minimized");
      }
    }
  }
  
  function showSuccess() {
    if (rafId) {
      cancelAnimationFrame(rafId);
      rafId = null;
      pendingProgress = null;
    }
    bar.style.width = "100%";
    pct.textContent = "100%";
    statusEl.textContent = "Selesai";
    errorEl.classList.add("hidden");
    doneEl.classList.remove("hidden");
    card.classList.add("upload-prog-success");
    card.classList.remove("upload-prog-fail");
    resetButton();
    card.classList.add("upload-prog-complete");
    spawnConfetti();
    setTimeout(function () {
      minimizeCard();
    }, 2500);
  }
  
  function showError(msg) {
    if (rafId) {
      cancelAnimationFrame(rafId);
      rafId = null;
      pendingProgress = null;
    }
    statusEl.textContent = "Gagal: " + msg;
    doneEl.classList.add("hidden");
    errorEl.classList.remove("hidden");
    card.classList.add("upload-prog-fail", "upload-prog-complete");
    card.classList.remove("upload-prog-success");
    resetButton();
  }
  
  function resetUploadForm(newToken) {
    var fi = document.getElementById("fileInput");
    var label = document.getElementById("fileLabel");
    var tokenInput = document.querySelector('input[name="csrf_token"]');
    if (fi) fi.value = "";
    if (label) {
      label.innerText = "Tarik file atau klik untuk memilih";
      label.classList.remove("text-blue-400", "font-bold");
      label.classList.add("text-gray-400", "font-medium");
    }
    if (tokenInput && newToken) tokenInput.value = newToken;
    resetButton();
  }
  
  function updateStorageBar(usageBytes, usagePct) {
    var textEl = document.getElementById("storageUsageText");
    var barEl = document.getElementById("storageUsageBar");
    if (!textEl || !barEl) return;
    var limit = 20 * 1024 * 1024 * 1024; 
    usagePct =
      usagePct !== undefined
        ? usagePct
        : Math.min(100, (usageBytes / limit) * 100);
    textEl.textContent = formatBytes(usageBytes) + " / 20 GB";
    barEl.style.width = usagePct + "%";
    if (usagePct > 80) {
      textEl.classList.remove("text-blue-500");
      textEl.classList.add("text-red-500");
    } else {
      textEl.classList.remove("text-red-500");
      textEl.classList.add("text-blue-500");
    }
  }
  
  function refreshFileGrids() {
    fetch(window.location.href)
      .then(function (r) {
        return r.text();
      })
      .then(function (html) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, "text/html");

        ["video", "audio", "dokumen"].forEach(function (section) {
          var oldGrid = document.getElementById("drive-" + section);
          var newGrid = doc.getElementById("drive-" + section);
          if (oldGrid && newGrid) {
            oldGrid.innerHTML = newGrid.innerHTML;
          }
        });
        var newCounts = {
          video: doc.querySelectorAll("#drive-video .glass").length,
          audio: doc.querySelectorAll("#drive-audio .glass").length,
          dokumen: doc.querySelectorAll("#drive-dokumen .glass").length,
        };
        Object.keys(newCounts).forEach(function (key) {
          if (window.counts) window.counts[key] = newCounts[key];
        });

        try {
          if (window.lucide) lucide.createIcons();
        } catch (e) {}
      })
      .catch(function () {
      });
  }
  
  function closeCard() {
    card.classList.remove("upload-prog-visible");
    card.classList.add("hidden");
    if (rafId) {
      cancelAnimationFrame(rafId);
      rafId = null;
      pendingProgress = null;
    }
    if (autoHideTimer) {
      clearTimeout(autoHideTimer);
      autoHideTimer = null;
    }
    if (submitBtn.disabled) resetButton();
  }
  closeBtn.addEventListener("click", closeCard);
  
  if (toggleBtn) {
    toggleBtn.addEventListener("click", function (e) {
      e.stopPropagation();
      toggleCard();
    });
  }
  
  var dropzone = document.getElementById("uploadDropzone");
  var dragCounter = 0;
  if (dropzone) {
    dropzone.addEventListener("dragenter", function (e) {
      e.preventDefault();
      e.stopPropagation();
      dragCounter++;
      if (dragCounter === 1) {
        dropzone.classList.add("drag-over");
      }
    });
    dropzone.addEventListener("dragover", function (e) {
      e.preventDefault();
      e.stopPropagation();
    });
    dropzone.addEventListener("dragleave", function (e) {
      e.preventDefault();
      e.stopPropagation();
      dragCounter--;
      if (dragCounter <= 0) {
        dragCounter = 0;
        dropzone.classList.remove("drag-over");
      }
    });
    dropzone.addEventListener("drop", function (e) {
      e.preventDefault();
      e.stopPropagation();
      dragCounter = 0;
      dropzone.classList.remove("drag-over");
      var files = e.dataTransfer.files;
      if (!files || files.length === 0) return;
      var file = files[0];
      var label = document.getElementById("fileLabel");
      if (label) {
        label.innerText = "Siap unggah: " + file.name;
        label.classList.remove("text-gray-400", "font-medium");
        label.classList.add("text-blue-400", "font-bold");
      }
    var fd = new FormData(form);
      fd.delete("file_drive");
      fd.append("file_drive", file, file.name);
      if (!fd.has("submit_upload")) {
        fd.append("submit_upload", "1");
      }
      startUpload(fd, file.name, file.size);
    });
  }
  
  function startUpload(formData, fileName, totalSize) {
    if (currentXhr) {
      try {
        currentXhr.abort();
      } catch (e) {}
    }
    showCard(fileName);
    sizeEl.textContent = formatBytes(totalSize);
    var xhr = new XMLHttpRequest();
    currentXhr = xhr;
    xhr.upload.addEventListener("progress", function (evt) {
      if (!evt.lengthComputable) return;
      var now = Date.now();
      var loaded = evt.loaded;
      var percent = Math.round((loaded / totalSize) * 100);
      var timeDelta = (now - lastTime) / 1000;
      var speedText = speedEl.textContent;
      var durText = durationEl.textContent;
      if (timeDelta > 0.5) {
        var byteDelta = loaded - lastLoaded;
        var speedBps = byteDelta / timeDelta;
        speedText = formatSpeed(speedBps);
        if (speedBps > 0) {
          var remaining = (totalSize - loaded) / speedBps;
          durText = formatDuration(remaining);
        }
        lastLoaded = loaded;
        lastTime = now;
      }
      scheduleProgress({
        percent: percent,
        speed: speedText,
        duration: durText,
      });
    });
    xhr.addEventListener("load", function () {
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          var resp = JSON.parse(xhr.responseText);
          if (resp.status === "success") {
            showSuccess();
            resetUploadForm(resp.csrf_token);
            refreshFileGrids();
            if (typeof resp.storage_usage !== "undefined") {
              updateStorageBar(resp.storage_usage, resp.storage_percentage);
            }
          } else {
            showError(resp.message || "Terjadi kesalahan server.");
          }
        } catch (e) {
          showError("Respon server tidak valid.");
        }
      } else {
        var msg = "HTTP " + xhr.status;
        try {
          var resp = JSON.parse(xhr.responseText);
          msg = resp.message || msg;
        } catch (e) {}
        showError(msg);
      }
    });
    xhr.addEventListener("error", function () {
      showError("Koneksi terputus.");
    });
    xhr.addEventListener("abort", function () {
      showError("Dibatalkan.");
    });
    xhr.open("POST", form.getAttribute("action"));
    xhr.send(formData);
  }
  
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    var fileInput = document.getElementById("fileInput");
    if (!fileInput || !fileInput.files || fileInput.files.length === 0) return;
    var file = fileInput.files[0];
    var formData = new FormData(form);
    startUpload(formData, file.name, file.size);
  });
  
  window.refreshDrive = function () {
    var btn = document.getElementById("refreshBtn");
    var icon = btn ? btn.querySelector("i") : null;
    if (icon) icon.classList.add("animate-spin");
    
    fetch("../controllers/api/ajax_refresh.php?_=" + Date.now())
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (
          data.status === "success" &&
          typeof data.storage_usage !== "undefined"
        ) {
          updateStorageBar(data.storage_usage, data.storage_percentage);
        }
      })
      .catch(function () {n
      })
      .then(function () {
        return fetch(
          window.location.href +
            (window.location.href.indexOf("?") === -1 ? "?_=" : "&_=") +
            Date.now(),
        );
      })
      .then(function (r) {
        return r.text();
      })
      .then(function (html) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, "text/html");
        ["video", "audio", "dokumen"].forEach(function (section) {
          var oldGrid = document.getElementById("drive-" + section);
          var newGrid = doc.getElementById("drive-" + section);
          if (oldGrid && newGrid) {
            oldGrid.innerHTML = newGrid.innerHTML;
          }
        });
        var newCounts = {
          video: doc.querySelectorAll("#drive-video .glass").length,
          audio: doc.querySelectorAll("#drive-audio .glass").length,
          dokumen: doc.querySelectorAll("#drive-dokumen .glass").length,
        };
        Object.keys(newCounts).forEach(function (key) {
          if (window.counts) window.counts[key] = newCounts[key];
        });
        try {
          if (window.lucide) lucide.createIcons();
        } catch (e) {}
        var activeSection = document.querySelector(
          ".drive-section:not(.hidden)",
        );
        if (activeSection) {
          var id = activeSection.id.replace("drive-", "");
          var fc = document.getElementById("fileCount");
          var fcm = document.getElementById("fileCountMobile");
          var n = window.counts && window.counts[id] ? window.counts[id] : 0;
          if (fc) fc.innerText = n + " file ditemukan";
          if (fcm) fcm.innerText = n + " file ditemukan";
        }
      })
      .catch(function () {
      })
      .then(function () {
        if (icon) icon.classList.remove("animate-spin");
      });
  };
})();
