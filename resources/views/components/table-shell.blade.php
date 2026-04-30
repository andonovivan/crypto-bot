@props([
    'columns' => [],
    'tbodyId' => '',
    'tableKey' => '',
    'colspan' => null,
    'placeholder' => 'Loading…',
])

@php
    $colspan = $colspan ?? count($columns);
@endphp

<div {{ $attributes->merge(['class' => 'overflow-x-auto rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-elevated)]']) }}>
    <table class="w-full text-sm">
        <thead class="text-[10px] uppercase tracking-wider text-[var(--color-text-subtle)] border-b border-[var(--color-border)]">
            <tr>
                @foreach ($columns as $col)
                    @php
                        $sortKey = is_array($col) ? ($col['sort'] ?? null) : null;
                        $label = is_array($col) ? $col['label'] : $col;
                        $align = is_array($col) ? ($col['align'] ?? 'left') : 'left';
                        $alignClass = $align === 'right' ? 'text-right' : ($align === 'center' ? 'text-center' : 'text-left');
                    @endphp
                    <th class="font-semibold px-4 py-2.5 whitespace-nowrap {{ $alignClass }} @if ($sortKey) sortable @endif"
                        @if ($sortKey)
                            data-sort-table="{{ $tableKey }}"
                            data-sort-key="{{ $sortKey }}"
                        @endif
                    >
                        <span class="inline-flex items-center gap-1">
                            {{ $label }}
                            @if ($sortKey)
                                <span class="text-[8px] opacity-60" data-sort-arrow="{{ $tableKey }}-{{ $sortKey }}"></span>
                            @endif
                        </span>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody id="{{ $tbodyId }}" class="font-tabular">
            <tr>
                <td colspan="{{ $colspan }}" class="text-center text-xs text-[var(--color-text-subtle)] py-8">{{ $placeholder }}</td>
            </tr>
        </tbody>
    </table>
</div>
