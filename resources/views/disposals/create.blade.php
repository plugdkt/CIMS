<x-layout>
    <div class="max-w-2xl">
        <div class="mb-5">
            <a href="{{ route('disposals.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('disposals.back_to_list') }}</a>
            <h1 class="font-display text-lg font-bold mt-1">{{ __('disposals.create_title') }}</h1>
        </div>

        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('disposals.store') }}" class="bg-surface border border-border rounded-xl p-6 space-y-4">
            @csrf

            <div>
                <label class="block text-sm font-medium mb-1" for="barcode">{{ __('disposals.field_barcode') }}</label>
                <input type="text" name="barcode" id="barcode" value="{{ old('barcode') }}"
                       class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="qty">{{ __('disposals.field_qty') }}</label>
                <input type="text" name="qty" id="qty" value="{{ old('qty') }}"
                       class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="reason">{{ __('disposals.field_reason') }}</label>
                <select name="reason" id="reason" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                    <option value="">{{ __('items.select_placeholder') }}</option>
                    @foreach (['EXPIRED', 'CONTAMINATED', 'DAMAGED', 'WASTE', 'OTHER'] as $reason)
                        <option value="{{ $reason }}" @selected(old('reason') === $reason)>{{ __('disposals.reason_'.strtolower($reason)) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="method">{{ __('disposals.field_method') }}</label>
                <input type="text" name="method" id="method" value="{{ old('method') }}"
                       placeholder="{{ __('disposals.field_method_hint') }}"
                       class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="disposal_date">{{ __('disposals.field_disposal_date') }}</label>
                <input type="date" name="disposal_date" id="disposal_date" value="{{ old('disposal_date', now()->toDateString()) }}"
                       class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div class="pt-2">
                <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">
                    {{ __('disposals.save') }}
                </button>
            </div>
        </form>
    </div>
</x-layout>
