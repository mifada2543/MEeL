
(function () {
  "use strict";
  function setOpen(dd, open) {
    var trigger = dd.querySelector(".lang-trigger");
    var options = dd.querySelector(".lang-options");
    dd.classList.toggle("open", open);
    options.classList.toggle("hidden", !open);
    trigger.setAttribute("aria-expanded", open ? "true" : "false");
    if (open) {
      var rect = trigger.getBoundingClientRect();
      var spaceBelow = window.innerHeight - rect.bottom;
      dd.classList.toggle("open-up", spaceBelow < 320);
      var active = options.querySelector(".lang-option.active");
      if (active) {
        setTimeout(function () {
          active.scrollIntoView({ block: "nearest", behavior: "smooth" });
        }, 60);
      }
    } else {
      dd.classList.remove("open-up");
    }
  }
  function closeAll() {
    document.querySelectorAll(".lang-dropdown.open").forEach(function (dd) {
      dd.classList.remove("open");
      dd.classList.remove("open-up");
      var o = dd.querySelector(".lang-options");
      if (o) o.classList.add("hidden");
      var t = dd.querySelector(".lang-trigger");
      if (t) t.setAttribute("aria-expanded", "false");
    });
  }
  function initLangDropdown(dd) {
    var trigger = dd.querySelector(".lang-trigger");
    var options = dd.querySelector(".lang-options");
    var label = dd.querySelector(".lang-trigger-label");
    var inputName = dd.getAttribute("data-name") || "subtitle_lang";
    var hidden = dd.parentElement
      ? dd.parentElement.querySelector('input[name="' + inputName + '"]')
      : null;
    if (!trigger || !options) return;
    trigger.addEventListener("click", function (e) {
      e.stopPropagation();
      if (!options.classList.contains("hidden")) {
        setOpen(dd, false);
        return;
      }
      closeAll();
      setOpen(dd, true);
    });
    options.addEventListener("click", function (e) {
      var opt = e.target.closest(".lang-option");
      if (!opt) return;
      var lang = opt.getAttribute("data-lang");
      options.querySelectorAll(".lang-option").forEach(function (o) {
        var on = o === opt;
        o.classList.toggle("active", on);
        o.setAttribute("aria-selected", on ? "true" : "false");
      });
      if (label) label.textContent = opt.textContent;
      if (hidden) hidden.value = lang;
      setOpen(dd, false);
    });
    document.addEventListener("click", function (e) {
      if (!dd.contains(e.target)) setOpen(dd, false);
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") setOpen(dd, false);
    });
  }
  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".lang-dropdown").forEach(initLangDropdown);
  });
})();
