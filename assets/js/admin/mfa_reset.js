
(function () {
  "use strict";
  document.addEventListener("DOMContentLoaded", function () {
    if (typeof lucide !== "undefined") lucide.createIcons();
  });
})();

function confirmResetMFA(userId, username) {
  if (typeof Swal === "undefined") return;
  Swal.fire({
    title: "Reset MFA " + username + "?",
    html:
      '<div style="font-size:12px;color:#9ca3af">' +
      'MFA untuk <strong style="color:#e5e7eb">@' +
      username +
      "</strong> akan dinonaktifkan.<br>" +
      'User harus <strong style="color:#fbbf24">setup ulang MFA</strong> dari profil mereka.<br><br>' +
      '<span style="color:#ef4444;font-size:11px">Tindakan ini tidak bisa dibatalkan.</span>' +
      "</div>",
    icon: "warning",
    iconColor: "#ef4444",
    showCancelButton: true,
    confirmButtonText: "RESET MFA",
    cancelButtonText: "BATAL",
    background: "#141820",
    color: "#fff",
    reverseButtons: true,
    customClass: {
      popup: "border border-red-600/25 rounded-2xl shadow-2xl",
      title: "text-sm font-black uppercase tracking-wider pt-4 text-red-500",
      htmlContainer: "mt-1 mb-4",
      confirmButton:
        "bg-red-600 hover:bg-red-500 text-white text-xs font-black uppercase tracking-wider py-2.5 px-6 rounded-xl transition-all border-none cursor-pointer ml-2",
      cancelButton:
        "bg-white/5 hover:bg-white/10 text-gray-400 text-xs font-black uppercase tracking-wider py-2.5 px-6 rounded-xl border border-white/10 cursor-pointer transition-all mr-2",
    },
  }).then(function (result) {
    if (result.isConfirmed) {
      var csrfInput = document.querySelector('input[name=\"csrf_token\"]');
      var token = csrfInput ? csrfInput.value : "";
      window.location.href =
        "mfa_reset.php?reset_mfa=1&user_id=" +
        userId +
        "&csrf_token=" +
        encodeURIComponent(token);
    }
  });
}
