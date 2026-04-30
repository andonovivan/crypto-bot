import Alpine from 'alpinejs';
import { dashboardTopbar } from './topbar.js';
import { toastBus } from './toast.js';
import './polling.js'; // exposes window.dashboardPolling
import { bindPositionsTable } from './tables/positions.js';
import { bindScanner } from './tables/scanner.js';
import { bindTradesTable } from './tables/trades.js';
import { bindFailedTable } from './tables/failed.js';
import { bindSettingsPage } from './settings.js';

window.Alpine = Alpine;

document.addEventListener('alpine:init', () => {
    Alpine.data('dashboardTopbar', dashboardTopbar);
    Alpine.data('toastBus', toastBus);
});

function mountPage() {
    const page = document.body.dataset.page || 'overview';

    if (document.getElementById('positions-body')) bindPositionsTable();
    if (document.getElementById('scanner-body')) bindScanner();
    if (document.getElementById('history-body')) bindTradesTable();
    if (document.getElementById('failed-body')) bindFailedTable();
    if (document.getElementById('settings-form')) bindSettingsPage();

    window.dashboardPolling.start(page);
}

async function navigate(href, { push = true } = {}) {
    try {
        const res = await fetch(href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!res.ok) {
            window.location.href = href;
            return;
        }
        const html = await res.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const newContent = doc.getElementById('spa-content');
        const currentContent = document.getElementById('spa-content');
        if (!newContent || !currentContent) {
            window.location.href = href;
            return;
        }

        if (doc.title) document.title = doc.title;

        const newPage = doc.body.dataset.page || 'overview';
        document.body.dataset.page = newPage;

        // Stop polling before swap so timers don't fire against the old DOM.
        window.dashboardPolling.stop();

        currentContent.replaceWith(newContent);

        document.querySelectorAll('[data-spa-link]').forEach((a) => {
            const linkPath = new URL(a.href).pathname;
            a.dataset.spaActive = linkPath === new URL(href, window.location.origin).pathname ? 'true' : 'false';
        });

        // Wire up Alpine directives in the swapped subtree.
        if (window.Alpine?.initTree) window.Alpine.initTree(newContent);

        // Re-bind page-specific helpers + register the right pollers for this page.
        mountPage();

        if (push) history.pushState({ spa: true, href }, '', href);

        window.dispatchEvent(new CustomEvent('spa:navigated', { detail: { page: newPage } }));
    } catch (e) {
        console.error('SPA navigate failed, falling back to full nav:', e);
        window.location.href = href;
    }
}

function bindSpaLinks() {
    document.addEventListener('click', (e) => {
        const a = e.target.closest('a[data-spa-link]');
        if (!a) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
        if (a.target && a.target !== '_self') return;
        const href = a.getAttribute('href');
        if (!href) return;
        // Ignore in-page navigations.
        if (new URL(a.href).pathname === window.location.pathname) {
            e.preventDefault();
            return;
        }
        e.preventDefault();
        navigate(href);
    });

    window.addEventListener('popstate', () => {
        navigate(window.location.pathname + window.location.search, { push: false });
    });
}

function boot() {
    bindSpaLinks();
    mountPage();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}

Alpine.start();
