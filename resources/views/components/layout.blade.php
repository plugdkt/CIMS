<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('auth.app_name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
{{--
    Mobile drawer uses a plain checkbox toggle, not Alpine x-data — Alpine's expression
    evaluator needs `unsafe-eval`, which the CSP (SecurityHeaders middleware) explicitly
    forbids in script-src. See CLAUDE.md for the full reasoning.
--}}
<body class="h-full bg-bg text-ink antialiased md:grid md:grid-cols-[16rem_1fr] md:min-h-full">
    <input type="checkbox" id="drawer-toggle" class="peer hidden">

    <label for="drawer-toggle" class="hidden peer-checked:block fixed inset-0 bg-ink/40 z-30 md:hidden" aria-hidden="true"></label>

    <aside class="fixed z-40 inset-y-0 left-0 w-64 bg-surface border-r border-border p-4 flex flex-col gap-6 -translate-x-full transition-transform peer-checked:translate-x-0 md:static md:translate-x-0">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-3 px-1">
            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-accent to-gold flex items-center justify-center text-white font-display font-bold text-sm">
                CM
            </div>
            <div>
                <div class="font-display font-bold text-sm leading-none">{{ __('auth.app_name') }}</div>
                <div class="text-xs text-ink-muted mt-0.5">{{ __('auth.app_tagline') }}</div>
            </div>
        </a>

        <nav class="flex flex-col gap-1">
            <x-nav-link route="{{ route('dashboard') }}" :active="request()->routeIs('dashboard')" class="mb-2">
                <x-slot:icon><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 11.5 12 4l9 7.5M5 10v9a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1v-9"/></svg></x-slot:icon>
                {{ __('nav.dashboard') }}
            </x-nav-link>

            <div class="text-xs font-semibold tracking-wide uppercase text-ink-faint px-2 mb-1">
                {{ __('nav.group_inventory') }}
            </div>
            @can('viewAny', App\Models\Container::class)
                <x-nav-link route="{{ route('stock-in.index') }}" :active="request()->routeIs('stock-in.*')">
                    <x-slot:icon><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v10m0 0 4-4m-4 4-4-4M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/></svg></x-slot:icon>
                    {{ __('nav.stock_in') }}
                </x-nav-link>
            @endcan
            @can('viewAny', App\Models\Requisition::class)
                <x-nav-link route="{{ route('requisitions.index') }}" :active="request()->routeIs('requisitions.*')">
                    <x-slot:icon><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3.75h6a1 1 0 0 1 1 1V5h1.25A1.75 1.75 0 0 1 19 6.75v12.5A1.75 1.75 0 0 1 17.25 21H6.75A1.75 1.75 0 0 1 5 19.25V6.75A1.75 1.75 0 0 1 6.75 5H8v-.25a1 1 0 0 1 1-1Z"/><path stroke-linecap="round" d="M8.5 11h7M8.5 14.5h7M8.5 18h4"/></svg></x-slot:icon>
                    {{ __('nav.requisitions') }}
                </x-nav-link>
            @endcan
            @can('viewAny', App\Models\StockTake::class)
                <x-nav-link route="{{ route('stock-takes.index') }}" :active="request()->routeIs('stock-takes.*')">
                    <x-slot:icon><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4M9 5h6a1 1 0 0 1 1 1v.25h1a1.75 1.75 0 0 1 1.75 1.75v11.25A1.75 1.75 0 0 1 17.05 20H6.95A1.75 1.75 0 0 1 5.2 18.25V7A1.75 1.75 0 0 1 7 5.25h1V6a1 1 0 0 0 1 1Z"/></svg></x-slot:icon>
                    {{ __('nav.stock_takes') }}
                </x-nav-link>
            @endcan
            @can('viewAny', App\Models\Disposal::class)
                <x-nav-link route="{{ route('disposals.index') }}" :active="request()->routeIs('disposals.*')">
                    <x-slot:icon><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 7h14M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m1 0-.7 11.2A2 2 0 0 1 15.3 21H8.7a2 2 0 0 1-2-1.8L6 7m4 4v6m4-6v6"/></svg></x-slot:icon>
                    {{ __('nav.disposals') }}
                </x-nav-link>
            @endcan
            @can('viewAdjustments', App\Models\StockLedger::class)
                <x-nav-link route="{{ route('adjustments.index') }}" :active="request()->routeIs('adjustments.*')">
                    <x-slot:icon><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 4v16m0-16 3 3m-3-3-3 3m15 10v3m0-16v10m0 6 3-3m-3 3-3-3M6 14h.01M18 10h.01" /><path stroke-linecap="round" stroke-linejoin="round" d="M4 14a2 2 0 1 0 4 0 2 2 0 0 0-4 0Zm12-4a2 2 0 1 0 4 0 2 2 0 0 0-4 0Z"/></svg></x-slot:icon>
                    {{ __('nav.adjustments') }}
                </x-nav-link>
            @endcan
            @can('report.view')
                <x-nav-link route="{{ route('reports.index') }}" :active="request()->routeIs('reports.*')">
                    <x-slot:icon><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20V10m6 10V4m6 16v-7"/></svg></x-slot:icon>
                    {{ __('nav.reports') }}
                </x-nav-link>
            @endcan
            @can('manageMembers', App\Models\Lab::class)
                <x-nav-link route="{{ route('labs.members') }}" :active="request()->routeIs('labs.members')">
                    <x-slot:icon><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1M10 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm9 9v-1a4 4 0 0 0-2.4-3.66M15.5 4.1a3.5 3.5 0 0 1 0 6.79"/></svg></x-slot:icon>
                    {{ __('nav.lab_members') }}
                </x-nav-link>
            @endcan

            <div class="text-xs font-semibold tracking-wide uppercase text-ink-faint px-2 mb-1 mt-3">
                {{ __('nav.group_system') }}
            </div>
            @can('viewAny', App\Models\Item::class)
                <x-nav-link route="{{ route('items.index') }}" :active="request()->routeIs('items.*')">
                    <x-slot:icon><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Zm0 0v18m8-13.5L12 12m0 0L4 7.5"/></svg></x-slot:icon>
                    {{ __('nav.items') }}
                </x-nav-link>
            @endcan
            @can('item.view')
                <x-nav-link route="{{ route('chemicals.lookup') }}" :active="request()->routeIs('chemicals.lookup')">
                    <x-slot:icon><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5"/><path stroke-linecap="round" d="m20 20-4.7-4.7"/></svg></x-slot:icon>
                    {{ __('nav.chemical_lookup') }}
                </x-nav-link>
            @endcan
            @can('viewAny', App\Models\Location::class)
                <x-nav-link route="{{ route('locations.index') }}" :active="request()->routeIs('locations.*')">
                    <x-slot:icon><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-6.4 7-11.5a7 7 0 1 0-14 0C5 14.6 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.25"/></svg></x-slot:icon>
                    {{ __('nav.locations') }}
                </x-nav-link>
            @endcan
            @can('viewAny', App\Models\Lab::class)
                <x-nav-link route="{{ route('admin.labs.index') }}" :active="request()->routeIs('admin.labs.*')">
                    <x-slot:icon><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 21V6a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v15M12 10h7a1 1 0 0 1 1 1v10M8 9h.01M8 12h.01M8 15h.01M16 14h.01M16 17h.01"/></svg></x-slot:icon>
                    {{ __('nav.labs') }}
                </x-nav-link>
            @endcan
            @can('viewAny', App\Models\User::class)
                <x-nav-link route="{{ route('admin.users.index') }}" :active="request()->routeIs('admin.users.*')">
                    <x-slot:icon><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 20v-1a5 5 0 0 1 5-5h2a5 5 0 0 1 5 5v1M16 4.5a3 3 0 0 1 0 6M21 20v-1a4.5 4.5 0 0 0-3-4.24"/></svg></x-slot:icon>
                    {{ __('nav.users_roles') }}
                </x-nav-link>
            @endcan
        </nav>
    </aside>

    <div class="flex flex-col min-w-0">
        <header class="sticky top-0 z-20 flex items-center gap-3 px-4 md:px-6 py-3 bg-surface border-b border-border">
            <label for="drawer-toggle" class="md:hidden w-9 h-9 rounded-lg border border-border flex items-center justify-center cursor-pointer">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            </label>

            <div class="ml-auto flex items-center gap-3">
                @php($unreadCount = auth()->user()->notifications()->whereNull('read_at')->count())
                <a href="{{ route('notifications.index') }}" class="relative w-9 h-9 rounded-lg border border-border flex items-center justify-center text-ink-muted hover:bg-surface-alt hover:text-ink" aria-label="{{ __('notifications.index_title') }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9"/></svg>
                    @if ($unreadCount > 0)
                        <span class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-danger text-white text-[10px] font-bold flex items-center justify-center">
                            {{ $unreadCount > 9 ? '9+' : $unreadCount }}
                        </span>
                    @endif
                </a>
                <a href="{{ route('account.my-data') }}" class="flex items-center gap-3" title="{{ __('privacy.my_data_title') }}">
                    <div class="text-right hidden sm:block">
                        <div class="text-sm font-semibold leading-none">{{ auth()->user()->full_name }}</div>
                        <div class="text-xs text-ink-muted mt-0.5">{{ auth()->user()->username }}</div>
                    </div>
                    <div class="w-9 h-9 rounded-full bg-accent-soft text-accent-soft-ink flex items-center justify-center font-display font-bold text-xs">
                        {{ Illuminate\Support\Str::of(auth()->user()->full_name)->substr(0, 2) }}
                    </div>
                </a>
                <form method="GET" action="{{ route('logout') }}">
                    <button type="submit" class="text-xs font-semibold text-ink-muted hover:text-danger">
                        {{ __('nav.logout') }}
                    </button>
                </form>
            </div>
        </header>

        <main class="flex-1 p-4 md:p-6">
            @if (session('status'))
                <div class="mb-4 rounded-lg bg-success-soft text-success-ink text-sm px-4 py-3">
                    {{ session('status') }}
                </div>
            @endif

            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
