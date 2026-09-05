
function openPreview(path, type, name) {
  var modal = document.getElementById("previewModal");
  var content = document.getElementById("previewContent");
  var title = document.getElementById("previewTitle");
  if (!modal || !content || !title) return;
  title.innerText = name;
  modal.classList.remove("hidden");
  modal.classList.add("flex");
  content.innerHTML =
    '<div class="text-gray-500 flex flex-col items-center"><div class="animate-spin mb-2">⏳</div> Memuat pratinjau...</div>';
  var modalBox = modal.firstElementChild;
  if (modalBox) modalBox.classList.remove("preview-modal-pdf");
  var html = "";
  var ext = name.split(".").pop().toLowerCase();
  if (type === "video") {
    html =
      '\n            <video controls autoplay class="max-w-full max-h-[75vh] rounded-lg shadow-2xl bg-black">\n                <source src="' +
      path +
      '" type="video/mp4">\n                <source src="' +
      path +
      '" type="video/webm">\n                Browser Anda tidak mendukung pratinjau video.\n            </video>';
  } else if (type === "audio") {
    html =
      '\n            <div class="bg-gray-900 p-10 rounded-2xl border border-gray-800 w-full max-w-md text-center shadow-2xl">\n                <div class="mb-6 inline-block p-4 bg-orange-500/10 rounded-full">\n                    <i data-lucide="music" class="w-12 h-12 text-orange-500"></i>\n                </div>\n                <audio controls autoplay class="w-full">\n                    <source src="' +
      path +
      '" type="audio/mpeg">\n                    <source src="' +
      path +
      '" type="audio/wav">\n                    Browser Anda tidak mendukung pratinjau audio.\n                </audio>\n                <p class="text-gray-500 text-xs mt-4 truncate">' +
      name +
      "</p>\n            </div>";
  } else if (type === "dokumen") {
    var imageExtensions = ["jpg", "jpeg", "png", "gif", "webp", "svg"];
    if (ext === "pdf") {
      html =
        '\n                <div class="w-full h-full flex flex-col min-h-0">' +
        '\n                    <iframe src="' +
        path +
        '" title="Pratinjau PDF: ' +
        name +
        '" class="w-full flex-1 min-h-0 rounded-lg bg-white"></iframe>' +
        '\n                    <div class="flex justify-center mt-3">' +
        '\n                        <a href="' +
        path +
        '" target="_blank" rel="noopener" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold uppercase tracking-wider px-4 py-2 rounded-lg transition" title="Buka PDF di tab baru (fallback untuk browser yang tidak mendukung pratinjau)">' +
        '\n                            <i data-lucide="external-link" class="w-3.5 h-3.5"></i> Buka di Tab Baru' +
        "\n                        </a>" +
        "\n                    </div>" +
        "\n                </div>";
      if (modalBox) modalBox.classList.add("preview-modal-pdf");
    } else if (imageExtensions.indexOf(ext) !== -1) {
      html =
        '<img src="' +
        path +
        '" class="max-w-full max-h-[75vh] object-contain rounded-lg shadow-2xl">';
    } else {
      html =
        '\n                <div class="text-center p-10 bg-gray-900 rounded-2xl border border-gray-800">\n                    <i data-lucide="file-warning" class="w-16 h-16 text-gray-600 mx-auto mb-4"></i>\n                    <p class="text-gray-400 mb-4">Pratinjau tidak tersedia untuk file .' +
        ext +
        '</p>\n                    <a href="' +
        path +
        '" download class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition font-bold">\n                        Unduh untuk Melihat\n                    </a>\n                </div>';
    }
  }
  setTimeout(function () {
    content.innerHTML = html;
    if (window.lucide) lucide.createIcons();
  }, 200);
}
function closePreview() {
  var modal = document.getElementById("previewModal");
  var content = document.getElementById("previewContent");
  if (!modal || !content) return;
  var modalBox = modal.firstElementChild;
  if (modalBox) modalBox.classList.remove("preview-modal-pdf");
  modal.classList.add("hidden");
  modal.classList.remove("flex");
  content.innerHTML = "";
}
(function () {
  var modal = document.getElementById("previewModal");
  if (modal) {
    modal.addEventListener("click", function (event) {
      if (event.target.id === "previewModal") {
        closePreview();
      }
    });
  }
})();
