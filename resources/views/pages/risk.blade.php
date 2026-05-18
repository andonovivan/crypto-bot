@section('title', 'Risk')

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <x-kpi-tile label="Current Drawdown" value-id="kpi-current-dd" sub-id="kpi-current-dd-sub" />
    <x-kpi-tile label="Max Drawdown" value-id="kpi-max-dd" sub-id="kpi-max-dd-sub" />
    <x-kpi-tile label="Open Exposure" value-id="kpi-exposure" sub-id="kpi-exposure-sub" />
</div>

<x-card title="Breaker Allocation" class="mb-6">
    <x-slot:actions>
        <button
            id="risk-sync-balance-btn"
            type="button"
            class="text-xs px-3 py-1.5 rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] hover:bg-[var(--color-surface-elevated)] cursor-pointer"
            title="Snap starting_balance to current wallet; re-anchors each strategy's breaker peak on next check.">
            Sync to wallet
        </button>
    </x-slot:actions>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
        <div>
            <div class="text-xs text-[var(--color-text-muted)] mb-1">Configured starting balance</div>
            <div id="risk-starting-balance" class="font-mono text-base">—</div>
        </div>
        <div>
            <div class="text-xs text-[var(--color-text-muted)] mb-1">Current wallet</div>
            <div id="risk-current-wallet" class="font-mono text-base">—</div>
        </div>
        <div>
            <div class="text-xs text-[var(--color-text-muted)] mb-1">Per-strategy allocation</div>
            <div id="risk-allocation" class="font-mono text-base">—</div>
        </div>
    </div>
    <div class="text-xs text-[var(--color-text-muted)] mt-3">
        Each strategy's circuit breaker measures drawdown against
        <code>starting_balance / num_enabled_strategies + own pnl</code>. Sync rebases this allocation to the current wallet so the breaker
        scales with the real account size. Active cooldowns are <em>not</em> cleared.
    </div>
</x-card>

<div id="risk-cb-container" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
    <div class="text-xs text-[var(--color-text-muted)] p-4">Loading circuit breaker state…</div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
    <x-card title="Recent Performance">
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-xs text-[var(--color-text-muted)]">Best trade</span>
                <span id="risk-best-trade" class="text-sm font-mono">—</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-xs text-[var(--color-text-muted)]">Worst trade</span>
                <span id="risk-worst-trade" class="text-sm font-mono">—</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-xs text-[var(--color-text-muted)]">Current streak</span>
                <span id="risk-streak" class="text-sm">—</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-xs text-[var(--color-text-muted)]">Funding (30d)</span>
                <span id="risk-funding-30d" class="text-sm font-mono">—</span>
            </div>
        </div>
    </x-card>
</div>

<x-card title="Open Positions" padding="p-0">
    <x-slot:actions>
        <span class="text-xs text-[var(--color-text-muted)]" id="kpi-open-positions">0</span>
        <span class="text-xs text-[var(--color-text-subtle)]">open</span>
    </x-slot:actions>
    <x-table-shell
        table-key="positions"
        tbody-id="positions-body"
        placeholder="Loading positions…"
        :colspan="11"
        :columns="[
            ['label' => 'Symbol', 'sort' => 'symbol'],
            ['label' => 'Entry', 'sort' => 'entry_price'],
            ['label' => 'Current', 'sort' => 'current_price'],
            ['label' => 'Unrealized', 'sort' => 'unrealized_pnl'],
            ['label' => 'P&L %', 'sort' => 'pnl_pct'],
            ['label' => 'Size', 'sort' => 'position_size_usdt'],
            ['label' => 'Net P&L', 'sort' => 'net_pnl'],
            ['label' => 'SL / TP'],
            ['label' => 'Opened', 'sort' => 'opened_at'],
            ['label' => 'Hold'],
            ['label' => ''],
        ]"
        class="rounded-none border-0"
    />
</x-card>
