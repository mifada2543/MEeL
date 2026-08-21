/* MEeL Admin — Edit Shared: Thumbnail / Cover Preview */
(function () {
  "use strict";

  /* * * Handle file input change — update img preview & show badge * @param {HTMLInputElement} input - file input element * @param {string} previewId - ID from img element * @param {string} badgeId - ID from changed badge */
  window.handleImageChange = function (input, previewId, badgeId) {
    if (!input || !input.files || !input.files[0]) return;
    var reader = new FileReader();
    reader.onload = function (e) {
      var preview = document.getElementById(previewId);
      if (preview) preview.src = e.target.result;
      var badge = document.getElementById(badgeId);
      if (badge) badge.style.display = "block";
    };
    reader.readAsDataURL(input.files[0]);
  };
  // Map untuk backward compatibility
  window.handleThumbChange = function (input) {
    window.handleImageChange(input, "thumb-preview", "thumb-changed-badge");
  };
  window.handleCoverChange = function (input) {
    window.handleImageChange(input, "cover-preview", "cover-changed-badge");
  };
})();
