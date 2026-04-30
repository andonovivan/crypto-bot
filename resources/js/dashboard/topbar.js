// Alpine data factory for the topbar. Subscribes to polling state and exposes
// reactive bindings for the layout's <header>.

import { fmtMoney, fmtPnl } from './formatters.js';

const TITLES = {
    overview: 'Overview',
    positions: 'Positions',
    scanner: 'Scanner',
    history: 'Trade History',
    failed: 'Failed Entries',
    risk: 'Risk',
    settings: 'Settings',
};

export function dashboardTopbar() {
    return {
        title: TITLES[document.body.dataset.page] || 'Dashboard',
        status: {
            dryRun: false,
            paused: false,
            wallet: 0,
            todayPnl: 0,
            circuitBreakerActive: false,
        },
        lastUpdate: null,
        pulse: false,
        busy: false,

        formatMoney(v) {
            return fmtMoney(v);
        },
        formatPnl(v) {
            return fmtPnl(v);
        },

        get lastUpdateLabel() {
            if (!this.lastUpdate) return '—';
            const d = new Date(this.lastUpdate * 1000);
            return d.toLocaleTimeString();
        },

        async togglePause() {
            this.busy = true;
            try {
                await window.dashboardPolling.togglePause();
            } finally {
                this.busy = false;
            }
        },

        init() {
            window.dashboardPolling?.subscribe((s) => {
                if (s.lastUpdate && s.lastUpdate !== this.lastUpdate) {
                    this.pulse = true;
                    setTimeout(() => {
                        this.pulse = false;
                    }, 700);
                }
                this.status = {
                    dryRun: s.dryRun,
                    paused: s.paused,
                    wallet: s.wallet,
                    todayPnl: s.todayPnl,
                    circuitBreakerActive: s.circuitBreakerActive,
                };
                this.lastUpdate = s.lastUpdate;
            });
        },
    };
}
