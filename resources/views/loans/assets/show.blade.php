<x-layout>
    @php $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp

    @can('browseRegistry', \App\Models\LoanAsset::class)
        <a href="{{ route('loans.assets.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('loans.registry_title') }}</a>
    @endcan
    <h1 class="font-display text-lg font-bold mt-1">{{ $asset->item?->name_th }}</h1>
    <p class="text-xs font-mono text-ink-faint mb-5">{{ $asset->item?->item_code }}@if ($asset->asset_tag) · {{ $asset->asset_tag }}@endif · {{ $asset->lab?->name_th }}</p>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-success-soft text-success-ink text-sm px-4 py-3" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3" role="alert">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <dl class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
        <div class="bg-surface border border-border rounded-xl p-4"><dt class="text-xs text-ink-faint">{{ __('loans.col_total') }}</dt><dd class="font-display text-xl font-bold">{{ $fmt($asset->qty_total) }}</dd></div>
        <div class="bg-surface border border-border rounded-xl p-4"><dt class="text-xs text-ink-faint">{{ __('loans.col_on_loan') }}</dt><dd class="font-display text-xl font-bold">{{ $fmt($asset->qty_on_loan) }}</dd></div>
        <div class="bg-surface border border-border rounded-xl p-4"><dt class="text-xs text-ink-faint">{{ __('loans.col_unavailable') }}</dt><dd class="font-display text-xl font-bold">{{ $fmt($asset->qty_unavailable) }}</dd></div>
        <div class="bg-surface border border-border rounded-xl p-4"><dt class="text-xs text-ink-faint">{{ __('loans.col_available') }}</dt><dd class="font-display text-xl font-bold">{{ $fmt($asset->available()) }}</dd></div>
        <div class="bg-surface border border-border rounded-xl p-4"><dt class="text-xs text-ink-faint">{{ __('loans.col_status') }}</dt><dd class="font-semibold">{{ $asset->status === 'ACTIVE' ? __('loans.status_active') : __('loans.status_retired') }}</dd></div>
    </dl>

    @can('editAsset', $asset)
        <section class="bg-surface border border-border rounded-xl p-6 mb-6 max-w-2xl" aria-labelledby="edit-h">
            <h2 id="edit-h" class="font-display text-base font-bold mb-3">{{ __('loans.section_details') }}</h2>
            <form method="POST" action="{{ route('loans.assets.update', $asset) }}" class="space-y-4">
                @csrf @method('PUT')
                <div>
                    <label for="location_id" class="block text-sm font-medium mb-1">{{ __('loans.col_location') }}</label>
                    <select id="location_id" name="location_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                        <option value="">—</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected((string) old('location_id', $asset->location_id) === (string) $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="max_loan_days" class="block text-sm font-medium mb-1">{{ __('loans.field_max_days') }}</label>
                    <input id="max_loan_days" name="max_loan_days" type="number" min="1" max="365" value="{{ old('max_loan_days', $asset->max_loan_days) }}" class="w-32 rounded-lg border border-border px-3 py-2 text-sm">
                </div>
                @if ($canSeeValue)
                    <div>
                        <label for="replacement_value" class="block text-sm font-medium mb-1">{{ __('loans.field_value') }}</label>
                        <input id="replacement_value" name="replacement_value" type="number" min="0" step="0.01" value="{{ old('replacement_value', $asset->replacement_value) }}" class="w-40 rounded-lg border border-border px-3 py-2 text-sm">
                    </div>
                @endif
                <div>
                    <label for="condition_note" class="block text-sm font-medium mb-1">{{ __('loans.field_note') }}</label>
                    <input id="condition_note" name="condition_note" type="text" maxlength="255" value="{{ old('condition_note', $asset->condition_note) }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                </div>
                <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">{{ __('loans.btn_save') }}</button>
            </form>
        </section>

        @if ($asset->tracking_mode === 'POOL')
            <section class="bg-surface border border-border rounded-xl p-6 mb-6 max-w-2xl" aria-labelledby="adjust-h">
                <h2 id="adjust-h" class="font-display text-base font-bold mb-1">{{ __('loans.section_adjust') }}</h2>
                <p class="text-xs text-ink-faint mb-3">{{ __('loans.adjust_hint') }}</p>
                <form method="POST" action="{{ route('loans.assets.adjust', $asset) }}" class="space-y-4">
                    @csrf
                    <fieldset>
                        <legend class="block text-sm font-medium mb-1">{{ __('loans.field_direction') }}</legend>
                        <div class="flex gap-6">
                            <label class="flex items-center gap-2 text-sm" for="dir-in"><input type="radio" id="dir-in" name="direction" value="IN" @checked(old('direction') === 'IN') required> {{ __('loans.direction_in') }}</label>
                            <label class="flex items-center gap-2 text-sm" for="dir-out"><input type="radio" id="dir-out" name="direction" value="OUT" @checked(old('direction') === 'OUT') required> {{ __('loans.direction_out') }}</label>
                        </div>
                    </fieldset>
                    <div>
                        <label for="adj-qty" class="block text-sm font-medium mb-1">{{ __('loans.field_qty') }}</label>
                        <input id="adj-qty" name="qty" type="number" step="1" min="1" value="{{ old('qty') }}" required class="w-32 rounded-lg border border-border px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="adj-note" class="block text-sm font-medium mb-1">{{ __('loans.field_reason') }}</label>
                        <textarea id="adj-note" name="note" rows="2" required minlength="10" maxlength="500" class="w-full rounded-lg border border-border px-3 py-2 text-sm">{{ old('note') }}</textarea>
                        <p class="mt-1 text-xs text-ink-faint">{{ __('loans.reason_hint') }}</p>
                    </div>
                    <div>
                        <label for="adj-ref" class="block text-sm font-medium mb-1">{{ __('loans.field_external_ref') }}</label>
                        <input id="adj-ref" name="external_ref" type="text" maxlength="255" value="{{ old('external_ref') }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                        <p class="mt-1 text-xs text-ink-faint">{{ __('loans.external_ref_hint') }}</p>
                    </div>
                    <div>
                        <label for="adj-approver" class="block text-sm font-medium mb-1">{{ __('loans.field_approver') }}</label>
                        <select id="adj-approver" name="approved_by" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                            <option value="">{{ __('items.select_placeholder') }}</option>
                            @foreach ($approvers as $approver)
                                <option value="{{ $approver->id }}" @selected((string) old('approved_by') === (string) $approver->id)>{{ $approver->full_name }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-ink-faint">{{ __('loans.approver_hint') }}</p>
                    </div>
                    <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">{{ __('loans.btn_adjust') }}</button>
                </form>
            </section>
        @endif

        <section class="bg-surface border border-border rounded-xl p-6 mb-6 max-w-2xl" aria-labelledby="retire-h">
            <h2 id="retire-h" class="font-display text-base font-bold mb-1">{{ __('loans.section_retire') }}</h2>
            <p class="text-xs text-ink-faint mb-3">{{ __('loans.retire_hint') }}</p>
            <form method="POST" action="{{ route('loans.assets.retire', $asset) }}" class="space-y-4">
                @csrf
                <div>
                    <label for="ret-note" class="block text-sm font-medium mb-1">{{ __('loans.field_reason') }}</label>
                    <textarea id="ret-note" name="note" rows="2" required minlength="10" maxlength="500" class="w-full rounded-lg border border-border px-3 py-2 text-sm"></textarea>
                </div>
                <div>
                    <label for="ret-approver" class="block text-sm font-medium mb-1">{{ __('loans.field_approver') }}</label>
                    <select id="ret-approver" name="approved_by" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                        <option value="">{{ __('items.select_placeholder') }}</option>
                        @foreach ($approvers as $approver)
                            <option value="{{ $approver->id }}">{{ $approver->full_name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="rounded-lg border border-danger text-danger-ink hover:bg-danger-soft text-sm font-semibold px-5 py-2.5">{{ __('loans.btn_retire') }}</button>
            </form>
        </section>
    @endcan

    <section class="mb-6" aria-labelledby="log-h">
        <div class="flex items-center justify-between gap-3 mb-2">
            <h2 id="log-h" class="font-display text-base font-bold">{{ __('loans.section_log') }}</h2>
            @if ($canVerify)
                <form method="POST" action="{{ route('loans.assets.verify', $asset) }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-border text-xs font-semibold px-3 py-1.5 hover:bg-surface-alt">{{ __('loans.btn_verify') }}</button>
                </form>
            @endif
        </div>
        <div class="bg-surface border border-border rounded-xl overflow-hidden">
            <div class="overflow-x-auto" tabindex="0">
                <table class="w-full text-sm">
                    <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_when') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_movement') }}</th>
                            <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_qty') }}</th>
                            <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_total') }}</th>
                            <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_on_loan') }}</th>
                            <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_unavailable') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_by') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_note') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($movements as $movement)
                            <tr>
                                <td class="px-4 py-3 align-top whitespace-nowrap">{{ $movement->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-3 align-top">{{ __('loans.movement_'.strtolower($movement->movement_type)) }}</td>
                                <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($movement->qty) }}</td>
                                <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($movement->qty_total_after) }}</td>
                                <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($movement->qty_on_loan_after) }}</td>
                                <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($movement->qty_unavailable_after) }}</td>
                                <td class="px-4 py-3 align-top">{{ $movement->creator?->full_name }}@if ($movement->approver)<div class="text-xs text-ink-faint">{{ __('loans.approved_by') }} {{ $movement->approver->full_name }}</div>@endif</td>
                                <td class="px-4 py-3 align-top">{{ $movement->note }}@if ($movement->external_ref)<div class="text-xs text-ink-faint">{{ $movement->external_ref }}</div>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</x-layout>
