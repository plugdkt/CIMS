<x-layout>
    <div class="max-w-2xl">
        <div class="mb-5">
            <a href="{{ route('adjustments.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('adjustments.back_to_list') }}</a>
            <h1 class="font-display text-lg font-bold mt-1">{{ __('adjustments.create_title') }}</h1>
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

        <form method="POST" action="{{ route('adjustments.store') }}" class="bg-surface border border-border rounded-xl p-6 space-y-4">
            @csrf

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('adjustments.field_barcode') }}</label>
                <input type="text" name="barcode" value="{{ old('barcode') }}"
                       class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('adjustments.field_direction') }}</label>
                <select name="direction" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                    <option value="IN" @selected(old('direction') === 'IN')>{{ __('adjustments.direction_in') }}</option>
                    <option value="OUT" @selected(old('direction') === 'OUT')>{{ __('adjustments.direction_out') }}</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('adjustments.field_qty') }}</label>
                <input type="text" name="qty" value="{{ old('qty') }}"
                       class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('adjustments.field_remark') }}</label>
                <textarea name="remark" rows="3"
                       class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">{{ old('remark') }}</textarea>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('adjustments.field_approved_by') }}</label>
                <select name="approved_by" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                    <option value="">{{ __('items.select_placeholder') }}</option>
                    @foreach ($approvers as $approver)
                        <option value="{{ $approver->id }}" @selected((string) old('approved_by') === (string) $approver->id)>{{ $approver->full_name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="pt-2">
                <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">
                    {{ __('adjustments.save') }}
                </button>
            </div>
        </form>
    </div>
</x-layout>
