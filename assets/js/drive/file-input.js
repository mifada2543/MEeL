/** MEeL Drive — File Input Label Update
 * Updates the label text when a file is selected. **/
function updateFileName(input) {
  var label = document.getElementById("fileLabel");
  if (!label || !input.files || input.files.length === 0) return;
  label.innerText = "Siap unggah: " + input.files[0].name;
  label.classList.remove("text-gray-400");
  label.classList.add("text-blue-400", "font-bold");
}
