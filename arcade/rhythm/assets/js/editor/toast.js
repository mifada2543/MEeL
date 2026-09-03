




export function showToast(message, type) {
  type = type || "info";

  var existing = document.getElementById("editorToast");
  if (existing) existing.remove();
  var toast = document.createElement("div");
  toast.id = "editorToast";
  toast.style.cssText =
    "position:fixed;top:20px;right:20px;z-index:9999;padding:14px 24px;border-radius:10px;" +
    "font-size:13px;font-weight:600;color:#fff;backdrop-filter:blur(12px);animation:toastIn .3s ease;max-width:360px;";
  var colors = {
    error: "rgba(239,68,68,0.9)",
    success: "rgba(34,197,94,0.9)",
    info: "rgba(99,102,241,0.9)",
    warning: "rgba(251,191,36,0.9)",
  };
  toast.style.background = colors[type] || colors.info;
  toast.textContent = message;
  document.body.appendChild(toast);
  setTimeout(function () {
    toast.style.opacity = "0";
    toast.style.transition = "opacity .3s";
    setTimeout(function () { toast.remove(); }, 300);
  }, type === "success" ? 3000 : 5000);
}


if (!document.getElementById("toastStyle")) {
  var st = document.createElement("style");
  st.id = "toastStyle";
  st.textContent = "@keyframes toastIn{from{opacity:0;transform:translateY(-12px)}to{opacity:1;transform:translateY(0)}}";
  document.head.appendChild(st);
}