<x-layout>
    <div class="max-w-lg">
        <div class="mb-5">
            <a href="{{ route('items.show', $export->item) }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('ledger.export_back_to_item') }}</a>
            <h1 class="font-display text-lg font-bold mt-1">{{ __('ledger.export_status_title') }}</h1>
        </div>

        <div class="bg-surface border border-border rounded-xl p-6 text-sm">
            @if ($export->status === 'FAILED')
                <p class="text-danger-ink">{{ __('ledger.export_status_failed') }}</p>
            @else
                <p class="text-ink-muted">{{ __('ledger.export_status_pending') }}</p>
            @endif
        </div>
    </div>
</x-layout>
