@section('title', 'Failed Entries')

<x-card title="Failed Entries" subtitle="Positions rejected by the exchange or whose bracket placement failed" padding="p-0">
    <x-table-shell
        table-key="failed"
        tbody-id="failed-body"
        placeholder="Loading failed entries…"
        :colspan="5"
        :columns="[
            ['label' => 'Symbol', 'sort' => 'symbol'],
            ['label' => 'Size', 'sort' => 'position_size_usdt'],
            ['label' => 'Leverage', 'sort' => 'leverage'],
            ['label' => 'Error'],
            ['label' => 'Time', 'sort' => 'opened_at'],
        ]"
        class="rounded-none border-0"
    />
</x-card>
<x-pagination name="failed" />
