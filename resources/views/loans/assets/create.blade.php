<x-layout>
    <a href="{{ route('loans.assets.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('loans.registry_title') }}</a>
    <h1 class="font-display text-lg font-bold mt-1 mb-5">{{ __('loans.register_title') }}</h1>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3" role="alert">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Step 1: find the catalog item (only items counted in whole pieces can be lent, BR-L01). --}}
    <form method="GET" action="{{ route('loans.assets.create') }}" class="flex flex-wrap items-end gap-3 mb-5">
        <div>
            <label for="q" class="block text-sm font-medium mb-1">{{ __('loans.field_item_search') }}</label>
            <input id="q" name="q" type="search" value="{{ $term }}" class="w-full sm:w-80 rounded-lg border border-border px-3 py-2 text-sm">
        </div>
        <button type="submit" class="rounded-lg border border-border text-sm font-semibold px-4 py-2 hover:bg-surface-alt">{{ __('loans.btn_search') }}</button>
    </form>

    <form method="POST" action="{{ route('loans.assets.store') }}" class="bg-surface border border-border rounded-xl p-6 space-y-5 max-w-2xl">
        @csrf

        <fieldset>
            <legend class="block text-sm font-medium mb-2">{{ __('loans.field_item') }}</legend>
            @if ($term === '')
                <p class="text-sm text-ink-muted">{{ __('loans.item_search_hint') }}</p>
            @elseif ($items->isEmpty())
                <p class="text-sm text-ink-muted">{{ __('loans.item_search_empty') }}</p>
            @else
                <div class="space-y-1.5">
                    @foreach ($items as $item)
                        <label class="flex items-center gap-2 text-sm" for="item-{{ $item->id }}">
                            <input type="radio" id="item-{{ $item->id }}" name="item_id" value="{{ $item->id }}" @checked((string) old('item_id') === (string) $item->id) required>
                            <span>{{ $item->name_th }} <span class="font-mono text-xs text-ink-faint">{{ $item->item_code }}</span></span>
                        </label>
                    @endforeach
                </div>
            @endif
        </fieldset>

        @if ($isAdmin)
            <div>
                <label for="lab_id" class="block text-sm font-medium mb-1">{{ __('loans.col_lab') }}</label>
                <select id="lab_id" name="lab_id" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <option value="">{{ __('items.select_placeholder') }}</option>
                    @foreach ($labs as $lab)
                        <option value="{{ $lab->id }}" @selected((string) old('lab_id') === (string) $lab->id)>{{ $lab->code }} {{ $lab->name_th }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-ink-faint">{{ __('loans.admin_choose_lab_hint') }}</p>
            </div>
        @else
            <p class="text-sm"><span class="text-ink-faint">{{ __('loans.col_lab') }}:</span> <span class="font-semibold">{{ $ownLab?->name_th }}</span></p>
        @endif

        <fieldset>
            <legend class="block text-sm font-medium mb-1">{{ __('loans.col_mode') }}</legend>
            <div class="flex gap-6">
                <label class="flex items-center gap-2 text-sm" for="mode-unit">
                    <input type="radio" id="mode-unit" name="tracking_mode" value="UNIT" @checked(old('tracking_mode') === 'UNIT') required>
                    {{ __('loans.mode_unit') }}
                </label>
                <label class="flex items-center gap-2 text-sm" for="mode-pool">
                    <input type="radio" id="mode-pool" name="tracking_mode" value="POOL" @checked(old('tracking_mode', 'POOL') === 'POOL') required>
                    {{ __('loans.mode_pool') }}
                </label>
            </div>
            <p class="mt-1 text-xs text-ink-faint">{{ __('loans.mode_hint') }}</p>
        </fieldset>

        <div>
            <label for="qty" class="block text-sm font-medium mb-1">{{ __('loans.field_qty') }}</label>
            <input id="qty" name="qty" type="number" step="1" min="1" value="{{ old('qty', 1) }}" class="w-32 rounded-lg border border-border px-3 py-2 text-sm">
            <p class="mt-1 text-xs text-ink-faint">{{ __('loans.qty_hint') }}</p>
        </div>

        <div>
            <label for="asset_tag" class="block text-sm font-medium mb-1">{{ __('loans.field_asset_tag') }}</label>
            <input id="asset_tag" name="asset_tag" type="text" maxlength="64" value="{{ old('asset_tag') }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm font-mono">
            <p class="mt-1 text-xs text-ink-faint">{{ __('loans.asset_tag_hint') }}</p>
        </div>

        <div>
            <label for="location_id" class="block text-sm font-medium mb-1">{{ __('loans.col_location') }}</label>
            <select id="location_id" name="location_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                <option value="">—</option>
                @foreach ($locations as $location)
                    <option value="{{ $location->id }}" @selected((string) old('location_id') === (string) $location->id)>@if ($isAdmin){{ $location->lab?->code }} · @endif{{ $location->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="max_loan_days" class="block text-sm font-medium mb-1">{{ __('loans.field_max_days') }}</label>
            <input id="max_loan_days" name="max_loan_days" type="number" min="1" max="365" value="{{ old('max_loan_days') }}" class="w-32 rounded-lg border border-border px-3 py-2 text-sm">
            <p class="mt-1 text-xs text-ink-faint">{{ __('loans.max_days_hint') }}</p>
        </div>

        <div>
            <label for="replacement_value" class="block text-sm font-medium mb-1">{{ __('loans.field_value') }}</label>
            <input id="replacement_value" name="replacement_value" type="number" min="0" step="0.01" value="{{ old('replacement_value') }}" class="w-40 rounded-lg border border-border px-3 py-2 text-sm">
            <p class="mt-1 text-xs text-ink-faint">{{ __('loans.value_hint') }}</p>
        </div>

        <div>
            <label for="condition_note" class="block text-sm font-medium mb-1">{{ __('loans.field_note') }}</label>
            <input id="condition_note" name="condition_note" type="text" maxlength="255" value="{{ old('condition_note') }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
        </div>

        <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">{{ __('loans.btn_save') }}</button>
    </form>
</x-layout>
