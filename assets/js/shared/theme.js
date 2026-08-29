/**
 * shared/theme.js — Light/Dark Theme Toggle Manager
 *
 * Strategi persist:
 * - localStorage = source of truth (cepat, anti-flash)
 * - DB (custom_theme) = sync untuk logged-in user
 * - Toggle → save ke localStorage DAN DB sekaligus
 * - Init → localStorage dulu, lalu sync DB (jika login)
 *
 * Usage: MEELTheme.init({ isLoggedIn, csrfToken })
 *        MEELTheme.toggle()
 */
window.MEELTheme = (function () {
  'use strict';

  var STORAGE_KEY = 'meel_theme';
  var currentTheme = 'dark';
  var isLoggedIn = false;
  var csrfToken = '';

  function applyTheme(theme) {
    currentTheme = theme;
    var html = document.documentElement;
    html.setAttribute('data-theme', theme);

    if (theme === 'dark') {
      html.classList.add('dark');
    } else {
      html.classList.remove('dark');
    }

    // Update ALL toggle icons di seluruh halaman
    var buttons = document.querySelectorAll('#theme-toggle, .meel-theme-toggle');
    for (var i = 0; i < buttons.length; i++) {
      var btn = buttons[i];
      var sunIcon = btn.querySelector('[data-theme-icon="sun"]');
      var moonIcon = btn.querySelector('[data-theme-icon="moon"]');
      if (sunIcon && moonIcon) {
        if (theme === 'dark') {
          sunIcon.classList.remove('hidden');
          moonIcon.classList.add('hidden');
        } else {
          sunIcon.classList.add('hidden');
          moonIcon.classList.remove('hidden');
        }
      }
      btn.setAttribute('title', theme === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode');
    }

    // Update profile page theme toggle
    var track = document.getElementById('theme-track');
    var text = document.getElementById('theme-text');
    var label = document.getElementById('theme-label');
    if (track) {
      if (theme === 'light') {
        track.classList.remove('mfa-track--off');
        track.classList.add('mfa-track--on');
      } else {
        track.classList.remove('mfa-track--on');
        track.classList.add('mfa-track--off');
      }
    }
    if (text) {
      if (theme === 'light') {
        text.classList.remove('mfa-label--off');
        text.classList.add('mfa-label--on');
      } else {
        text.classList.remove('mfa-label--on');
        text.classList.add('mfa-label--off');
      }
    }
    if (label) {
      label.textContent = theme === 'dark' ? 'Gelap' : 'Terang';
    }

    // Update meta theme-color
    var metaTheme = document.querySelector('meta[name="theme-color"]');
    if (metaTheme) {
      metaTheme.setAttribute('content', theme === 'dark' ? '#05070c' : '#f5f5f5');
    }
  }

  function apiBase() {
    return (window.MEEL_BASE || '') + '/api/theme';
  }

  function fetchThemeFromDB() {
    return fetch(apiBase(), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
    .then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    })
    .then(function (data) {
      return data.theme || null;
    })
    .catch(function () {
      return null;
    });
  }

  function saveThemeToDB(theme) {
    return fetch(apiBase(), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        theme: theme,
        csrf_token: csrfToken
      })
    })
    .then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    })
    .catch(function (err) {
      console.warn('[MEELTheme] Gagal save ke DB:', err.message);
    });
  }

  function getLocalTheme() {
    try {
      var saved = localStorage.getItem(STORAGE_KEY);
      if (saved === 'light' || saved === 'dark') return saved;
    } catch (e) {}
    return null;
  }

  function setLocalTheme(theme) {
    try {
      localStorage.setItem(STORAGE_KEY, theme);
    } catch (e) {}
  }

  /**
   * Init: localStorage sebagai source of truth, lalu sync DB
   */
  function doInit(opts) {
    opts = opts || {};
    isLoggedIn = !!opts.isLoggedIn;
    csrfToken = opts.csrfToken || '';

    // 1. SELALU baca localStorage dulu (anti-flash)
    var local = getLocalTheme();
    if (local) {
      applyTheme(local);
    }

    // 2. Jika login, sync dengan DB
    if (isLoggedIn) {
      fetchThemeFromDB().then(function (dbTheme) {
        if (dbTheme && dbTheme !== local) {
          // DB punya preferensi yang beda dari localStorage → sync
          applyTheme(dbTheme);
          setLocalTheme(dbTheme);
        } else if (!local && dbTheme) {
          // Tidak ada localStorage tapi ada DB → apply DB
          applyTheme(dbTheme);
          setLocalTheme(dbTheme);
        } else if (local && !dbTheme) {
          // Ada localStorage tapi DB masih default → push ke DB
          saveThemeToDB(local);
        }
      });
    }
  }

  return {
    init: function (opts) {
      doInit(opts);
    },

    toggle: function () {
      var newTheme = currentTheme === 'dark' ? 'light' : 'dark';
      var html = document.documentElement;

      // Add transition class untuk smooth animation
      html.classList.add('theme-transition');

      applyTheme(newTheme);

      // SELALU simpan ke localStorage
      setLocalTheme(newTheme);

      // Jika login, sync ke DB juga
      if (isLoggedIn) {
        saveThemeToDB(newTheme);
      }

      // Remove transition class setelah animation selesai
      setTimeout(function () {
        html.classList.remove('theme-transition');
      }, 400);
    },

    getTheme: function () {
      return currentTheme;
    },

    applyInitial: function (theme) {
      applyTheme(theme || 'dark');
    }
  };
})();
