<x-layout>
    <div class="max-w-xl">
        <a href="{{ route('ims.lots.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('ims.back') }}</a>
        <h1 class="font-display text-lg font-bold mt-1">{{ __('ims.transfer_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1 mb-4">{{ __('ims.transfer_hint') }}</p>

        <div class="bg-surface-alt rounded-xl p-4 mb-4 text-sm">
            <div class="font-semibold">{{ $lot->item?->name_th }} <span class="font-mono text-xs text-ink-faint">{{ $lot->item?->item_code }}</span></div>
            <div class="text-ink-muted mt-1">
                {{ __('ims.col_lot') }} {{ $lot->lot_no ?: '—' }} · {{ __('ims.lot_balance') }}
                <span class="font-mono font-semibold">{{ rtrim(rtrim((string) $lot->qty_remaining_base, '0'), '.') }} {{ $lot->item?->baseUnit?->code }}</span>
            </div>
        </div>

        @error('transfer')
            <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">{{ $message }}</div>
        @enderror

        <form method="POST" action="{{ route('ims.lots.transfer', $lot) }}" class="bg-surface border border-border rounded-xl p-6 space-y-4">
            @csrf

            <div>
                <label for="ims_doc_no" class="block text-sm font-medium mb-1">{{ __('ims.field_ims_doc_no') }}</label>
                <input id="ims_doc_no" name="ims_doc_no" type="text" maxlength="64" required value="{{ old('ims_doc_no') }}"
                       class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                @error('ims_doc_no') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="tracking_type" class="block text-sm font-medium mb-1">{{ __('ims.field_tracking') }}</label>
                <select id="tracking_type" name="tracking_type" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                    <option value="CONTAINER" @selected(old('tracking_type', 'CONTAINER') === 'CONTAINER')>{{ __('ims.tracking_container') }}</option>
                    <option value="BULK" @selected(old('tracking_type') === 'BULK')>{{ __('ims.tracking_bulk') }}</option>
                </select>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label for="container_count" class="block text-sm font-medium mb-1">{{ __('ims.field_container_count') }}</label>
                    <input id="container_count" name="container_count" type="number" min="1" value="{{ old('container_count', 1) }}"
                           class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                    @error('container_count') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="qty_per_container" class="block text-sm font-medium mb-1">{{ __('ims.field_qty_per_container') }}</label>
                    <input id="qty_per_container" name="qty_per_container" type="number" step="any" min="0" value="{{ old('qty_per_container') }}"
                           class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                    @error('qty_per_container') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="qty" class="block text-sm font-medium mb-1">{{ __('ims.field_total_qty') }}</label>
                    <input id="qty" name="qty" type="number" step="any" min="0" value="{{ old('qty') }}"
                           class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                    @error('qty') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="unit_id" class="block text-sm font-medium mb-1">{{ __('ims.field_unit') }}</label>
                    <select id="unit_id" name="unit_id" required class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}" @selected((int) old('unit_id', $lot->item?->base_unit_id) === $unit->id)>{{ $unit->code }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="location_id" class="block text-sm font-medium mb-1">{{ __('ims.field_location') }}</label>
                    <select id="location_id" name="location_id" required class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected((int) old('location_id') === $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                    @error('location_id') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="remark" class="block text-sm font-medium mb-1">{{ __('ims.field_remark') }}</label>
                <input id="remark" name="remark" type="text" maxlength="500" value="{{ old('remark') }}"
                       class="w-full rounded-lg border border-border px-3 py-2 text-sm">
            </div>

            <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">
                {{ __('ims.btn_do_transfer') }}
            </button>
        </form>
    </div>
</x-layout>
