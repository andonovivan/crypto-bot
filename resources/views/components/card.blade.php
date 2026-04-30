@props([
    'title' => null,
    'subtitle' => null,
    'padding' => 'p-5',
])

<section
    {{ $attributes->merge([
        'class' => 'bg-[var(--color-surface-elevated)] border border-[var(--color-border)] rounded-xl ' . $padding,
    ]) }}
>
    @if ($title || $subtitle || isset($actions))
        <header class="flex items-start justify-between gap-3 mb-4">
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

    {{ $slot }}
</section>
