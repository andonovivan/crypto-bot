@section('title', 'Positions')

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <x-kpi-tile label="Wallet Balance" value-id="kpi-wallet" />
    <x-kpi-tile label="Available" value-id="kpi-available" />
    <x-kpi-tile label="Margin in Use" value-id="kpi-margin-in-use" />
    <x-kpi-tile label="Open Exposure" value-id="kpi-exposure" sub-id="kpi-exposure-sub" />
</div>

<x-card title="Open Positions" padding="p-0">
    <x-slot:actions>
        <span class="text-xs text-[var(--color-text-muted)]" id="kpi-open-positions">0</span>
        <span class="text-xs text-[var(--color-text-subtle)]">open</span>
        <button id="close-all-btn" class="ml-2 px-3 py-1 rounded-lg text-xs bg-[var(--color-danger-soft)] text-[var(--color-danger)] hover:bg-[var(--color-danger)] hover:text-[var(--color-surface)] transition-colors">Close All</button>
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
