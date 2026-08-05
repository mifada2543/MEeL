/** MEeL Drive — Navigation & Section Switching
 * Handles category tabs (Video/Audio/Dokumen), heading updates, file counts. **/
(function () {
  lucide.createIcons();
  window.counts = {
    video: document.querySelectorAll("#drive-video .glass").length,
    audio: document.querySelectorAll("#drive-audio .glass").length,
    dokumen: document.querySelectorAll("#drive-dokumen .glass").length,
  };
  var accents = {
    video: { color: "text-red-500", label: "Video" },
    audio: { color: "text-orange-500", label: "Audio" },
    dokumen: { color: "text-green-500", label: "Dokumen" },
  };
  window.showSection = function (id, btn, isMobile) {
    isMobile = isMobile === true;
    document.querySelectorAll(".drive-section").forEach(function (s) {
      s.classList.add("hidden");
    });
    var target = document.getElementById("drive-" + id);
    if (target) target.classList.remove("hidden");
    // Desktop nav reset
    document.querySelectorAll(".nav-btn-desktop").forEach(function (b) {
      b.classList.remove("nav-active", "text-blue-500");
      b.classList.add("text-gray-400");
    });
    // Mobile nav reset
    document.querySelectorAll(".nav-btn-mobile").forEach(function (b) {
      b.classList.remove("bg-blue-500/10", "border-blue-500", "text-blue-500");
      b.classList.add("bg-gray-800", "border-transparent", "text-gray-400");
    });
    if (btn) {
      if (isMobile) {
        btn.classList.add("bg-blue-500/10", "border-blue-500", "text-blue-500");
        btn.classList.remove(
          "bg-gray-800",
          "border-transparent",
          "text-gray-400",
        );
        var dtBtn = document.querySelector(
          '.nav-btn-desktop[onclick*="' + id + '"]',
        );
        if (dtBtn) {
          dtBtn.classList.add("nav-active");
          dtBtn.classList.remove("text-gray-400");
        }
      } else {
        btn.classList.add("nav-active");
        btn.classList.remove("text-gray-400");
        var mbBtn = document.querySelector(
          '.nav-btn-mobile[onclick*="' + id + '"]',
        );
        if (mbBtn) {
          mbBtn.classList.add(
            "bg-blue-500/10",
            "border-blue-500",
            "text-blue-500",
          );
          mbBtn.classList.remove(
            "bg-gray-800",
            "border-transparent",
            "text-gray-400",
          );
        }
      }
    }
    var headingAccent = document.getElementById("sectionAccent");
    if (headingAccent) {
      headingAccent.innerText = accents[id].label;
      headingAccent.className = accents[id].color;
    }
    var headingAccentMobile = document.getElementById("sectionAccentMobile");
    if (headingAccentMobile) {
      headingAccentMobile.innerText = accents[id].label;
      headingAccentMobile.className = accents[id].color;
    }
    var fileCount = document.getElementById("fileCount");
    if (fileCount) fileCount.innerText = counts[id] + " file ditemukan";
    var fileCountMobile = document.getElementById("fileCountMobile");
    if (fileCountMobile)
      fileCountMobile.innerText = counts[id] + " file ditemukan";
    lucide.createIcons();
  };
  var initialBtn = document.querySelector(".nav-btn-desktop.active");
  if (initialBtn) {
    window.showSection("video", initialBtn);
  }
})();
