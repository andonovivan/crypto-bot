import { postJson } from './api.js';
import { toast } from './toast.js';

/**
 * Risk-page bindings: currently just the "Sync to wallet" button that snaps
 * starting_balance to the current wallet so per-strategy breaker allocations
 * scale with the real account.
 *
 * Display values (configured / wallet / allocation) come from /api/stats and
 * are populated in polling.js — this file only handles the button click.
 */
export function bindRiskPage() {
    const btn = document.getElementById('risk-sync-balance-btn');
    if (!btn) return;

    btn.addEventListener('click', async () => {
        const startEl = document.getElementById('risk-starting-balance');
        const walletEl = document.getElementById('risk-current-wallet');
        const prev = startEl?.textContent?.trim() ?? '—';
        const wallet = walletEl?.textContent?.trim() ?? 'current wallet';
        if (!confirm(`Snap starting_balance from ${prev} to ${wallet}? Per-strategy breaker peaks will re-anchor on next check; active cooldowns stay in effect.`)) return;

        btn.disabled = true;
        const originalText = btn.textContent;
        btn.textContent = 'Syncing…';
        try {
            const res = await postJson('/api/sync-starting-balance');
            toast.success(`starting_balance: $${res.previous} → $${res.new} · ${res.samples_cleared.length} breaker peak${res.samples_cleared.length === 1 ? '' : 's'} cleared.`);
            window.dashboardPolling?.refreshNow();
        } catch (e) {
            toast.error(e.message || 'Sync failed');
        } finally {
            btn.disabled = false;
            btn.textContent = originalText;
        }
    });
}
