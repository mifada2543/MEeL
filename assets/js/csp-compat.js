/**
 * CSP Compatibility Layer — MEeL
 * ==============================
 * Event delegation system to replace inline event handlers (onclick, onsubmit, etc.)
 * that are blocked by Content-Security-Policy when 'unsafe-inline' is removed.
 *
 * Usage:
 *   Replace:  <button onclick="someFunction()">Click</button>
 *   With:     <button data-csp-click="someFunction">Click</button>
 *
 * For navigation:
 *   Replace:  <div onclick="window.location.href='url'">
 *   With:     <div data-csp-nav="url">
 *
 * For forms with onsubmit:
 *   Replace:  <form onsubmit="return handler()">
 *   With:     <form data-csp-submit="handler">
 *
 * For onerror on images:
 *   Replace:  <img onerror="this.style.display='none'">
 *   With:     <img data-csp-imgerror="hide">
 *
 * For onchange:
 *   Replace:  <input onchange="handler(this)">
 *   With:     <input data-csp-change="handler">
 */

(function() {
  'use strict';

  // ─── Click Delegation ───────────────────────────────────────
  document.addEventListener('click', function(e) {
    var el = e.target.closest('[data-csp-click]');
    if (!el) return;
    var action = el.getAttribute('data-csp-click');
    if (!action) return;

    switch (action) {
      // ── Drawer toggles (nav.php) ──
      case 'toggleNavDropdown':
        toggleNavDropdown();
        break;
      case 'toggleNavDrawer':
        toggleNavDrawer();
        break;
      case 'toggleNavDrawerGuest':
        toggleNavDrawerGuest();
        break;

      // ── Playlist view toggles (view_playlist.php) ──
      case 'resetActivePlaylist':
        if (typeof resetActivePlaylist === 'function') resetActivePlaylist();
        if (typeof resetArtistHighlight === 'function') resetArtistHighlight();
        break;

      case 'toggleChDropdownTop':
        toggleChDropdown('top');
        break;
      case 'toggleChDropdownBottom':
        toggleChDropdown('bottom');
        break;
      case 'scrollToTop':
        scrollToTop();
        break;

      // ── Admin modals (update.php) ──
      case 'openModalAddUpdate':
        openModal('modal-add-update');
        break;
      case 'openModalEditSidebar':
        openModal('modal-edit-sidebar');
        break;
      case 'closeModalAddUpdate':
        closeModal('modal-add-update');
        break;
      case 'closeModalEditUpdate':
        closeModal('modal-edit-update');
        break;
      case 'closeModalEditSidebar':
        closeModal('modal-edit-sidebar');
        break;

      // ── Action dropdown (activity_log.php) ──
      case 'toggleActionDropdown':
        toggleActionDropdown();
        break;
      case 'selectActionEmpty':
        selectAction('');
        break;

      // ── Drive section (drive/index.php) ──
      case 'showSectionVideo':
        showSection('video', el);
        break;
      case 'showSectionAudio':
        showSection('audio', el);
        break;
      case 'showSectionDokumen':
        showSection('dokumen', el);
        break;
      case 'showSectionVideoMobile':
        showSection('video', el, true);
        break;
      case 'showSectionAudioMobile':
        showSection('audio', el, true);
        break;
      case 'showSectionDokumenMobile':
        showSection('dokumen', el, true);
        break;
      case 'closePreview':
        closePreview();
        break;

      // ── Admin confirm delete ──
      case 'confirmDelete':
        var id = el.getAttribute('data-csp-id');
        var type = el.getAttribute('data-csp-type');
        var title = el.getAttribute('data-csp-title');
        confirmDelete(id, type, title);
        break;

      // ── Open preview (file_grid.php) ──
      case 'openPreview':
        var prepPath = el.getAttribute('data-csp-path');
        var prepType = el.getAttribute('data-csp-type');
        var prepName = el.getAttribute('data-csp-name');
        openPreview(prepPath, prepType, prepName);
        break;

      // ── Delete file (file_grid.php) ──
      case 'deleteFile':
        var delFormId = el.getAttribute('data-csp-form');
        if (confirm('Hapus file ini?')) {
          document.getElementById(delFormId).submit();
        }
        break;

      // ── Toggle description (video/watch.php) ──
      case 'toggleDescription':
        toggleDescription();
        break;

      // ── Music player mini-player (view_playlist.php) ──
      case 'miniSeek':
        miniSeekIndex(event);
        break;
      case 'expandPlayer':
        expandPlayerFromMiniPlayer();
        break;
      case 'toggleMiniLoop':
        toggleMiniLoopIndex();
        break;
      case 'miniPrev':
        miniPrevIndex();
        break;
      case 'miniPlayPause':
        miniPlayPauseIndex();
        break;
      case 'miniNext':
        miniNextIndex();
        break;

      // ── Music watch.php player ──
      case 'toggleLoop':
        toggleLoop();
        break;
      case 'toggleVisualizer':
        toggleVisualizer();
        break;
      case 'toggleEqualizer':
        toggleEqualizer();
        break;
      case 'toggleEqPresetDropdown':
        toggleEqPresetDropdown();
        break;
      case 'selectEqPresetFlat':
        selectEqPreset('flat');
        break;
      case 'selectEqPresetBass':
        selectEqPreset('bass');
        break;
      case 'selectEqPresetTreble':
        selectEqPreset('treble');
        break;
      case 'selectEqPresetVocal':
        selectEqPreset('vocal');
        break;
      case 'selectEqPresetRock':
        selectEqPreset('rock');
        break;
      case 'selectEqPresetClassical':
        selectEqPreset('classical');
        break;
      case 'selectEqPresetPop':
        selectEqPreset('pop');
        break;
      case 'selectEqPresetJazz':
        selectEqPreset('jazz');
        break;
      case 'selectEqPresetElectronic':
        selectEqPreset('electronic');
        break;
      case 'selectEqPresetAcoustic':
        selectEqPreset('acoustic');
        break;
      case 'selectEqPresetGaming':
        selectEqPreset('gaming');
        break;

      // ── Sidebar setActive (view_playlist.php) ──
      case 'setActivePlaylist':
        var plId = parseInt(el.getAttribute('data-csp-plid'));
        setActivePlaylistSidebar(plId);
        break;

      case 'toggleArtistDropdown':
        toggleArtistDropdownPL();
        break;
      case 'navigateToArtistAll':
        navigateToArtistPL('all');
        break;
      case 'togglePlaylistDropdown':
        togglePlaylistDropdownPL();
        break;

      // ── Arcade ──
      case 'arcadeOpenModal':
        var gameId = el.getAttribute('data-csp-gameid');
        openModal(gameId);
        break;
      case 'arcadeCloseModal':
        closeModal();
        break;

      // ── Default: try calling as global function ──
      default:
        // Parse parameterized calls like 'funcName(arg)' or 'funcName("arg")'
        var fnMatch = action.match(/^([\w$]+)\((.+)?\)$/);
        if (fnMatch) {
          var fnName = fnMatch[1];
          var argsStr = fnMatch[2] ? fnMatch[2].trim() : '';
          if (typeof window[fnName] === 'function') {
            // Parse comma-separated arguments
            if (argsStr) {
              var args = [];
              // Simple argument parser: handles quoted strings, numbers, and undefined
              var argParts = argsStr.split(',');
              for (var i = 0; i < argParts.length; i++) {
                var a = argParts[i].trim();
                if (a === 'undefined') { args.push(undefined); }
                else if (a === 'null') { args.push(null); }
                else if (a === 'true') { args.push(true); }
                else if (a === 'false') { args.push(false); }
                else if (!isNaN(a)) { args.push(parseFloat(a)); }
                else if ((a.startsWith("'\"") && a.endsWith("'\"")) || (a.startsWith("\"'") && a.endsWith("\"'"))) {
                  args.push(a.slice(1, -1));
                } else if ((a.startsWith("'")) && a.endsWith("'")) {
                  args.push(a.slice(1, -1));
                } else if ((a.startsWith('"')) && a.endsWith('"')) {
                  args.push(a.slice(1, -1));
                } else {
                  args.push(a);
                }
              }
              window[fnName].apply(null, args);
            } else {
              window[fnName](e);
            }
            return;
          }
        } else if (typeof window[action] === 'function') {
          window[action](e);
        } else if (action.startsWith('window.location.href')) {
          var urlMatch = action.match(/['"]([^'"]+)['"]/);
          if (urlMatch) window.location.href = urlMatch[1];
        }
    }
  });

  // ─── Navigation (data-csp-nav) ──────────────────────────────
  document.addEventListener('click', function(e) {
    var el = e.target.closest('[data-csp-nav]');
    if (!el) return;
    var url = el.getAttribute('data-csp-nav');
    if (url) window.location.href = url;
  });

  // ─── Form Submit Delegation (data-csp-submit) ───────────────
  document.addEventListener('submit', function(e) {
    var form = e.target;
    var action = form.getAttribute('data-csp-submit');
    if (!action) return;

    switch (action) {
      case 'handleSubmit':
        e.preventDefault();
        handleSubmit();
        break;
      case 'startAdvancedUpload':
        e.preventDefault();
        return startAdvancedUpload(form);
      case 'startProcess':
        e.preventDefault();
        startProcess();
        break;
      default:
        if (typeof window[action] === 'function') {
          e.preventDefault();
          window[action](form);
        }
    }
  });

  // ─── Image Error (data-csp-imgerror) ────────────────────────
  document.addEventListener('error', function(e) {
    var target = e.target;
    if (target.tagName !== 'IMG') return;
    var action = target.getAttribute('data-csp-imgerror');
    if (!action) return;

    switch (action) {
      case 'hide':
        target.style.display = 'none';
        var fallback = target.nextElementSibling;
        if (fallback) fallback.style.display = 'flex';
        break;
      case 'reload':
        // For book covers / thumbnails
        target.style.display = 'none';
        var fb = target.parentElement ? target.parentElement.querySelector('.book-fallback') : null;
        if (fb) fb.classList.remove('hidden');
        break;
    }
  }, true);

  // ─── Change Delegation (data-csp-change) ────────────────────
  document.addEventListener('change', function(e) {
    var el = e.target;
    var action = el.getAttribute('data-csp-change');
    if (!action) return;

    switch (action) {
      case 'handleVideoFile':
        handleVideoFile(el);
        break;
      case 'handleThumbFile':
        handleThumbFile(el);
        break;
      case 'handleAudioFile':
        handleAudioFile(el);
        break;
      case 'handleCoverFile':
        handleCoverFile(el);
        break;
      case 'updateFileName':
        updateFileName(el);
        break;
      case 'autoFillMetadata':
        autoFillMetadata();
        break;
    }
  });

})();
