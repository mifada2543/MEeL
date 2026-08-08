/* MEeL Drive — File Search / Filter */
function filterDriveFiles() {
  var desktopInput = document.getElementById("search-input-desktop");
  var mobileInput = document.getElementById("search-input-mobile");
  var active = document.activeElement;
  // Ambil nilai dari input yang sedang difokuskan user
  var value =
    active === mobileInput
      ? mobileInput
        ? mobileInput.value
        : ""
      : desktopInput
        ? desktopInput.value
        : mobileInput
          ? mobileInput.value
          : "";
  var keyword = value.toLowerCase();
  // Sync kedua input agar konsisten saat resize layar
  if (desktopInput && desktopInput !== active) desktopInput.value = value;
  if (mobileInput && mobileInput !== active) mobileInput.value = value;
  var activeSection = document.querySelector(".drive-section:not(.hidden)");
  if (!activeSection) return;
  activeSection.querySelectorAll(".glass").forEach(function (card) {
    var nameEl = card.querySelector("h3");
    var fileName = nameEl ? nameEl.innerText.toLowerCase() : "";
    card.style.display = fileName.indexOf(keyword) !== -1 ? "block" : "none";
  });
}
// Filter hanya saat Enter ditekan
document.addEventListener("keydown", function (event) {
  if (event.key !== "Enter") return;
  var target = event.target;
  if (
    target.id === "search-input-desktop" ||
    target.id === "search-input-mobile"
  ) {
    event.preventDefault();
    filterDriveFiles();
  }
});
