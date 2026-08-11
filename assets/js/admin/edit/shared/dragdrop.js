/* MEeL Admin — Edit Shared: Drag & Drop Thumbnail / Cover */
(function () {
  'use strict';
  /* * * Setup drag-drop pada image wrap element * @param {string} wrapId - ID container element (click/drag target) * @param {string} inputId - ID hidden file input * @param {string} previewId - ID img preview * @param {string} badgeId - ID changed badge * @param {Function} onChange - callback saat file berubah */
  window.setupImageDragDrop = function (wrapId, inputId, previewId, badgeId, onChange) {
    var wrap = document.getElementById(wrapId);
    var input = document.getElementById(inputId);
    if (!wrap || !input) return;
    // Click → trigger hidden input
    wrap.addEventListener('click', function (e) {
      if (e.target === input) return;
      input.click();
    });
    // Hidden input change → preview
    input.addEventListener('change', function () {
      if (onChange) onChange(this);
    });
    // Drag-over visual
    wrap.addEventListener('dragover', function (e) {
      e.preventDefault();
      wrap.classList.add('drag-over');
    });
    wrap.addEventListener('dragleave', function () {
      wrap.classList.remove('drag-over');
    });
    // Drop → set file & preview
    wrap.addEventListener('drop', function (e) {
      e.preventDefault();
      wrap.classList.remove('drag-over');
      var files = e.dataTransfer.files;
      if (!files || !files[0] || !files[0].type.startsWith('image/')) return;
      var dt = new DataTransfer();
      dt.items.add(files[0]);
      input.files = dt.files;
      if (onChange) onChange(input);
    });
  };
})();
