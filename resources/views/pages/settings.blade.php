@section('title', 'Settings')

<div class="flex items-start gap-4 mb-4">
    <div class="flex-1 min-w-0">
        <input
            id="settings-search"
            type="text"
            placeholder="Search settings…"
            class="w-full bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[var(--color-accent)]"
        />
    </div>
    <div class="text-xs text-[var(--color-text-muted)] hidden md:block py-2">
        Exchange: <span id="settings-exchange-driver" class="text-[var(--color-text)] font-mono">—</span>
        · Testnet: <span id="settings-exchange-testnet" class="text-[var(--color-text)] font-mono">—</span>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-[200px_1fr] gap-6">
    {{-- Sticky table of contents --}}
    <aside class="hidden lg:block">
        <div id="settings-toc" class="sticky top-20 space-y-0.5 text-sm"></div>
    </aside>

    {{-- Settings groups + danger zone --}}
    <div class="space-y-4 min-w-0">
        <div id="settings-form" class="space-y-4">
            <div class="bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-5">
                <div class="skeleton h-5 w-40 mb-3"></div>
                <div class="skeleton h-4 w-full mb-2"></div>
                <div class="skeleton h-4 w-3/4"></div>
            </div>
        </div>

        {{-- Danger zone --}}
        <section class="bg-[var(--color-surface-elevated)] border border-[var(--color-danger)]/40 rounded-xl p-5">
            <header class="mb-3">
                <h3 class="text-base font-semibold tracking-tight text-[var(--color-danger)]">Danger Zone</h3>
                <p class="text-xs text-[var(--color-text-subtle)] mt-1">Irreversible actions. Use with care.</p>
            </header>
            <div class="flex items-center justify-between gap-4 py-3">
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium">Reset all data</div>
                    <p class="text-[11px] text-[var(--color-text-subtle)] mt-1 leading-snug">
                        Truncate all trades and positions. Clears the circuit-breaker peak. Cannot be undone.
                    </p>
                </div>
                <button id="settings-reset" class="px-3 py-1.5 rounded-lg text-xs bg-[var(--color-danger-soft)] text-[var(--color-danger)] hover:bg-[var(--color-danger)] hover:text-[var(--color-surface)] transition-colors shrink-0">Reset</button>
            </div>
        </section>
    </div>
</div>

{{-- Sticky save bar appears when settings are dirty --}}
<div
    id="settings-savebar"
    class="fixed bottom-4 left-1/2 -translate-x-1/2 z-30 bg-[var(--color-surface-elevated)] border border-[var(--color-border-strong)] rounded-xl px-4 py-3 shadow-2xl flex items-center gap-3 transition-all duration-200 translate-y-full opacity-0 pointer-events-none"
>
    <span class="text-sm">
        <span id="settings-savebar-count" class="font-bold">0</span>
        <span class="text-[var(--color-text-muted)]">change(s) pending</span>
    </span>
    <button id="settings-discard" class="px-3 py-1.5 rounded-lg text-xs border border-[var(--color-border-strong)] text-[var(--color-text-muted)] hover:text-[var(--color-text)]">Discard</button>
    <button id="settings-save" class="px-3 py-1.5 rounded-lg text-xs bg-[var(--color-success)] text-[var(--color-surface)] font-semibold hover:bg-[var(--color-success-soft)] hover:text-[var(--color-success)] transition-colors">Save</button>
</div>
