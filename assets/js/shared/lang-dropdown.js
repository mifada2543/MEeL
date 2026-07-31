/**
 * MEeL — Custom Language Dropdown (shared)
 * Gaya dropdown chapter di books/read.php (.ch-trigger / .ch-options).
 *
 * Dipakai di:
 *   - video/upload.php        (Bahasa Subtitle)
 *   - admin/edit-video.php    (Bahasa Subtitle)
 *   - modul lain yang butuh pilihan bahasa (cukup set data-name)
 *
 * Struktur HTML yang diharapkan:
 *   <div class="lang-dropdown" id="..." data-name="subtitle_lang">
 *     <button type="button" class="lang-trigger" aria-haspopup="listbox" aria-expanded="false">
 *       <span class="lang-trigger-label">Indonesia</span>
 *       <i data-lucide="chevron-down" class="lang-trigger-chevron"></i>
 *     </button>
 *     <div class="lang-options hidden" role="listbox">
 *       <button type="button" class="lang-option active" data-lang="id" role="option">Indonesia</button>
 *       ...
 *     </div>
 *   </div>
 *   <input type="hidden" name="subtitle_lang" value="id">
 *
 * Atribut data-name pada .lang-dropdown menentukan `name` dari hidden
 * input yang akan di-update (default: subtitle_lang). Ini membuat
 * komponen reusable untuk modul lain (musik, buku, dsb.).
 *
 * Perilaku (meniru books/read.php):
 *   - Klik trigger → buka/tutup panel opsi
 *   - Klik opsi → set nilai hidden input, update label, tandai .active
 *   - Klik di luar / Escape → tutup
 *   - Auto-scroll ke opsi aktif saat panel dibuka
 *   - Hanya satu panel terbuka dalam satu waktu
 */
(function () {
  'use strict';

  function setOpen(dd, open) {
    var trigger = dd.querySelector('.lang-trigger');
    var options = dd.querySelector('.lang-options');
    dd.classList.toggle('open', open);
    options.classList.toggle('hidden', !open);
    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');

    if (open) {
      // Jika ruang di bawah trigger sempit (mis. dropdown di paling bawah form
      // yang merupakan scroll container), buka panel ke ATAS agar tidak terpotong.
      var rect = trigger.getBoundingClientRect();
      var spaceBelow = window.innerHeight - rect.bottom;
      dd.classList.toggle('open-up', spaceBelow < 320);

      // Auto-scroll ke opsi aktif (sama seperti books/read.php)
      var active = options.querySelector('.lang-option.active');
      if (active) {
        setTimeout(function () {
          active.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }, 60);
      }
    } else {
      // Bersihkan state open-up saat ditutup agar trigger kembali rounded
      dd.classList.remove('open-up');
    }
  }

  function closeAll() {
    document.querySelectorAll('.lang-dropdown.open').forEach(function (dd) {
      dd.classList.remove('open');
      dd.classList.remove('open-up');
      var o = dd.querySelector('.lang-options');
      if (o) o.classList.add('hidden');
      var t = dd.querySelector('.lang-trigger');
      if (t) t.setAttribute('aria-expanded', 'false');
    });
  }

  function initLangDropdown(dd) {
    var trigger = dd.querySelector('.lang-trigger');
    var options = dd.querySelector('.lang-options');
    var label = dd.querySelector('.lang-trigger-label');
    // Nama hidden input diambil dari data-name (default subtitle_lang) —
    // memungkinkan komponen dipakai modul lain (musik, buku, dsb.).
    var inputName = dd.getAttribute('data-name') || 'subtitle_lang';
    var hidden = dd.parentElement
      ? dd.parentElement.querySelector('input[name="' + inputName + '"]')
      : null;
    if (!trigger || !options) return;

    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      if (!options.classList.contains('hidden')) {
        setOpen(dd, false);
        return;
      }
      closeAll();
      setOpen(dd, true);
    });

    options.addEventListener('click', function (e) {
      var opt = e.target.closest('.lang-option');
      if (!opt) return;
      var lang = opt.getAttribute('data-lang');

      options.querySelectorAll('.lang-option').forEach(function (o) {
        var on = o === opt;
        o.classList.toggle('active', on);
        o.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      if (label) label.textContent = opt.textContent;
      if (hidden) hidden.value = lang;
      setOpen(dd, false);
    });

    // Tutup saat klik di luar dropdown
    document.addEventListener('click', function (e) {
      if (!dd.contains(e.target)) setOpen(dd, false);
    });

    // Tutup dengan tombol Escape
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') setOpen(dd, false);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.lang-dropdown').forEach(initLangDropdown);
  });
})();
