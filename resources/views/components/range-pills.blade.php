@props([
    'name' => '',
    'options' => [],
    'active' => null,
])

<div class="inline-flex items-center bg-[var(--color-surface)] border border-[var(--color-border)] rounded-lg p-0.5 text-xs" data-range-group="{{ $name }}">
    @foreach ($options as $opt)
        <button
            type="button"
            data-range="{{ $opt }}"
            class="px-2.5 py-1 rounded-md text-[var(--color-text-muted)] hover:text-[var(--color-text)] data-[active=true]:bg-[var(--color-accent-soft)] data-[active=true]:text-[var(--color-accent)] transition-colors"
            @if ($active === $opt) data-active="true" @endif
        >{{ $opt }}</button>
    @endforeach
</div>
