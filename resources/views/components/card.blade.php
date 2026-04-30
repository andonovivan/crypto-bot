@props([
    'title' => null,
    'subtitle' => null,
    'padding' => 'p-5',
])

@php
    // Header always carries its own padding so titles never touch the border,
    // even when the body uses `p-0` to render an edge-to-edge table.
    $hasHeader = $title || $subtitle || isset($actions);
    $headerPadding = 'px-5 pt-5 ' . ($hasHeader ? 'pb-4' : 'pb-0');
    $bodyClass = $hasHeader ? $padding : $padding;
@endphp

<section
    {{ $attributes->merge([
        'class' => 'bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl overflow-hidden',
    ]) }}
>
    @if ($hasHeader)
        <header class="flex items-start justify-between gap-3 {{ $headerPadding }}">
            <div class="min-w-0">
                @if ($title)
                    <h2 class="text-sm font-semibold tracking-tight">{{ $title }}</h2>
                @endif
                @if ($subtitle)
                    <p class="text-xs text-[var(--color-text-subtle)] mt-0.5">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex items-center gap-2 shrink-0">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div class="{{ $bodyClass }}">
        {{ $slot }}
    </div>
</section>
