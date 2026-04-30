<!DOCTYPE html>
<html lang="en" class="bg-[var(--color-surface)]">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') · Crypto Bot</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://rsms.me/">
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body
    class="min-h-screen text-[var(--color-text)] font-sans antialiased"
    data-page="{{ $page ?? 'overview' }}"
    x-data="{ sidebarOpen: false, sidebarCollapsed: window.matchMedia('(max-width: 768px)').matches }"
>
    <div class="flex min-h-screen">
        @include('components.sidebar', ['active' => $page ?? 'overview'])

        <main class="flex-1 min-w-0 flex flex-col">
            @include('components.topbar')

            <div id="spa-content" class="flex-1 px-5 md:px-7 lg:px-9 py-6 max-w-[1500px] w-full mx-auto">
                @yield('content')
            </div>

            <footer class="text-xs text-[var(--color-text-subtle)] px-7 py-4 border-t border-[var(--color-border)] mt-6">
                Polling: positions every 10s · stats every 30s · scanner every 15s · charts every 60s
            </footer>
        </main>
    </div>

    {{-- Toast container --}}
    <div
        x-data="toastBus"
        class="fixed bottom-6 right-6 z-50 flex flex-col gap-2 pointer-events-none"
    >
        <template x-for="t in items" :key="t.id">
            <div
                class="pointer-events-auto px-4 py-3 rounded-lg shadow-lg border min-w-[260px] max-w-[420px] flex items-start gap-3 transition"
                :class="{
                    'bg-[var(--color-success-soft)] border-[var(--color-success)]/40 text-[var(--color-success)]': t.kind === 'success',
                    'bg-[var(--color-danger-soft)] border-[var(--color-danger)]/40 text-[var(--color-danger)]': t.kind === 'error',
                    'bg-[var(--color-surface-elevated)] border-[var(--color-border-strong)] text-[var(--color-text)]': t.kind === 'info',
                }"
                x-transition.opacity.duration.200ms
            >
                <div class="flex-1 text-sm leading-snug" x-text="t.message"></div>
                <button @click="dismiss(t.id)" class="text-xs opacity-60 hover:opacity-100">✕</button>
            </div>
        </template>
    </div>
</body>
</html>
