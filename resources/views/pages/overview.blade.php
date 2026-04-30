{{-- Overview: top KPI strip → equity chart → performance band → charts row → open positions --}}

@section('title', 'Overview')

{{-- Top KPI strip --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <x-kpi-tile label="Equity" value-id="kpi-equity" tone="accent" sub-id="kpi-equity-sub" />
    <x-kpi-tile label="Current Drawdown" value-id="kpi-current-dd" sub-id="kpi-current-dd-sub" />
    <x-kpi-tile :label="'Today P&L'" value-id="kpi-today-pnl" sub="realized + funding" />
    <x-kpi-tile label="Open Exposure" value-id="kpi-exposure" sub-id="kpi-exposure-sub" />
</div>

{{-- Equity curve --}}
<div class="mb-6">
    <x-chart-card title="Equity Curve" subtitle="Wallet & available balance · drawdown overlay" chart-id="equity-chart" height="h-80">
        <x-slot:actions>
            <x-range-pills name="equity" :options="['1h','6h','24h','7d','30d','all']" active="24h" />
        </x-slot:actions>
    </x-chart-card>
</div>

{{-- Performance band --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <x-kpi-tile label="Profit Factor (30d)" value-id="kpi-profit-factor" sub="wins / |losses|" />
    <x-kpi-tile label="Rolling Win Rate" value-id="kpi-rolling-wr" sub-id="kpi-rolling-wr-sub">
        <x-slot:extra>
            <div id="kpi-rolling-wr-spark" class="h-8"></div>
        </x-slot:extra>
    </x-kpi-tile>
    <x-kpi-tile label="Avg Trade Duration" value-id="kpi-avg-duration" sub="closed trades" />
    <x-kpi-tile label="Max Drawdown" value-id="kpi-max-dd" sub-id="kpi-max-dd-sub" />
</div>

{{-- Charts row --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
    <x-chart-card title="P&L by Symbol" subtitle="Top 10 contributors" chart-id="chart-pnl-by-symbol" height="h-72" />
    <x-chart-card title="Close Reasons" subtitle="Last 30 days" chart-id="chart-close-reason" height="h-72" />
    <x-chart-card title="Trades per Day" subtitle="Last 30 days" chart-id="chart-trades-per-day" height="h-72" />
</div>

{{-- Open positions --}}
<x-card title="Open Positions" :subtitle="null" padding="p-0">
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
