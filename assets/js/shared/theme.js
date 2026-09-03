











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

    
    var themeIcon = document.getElementById('theme-icon');
    if (themeIcon) {
      themeIcon.textContent = theme === 'dark' ? '🌙' : '☀️';
    }
    
    var buttons = document.querySelectorAll('#theme-toggle, .meel-theme-toggle');
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].setAttribute('title', theme === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode');
    }

    
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

  


  function doInit(opts) {
    opts = opts || {};
    isLoggedIn = !!opts.isLoggedIn;
    csrfToken = opts.csrfToken || '';

    
    var local = getLocalTheme();
    if (local) {
      applyTheme(local);
    }

    
    if (isLoggedIn) {
      fetchThemeFromDB().then(function (dbTheme) {
        if (dbTheme && dbTheme !== local) {
          
          applyTheme(dbTheme);
          setLocalTheme(dbTheme);
        } else if (!local && dbTheme) {
          
          applyTheme(dbTheme);
          setLocalTheme(dbTheme);
        } else if (local && !dbTheme) {
          
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

      
      html.classList.add('theme-transition');

      applyTheme(newTheme);

      
      setLocalTheme(newTheme);

      
      if (isLoggedIn) {
        saveThemeToDB(newTheme);
      }

      
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
