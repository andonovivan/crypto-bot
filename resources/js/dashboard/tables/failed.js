import { fmtNum, formatTimestamp, escapeHtml } from '../formatters.js';
import { getJson } from '../api.js';
import { toast } from '../toast.js';

const state = {
    page: 1,
    perPage: 50,
    sortBy: 'opened_at',
    sortDir: 'desc',
    total: 0,
    totalPages: 1,
    rows: [],
    abort: null,
};

function rowHtml(f) {
    return `<tr class="border-t border-[var(--color-border)] hover:bg-[var(--color-surface-hover)]">
        <td class="px-4 py-3">
            <div class="font-semibold">${f.symbol}</div>
            <div class="mt-0.5">
                <span class="text-[var(--color-danger)] font-bold text-[10px]">${f.side ?? 'SHORT'}</span>
                ${f.is_dry_run ? '<span class="ml-1 px-1 py-0.5 rounded text-[8px] font-bold bg-[var(--color-warning-soft)] text-[var(--color-warning)]">DRY</span>' : ''}
            </div>
        </td>
        <td class="px-4 py-3 font-mono text-xs">${f.position_size_usdt ? '$' + fmtNum(f.position_size_usdt, 2) : '—'}</td>
        <td class="px-4 py-3 font-mono text-xs">${f.leverage || '—'}×</td>
        <td class="px-4 py-3 text-xs text-[var(--color-danger)] break-words max-w-md">${escapeHtml(f.error_message || 'Unknown error')}</td>
        <td class="px-4 py-3 text-xs text-[var(--color-text-muted)]">${formatTimestamp(f.opened_at)}</td>
    </tr>`;
}

function render() {
    const body = document.getElementById('failed-body');
    if (!body) return;
    if (!state.rows.length) {
        body.innerHTML = `<tr><td colspan="5" class="text-center text-xs text-[var(--color-text-subtle)] py-8">No failed entries.</td></tr>`;
    } else {
        body.innerHTML = state.rows.map(rowHtml).join('');
    }
    renderPagination();
    updateArrows();
}

function renderPagination() {
    const el = document.getElementById('failed-pagination');
    if (!el) return;
    const { page, perPage, total, totalPages } = state;
    const from = total === 0 ? 0 : (page - 1) * perPage + 1;
    const to = Math.min(page * perPage, total);
    const opts = [25, 50, 100, 500].map(
        (n) => `<option value="${n}" ${n === perPage ? 'selected' : ''}>${n}</option>`,
    ).join('');
    el.innerHTML = `
        <span>Showing ${fmtNum(from, 0)}–${fmtNum(to, 0)} of ${fmtNum(total, 0)}</span>
        <span class="ml-auto">Rows:</span>
        <select id="failed-per-page" class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded px-1.5 py-0.5 text-xs">${opts}</select>
        <button data-page="1" ${page <= 1 ? 'disabled' : ''} class="px-2 py-1 rounded border border-[var(--color-border)] hover:bg-[var(--color-surface-hover)] disabled:opacity-40">«</button>
        <button data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''} class="px-2 py-1 rounded border border-[var(--color-border)] hover:bg-[var(--color-surface-hover)] disabled:opacity-40">‹</button>
        <span>Page ${page} / ${totalPages}</span>
        <button data-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''} class="px-2 py-1 rounded border border-[var(--color-border)] hover:bg-[var(--color-surface-hover)] disabled:opacity-40">›</button>
        <button data-page="${totalPages}" ${page >= totalPages ? 'disabled' : ''} class="px-2 py-1 rounded border border-[var(--color-border)] hover:bg-[var(--color-surface-hover)] disabled:opacity-40">»</button>
    `;
    el.querySelectorAll('button[data-page]').forEach((b) => {
        b.addEventListener('click', () => goTo(parseInt(b.dataset.page, 10)));
    });
    document.getElementById('failed-per-page')?.addEventListener('change', (e) => {
        state.perPage = parseInt(e.target.value, 10);
        state.page = 1;
        loadFailed();
    });
}

function updateArrows() {
    document.querySelectorAll('[data-sort-arrow^="failed-"]').forEach((el) => {
        const key = el.dataset.sortArrow.replace('failed-', '');
        el.textContent = state.sortBy === key ? (state.sortDir === 'asc' ? '▲' : '▼') : '';
    });
}

async function loadFailed() {
    if (state.abort) state.abort.abort();
    state.abort = new AbortController();
    try {
        const qs = new URLSearchParams({
            page: String(state.page),
            per_page: String(state.perPage),
            sort_by: state.sortBy,
            sort_dir: state.sortDir,
        });
        const data = await getJson('/api/failed-entries?' + qs.toString());
        state.rows = data.data || [];
        state.page = data.page || 1;
        state.perPage = data.per_page || state.perPage;
        state.total = data.total || 0;
        state.totalPages = data.total_pages || 1;
        render();
    } catch (e) {
        if (e.name === 'AbortError') return;
        toast.error('Failed entries: ' + e.message);
    }
}

function goTo(p) {
    const np = Math.max(1, Math.min(p, state.totalPages));
    if (np === state.page) return;
    state.page = np;
    loadFailed();
}

export function bindFailedTable() {
    document.querySelectorAll('[data-sort-table="failed"]').forEach((th) => {
        th.addEventListener('click', () => {
            const key = th.dataset.sortKey;
            if (state.sortBy === key) state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
            else {
                state.sortBy = key;
                state.sortDir = 'asc';
            }
            state.page = 1;
            loadFailed();
        });
    });
    loadFailed();
}
