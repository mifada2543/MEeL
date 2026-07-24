/**
 * ═══════════════════════════════════════════════════════════
 * MEeL — Documentation Shared JavaScript
 * ═══════════════════════════════════════════════════════════
 *
 * Dependencies:
 *   - marked.js (Markdown parser, loaded in head)
 *   - partials/head.html (CSS)
 *   - partials/nav.html (navbar template)
 *   - partials/footer.html (footer template)
 * ═══════════════════════════════════════════════════════════
 */

// ── Navigation Structure ──────────────────────────────────
const DOCS = {
    en: {
        label: 'English',
        flag: '🇬🇧',
        nav: [
            ['index',             '🏠 Home'],
            ['installation',      '🚀 Install'],
            ['configuration',     '⚙️ Config'],
            ['modules',           '🏗️ Modules'],
            ['api',               '🔌 API'],
            ['security',          '🔒 Security'],
            ['development',       '👨‍💻 Dev'],
            ['troubleshooting',   '🔧 Troubleshoot'],
            ['problem-solved',    '🌍 Problems'],
            ['upload_issue',      '📥 Upload'],
            ['analysis',          '📋 Analysis'],
        ]
    },
    id: {
        label: 'Indonesia',
        flag: '🇮🇩',
        nav: [
            ['index',             '🏠 Beranda'],
            ['installation',      '🚀 Instalasi'],
            ['configuration',     '⚙️ Konfigurasi'],
            ['modules',           '🏗️ Modul'],
            ['api',               '🔌 API'],
            ['security',          '🔒 Keamanan'],
            ['development',       '👨‍💻 Dev'],
            ['troubleshooting',   '🔧 Troubleshoot'],
            ['problem-solved',    '🌍 Masalah'],
            ['upload_issue',      '📥 Upload'],
            ['deskripsi',         '📋 Analisis'],
        ]
    }
};

// ── Partial Loader ──────────────────────────────────────
async function loadPartial(url, targetId) {
    try {
        const res = await fetch(url);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const html = await res.text();
        const el = document.getElementById(targetId);
        if (el) el.innerHTML = html;
        return true;
    } catch (err) {
        console.warn(`[docs.js] Failed to load partial: ${url}`, err.message);
        return false;
    }
}

// ── URL Params ──────────────────────────────────────────
function getDocParams() {
    const p = new URLSearchParams(window.location.search);
    return {
        lang: (p.get('lang') || 'en').toLowerCase(),
        file: p.get('file') || 'index'
    };
}

// ── Render Navbar ──────────────────────────────────────
function renderNavbar(lang, activeFile) {
    const docs = DOCS[lang] || DOCS.en;
    const otherLang = lang === 'en' ? 'id' : 'en';
    const otherDocs = DOCS[otherLang];

    const linksContainer = document.getElementById('nav-links');
    const langSwitch = document.getElementById('lang-switch');

    if (!linksContainer) return;

    // Build nav links
    let linksHtml = '';
    docs.nav.forEach(([slug, label]) => {
        const active = slug === activeFile ? 'active' : '';
        linksHtml += `<a href="?lang=${lang}&file=${slug}" class="nav-link ${active}">${label}</a>`;
    });
    linksContainer.innerHTML = linksHtml;

    // Language switcher
    if (langSwitch) {
        langSwitch.href = `?lang=${otherLang}&file=${activeFile}`;
        langSwitch.textContent = `${otherDocs.flag} ${otherDocs.label}`;
    }
}

// ── Load & Render Markdown ─────────────────────────────
const DOC_STATE = { lang: 'en', file: 'index' };

async function loadDoc(lang, file) {
    DOC_STATE.lang = lang;
    DOC_STATE.file = file;

    const loading = document.getElementById('loading');
    const content = document.getElementById('content');
    const error = document.getElementById('error');
    const errorMsg = document.getElementById('errorMsg');

    if (!content) return;

    // Show loading
    if (loading) loading.style.display = 'flex';
    if (content) content.style.display = 'none';
    if (error) error.style.display = 'none';

    // Update navbar
    renderNavbar(lang, file);

    const mdPath = `${lang}/${file}.md`;

    try {
        const res = await fetch(mdPath);
        if (!res.ok) throw new Error(`HTTP ${res.status}: ${res.statusText}`);

        const md = await res.text();
        const html = marked.parse(md, { gfm: true });

        // Fix internal .md links → viewer links
        const fixedHtml = html.replace(
            /href="([^"]+)\.md"/g,
            (match, path) => {
                if (!path.includes('://') && !path.startsWith('#')) {
                    return `href="?lang=${lang}&file=${path.replace(/\.md$/, '')}"`;
                }
                return match;
            }
        );

        content.innerHTML = fixedHtml;

        if (loading) loading.style.display = 'none';
        content.style.display = 'block';

        // Update page title from first h1
        const titleEl = content.querySelector('h1');
        document.title = titleEl
            ? titleEl.textContent.replace(/[:|].*/, '').trim() + ' | MEeL Docs'
            : 'MEeL Documentation';

    } catch (err) {
        if (loading) loading.style.display = 'none';
        if (error) {
            error.style.display = 'block';
            if (errorMsg) errorMsg.textContent = `Failed to load ${mdPath}: ${err.message}`;
        }
        document.title = 'Not Found | MEeL Docs';
    }
}

// ── Load Footer ─────────────────────────────────────────
async function loadFooter() {
    await loadPartial('partials/footer.html', 'footer');
}

// ── Init Documentation Page ─────────────────────────────
function initDocViewer() {
    const params = getDocParams();
    DOC_STATE.lang = params.lang;
    DOC_STATE.file = params.file;
    loadDoc(params.lang, params.file);
}

// ── Auto-init ─────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('content')) {
        initDocViewer();
    }
    loadFooter();
});
