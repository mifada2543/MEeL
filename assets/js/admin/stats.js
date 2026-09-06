(function() {
    'use strict';

    /* ── Lucide Icons ── */
    function initIcons() {
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    /* ── Delete Modal ── */
    window.confirmDelete = function(id, type, title) {
        var idEl    = document.getElementById('modal-media-id');
        var typeEl  = document.getElementById('modal-media-type');
        var titleEl = document.getElementById('modal-title-display');
        var badge   = document.getElementById('modal-type-badge');
        var modal   = document.getElementById('delete-modal');
        if (idEl) idEl.value = id;
        if (typeEl) typeEl.value = type;
        if (titleEl) titleEl.textContent = title;
        if (badge) {
            var isVideo = type === 'video';
            badge.textContent = type.toUpperCase();
            badge.style.cssText =
                'font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;padding:3px 9px;border-radius:8px;' +
                (isVideo
                    ? 'background:rgba(239,68,68,.1);color:#ef4444;border:1px solid rgba(239,68,68,.2);'
                    : 'background:rgba(249,115,22,.1);color:#f97316;border:1px solid rgba(249,115,22,.2);');
        }
        if (modal) modal.classList.add('open');
    };
    window.closeDeleteModal = function() {
        var modal = document.getElementById('delete-modal');
        if (modal) modal.classList.remove('open');
    };
    var deleteModal = document.getElementById('delete-modal');
    if (deleteModal) {
        deleteModal.addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeDeleteModal();
    });

    /* ── Search Enter ── */
    var searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var form = document.getElementById('search-form');
                if (form) form.submit();
            }
        });
    }

    /* ── Type Dropdown Toggle ── */
    var typeTrigger = document.getElementById('type-trigger');
    var typeDropdown = document.getElementById('type-dropdown');
    if (typeTrigger && typeDropdown) {
        typeTrigger.addEventListener('click', function(e) {
            e.stopPropagation();
            typeDropdown.classList.toggle('open');
        });
    }
    document.addEventListener('click', function(e) {
        var dd = document.getElementById('type-dropdown');
        if (dd && !dd.contains(e.target)) dd.classList.remove('open');
    });

    /* ══════════════════════════════════════════════════════
       CLIENT-SIDE SORT FIX
       Reads URL params, rewrites ALL sort links + chevrons
       Works even if page is served from cache.
       ══════════════════════════════════════════════════════ */
    var p    = new URLSearchParams(window.location.search);
    var sort = p.get('sort') || 'views';
    var dir  = p.get('dir')  || '';
    var type = p.get('type') || 'all';
    var search = p.get('search') || '';
    if (!dir) {
        dir = (sort === 'id' || sort === 'title') ? 'asc' : 'desc';
    }
    var base = window.location.pathname;

    function buildUrl(overrides) {
        var o = { sort: sort, dir: dir, type: type, search: search };
        for (var k in overrides) o[k] = overrides[k];
        var qs = [];
        for (var key in o) {
            if (o[key] !== '' && o[key] !== null && o[key] !== undefined) {
                qs.push(encodeURIComponent(key) + '=' + encodeURIComponent(o[key]));
            }
        }
        return base + (qs.length ? '?' + qs.join('&') : '');
    }

    function makeChevron(dir) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('width', '12');
        svg.setAttribute('height', '12');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', '#60a5fa');
        svg.setAttribute('stroke-width', '3');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.style.cssText = 'display:inline-block;vertical-align:middle;margin-left:4px;';
        var poly = document.createElementNS(ns, 'polyline');
        poly.setAttribute('points', dir === 'asc' ? '6 9 12 15 18 9' : '18 15 12 9 6 15');
        svg.appendChild(poly);
        return svg;
    }

    /* Fix sort links + chevrons */
    document.querySelectorAll('.sort-link[data-sort]').forEach(function(a) {
        var field = a.getAttribute('data-sort');
        var nextDir = (field === sort)
            ? (dir === 'asc' ? 'desc' : 'asc')
            : ((field === 'id' || field === 'title') ? 'asc' : 'desc');
        a.href = buildUrl({ sort: field, dir: nextDir });

        var old = a.querySelector('svg');
        if (old) old.remove();
        if (field === sort) {
            a.appendChild(makeChevron(dir));
        }
    });

    /* Fix stat chip links */
    document.querySelectorAll('.stat-chip[data-type]').forEach(function(chip) {
        chip.href = buildUrl({ type: chip.getAttribute('data-type') });
    });

    /* Fix type option links */
    document.querySelectorAll('.type-option[data-type]').forEach(function(opt) {
        opt.href = buildUrl({ type: opt.getAttribute('data-type') });
    });

    /* Fix clear filter link */
    var clearBtn = document.querySelector('.btn-clear-filter');
    if (clearBtn) {
        clearBtn.href = buildUrl({ search: '', type: type });
    }

    /* Fix search form hidden inputs */
    document.querySelectorAll('#search-form input[type="hidden"]').forEach(function(input) {
        if (input.name === 'sort') input.value = sort;
        else if (input.name === 'dir') input.value = dir;
        else if (input.name === 'type') input.value = type;
    });

    /* ── Hover Effects ── */
    document.querySelectorAll('.admin-table tbody tr').forEach(function(row) {
        row.addEventListener('mouseenter', function() { this.style.background = 'rgba(255,255,255,0.02)'; });
        row.addEventListener('mouseleave', function() { this.style.background = 'transparent'; });
    });

    /* ── Init ── */
    initIcons();

})();
