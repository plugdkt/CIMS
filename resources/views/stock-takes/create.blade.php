<x-layout>
    <div class="max-w-2xl">
        <div class="mb-5">
            <a href="{{ route('stock-takes.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('stock_takes.back_to_list') }}</a>
            <h1 class="font-display text-lg font-bold mt-1">{{ __('stock_takes.create_title') }}</h1>
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

        <form method="POST" action="{{ route('stock-takes.store') }}" class="bg-surface border border-border rounded-xl p-6 space-y-4">
            @csrf

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('stock_takes.field_lab') }}</label>
                <select name="lab_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                    <option value="">{{ __('items.select_placeholder') }}</option>
                    @foreach ($labs as $lab)
                        <option value="{{ $lab->id }}" @selected((int) old('lab_id') === $lab->id)>{{ $lab->name_th }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('stock_takes.field_count_date') }}</label>
                <input type="date" name="count_date" value="{{ old('count_date', now()->toDateString()) }}"
                       class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <p class="text-xs text-ink-muted">{{ __('stock_takes.create_hint') }}</p>

            <div class="pt-2">
                <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">
                    {{ __('stock_takes.save') }}
                </button>
            </div>
        </form>
    </div>
</x-layout>
