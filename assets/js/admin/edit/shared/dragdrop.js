
(function () {
  'use strict';
  
  window.setupImageDragDrop = function (wrapId, inputId, previewId, badgeId, onChange) {
    var wrap = document.getElementById(wrapId);
    var input = document.getElementById(inputId);
    if (!wrap || !input) return;
    wrap.addEventListener('click', function (e) {
      if (e.target === input) return;
      input.click();
    });
    input.addEventListener('change', function () {
      if (onChange) onChange(this);
    });
    wrap.addEventListener('dragover', function (e) {
      e.preventDefault();
      wrap.classList.add('drag-over');
    });
    wrap.addEventListener('dragleave', function () {
      wrap.classList.remove('drag-over');
    });
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
