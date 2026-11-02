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
    ITM-2610-052: navigation = the home-page cards (MenuRegistry) plus, on every page that belongs to a
    card, a sidebar that lists only that card's menu (card name as the group heading, "back to home" on
    top). The home page itself has no sidebar. Desktop: permanent left column.

    Progressive enhancement on a phone: without JavaScript the sidebar is an ordinary block stacked above
    the page content, so the menu is always reachable. A small nonce'd script then turns it into an
    off-canvas drawer (classes below) opened by the header button, and adds aria-expanded, Escape to close,
    focus hand-over and `inert` on the page behind. Plain vanilla JS is used for this because it is a few
    lines and has no dependency; Livewire/Alpine already load on the page (the CSP carries 'unsafe-eval'
    for them, see CLAUDE.md) but the menu does not need them. The bell, "my data" and logout stay in the
    header on every page.
--}}
@php
    $menu = app(App\Domain\Navigation\MenuRegistry::class)->contextFor(auth()->user(), request());
    $hasSidebar = $menu['section'] !== null || $menu['showBack'];
@endphp
<body @class(['h-full bg-bg text-ink antialiased', 'md:grid md:grid-cols-[16rem_minmax(0,1fr)] md:min-h-full' => $hasSidebar])>
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-lg focus:bg-surface focus:border focus:border-accent focus:px-4 focus:py-2 focus:text-sm focus:font-semibold">{{ __('nav.skip_to_content') }}</a>

    @if ($hasSidebar)
        <div id="drawer-overlay" class="hidden fixed inset-0 bg-ink/40 z-30 md:hidden" aria-hidden="true"></div>

        {{-- Default (no JS): a normal block above the content on a phone, the left column from md up.
             The scripted drawer classes are added by the script right after this element. --}}
        <aside id="app-sidebar" aria-label="{{ __('nav.sidebar_label') }}" tabindex="0"
               class="w-full bg-surface border-b border-border p-4 flex flex-col gap-4 overflow-y-auto md:static md:w-64 md:border-b-0 md:border-r focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent">
            <button id="drawer-close" type="button" class="hidden self-end w-9 h-9 rounded-lg border border-border items-center justify-center text-ink-muted hover:bg-surface-alt focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent" aria-label="{{ __('nav.close_menu') }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
            </button>

            <nav class="flex flex-col gap-1" aria-label="{{ __('nav.tabs_label') }}">
                @if ($menu['showBack'])
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2 rounded-lg border border-border bg-accent-soft text-accent-soft-ink text-sm font-bold px-3 py-2.5 mb-3 hover:bg-surface-alt focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent">{{ __('nav.back_home') }}</a>
                @endif

                @if ($menu['section'] !== null)
                    <div class="font-display font-bold text-base px-2 mb-1">{{ $menu['title'] }}</div>
                @endif

                @foreach ($menu['tabs'] as $tab)
                    <a href="{{ $tab['url'] }}"
                       @class([
                           'flex items-center pl-3 pr-2 py-2.5 rounded-lg text-base border-l-[3px] transition-colors motion-reduce:transition-none leading-tight focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent',
                           'bg-accent-soft text-accent-soft-ink font-semibold border-accent' => $tab['active'],
                           'text-ink-muted font-medium border-transparent hover:bg-surface-alt hover:text-ink' => ! $tab['active'],
                       ])
                       @if ($tab['active']) aria-current="page" @endif>{{ $tab['label'] }}</a>
                @endforeach
            </nav>
        </aside>

        <script nonce="{{ request()->attributes->get('csp_nonce') }}">
            // Runs right after the <aside> so the phone layout never flashes the stacked version.
            (function () {
                var aside = document.getElementById('app-sidebar');
                if (!aside) { return; }
                // max-md:* classes: only the phone layout becomes a drawer, md and up keep the column.
                aside.classList.add('max-md:fixed', 'max-md:z-40', 'max-md:inset-y-0', 'max-md:left-0', 'max-md:w-64', 'max-md:border-b-0', 'max-md:border-r',
                    'max-md:-translate-x-full', 'max-md:invisible', 'max-md:transition-transform', 'max-md:motion-reduce:transition-none');
                document.getElementById('drawer-close').classList.add('max-md:flex');
                var opener = document.getElementById('drawer-open');
                if (opener) { opener.classList.add('max-md:flex'); }
                document.documentElement.setAttribute('data-drawer', 'ready');
            })();
        </script>
    @endif

    <div id="page-column" class="flex flex-col min-w-0 min-h-full">
        <header class="sticky top-0 z-20 flex items-center gap-3 px-4 md:px-6 py-3 bg-surface border-b border-border">
            @if ($hasSidebar)
                <button id="drawer-open" type="button" class="hidden w-9 h-9 rounded-lg border border-border items-center justify-center focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent" aria-label="{{ __('nav.open_menu') }}" aria-controls="app-sidebar" aria-expanded="false">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
            @endif

            <a href="{{ $menu['homeUrl'] }}" class="flex items-center gap-3 px-1">
                <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-accent to-gold flex items-center justify-center text-white font-display font-bold text-sm">
                    CM
                </div>
                <div>
                    <div class="font-display font-bold text-sm leading-none">{{ __('auth.app_name') }}</div>
                    <div class="text-xs text-ink-muted mt-0.5 hidden sm:block">{{ __('auth.app_tagline') }}</div>
                </div>
            </a>

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

        <main id="main-content" tabindex="-1" class="flex-1 focus:outline-none p-4 md:p-6 {{ $hasSidebar ? '' : 'w-full max-w-7xl mx-auto' }}">
            @if (session('status'))
                <div class="mb-4 rounded-lg bg-success-soft text-success-ink text-sm px-4 py-3">
                    {{ session('status') }}
                </div>
            @endif

            {{ $slot }}
        </main>
    </div>

    @if ($hasSidebar)
        <script nonce="{{ request()->attributes->get('csp_nonce') }}">
            (function () {
                var openBtn = document.getElementById('drawer-open');
                var closeBtn = document.getElementById('drawer-close');
                var aside = document.getElementById('app-sidebar');
                var overlay = document.getElementById('drawer-overlay');
                var page = document.getElementById('page-column');
                if (!openBtn || !closeBtn || !aside || !overlay || !page) { return; }

                function setOpen(open) {
                    aside.classList.toggle('max-md:translate-x-0', open);
                    aside.classList.toggle('max-md:-translate-x-full', !open);
                    aside.classList.toggle('max-md:invisible', !open);
                    overlay.classList.toggle('hidden', !open);
                    // Keep the keyboard and screen reader inside the drawer while it is open.
                    if (open) { page.setAttribute('inert', ''); } else { page.removeAttribute('inert'); }
                    openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    if (open) { closeBtn.focus(); } else { openBtn.focus(); }
                }

                openBtn.addEventListener('click', function () { setOpen(true); });
                closeBtn.addEventListener('click', function () { setOpen(false); });
                overlay.addEventListener('click', function () { setOpen(false); });
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && openBtn.getAttribute('aria-expanded') === 'true') { setOpen(false); }
                });
            })();
        </script>
    @endif

    @livewireScripts
</body>
</html>
