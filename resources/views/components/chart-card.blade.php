@props([
    'title' => '',
    'subtitle' => null,
    'chartId' => '',
    'height' => 'h-72',
])

<x-card :title="$title" :subtitle="$subtitle" padding="p-5">
    @isset($actions)
        <x-slot:actions>{{ $actions }}</x-slot:actions>
    @endisset
    <div id="{{ $chartId }}" class="{{ $height }} w-full" data-chart="{{ $chartId }}"></div>
    <div id="{{ $chartId }}-empty" class="hidden text-center text-xs text-[var(--color-text-subtle)] py-12">No data yet.</div>
</x-card>
