@props([
    'label' => '',
    'valueId' => '',
    'tone' => 'neutral',
    'sub' => null,
    'subId' => null,
])

@php
    $toneClass = match ($tone) {
        'success' => 'text-[var(--color-success)]',
        'danger'  => 'text-[var(--color-danger)]',
        'warning' => 'text-[var(--color-warning)]',
        'accent'  => 'text-[var(--color-accent)]',
        default   => 'text-[var(--color-text)]',
    };
@endphp

<div
    {{ $attributes->merge([
        'class' => 'bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl p-4 hover:border-[var(--color-border-strong)] transition-colors',
    ]) }}
>
    <div class="flex items-center justify-between gap-2 mb-1.5">
        <span class="text-[10px] uppercase tracking-wider text-[var(--color-text-subtle)] font-semibold">{{ $label }}</span>
        @isset($icon)
            <span class="text-[var(--color-text-subtle)]">{{ $icon }}</span>
        @endisset
    </div>
    <div class="text-2xl font-semibold font-tabular {{ $toneClass }}" id="{{ $valueId }}">—</div>
    @if ($subId || $sub)
        <div class="text-xs text-[var(--color-text-muted)] mt-1" @if ($subId) id="{{ $subId }}" @endif>{{ $sub ?? '' }}</div>
    @endif
    @isset($extra)
        <div class="mt-2">{{ $extra }}</div>
    @endisset
</div>
