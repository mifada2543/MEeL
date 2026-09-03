
(function () {
  "use strict";
  
  window.handleSubtitleFile = function (input) {
    if (!input.files || !input.files[0]) return;
    var file = input.files[0];
    var ext = file.name.split(".").pop().toLowerCase();
    var allowed = ["vtt", "srt"];
    if (allowed.indexOf(ext) === -1) {
      if (typeof meelAlert !== "undefined") {
        meelAlert({
          title: "Format Ditolak",
          text: "Gunakan VTT atau SRT.",
          icon: "error",
        });
      }
      input.value = "";
      return;
    }
    var zone = document.getElementById("subtitle-zone");
    var label = document.getElementById("subtitle-label");
    var sub = document.getElementById("subtitle-sub");
    if (label) label.textContent = file.name;
    if (sub)
      sub.textContent =
        ext === "srt" ? "SRT · akan dikonversi otomatis" : "VTT";
    if (zone) zone.classList.add("has-file");
    
    var langWrap = document.getElementById("subtitle-lang-wrap");
    if (langWrap) langWrap.style.display = "";
  };
  
  function setupSubtitleDragDrop() {
    var zone = document.getElementById("subtitle-zone");
    var input = document.getElementById("f-subtitle");
    if (!zone || !input) return;
    zone.addEventListener("dragover", function (e) {
      e.preventDefault();
      zone.classList.add("drag-over");
    });
    zone.addEventListener("dragleave", function () {
      zone.classList.remove("drag-over");
    });
    zone.addEventListener("drop", function (e) {
      e.preventDefault();
      zone.classList.remove("drag-over");
      var files = e.dataTransfer.files;
      if (!files || !files[0]) return;
      var dt = new DataTransfer();
      dt.items.add(files[0]);
      input.files = dt.files;
      window.handleSubtitleFile(input);
    });
  }
  
  document.addEventListener("DOMContentLoaded", function () {
    if (typeof lucide !== "undefined") lucide.createIcons();
    if (typeof setupImageDragDrop !== "undefined") {
      setupImageDragDrop(
        "thumb-wrap",
        "thumb-file-hidden",
        "thumb-preview",
        "thumb-changed-badge",
        window.handleThumbChange,
      );
    }
    setupSubtitleDragDrop();
  });
})();
