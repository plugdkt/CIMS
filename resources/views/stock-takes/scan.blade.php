<x-layout>
    <div class="max-w-md mx-auto">
        <div class="mb-5">
            <a href="{{ route('stock-takes.show', $stockTake) }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('stock_takes.back_to_round') }}</a>
            <h1 class="font-display text-lg font-bold mt-1">{{ __('stock_takes.scan_title') }}</h1>
            <p class="text-sm text-ink-muted mt-1">
                {{ __('stock_takes.scan_progress', ['counted' => $total - $remaining, 'total' => $total]) }}
            </p>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded-lg bg-success-soft text-success-ink text-sm px-4 py-3">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($remaining === 0)
            <div class="bg-surface border border-border rounded-xl p-6 text-center">
                <p class="text-sm text-success-ink font-semibold">{{ __('stock_takes.all_counted') }}</p>
                <a href="{{ route('stock-takes.show', $stockTake) }}" class="text-xs font-semibold text-accent hover:text-accent-strong mt-2 inline-block">
                    {{ __('stock_takes.back_to_round') }}
                </a>
            </div>
        @else
            <form method="POST" action="{{ route('stock-takes.count', $stockTake) }}" class="bg-surface border border-border rounded-xl p-6 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium mb-1">{{ __('stock_takes.field_barcode') }}</label>
                    <input type="text" name="barcode" autofocus
                           class="w-full rounded-lg border border-border bg-surface px-4 py-3 text-base font-mono">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">{{ __('stock_takes.field_counted_qty') }}</label>
                    <input type="text" name="counted_qty"
                           class="w-full rounded-lg border border-border bg-surface px-4 py-3 text-base">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">{{ __('stock_takes.field_reason') }}</label>
                    <input type="text" name="reason"
                           placeholder="{{ __('stock_takes.field_reason_hint') }}"
                           class="w-full rounded-lg border border-border bg-surface px-4 py-3 text-base">
                </div>
                <button type="submit" class="w-full rounded-lg bg-accent hover:bg-accent-strong text-white text-base font-semibold py-3">
                    {{ __('stock_takes.save_and_next') }}
                </button>
            </form>
        @endif
    </div>
</x-layout>
