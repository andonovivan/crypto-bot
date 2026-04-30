<header
    class="h-16 sticky top-0 z-20 backdrop-blur-md bg-[var(--color-surface)]/80 border-b border-[var(--color-border)] flex items-center justify-between gap-4 px-5 md:px-7"
    x-data="dashboardTopbar"
>
    <div class="flex items-center gap-3 min-w-0">
        <h1 class="text-base font-semibold tracking-tight truncate" x-text="title"></h1>

        <span
            x-cloak
            x-show="status.dryRun"
            class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-[var(--color-warning-soft)] text-[var(--color-warning)] border border-[var(--color-warning)]/40"
        >Dry Run</span>
        <span
            x-cloak
            x-show="!status.dryRun && lastUpdate"
            class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-[var(--color-danger-soft)] text-[var(--color-danger)] border border-[var(--color-danger)]/40"
        >Live</span>

        <span
            x-cloak
            x-show="status.paused"
            class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-[var(--color-text-subtle)]/15 text-[var(--color-text-muted)] border border-[var(--color-text-subtle)]/30"
        >Paused</span>

        <span
            x-cloak
            x-show="status.circuitBreakerActive"
            class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-[var(--color-danger-soft)] text-[var(--color-danger)] border border-[var(--color-danger)]/40"
            title="Circuit breaker tripped — new entries blocked"
        >Breaker Tripped</span>
    </div>

    <div class="flex items-center gap-3 md:gap-5 text-sm">
        <div class="hidden sm:flex flex-col items-end leading-tight">
            <div class="text-[10px] uppercase tracking-wider text-[var(--color-text-subtle)]">Wallet</div>
            <div class="font-mono font-semibold" x-text="formatMoney(status.wallet)"></div>
        </div>
        <div class="hidden md:flex flex-col items-end leading-tight">
            <div class="text-[10px] uppercase tracking-wider text-[var(--color-text-subtle)]">Today P&amp;L</div>
            <div class="font-mono font-semibold" :class="status.todayPnl >= 0 ? 'text-[var(--color-success)]' : 'text-[var(--color-danger)]'" x-text="formatPnl(status.todayPnl)"></div>
        </div>

        <button
            @click="togglePause()"
            :disabled="busy"
            class="px-3 py-1.5 rounded-lg text-xs border transition-colors flex items-center gap-1.5"
            :class="status.paused
                ? 'bg-[var(--color-success-soft)] border-[var(--color-success)]/40 text-[var(--color-success)] hover:bg-[var(--color-success-soft)]/70'
                : 'bg-[var(--color-surface-elevated)] border-[var(--color-border-strong)] text-[var(--color-text-muted)] hover:text-[var(--color-text)]'"
        >
            <span x-text="status.paused ? '▶' : '⏸'"></span>
            <span x-text="status.paused ? 'Resume' : 'Pause'"></span>
        </button>

        <div class="flex items-center gap-1.5 text-[var(--color-text-subtle)] text-xs" :title="'Last update: ' + lastUpdateLabel">
            <span class="w-2 h-2 rounded-full bg-[var(--color-success)]" :class="{ 'pulse-once': pulse }"></span>
            <span x-text="lastUpdateLabel"></span>
        </div>
    </div>
</header>
