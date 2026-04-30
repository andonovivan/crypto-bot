import { getJson, postJson } from './api.js';
import { toast } from './toast.js';

let settingsCache = null;
let groupsCache = [];
let initial = {};
let dirty = new Map();
let renderListeners = new Set();

function emit() {
    renderListeners.forEach((fn) => fn(dirty.size));
}

function coerceNumber(v) {
    if (v === '' || v === null || v === undefined) return null;
    const n = Number(v);
    return Number.isNaN(n) ? null : n;
}

function fieldHtml(key, meta) {
    const id = `set-${key}`;
    const help = meta.description ? `<p class="mt-1 text-[11px] text-[var(--color-text-subtle)] leading-snug">${meta.description}</p>` : '';
    if (meta.type === 'bool') {
        return `<div class="setting-field py-3 flex items-center justify-between gap-4 border-b border-[var(--color-border)] last:border-0" data-key="${key}">
            <div class="min-w-0 flex-1">
                <label for="${id}" class="block text-sm font-medium text-[var(--color-text)]">${meta.label}</label>
                ${help}
            </div>
            <label class="relative inline-flex items-center cursor-pointer shrink-0">
                <input type="checkbox" id="${id}" data-key="${key}" data-type="bool" class="sr-only peer" ${meta.value ? 'checked' : ''}>
                <span class="w-11 h-6 bg-[var(--color-surface-hover)] border border-[var(--color-border-strong)] rounded-full peer-checked:bg-[var(--color-accent)] peer-checked:border-[var(--color-accent)] transition-colors"></span>
                <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-[var(--color-text)] transition-transform peer-checked:translate-x-5"></span>
            </label>
        </div>`;
    }
    if (meta.type === 'string') {
        return `<div class="setting-field py-3 flex items-center justify-between gap-4 border-b border-[var(--color-border)] last:border-0" data-key="${key}">
            <div class="min-w-0 flex-1">
                <label for="${id}" class="block text-sm font-medium text-[var(--color-text)]">${meta.label}</label>
                ${help}
            </div>
            <input type="text" id="${id}" data-key="${key}" data-type="string"
                value="${meta.value ?? ''}"
                class="w-40 bg-[var(--color-surface)] border border-[var(--color-border)] rounded-lg px-2.5 py-1.5 text-sm font-mono focus:outline-none focus:border-[var(--color-accent)]" />
        </div>`;
    }
    const c = meta.constraints || {};
    const step = c.step ?? (meta.type === 'float' ? '0.1' : '1');
    const min = c.min !== undefined ? `min="${c.min}"` : '';
    const max = c.max !== undefined ? `max="${c.max}"` : '';
    return `<div class="setting-field py-3 flex items-center justify-between gap-4 border-b border-[var(--color-border)] last:border-0" data-key="${key}">
        <div class="min-w-0 flex-1">
            <label for="${id}" class="block text-sm font-medium text-[var(--color-text)]">${meta.label}</label>
            ${help}
        </div>
        <input type="number" id="${id}" data-key="${key}" data-type="${meta.type}"
            value="${meta.value}" step="${step}" ${min} ${max}
            class="w-32 bg-[var(--color-surface)] border border-[var(--color-border)] rounded-lg px-2.5 py-1.5 text-sm font-mono text-right focus:outline-none focus:border-[var(--color-accent)]" />
    </div>`;
}

function groupHtml(group) {
    if (!group.keys.length) return '';
    const fields = group.keys.map((k) => settingsCache[k] ? fieldHtml(k, settingsCache[k]) : '').join('');
    return `<section id="settings-group-${group.id}" class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5 scroll-mt-24">
        <header class="mb-4">
            <h3 class="text-base font-semibold tracking-tight">${group.title}</h3>
            ${group.description ? `<p class="text-xs text-[var(--color-text-subtle)] mt-1">${group.description}</p>` : ''}
        </header>
        <div class="divide-y divide-[var(--color-border)]">${fields}</div>
    </section>`;
}

function tocHtml() {
    return groupsCache
        .filter((g) => g.keys.length)
        .map((g) => `<a href="#settings-group-${g.id}" class="block px-3 py-1.5 rounded-lg text-sm text-[var(--color-text-muted)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-hover)]">${g.title}</a>`)
        .join('');
}

function attachInputHandlers() {
    document.querySelectorAll('[data-key][data-type]').forEach((input) => {
        input.addEventListener('input', () => {
            const key = input.dataset.key;
            const type = input.dataset.type;
            let val;
            if (type === 'bool') val = input.checked;
            else if (type === 'string') val = input.value;
            else val = coerceNumber(input.value);
            const orig = initial[key];
            const same = (type === 'bool' ? Boolean(orig) === Boolean(val) : String(orig) === String(val));
            if (same) dirty.delete(key);
            else dirty.set(key, val);
            emit();
        });
    });
}

function applyFilter(query) {
    const q = query.trim().toLowerCase();
    const fields = document.querySelectorAll('.setting-field');
    fields.forEach((field) => {
        if (!q) {
            field.style.display = '';
            return;
        }
        const key = field.dataset.key;
        const meta = settingsCache[key];
        const hay = (key + ' ' + (meta?.label ?? '') + ' ' + (meta?.description ?? '')).toLowerCase();
        field.style.display = hay.includes(q) ? '' : 'none';
    });
    document.querySelectorAll('section[id^="settings-group-"]').forEach((sec) => {
        const visible = Array.from(sec.querySelectorAll('.setting-field')).some((f) => f.style.display !== 'none');
        sec.style.display = visible ? '' : 'none';
    });
}

async function load() {
    const data = await getJson('/api/settings');
    settingsCache = data.settings;
    groupsCache = data.groups;
    initial = {};
    dirty = new Map();
    Object.entries(settingsCache).forEach(([k, m]) => {
        initial[k] = m.value;
    });

    const formEl = document.getElementById('settings-form');
    if (formEl) formEl.innerHTML = groupsCache.map(groupHtml).join('');

    const tocEl = document.getElementById('settings-toc');
    if (tocEl) tocEl.innerHTML = tocHtml();

    attachInputHandlers();
    emit();

    const exDriver = document.getElementById('settings-exchange-driver');
    if (exDriver) exDriver.textContent = (data.exchange?.driver ?? '?').toUpperCase();
    const exTestnet = document.getElementById('settings-exchange-testnet');
    if (exTestnet) exTestnet.textContent = data.exchange?.testnet ? 'Yes' : 'No';
}

async function save() {
    if (!dirty.size) return;
    const payload = Object.fromEntries(dirty.entries());
    try {
        await postJson('/api/settings', { settings: payload });
        toast.success(`Saved ${dirty.size} setting${dirty.size === 1 ? '' : 's'} — applies next scan cycle.`);
        await load();
        window.dashboardPolling?.refreshNow();
    } catch (e) {
        toast.error(e.message);
    }
}

function discard() {
    dirty.clear();
    load();
}

async function resetAll() {
    if (!confirm('Delete ALL trades and positions. This cannot be undone. Continue?')) return;
    try {
        await postJson('/api/reset');
        toast.success('All trades and positions reset.');
        window.dashboardPolling?.refreshNow();
    } catch (e) {
        toast.error(e.message);
    }
}

export function bindSettingsPage() {
    if (!document.getElementById('settings-form')) return;
    load();

    document.getElementById('settings-search')?.addEventListener('input', (e) => applyFilter(e.target.value));
    document.getElementById('settings-save')?.addEventListener('click', save);
    document.getElementById('settings-discard')?.addEventListener('click', discard);
    document.getElementById('settings-reset')?.addEventListener('click', resetAll);

    renderListeners.add((count) => {
        const bar = document.getElementById('settings-savebar');
        const counter = document.getElementById('settings-savebar-count');
        if (!bar) return;
        if (count > 0) {
            bar.classList.remove('translate-y-full', 'opacity-0', 'pointer-events-none');
            bar.classList.add('translate-y-0', 'opacity-100');
            if (counter) counter.textContent = count;
        } else {
            bar.classList.add('translate-y-full', 'opacity-0', 'pointer-events-none');
            bar.classList.remove('translate-y-0', 'opacity-100');
        }
    });
}
