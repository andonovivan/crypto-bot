import { fmtVolume, formatPrice, escapeHtml } from '../formatters.js';
import { getJson, postJson } from '../api.js';
import { toast } from '../toast.js';

const sortState = { key: 'price_change_pct', asc: false };
let cached = null;
let timer = null;

function reasonPill(reason) {
    if (reason === 'pump')
        return '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold tracking-wide uppercase border bg-[var(--color-success-soft)] text-[var(--color-success)] border-[var(--color-success)]/30">Pump</span>';
    if (reason === 'dump')
        return '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold tracking-wide uppercase border bg-[var(--color-danger-soft)] text-[var(--color-danger)] border-[var(--color-danger)]/30">Dump</span>';
    return '';
}

function trendPill(c) {
    if (c.ema_fast == null || c.ema_slow == null) {
        return '<span class="text-[var(--color-text-subtle)] text-xs">—</span>';
    }
    if (c.ema_fast < c.ema_slow) return '<span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-[var(--color-success-soft)] text-[var(--color-success)]">DOWN</span>';
    if (c.ema_fast > c.ema_slow) return '<span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-[var(--color-danger-soft)] text-[var(--color-danger)]">UP</span>';
    return '<span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-[var(--color-surface-hover)] text-[var(--color-text-muted)]">FLAT</span>';
}

function candleCell(c) {
    if (c.last_candle_red === null || c.last_candle_red === undefined) return '<span class="text-[var(--color-text-subtle)]">—</span>';
    const redCount = (c.last_candle_red ? 1 : 0) + (c.prior_candle_red ? 1 : 0);
    const tone = redCount === 2 ? 'bg-[var(--color-success-soft)] text-[var(--color-success)]'
        : redCount === 1 ? 'bg-[var(--color-warning-soft)] text-[var(--color-warning)]'
        : 'bg-[var(--color-danger-soft)] text-[var(--color-danger)]';
    const label = redCount === 2 ? '2/2 RED' : redCount === 1 ? '1/2 RED' : '0/2 RED';
    const body = c.candle_body_pct != null
        ? ` <span class="text-[var(--color-text-subtle)] text-[10px]">${c.candle_body_pct.toFixed(2)}%</span>`
        : '';
    return `<span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase ${tone}">${label}</span>${body}`;
}

function fundingCell(rate) {
    if (rate == null) return '<span class="text-[var(--color-text-subtle)]">—</span>';
    const pct = (rate * 100).toFixed(4);
    const cls = rate < 0 ? 'text-[var(--color-danger)]' : 'text-[var(--color-success)]';
    return `<span class="${cls} font-mono text-xs">${rate >= 0 ? '+' : ''}${pct}%</span>`;
}

function rowHtml(c) {
    const changeCls = c.price_change_pct >= 0 ? 'text-[var(--color-success)]' : 'text-[var(--color-danger)]';
    const ok = c.can_enter
        ? '<span class="text-[var(--color-success)] text-base">✓</span>'
        : '<span class="text-[var(--color-danger)] text-base">✗</span>';
    const reasonText = c.blocked_reasons?.length
        ? `<div class="text-[10px] text-[var(--color-text-subtle)] mt-0.5" title="${escapeHtml(c.blocked_reasons.join('\n'))}">${escapeHtml(c.blocked_reasons[0])}</div>`
        : '';

    return `<tr class="border-t border-[var(--color-border)] hover:bg-[var(--color-surface-hover)] transition-colors">
        <td class="px-4 py-3">
            <div class="font-semibold">${c.symbol}</div>
            <div class="mt-0.5">${reasonPill(c.reason)}</div>
        </td>
        <td class="px-4 py-3 font-mono font-semibold ${changeCls}">${c.price_change_pct >= 0 ? '+' : ''}${c.price_change_pct.toFixed(2)}%</td>
        <td class="px-4 py-3 font-mono text-xs">${fmtVolume(c.volume)}</td>
        <td class="px-4 py-3 font-mono text-xs">${formatPrice(c.price)}</td>
        <td class="px-4 py-3">${trendPill(c)}</td>
        <td class="px-4 py-3">${candleCell(c)}</td>
        <td class="px-4 py-3">${fundingCell(c.funding_rate)}</td>
        <td class="px-4 py-3 font-mono text-xs text-center">${c.open_positions}</td>
        <td class="px-4 py-3">${ok}${reasonText}</td>
        <td class="px-4 py-3 whitespace-nowrap">
            <button data-action="short" data-symbol="${c.symbol}" class="px-2 py-1 rounded text-[11px] bg-[var(--color-danger-soft)] text-[var(--color-danger)] hover:bg-[var(--color-danger)] hover:text-[var(--color-surface)] transition-colors">Short</button>
        </td>
    </tr>`;
}

function sortRows(rows) {
    const k = sortState.key;
    return rows.slice().sort((a, b) => {
        let va = a[k];
        let vb = b[k];
        if (va == null) va = -Infinity;
        if (vb == null) vb = -Infinity;
        if (typeof va === 'string') return sortState.asc ? va.localeCompare(vb) : vb.localeCompare(va);
        return sortState.asc ? va - vb : vb - va;
    });
}

function render(data) {
    cached = data;
    const body = document.getElementById('scanner-body');
    if (!body) return;
    if (!data.candidates?.length) {
        body.innerHTML = `<tr><td colspan="10" class="text-center text-xs text-[var(--color-text-subtle)] py-8">No pump/dump candidates right now.</td></tr>`;
        return;
    }
    body.innerHTML = sortRows(data.candidates).map(rowHtml).join('');
    updateArrows();

    const sel = document.getElementById('manual-symbol');
    if (sel) {
        const cur = sel.value;
        sel.innerHTML = '<option value="">Select symbol…</option>' +
            data.candidates.map((s) => `<option value="${s.symbol}" ${s.symbol === cur ? 'selected' : ''}>${s.symbol}</option>`).join('');
    }

    const ts = document.getElementById('scanner-updated');
    if (ts) ts.textContent = data.scanned_at ? new Date(data.scanned_at * 1000).toLocaleTimeString() : '';
}

function updateArrows() {
    document.querySelectorAll('[data-sort-arrow^="scanner-"]').forEach((el) => {
        const key = el.dataset.sortArrow.replace('scanner-', '');
        el.textContent = sortState.key === key ? (sortState.asc ? '▲' : '▼') : '';
    });
}

async function fetchScanner(silent = false) {
    const btn = document.getElementById('scan-btn');
    if (btn && !silent) {
        btn.disabled = true;
        btn.dataset.origText = btn.textContent;
        btn.textContent = 'Scanning…';
    }
    try {
        const data = await getJson('/api/scanner');
        if (data.ok) render(data);
    } catch (e) {
        if (!silent) toast.error('Scanner: ' + e.message);
    } finally {
        if (btn && !silent) {
            btn.disabled = false;
            btn.textContent = btn.dataset.origText || 'Scan';
        }
    }
}

function startTimer() {
    stopTimer();
    timer = setInterval(() => {
        if (!document.hidden) fetchScanner(true);
    }, 15000);
}

function stopTimer() {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
}

// Delegated click handler for the per-row Short button. Attached once at
// module load so that SPA navigation (which re-runs bindScanner()) doesn't
// stack duplicate listeners — without this, a single Short click after N
// visits to /scanner fired N POSTs to /api/open-position.
document.addEventListener('click', async (e) => {
    const shortBtn = e.target.closest('#scanner-body [data-action="short"]');
    if (!shortBtn) return;
    const symbol = shortBtn.dataset.symbol;
    if (!confirm(`Open SHORT position on ${symbol}?`)) return;
    shortBtn.disabled = true;
    const orig = shortBtn.textContent;
    shortBtn.textContent = 'Opening…';
    try {
        await postJson('/api/open-position', { symbol });
        toast.success(`Opened SHORT on ${symbol}`);
        fetchScanner(true);
        window.dashboardPolling?.refreshNow();
    } catch (err) {
        toast.error(err.message);
    } finally {
        shortBtn.disabled = false;
        shortBtn.textContent = orig;
    }
});

// Same singleton treatment for visibility — startTimer() guards against
// duplicate intervals via stopTimer(), but accumulating listeners still
// queued multiple stop/start cycles per visibility change.
document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopTimer();
    else if (document.getElementById('scanner-body')) startTimer();
});

export function bindScanner() {
    document.querySelectorAll('[data-sort-table="scanner"]').forEach((th) => {
        th.addEventListener('click', () => {
            const key = th.dataset.sortKey;
            if (sortState.key === key) sortState.asc = !sortState.asc;
            else {
                sortState.key = key;
                sortState.asc = true;
            }
            if (cached) render(cached);
        });
    });

    document.getElementById('scan-btn')?.addEventListener('click', () => fetchScanner(false));
    document.getElementById('scan-auto-btn')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        btn.disabled = true;
        const orig = btn.textContent;
        btn.textContent = 'Scanning…';
        try {
            const data = await postJson('/api/scan', { auto_trade: true });
            let msg = `Found ${data.candidate_count} candidate(s)`;
            if (data.trades_opened?.length) msg += ` — opened: ${data.trades_opened.join(', ')}`;
            toast.success(msg);
            fetchScanner(true);
            window.dashboardPolling?.refreshNow();
        } catch (err) {
            toast.error(err.message);
        } finally {
            btn.disabled = false;
            btn.textContent = orig;
        }
    });

    document.getElementById('open-manual-btn')?.addEventListener('click', async () => {
        const sel = document.getElementById('manual-symbol');
        const symbol = sel?.value;
        if (!symbol) {
            toast.error('Pick a symbol first');
            return;
        }
        if (!confirm(`Open SHORT on ${symbol}?`)) return;
        try {
            await postJson('/api/open-position', { symbol });
            toast.success(`Opened SHORT on ${symbol}`);
            fetchScanner(true);
            window.dashboardPolling?.refreshNow();
        } catch (err) {
            toast.error(err.message);
        }
    });

    fetchScanner(true);
    startTimer();
}
