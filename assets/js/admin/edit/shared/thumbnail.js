
(function () {
  "use strict";

  
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
  
  window.handleThumbChange = function (input) {
    window.handleImageChange(input, "thumb-preview", "thumb-changed-badge");
  };
  window.handleCoverChange = function (input) {
    window.handleImageChange(input, "cover-preview", "cover-changed-badge");
  };
})();
