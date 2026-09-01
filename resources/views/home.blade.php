<x-layout>
    <div class="mb-6">
        <h1 class="font-display text-lg font-bold">{{ __('home.greeting', ['name' => auth()->user()->full_name]) }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('home.subtitle') }}</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-2xl">
        @can('viewAny', App\Models\Item::class)
            <a href="{{ route('items.index') }}" class="block bg-surface border border-border rounded-xl p-5 hover:border-accent transition-colors">
                <div class="w-9 h-9 rounded-lg bg-accent-soft text-accent-soft-ink flex items-center justify-center mb-3">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M10 3h4M10.5 3v6.5L5.8 18a1.6 1.6 0 0 0 1.4 2.4h9.6a1.6 1.6 0 0 0 1.4-2.4l-4.7-8.5V3"/></svg>
                </div>
                <div class="font-semibold text-sm">{{ __('nav.items') }}</div>
                <div class="text-xs text-ink-muted mt-1">{{ __('home.items_desc') }}</div>
            </a>
        @endcan

        @can('viewAny', App\Models\User::class)
            <a href="{{ route('admin.users.index') }}" class="block bg-surface border border-border rounded-xl p-5 hover:border-accent transition-colors">
                <div class="w-9 h-9 rounded-lg bg-accent-soft text-accent-soft-ink flex items-center justify-center mb-3">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="8.3" r="3.3"/><path d="M5 20c1-3.6 4-5.5 7-5.5s6 1.9 7 5.5"/></svg>
                </div>
                <div class="font-semibold text-sm">{{ __('nav.users_roles') }}</div>
                <div class="text-xs text-ink-muted mt-1">{{ __('home.users_desc') }}</div>
            </a>
        @endcan
    </div>
</x-layout>
