@section('title', 'Scanner')

<x-card title="Market Scanner" subtitle="USDT perpetuals · 24h pumps & dumps with 15m downtrend confirmation" padding="p-0">
    <x-slot:actions>
        <span id="scanner-updated" class="text-xs text-[var(--color-text-subtle)]"></span>
        <button id="scan-btn" class="px-3 py-1.5 rounded-lg text-xs bg-[var(--color-accent-soft)] text-[var(--color-accent)] hover:bg-[var(--color-accent)] hover:text-[var(--color-surface)] transition-colors">Scan</button>
        <button id="scan-auto-btn" class="px-3 py-1.5 rounded-lg text-xs bg-[var(--color-success-soft)] text-[var(--color-success)] hover:bg-[var(--color-success)] hover:text-[var(--color-surface)] transition-colors">Scan + Auto Trade</button>
    </x-slot:actions>

    <x-table-shell
        table-key="scanner"
        tbody-id="scanner-body"
        placeholder="Click Scan to find pump/dump candidates."
        :colspan="10"
        :columns="[
            ['label' => 'Symbol', 'sort' => 'symbol'],
            ['label' => '24h %', 'sort' => 'price_change_pct'],
            ['label' => 'Volume', 'sort' => 'volume'],
            ['label' => 'Price', 'sort' => 'price'],
            ['label' => '15m Trend'],
            ['label' => 'Last Candle', 'sort' => 'candle_body_pct'],
            ['label' => 'Funding', 'sort' => 'funding_rate'],
            ['label' => 'Pos', 'sort' => 'open_positions', 'align' => 'center'],
            ['label' => 'Status'],
            ['label' => ''],
        ]"
        class="rounded-none border-0"
    />
</x-card>

<div class="mt-4 bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-4 flex items-center gap-3 flex-wrap">
    <div class="flex flex-col">
        <span class="text-[10px] uppercase tracking-wider text-[var(--color-text-subtle)]">Manual SHORT</span>
        <span class="text-xs text-[var(--color-text-muted)]">Open a position on any candidate symbol</span>
    </div>
    <select id="manual-symbol" class="bg-[var(--color-surface)] border border-[var(--color-border)] rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-[var(--color-accent)]">
        <option value="">Select symbol…</option>
    </select>
    <button id="open-manual-btn" class="px-3 py-1.5 rounded-lg text-xs bg-[var(--color-danger-soft)] text-[var(--color-danger)] hover:bg-[var(--color-danger)] hover:text-[var(--color-surface)] transition-colors">Open SHORT</button>
</div>
