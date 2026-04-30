@php
    $active = $active ?? 'overview';
    $items = [
        ['id' => 'overview',  'label' => 'Overview',  'route' => 'dashboard.overview',  'icon' => 'home'],
        ['id' => 'positions', 'label' => 'Positions', 'route' => 'dashboard.positions', 'icon' => 'positions'],
        ['id' => 'scanner',   'label' => 'Scanner',   'route' => 'dashboard.scanner',   'icon' => 'scanner'],
        ['id' => 'history',   'label' => 'History',   'route' => 'dashboard.history',   'icon' => 'history'],
        ['id' => 'failed',    'label' => 'Failed',    'route' => 'dashboard.failed',    'icon' => 'failed'],
        ['id' => 'risk',      'label' => 'Risk',      'route' => 'dashboard.risk',      'icon' => 'risk'],
        ['id' => 'settings',  'label' => 'Settings',  'route' => 'dashboard.settings',  'icon' => 'settings'],
    ];

    $icons = [
        'home'      => '<path stroke-linecap="round" stroke-linejoin="round" d="m3 12 9-9 9 9M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10"/>',
        'positions' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 7h18M3 12h18M3 17h12"/>',
        'scanner'   => '<circle cx="11" cy="11" r="7" stroke-linecap="round" stroke-linejoin="round"/><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35"/>',
        'history'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 1 0 3.5-7.1L3 8M3 4v4h4M12 7v5l3 2"/>',
        'failed'    => '<circle cx="12" cy="12" r="9" stroke-linecap="round" stroke-linejoin="round"/><path stroke-linecap="round" stroke-linejoin="round" d="m9 9 6 6m0-6-6 6"/>',
        'risk'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3 2 21h20L12 3Zm0 7v5m0 3v.01"/>',
        'settings'  => '<circle cx="12" cy="12" r="3" stroke-linecap="round" stroke-linejoin="round"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h.1a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v.1a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
    ];
@endphp

<aside
    class="bg-[var(--color-surface-elevated)] border-r border-[var(--color-border)] flex flex-col transition-all duration-200 sticky top-0 h-screen z-30"
    :class="sidebarCollapsed ? 'w-16' : 'w-56'"
>
    <div class="h-16 flex items-center gap-3 px-4 border-b border-[var(--color-border)]">
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-[var(--color-accent)] to-[var(--color-purple)] flex items-center justify-center text-[var(--color-surface)] font-bold text-sm shrink-0">
            CB
        </div>
        <div class="flex-1 min-w-0" x-show="!sidebarCollapsed" x-transition.opacity>
            <div class="text-sm font-semibold leading-tight">Crypto Bot</div>
            <div class="text-[10px] text-[var(--color-text-subtle)] uppercase tracking-wider">Short Scalp</div>
        </div>
    </div>

    <nav class="flex-1 px-2 py-4 space-y-1 overflow-y-auto">
        @foreach ($items as $item)
            @php
                $isActive = $active === $item['id'];
                $svgPath = $icons[$item['icon']] ?? '';
            @endphp
            <a
                href="{{ route($item['route']) }}"
                data-spa-link
                data-spa-active="{{ $isActive ? 'true' : 'false' }}"
                class="group flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors text-[var(--color-text-muted)] data-[spa-active=false]:hover:bg-[var(--color-surface-hover)] data-[spa-active=false]:hover:text-[var(--color-text)] data-[spa-active=true]:bg-[var(--color-accent-soft)] data-[spa-active=true]:text-[var(--color-accent)]"
                :class="sidebarCollapsed ? 'justify-center' : ''"
                title="{{ $item['label'] }}"
            >
                <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                    {!! $svgPath !!}
                </svg>
                <span x-show="!sidebarCollapsed" x-transition.opacity class="truncate">{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <button
        class="h-12 border-t border-[var(--color-border)] text-[var(--color-text-muted)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-hover)] flex items-center justify-center gap-2 text-xs"
        @click="sidebarCollapsed = !sidebarCollapsed"
        title="Toggle sidebar"
    >
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" :style="sidebarCollapsed ? '' : 'transform: rotate(180deg)'">
            <path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"/>
        </svg>
        <span x-show="!sidebarCollapsed" x-transition.opacity>Collapse</span>
    </button>
</aside>
