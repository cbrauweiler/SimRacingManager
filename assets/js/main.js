/* ============================================================
   SimRace Liga Manager – Main JS (Public)
   ============================================================ */

// Mobile Nav
function toggleMobileNav() {
    const nav     = document.getElementById('mobile-nav');
    const overlay = document.getElementById('mobile-nav-overlay');
    const btn     = document.getElementById('nav-burger-btn');
    const isOpen  = nav?.classList.contains('open');
    if (isOpen) {
        closeMobileNav();
    } else {
        nav?.classList.add('open');
        overlay?.classList.add('open');
        btn?.classList.add('open');
        btn?.setAttribute('aria-expanded','true');
        nav?.setAttribute('aria-hidden','false');
        document.body.style.overflow = 'hidden';
    }
}

function closeMobileNav() {
    const nav     = document.getElementById('mobile-nav');
    const overlay = document.getElementById('mobile-nav-overlay');
    const btn     = document.getElementById('nav-burger-btn');
    nav?.classList.remove('open');
    overlay?.classList.remove('open');
    btn?.classList.remove('open');
    btn?.setAttribute('aria-expanded','false');
    nav?.setAttribute('aria-hidden','true');
    document.body.style.overflow = '';
}

function toggleMobileNav_legacy() {
    document.getElementById('mobile-nav')?.classList.toggle('open');
}
document.addEventListener('click', function(e) {
    const nav = document.getElementById('mobile-nav');
    if (nav && !nav.contains(e.target) && !e.target.closest('#nav-burger-btn')) {
        nav.classList.remove('open');
    }
});

// Tab switching (public pages)
function switchTab(tabName) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    const activeTab = document.querySelector(`.tab[data-tab="${tabName}"]`);
    const activePanel = document.getElementById('tab-' + tabName);
    if (activeTab)  activeTab.classList.add('active');
    if (activePanel) activePanel.classList.add('active');
}

// Fade-up on scroll
(function() {
    const obs = new IntersectionObserver((entries) => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                e.target.classList.add('fade-up');
                obs.unobserve(e.target);
            }
        });
    }, { threshold: 0.1 });
    document.querySelectorAll('.observe-fade').forEach(el => obs.observe(el));
})();

// Flash messages auto-dismiss
setTimeout(() => {
    document.querySelectorAll('.flash-message').forEach(el => {
        el.style.transition = 'opacity .5s';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 500);
    });
}, 4000);


// Sheet-Tab switching (Tabellenblatt-Style)
function sheetTab(group, tabId) {
    document.querySelectorAll(`.sheet-tab[data-group="${group}"]`).forEach(t => t.classList.remove('active'));
    document.querySelectorAll(`.sheet-panel[data-group="${group}"]`).forEach(p => p.classList.remove('active'));
    const tab   = document.querySelector(`.sheet-tab[data-group="${group}"][data-tab="${tabId}"]`);
    const panel = document.querySelector(`.sheet-panel[data-group="${group}"][data-tab="${tabId}"]`);
    if (tab)   tab.classList.add('active');
    if (panel) panel.classList.add('active');
    // URL hash updaten für Bookmarkability
    history.replaceState(null, '', '#' + group + '-' + tabId);
}

// Beim Laden: Hash prüfen
document.addEventListener('DOMContentLoaded', function() {
    const hash = window.location.hash.replace('#','');
    if (hash && hash.includes('-')) {
        const parts = hash.split('-');
        const group = parts[0];
        const tabId = parts.slice(1).join('-');
        const tab = document.querySelector(`.sheet-tab[data-group="${group}"][data-tab="${tabId}"]`);
        if (tab) sheetTab(group, tabId);
    }
});

// ESC schliesst Mobile Nav
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeMobileNav();
});
