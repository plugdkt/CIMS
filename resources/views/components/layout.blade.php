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
        <div class="flex items-center gap-3 px-1">
            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-accent to-gold flex items-center justify-center text-white font-display font-bold text-sm">
                CM
            </div>
            <div>
                <div class="font-display font-bold text-sm leading-none">{{ __('auth.app_name') }}</div>
                <div class="text-[11px] text-ink-muted mt-0.5">{{ __('auth.app_tagline') }}</div>
            </div>
        </div>

        <nav class="flex flex-col gap-1">
            <div class="text-[11px] font-semibold tracking-wide uppercase text-ink-faint px-2 mb-1">
                {{ __('nav.group_data') }}
            </div>
            @can('viewAny', App\Models\Item::class)
                <a href="{{ route('items.index') }}"
                   class="flex items-center gap-2 px-2 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('items.*') ? 'bg-accent-soft text-accent-soft-ink' : 'text-ink-muted hover:bg-surface-alt hover:text-ink' }}">
                    {{ __('nav.items') }}
                </a>
            @endcan
            @can('viewAny', App\Models\Location::class)
                <a href="{{ route('locations.index') }}"
                   class="flex items-center gap-2 px-2 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('locations.*') ? 'bg-accent-soft text-accent-soft-ink' : 'text-ink-muted hover:bg-surface-alt hover:text-ink' }}">
                    {{ __('nav.locations') }}
                </a>
            @endcan

            <div class="text-[11px] font-semibold tracking-wide uppercase text-ink-faint px-2 mb-1 mt-3">
                {{ __('nav.group_inventory') }}
            </div>
            @if(auth()->user()?->hasPermission('receiving.manage') || auth()->user()?->hasPermission('item.manage') || auth()->user()?->hasPermission('ledger.adjust') || auth()->user()?->hasRole('ADMIN'))
                <a href="{{ route('stock-in.create') }}"
                   class="flex items-center gap-2 px-2 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('stock-in.*') ? 'bg-accent-soft text-accent-soft-ink' : 'text-ink-muted hover:bg-surface-alt hover:text-ink' }}">
                    {{ __('nav.stock_in') }}
                </a>
            @endif
            @can('viewAny', App\Models\Requisition::class)
                <a href="{{ route('requisitions.index') }}"
                   class="flex items-center gap-2 px-2 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('requisitions.*') ? 'bg-accent-soft text-accent-soft-ink' : 'text-ink-muted hover:bg-surface-alt hover:text-ink' }}">
                    {{ __('nav.requisitions') }}
                </a>
            @endcan
            @can('viewAny', App\Models\StockTake::class)
                <a href="{{ route('stock-takes.index') }}"
                   class="flex items-center gap-2 px-2 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('stock-takes.*') ? 'bg-accent-soft text-accent-soft-ink' : 'text-ink-muted hover:bg-surface-alt hover:text-ink' }}">
                    {{ __('nav.stock_takes') }}
                </a>
            @endcan
            @can('viewAny', App\Models\Disposal::class)
                <a href="{{ route('disposals.index') }}"
                   class="flex items-center gap-2 px-2 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('disposals.*') ? 'bg-accent-soft text-accent-soft-ink' : 'text-ink-muted hover:bg-surface-alt hover:text-ink' }}">
                    {{ __('nav.disposals') }}
                </a>
            @endcan
            @can('adjust', App\Models\StockLedger::class)
                <a href="{{ route('adjustments.index') }}"
                   class="flex items-center gap-2 px-2 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('adjustments.*') ? 'bg-accent-soft text-accent-soft-ink' : 'text-ink-muted hover:bg-surface-alt hover:text-ink' }}">
                    {{ __('nav.adjustments') }}
                </a>
            @endcan
            @can('report.view')
                <a href="{{ route('reports.index') }}"
                   class="flex items-center gap-2 px-2 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('reports.*') ? 'bg-accent-soft text-accent-soft-ink' : 'text-ink-muted hover:bg-surface-alt hover:text-ink' }}">
                    {{ __('nav.reports') }}
                </a>
            @endcan

            <div class="text-[11px] font-semibold tracking-wide uppercase text-ink-faint px-2 mb-1 mt-3">
                {{ __('nav.group_system') }}
            </div>
            @can('viewAny', App\Models\User::class)
                <a href="{{ route('admin.users.index') }}"
                   class="flex items-center gap-2 px-2 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('admin.users.*') ? 'bg-accent-soft text-accent-soft-ink' : 'text-ink-muted hover:bg-surface-alt hover:text-ink' }}">
                    {{ __('nav.users_roles') }}
                </a>
            @endcan
            @can('viewAny', App\Models\Lab::class)
                <a href="{{ route('admin.labs.index') }}"
                   class="flex items-center gap-2 px-2 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('admin.labs.*') ? 'bg-accent-soft text-accent-soft-ink' : 'text-ink-muted hover:bg-surface-alt hover:text-ink' }}">
                    {{ __('nav.labs') }}
                </a>
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
