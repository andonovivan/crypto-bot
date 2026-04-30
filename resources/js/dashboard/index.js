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

function boot() {
    const page = document.body.dataset.page || 'overview';

    if (document.getElementById('positions-body')) bindPositionsTable();
    if (document.getElementById('scanner-body')) bindScanner();
    if (document.getElementById('history-body')) bindTradesTable();
    if (document.getElementById('failed-body')) bindFailedTable();
    if (document.getElementById('settings-form')) bindSettingsPage();

    window.dashboardPolling.start(page);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}

Alpine.start();
