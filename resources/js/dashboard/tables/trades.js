import { fmtNum, fmtPnl, formatPrice, formatTimestamp, pnlClass } from '../formatters.js';
import { getJson } from '../api.js';
import { toast } from '../toast.js';

const state = {
    page: 1,
    perPage: 50,
    sortBy: 'created_at',
    sortDir: 'desc',
    total: 0,
    totalPages: 1,
    rows: [],
    abort: null,
};

function reasonBadge(reason) {
    const palette = {
        take_profit: 'bg-[var(--color-success-soft)] text-[var(--color-success)]',
        partial_take_profit: 'bg-[var(--color-accent-soft)] text-[var(--color-accent)]',
        stop_loss: 'bg-[var(--color-danger-soft)] text-[var(--color-danger)]',
        expired: 'bg-[var(--color-warning-soft)] text-[var(--color-warning)]',
        manual: 'bg-[var(--color-surface-hover)] text-[var(--color-text-muted)]',
        reversed: 'bg-[var(--color-purple-soft)] text-[var(--color-purple)]',
    };
    const cls = palette[reason] || palette.manual;
    return `<span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase ${cls}">${reason.replace(/_/g, ' ')}</span>`;
}

function sideBadge(side, leverage, isDryRun) {
    const sideTone = side === 'LONG' ? 'text-[var(--color-success)]' : 'text-[var(--color-danger)]';
    return `<span class="${sideTone} font-bold text-[10px]">${side}</span>
        <span class="text-[var(--color-text-subtle)] text-[10px] ml-1">${leverage || '—'}×</span>
        ${isDryRun ? '<span class="ml-1 px-1 py-0.5 rounded text-[8px] font-bold bg-[var(--color-warning-soft)] text-[var(--color-warning)]">DRY</span>' : ''}`;
}

function rowHtml(t) {
    return `<tr class="border-t border-[var(--color-border)] hover:bg-[var(--color-surface-hover)]">
        <td class="px-4 py-3">
            <div class="font-semibold">${t.symbol}</div>
            <div class="mt-0.5">${sideBadge(t.side, t.leverage, t.is_dry_run)}</div>
        </td>
        <td class="px-4 py-3 font-mono text-xs">${formatPrice(t.entry_price)}</td>
        <td class="px-4 py-3 font-mono text-xs">${formatPrice(t.exit_price)}</td>
        <td class="px-4 py-3 font-mono font-semibold ${pnlClass(t.net_pnl)}">${fmtPnl(t.net_pnl)}</td>
        <td class="px-4 py-3 font-mono font-semibold ${pnlClass(t.pnl_pct)}">${t.pnl_pct >= 0 ? '+' : ''}${(t.pnl_pct ?? 0).toFixed(2)}%</td>
        <td class="px-4 py-3 font-mono text-xs">${t.position_size_usdt ? '$' + fmtNum(t.position_size_usdt, 2) : '—'}</td>
        <td class="px-4 py-3 font-mono text-xs">
            <div class="text-[var(--color-danger)]/80">−$${fmtNum(t.fees ?? 0, 4)}</div>
            ${t.funding_fee ? `<div class="text-[10px] ${pnlClass(t.funding_fee)}">${fmtPnl(t.funding_fee)} fund</div>` : ''}
        </td>
        <td class="px-4 py-3">${reasonBadge(t.close_reason)}</td>
        <td class="px-4 py-3 text-xs text-[var(--color-text-muted)]">${formatTimestamp(t.opened_at)}</td>
        <td class="px-4 py-3 text-xs text-[var(--color-text-muted)]">${formatTimestamp(t.created_at)}</td>
    </tr>`;
}

function render() {
    const body = document.getElementById('history-body');
    if (!body) return;
    if (!state.rows.length) {
        body.innerHTML = `<tr><td colspan="10" class="text-center text-xs text-[var(--color-text-subtle)] py-8">No closed trades yet.</td></tr>`;
    } else {
        body.innerHTML = state.rows.map(rowHtml).join('');
    }
    renderPagination();
    updateArrows();
}

function renderPagination() {
    const el = document.getElementById('history-pagination');
    if (!el) return;
    const { page, perPage, total, totalPages } = state;
    const from = total === 0 ? 0 : (page - 1) * perPage + 1;
    const to = Math.min(page * perPage, total);
    const pageOpts = [25, 50, 100, 500].map(
        (n) => `<option value="${n}" ${n === perPage ? 'selected' : ''}>${n}</option>`,
    ).join('');
    el.innerHTML = `
        <span>Showing ${fmtNum(from, 0)}–${fmtNum(to, 0)} of ${fmtNum(total, 0)}</span>
        <span class="ml-auto">Rows:</span>
        <select id="history-per-page" class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded px-1.5 py-0.5 text-xs">${pageOpts}</select>
        <button data-page="1" ${page <= 1 ? 'disabled' : ''} class="px-2 py-1 rounded border border-[var(--color-border)] hover:bg-[var(--color-surface-hover)] disabled:opacity-40 disabled:cursor-not-allowed">«</button>
        <button data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''} class="px-2 py-1 rounded border border-[var(--color-border)] hover:bg-[var(--color-surface-hover)] disabled:opacity-40 disabled:cursor-not-allowed">‹</button>
        <span>Page ${page} / ${totalPages}</span>
        <button data-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''} class="px-2 py-1 rounded border border-[var(--color-border)] hover:bg-[var(--color-surface-hover)] disabled:opacity-40 disabled:cursor-not-allowed">›</button>
        <button data-page="${totalPages}" ${page >= totalPages ? 'disabled' : ''} class="px-2 py-1 rounded border border-[var(--color-border)] hover:bg-[var(--color-surface-hover)] disabled:opacity-40 disabled:cursor-not-allowed">»</button>
    `;
    el.querySelectorAll('button[data-page]').forEach((b) => {
        b.addEventListener('click', () => goTo(parseInt(b.dataset.page, 10)));
    });
    document.getElementById('history-per-page')?.addEventListener('change', (e) => {
        state.perPage = parseInt(e.target.value, 10);
        state.page = 1;
        loadTrades();
    });
}

function updateArrows() {
    document.querySelectorAll('[data-sort-arrow^="history-"]').forEach((el) => {
        const key = el.dataset.sortArrow.replace('history-', '');
        el.textContent = state.sortBy === key ? (state.sortDir === 'asc' ? '▲' : '▼') : '';
    });
}

async function loadTrades() {
    if (state.abort) state.abort.abort();
    state.abort = new AbortController();
    try {
        const qs = new URLSearchParams({
            page: String(state.page),
            per_page: String(state.perPage),
            sort_by: state.sortBy,
            sort_dir: state.sortDir,
        });
        const data = await getJson('/api/trades?' + qs.toString());
        state.rows = (data.data || []).map((t) => ({ ...t, net_pnl: t.pnl + (t.funding_fee || 0) }));
        state.page = data.page || 1;
        state.perPage = data.per_page || state.perPage;
        state.total = data.total || 0;
        state.totalPages = data.total_pages || 1;
        render();
    } catch (e) {
        if (e.name === 'AbortError') return;
        toast.error('Trade history: ' + e.message);
    }
}

function goTo(p) {
    const np = Math.max(1, Math.min(p, state.totalPages));
    if (np === state.page) return;
    state.page = np;
    loadTrades();
}

export function bindTradesTable() {
    document.querySelectorAll('[data-sort-table="history"]').forEach((th) => {
        th.addEventListener('click', () => {
            const key = th.dataset.sortKey;
            if (state.sortBy === key) {
                state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                state.sortBy = key;
                state.sortDir = 'asc';
            }
            state.page = 1;
            loadTrades();
        });
    });
    loadTrades();
}
