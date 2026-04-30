import { fmtNum, fmtPnl, formatPrice, formatTimestamp, holdTime, pnlClass, fundingEarning } from '../formatters.js';
import { postJson } from '../api.js';
import { toast } from '../toast.js';

const sortState = { key: 'opened_at', asc: false };

function sideBadge(side, leverage, isDryRun) {
    const sideTone = side === 'LONG' ? 'text-[var(--color-success)]' : 'text-[var(--color-danger)]';
    return `<span class="${sideTone} font-bold text-[10px] tracking-wide">${side}</span>
        <span class="text-[var(--color-text-subtle)] text-[10px] ml-1">${leverage || '—'}×</span>
        ${isDryRun ? '<span class="ml-1 px-1 py-0.5 rounded text-[8px] font-bold bg-[var(--color-warning-soft)] text-[var(--color-warning)] border border-[var(--color-warning)]/40">DRY</span>' : ''}`;
}

function rowHtml(p) {
    const fundingHtml = p.funding_rate !== null && p.funding_rate !== undefined
        ? (() => {
            const earning = fundingEarning(p.side, p.funding_rate);
            const cls = earning ? 'text-[var(--color-success)]' : 'text-[var(--color-danger)]';
            const label = earning ? 'earning' : 'paying';
            return `<div class="text-[10px] ${cls} font-normal">${(Math.abs(p.funding_rate) * 100).toFixed(4)}% ${label}</div>`;
        })()
        : '';
    const accruedFunding = p.funding_fee
        ? `<div class="text-[10px] ${pnlClass(p.funding_fee)} font-normal">${fmtPnl(p.funding_fee)} funding</div>`
        : '';

    return `<tr class="border-t border-[var(--color-border)] hover:bg-[var(--color-surface-hover)] transition-colors">
        <td class="px-4 py-3">
            <div class="font-semibold">${p.symbol}</div>
            <div class="mt-0.5">${sideBadge(p.side, p.leverage, p.is_dry_run)}</div>
        </td>
        <td class="px-4 py-3 font-mono text-xs">${formatPrice(p.entry_price)}</td>
        <td class="px-4 py-3 font-mono text-xs">${formatPrice(p.current_price)}</td>
        <td class="px-4 py-3 font-mono ${pnlClass(p.unrealized_pnl)}">${fmtPnl(p.unrealized_pnl)}</td>
        <td class="px-4 py-3 font-mono font-semibold ${pnlClass(p.pnl_pct)}">${p.pnl_pct >= 0 ? '+' : ''}${(p.pnl_pct ?? 0).toFixed(2)}%</td>
        <td class="px-4 py-3 font-mono text-xs">$${fmtNum(p.position_size_usdt, 2)}</td>
        <td class="px-4 py-3">
            <div class="font-mono font-semibold ${pnlClass(p.net_pnl)}">${fmtPnl(p.net_pnl)}</div>
            <div class="text-[10px] text-[var(--color-text-subtle)] font-normal">−$${fmtNum(p.estimated_fees ?? 0, 4)} fees</div>
            ${fundingHtml}
            ${accruedFunding}
        </td>
        <td class="px-4 py-3 font-mono text-xs">
            <div class="text-[var(--color-danger)]/80">${formatPrice(p.stop_loss_price)}</div>
            <div class="text-[var(--color-success)]/80">${formatPrice(p.take_profit_price)}</div>
        </td>
        <td class="px-4 py-3 text-xs text-[var(--color-text-muted)]">${formatTimestamp(p.opened_at)}</td>
        <td class="px-4 py-3 text-xs text-[var(--color-text-muted)]">${holdTime(p.opened_at, p.expires_at)}</td>
        <td class="px-4 py-3 whitespace-nowrap">
            <div class="flex items-center gap-1">
                <input type="number" id="add-amt-${p.id}" placeholder="USDT" min="1" step="1"
                    class="w-16 bg-[var(--color-surface)] border border-[var(--color-border)] rounded px-1.5 py-1 text-xs text-right font-mono focus:outline-none focus:border-[var(--color-accent)]" />
                <button data-action="add" data-id="${p.id}" class="px-2 py-1 rounded text-[11px] bg-[var(--color-accent-soft)] text-[var(--color-accent)] hover:bg-[var(--color-accent)] hover:text-[var(--color-surface)] transition-colors">Add</button>
                <button data-action="reverse" data-id="${p.id}" class="px-2 py-1 rounded text-[11px] bg-[var(--color-purple-soft)] text-[var(--color-purple)] hover:bg-[var(--color-purple)] hover:text-[var(--color-surface)] transition-colors">Reverse</button>
                <button data-action="close" data-id="${p.id}" class="px-2 py-1 rounded text-[11px] bg-[var(--color-danger-soft)] text-[var(--color-danger)] hover:bg-[var(--color-danger)] hover:text-[var(--color-surface)] transition-colors">Close</button>
            </div>
        </td>
    </tr>`;
}

function sortRows(rows) {
    const k = sortState.key;
    return rows.slice().sort((a, b) => {
        let va = a[k];
        let vb = b[k];
        if (va === null || va === undefined) va = -Infinity;
        if (vb === null || vb === undefined) vb = -Infinity;
        if (typeof va === 'string') return sortState.asc ? va.localeCompare(vb) : vb.localeCompare(va);
        return sortState.asc ? va - vb : vb - va;
    });
}

let lastRows = [];

export function renderPositions(rows) {
    lastRows = rows;
    const body = document.getElementById('positions-body');
    if (!body) return;
    if (!rows.length) {
        body.innerHTML = `<tr><td colspan="11" class="text-center text-xs text-[var(--color-text-subtle)] py-8">No open positions</td></tr>`;
    } else {
        body.innerHTML = sortRows(rows).map(rowHtml).join('');
    }
    updateArrows();
}

function updateArrows() {
    document.querySelectorAll('[data-sort-arrow^="positions-"]').forEach((el) => {
        const key = el.dataset.sortArrow.replace('positions-', '');
        el.textContent = sortState.key === key ? (sortState.asc ? '▲' : '▼') : '';
    });
}

function bindSortHandlers() {
    document.querySelectorAll('[data-sort-table="positions"]').forEach((th) => {
        th.addEventListener('click', () => {
            const key = th.dataset.sortKey;
            if (sortState.key === key) sortState.asc = !sortState.asc;
            else {
                sortState.key = key;
                sortState.asc = true;
            }
            renderPositions(lastRows);
        });
    });
}

async function handleAction(action, id, btn) {
    if (action === 'add') {
        const input = document.getElementById('add-amt-' + id);
        const amount = parseFloat(input?.value);
        if (!amount || amount <= 0) {
            toast.error('Enter a USDT amount to add');
            return;
        }
        if (!confirm(`Add $${amount.toFixed(2)} USDT to this position?`)) return;
        btn.disabled = true;
        try {
            await postJson('/api/add-margin', { position_id: id, amount_usdt: amount });
            if (input) input.value = '';
            toast.success('Margin added');
            window.dashboardPolling?.refreshNow();
        } catch (e) {
            toast.error(e.message);
        } finally {
            btn.disabled = false;
        }
        return;
    }
    if (action === 'reverse') {
        if (!confirm('Reverse this position? It will be closed and a new opposite-side position opened with the same USDT size.')) return;
        btn.disabled = true;
        try {
            const data = await postJson('/api/reverse', { position_id: id });
            toast.success(data.warning || 'Position reversed');
            window.dashboardPolling?.refreshNow();
        } catch (e) {
            toast.error(e.message);
        } finally {
            btn.disabled = false;
        }
        return;
    }
    if (action === 'close') {
        if (!confirm('Close this position at market price?')) return;
        btn.disabled = true;
        try {
            await postJson('/api/close', { position_id: id });
            toast.success('Position closed');
            window.dashboardPolling?.refreshNow();
        } catch (e) {
            toast.error(e.message);
        } finally {
            btn.disabled = false;
        }
    }
}

export function bindPositionsTable() {
    bindSortHandlers();
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('#positions-body [data-action]');
        if (!btn) return;
        handleAction(btn.dataset.action, btn.dataset.id, btn);
    });

    const closeAllBtn = document.getElementById('close-all-btn');
    if (closeAllBtn) {
        closeAllBtn.addEventListener('click', async () => {
            if (!confirm('Close ALL open positions at market price?')) return;
            closeAllBtn.disabled = true;
            const orig = closeAllBtn.textContent;
            closeAllBtn.textContent = 'Closing…';
            try {
                const data = await postJson('/api/close-all');
                toast.success(`Closed ${data.closed} position${data.closed === 1 ? '' : 's'}`);
                if (data.failed?.length) toast.error(`${data.failed.length} failed: ${data.failed[0].symbol}`);
                window.dashboardPolling?.refreshNow();
            } catch (e) {
                toast.error(e.message);
            } finally {
                closeAllBtn.disabled = false;
                closeAllBtn.textContent = orig;
            }
        });
    }
}
