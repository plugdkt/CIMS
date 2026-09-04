<x-layout>
    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('notifications.index_title') }}</h1>
        </div>
        <form method="POST" action="{{ route('notifications.read-all') }}">
            @csrf
            <button type="submit" class="text-xs font-semibold text-accent hover:text-accent-strong">
                {{ __('notifications.mark_all_read') }}
            </button>
        </form>
    </div>

    <div class="bg-surface border border-border rounded-xl divide-y divide-border overflow-hidden">
        @forelse ($notifications as $notification)
            <form method="POST" action="{{ route('notifications.read', $notification) }}" class="block">
                @csrf
                <button type="submit" class="w-full text-left px-4 py-3 flex items-start gap-3 hover:bg-surface-alt {{ $notification->read_at === null ? 'bg-accent-soft/40' : '' }}">
                    <span class="w-2 h-2 mt-1.5 rounded-full flex-shrink-0 {{ $notification->read_at === null ? 'bg-accent' : 'bg-transparent' }}"></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold">{{ $notification->title }}</span>
                        @if ($notification->body)
                            <span class="block text-xs text-ink-muted mt-0.5">{{ $notification->body }}</span>
                        @endif
                        <span class="block text-[11px] text-ink-faint mt-1">{{ $notification->created_at->format('d/m/Y H:i') }}</span>
                    </span>
                </button>
            </form>
        @empty
            <div class="px-4 py-8 text-center text-ink-muted text-sm">
                {{ __('notifications.no_results') }}
            </div>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $notifications->links() }}
    </div>
</x-layout>
