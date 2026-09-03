



function startAdvancedUpload(form) {
  var urlInput = document.getElementById("url-input");
  if (!urlInput) return false;
  var url = urlInput.value.trim();
  if (!url) return false;
  var btn = document.getElementById("submit-btn");
  if (btn) {
    btn.disabled = true;
    btn.innerHTML =
      '<div style="width:14px;height:14px;border:1.5px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:meel-spin .7s linear infinite;"></div> Memproses...';
  }
  if (typeof meelPhase === "function") meelPhase("download");
  return true;
}
