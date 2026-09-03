
(function () {
  'use strict';
  
  document.addEventListener('DOMContentLoaded', function () {
    var deleteModal = document.getElementById('delete-modal');
    if (deleteModal) {
      deleteModal.addEventListener('click', function (e) {
        if (e.target === this) closeDeleteModal();
      });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeDeleteModal();
    });
  });
  
  window.confirmDelete = function (id, type, title) {
    var idEl = document.getElementById('modal-media-id');
    var typeEl = document.getElementById('modal-media-type');
    var titleEl = document.getElementById('modal-title-display');
    var badge = document.getElementById('modal-type-badge');
    var modal = document.getElementById('delete-modal');
    if (idEl) idEl.value = id;
    if (typeEl) typeEl.value = type;
    if (titleEl) titleEl.textContent = title;
    if (badge) {
      var isVideo = type === 'video';
      badge.textContent = type.toUpperCase();
      badge.style.cssText =
        'font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;padding:3px 9px;border-radius:8px;' +
        (isVideo
          ? 'background:rgba(239,68,68,.1);color:#ef4444;border:1px solid rgba(239,68,68,.2);'
          : 'background:rgba(249,115,22,.1);color:#f97316;border:1px solid rgba(249,115,22,.2);');
    }
    if (modal) modal.classList.add('open');
  };
  
  window.closeDeleteModal = function () {
    var modal = document.getElementById('delete-modal');
    if (modal) modal.classList.remove('open');
  };
})();
