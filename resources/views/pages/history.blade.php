@section('title', 'Trade History')

<x-card title="Closed Trades" padding="p-0">
    <x-table-shell
        table-key="history"
        tbody-id="history-body"
        placeholder="Loading trade history…"
        :colspan="10"
        :columns="[
            ['label' => 'Symbol', 'sort' => 'symbol'],
            ['label' => 'Entry', 'sort' => 'entry_price'],
            ['label' => 'Exit', 'sort' => 'exit_price'],
            ['label' => 'Realized', 'sort' => 'net_pnl'],
            ['label' => 'P&L %', 'sort' => 'pnl_pct'],
            ['label' => 'Size', 'sort' => 'position_size_usdt'],
            ['label' => 'Fees', 'sort' => 'fees'],
            ['label' => 'Reason'],
            ['label' => 'Opened', 'sort' => 'opened_at'],
            ['label' => 'Closed', 'sort' => 'created_at'],
        ]"
        class="rounded-none border-0"
    />
</x-card>
<x-pagination name="history" />
