<x-layout>
    @include('ims._tabs')
    @php
        $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
        $item = $lot->item;
        $unit = $item?->baseUnit?->code;
        $word = $item?->packagingWord();
        $size = $lot->pack_size_base !== null ? $fmt($lot->pack_size_base) : null;
        $packsReceived = $lot->packsReceived();
        $packsRemaining = $lot->packsRemaining();
        $unplaced = $lot->unplacedPacks();
        $canManage = auth()->user()?->can('manage', $lot);
    @endphp

    <a href="{{ route('ims.lots.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('ims.back_to_lots') }}</a>

    <div class="mt-1 mb-5">
        <h1 class="font-display text-lg font-bold">{{ $item?->name_th }}</h1>
        <p class="text-sm text-ink-muted mt-1">
            <span class="font-mono">{{ $item?->item_code }}</span> · {{ $item?->category?->name_th ?: '—' }}
            · {{ __('ims.col_lot') }} {{ $lot->lot_no ?: '—' }}
        </p>
    </div>

    @error('group') <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">{{ $message }}</div> @enderror

    {{-- What this lot holds: "5 ขวด × 100 g" --}}
    <dl class="bg-surface border border-border rounded-xl p-5 mb-6 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
        <div>
            <dt class="text-xs text-ink-faint">{{ __('ims.col_remaining') }}</dt>
            <dd class="font-semibold">
                @if ($packsRemaining !== null)
                    {{ $fmt($packsRemaining) }} {{ $word }} × {{ $size }} {{ $unit }}
                    <span class="block text-xs font-normal text-ink-faint">{{ __('ims.total_label') }} {{ $fmt($lot->qty_remaining_base) }} {{ $unit }}</span>
                @else
                    {{ $fmt($lot->qty_remaining_base) }} {{ $unit }}
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-ink-faint">{{ __('ims.col_received_qty') }}</dt>
            <dd>
                @if ($packsReceived !== null)
                    {{ $fmt($packsReceived) }} {{ $word }} × {{ $size }} {{ $unit }}
                @else
                    {{ $fmt($lot->qty_received_base) }} {{ $unit }}
                @endif
            </dd>
        </div>
        <div><dt class="text-xs text-ink-faint">{{ __('ims.col_doc_no') }}</dt><dd>{{ $lot->doc_no ?: '—' }}</dd></div>
        <div><dt class="text-xs text-ink-faint">{{ __('ims.col_fiscal_year') }} / {{ __('ims.col_round') }}</dt><dd>{{ $lot->fiscal_year ?: '—' }} / {{ $lot->purchase_round ?: '—' }}</dd></div>
        <div><dt class="text-xs text-ink-faint">{{ __('ims.col_price_per_pack') }}</dt><dd class="font-mono">{{ $lot->unit_price !== null ? number_format((float) $lot->unit_price, 2) : '—' }}</dd></div>
        <div><dt class="text-xs text-ink-faint">{{ __('ims.col_location') }}</dt><dd>{{ $lot->location?->name ?: '—' }}</dd></div>
        <div><dt class="text-xs text-ink-faint">{{ __('ims.col_expiry') }}</dt><dd>{{ $lot->expiry_date?->format('d/m/Y') ?: '—' }}</dd></div>
    </dl>

    {{-- Groups of packs: where each batch of bottles sits and when it expires --}}
    <div class="bg-surface border border-border rounded-xl overflow-hidden mb-6">
        <div class="px-5 pt-5">
            <h2 class="font-semibold">{{ __('ims.groups_title') }}</h2>
            <p class="text-xs text-ink-faint mt-1">{{ __('ims.groups_hint') }}</p>
        </div>

        @if ($packsRemaining === null)
            <p class="px-5 py-4 text-sm text-ink-muted">{{ __('ims.error.groups_need_pack_size') }}</p>
        @else
            <div class="overflow-x-auto mt-3" tabindex="0">
                <table class="w-full text-sm">
                    <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="px-4 py-3 font-semibold text-right">{{ __('ims.col_group_packs') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('ims.col_location') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('ims.col_expiry') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('ims.field_remark') }}</th>
                            <th class="px-4 py-3 font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($lot->groups as $group)
                            <tr>
                                <td class="px-4 py-3 text-right font-mono align-top">{{ $fmt($group->pack_count) }} {{ $word }} × {{ $size }} {{ $unit }}</td>
                                <td class="px-4 py-3 align-top">{{ $group->location?->name ?: '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap align-top">{{ $group->expiry_date?->format('d/m/Y') ?: '—' }}</td>
                                <td class="px-4 py-3 align-top">{{ $group->remark ?: '—' }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap align-top">
                                    @if ($canManage)
                                        <details class="inline-block text-left">
                                            <summary class="cursor-pointer text-xs font-semibold text-accent">{{ __('ims.btn_edit') }}</summary>
                                            <form method="POST" action="{{ route('ims.lots.groups.update', [$lot, $group]) }}" class="mt-2 grid grid-cols-2 gap-2 w-80 bg-surface-alt rounded-lg p-3">
                                                @csrf @method('PUT')
                                                <div>
                                                    <label for="g_packs-{{ $group->id }}" class="block text-xs mb-1">{{ __('ims.col_group_packs') }}</label>
                                                    <input id="g_packs-{{ $group->id }}" name="pack_count" type="number" step="any" min="0" required value="{{ $fmt($group->pack_count) }}" class="w-full rounded border border-border px-2 py-1 text-sm">
                                                </div>
                                                <div>
                                                    <label for="g_exp-{{ $group->id }}" class="block text-xs mb-1">{{ __('ims.col_expiry') }}</label>
                                                    <input id="g_exp-{{ $group->id }}" name="expiry_date" type="date" value="{{ $group->expiry_date?->toDateString() }}" class="w-full rounded border border-border px-2 py-1 text-sm">
                                                </div>
                                                <div class="col-span-2">
                                                    <label for="g_loc-{{ $group->id }}" class="block text-xs mb-1">{{ __('ims.col_location') }}</label>
                                                    <select id="g_loc-{{ $group->id }}" name="location_id" class="w-full rounded border border-border px-2 py-1 text-sm">
                                                        <option value="">—</option>
                                                        @foreach ($locations as $location)
                                                            <option value="{{ $location->id }}" @selected($group->location_id === $location->id)>{{ $location->code }} — {{ $location->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="col-span-2">
                                                    <label for="g_rem-{{ $group->id }}" class="block text-xs mb-1">{{ __('ims.field_remark') }}</label>
                                                    <input id="g_rem-{{ $group->id }}" name="remark" type="text" maxlength="255" value="{{ $group->remark }}" class="w-full rounded border border-border px-2 py-1 text-sm">
                                                </div>
                                                <div class="col-span-2">
                                                    <button type="submit" class="rounded bg-accent text-white text-xs font-semibold px-3 py-1.5">{{ __('ims.btn_save') }}</button>
                                                </div>
                                            </form>
                                        </details>
                                        <form method="POST" action="{{ route('ims.lots.groups.destroy', [$lot, $group]) }}" class="inline-block ml-2">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-xs font-semibold text-danger hover:underline">{{ __('ims.btn_remove') }}</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        @if (bccomp($unplaced, '0', 4) > 0)
                            <tr class="bg-surface-alt/50">
                                <td class="px-4 py-3 text-right font-mono">{{ $fmt($unplaced) }} {{ $word }} × {{ $size }} {{ $unit }}</td>
                                <td class="px-4 py-3">{{ $lot->location?->name ?: '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $lot->expiry_date?->format('d/m/Y') ?: '—' }}</td>
                                <td class="px-4 py-3 text-ink-muted" colspan="2">{{ __('ims.group_unplaced') }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            @if ($canManage && bccomp($unplaced, '0', 4) > 0)
                <form method="POST" action="{{ route('ims.lots.groups.store', $lot) }}" class="px-5 py-4 border-t border-border grid grid-cols-2 md:grid-cols-5 gap-3 items-end">
                    @csrf
                    <div>
                        <label for="new_packs" class="block text-xs font-medium mb-1">{{ __('ims.col_group_packs') }}</label>
                        <input id="new_packs" name="pack_count" type="number" step="any" min="0" required max="{{ $unplaced }}" value="{{ old('pack_count') }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                        @error('pack_count') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="new_loc" class="block text-xs font-medium mb-1">{{ __('ims.col_location') }}</label>
                        <select id="new_loc" name="location_id" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                            <option value="">—</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location->id }}" @selected((int) old('location_id') === $location->id)>{{ $location->code }} — {{ $location->name }}</option>
                            @endforeach
                        </select>
                        @error('location_id') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="new_exp" class="block text-xs font-medium mb-1">{{ __('ims.col_expiry') }}</label>
                        <input id="new_exp" name="expiry_date" type="date" value="{{ old('expiry_date') }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="new_rem" class="block text-xs font-medium mb-1">{{ __('ims.field_remark') }}</label>
                        <input id="new_rem" name="remark" type="text" maxlength="255" value="{{ old('remark') }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                    </div>
                    <div>
                        <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">{{ __('ims.btn_add_group') }}</button>
                    </div>
                </form>
            @endif
        @endif
    </div>

    <div class="grid gap-6 md:grid-cols-2 mb-6">
        @if ($canManage)
            <form method="POST" action="{{ route('ims.lots.update', $lot) }}" class="bg-surface border border-border rounded-xl p-5">
                @csrf @method('PUT')
                <h2 class="font-semibold mb-3">{{ __('ims.lot_info_title') }}</h2>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="lot_no" class="block text-sm font-medium mb-1">{{ __('ims.field_lot_no') }}</label>
                        <input id="lot_no" name="lot_no" type="text" maxlength="64" value="{{ old('lot_no', $lot->lot_no) }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                        @error('lot_no') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="expiry_date" class="block text-sm font-medium mb-1">{{ __('ims.field_expiry_default') }}</label>
                        <input id="expiry_date" name="expiry_date" type="date" value="{{ old('expiry_date', $lot->expiry_date?->toDateString()) }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                        @error('expiry_date') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="fiscal_year" class="block text-sm font-medium mb-1">{{ __('ims.col_fiscal_year') }}</label>
                        <input id="fiscal_year" name="fiscal_year" type="number" min="2500" max="2700" value="{{ old('fiscal_year', $lot->fiscal_year) }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                        @error('fiscal_year') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="purchase_round" class="block text-sm font-medium mb-1">{{ __('ims.col_round') }}</label>
                        <input id="purchase_round" name="purchase_round" type="text" maxlength="32" value="{{ old('purchase_round', $lot->purchase_round) }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="unit_price" class="block text-sm font-medium mb-1">{{ __('ims.field_price_per_pack') }}</label>
                        <input id="unit_price" name="unit_price" type="number" step="any" min="0" value="{{ old('unit_price', $lot->unit_price) }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                        @error('unit_price') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="location_id" class="block text-sm font-medium mb-1">{{ __('ims.field_storage_location_default') }}</label>
                        <select id="location_id" name="location_id" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                            <option value="">—</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location->id }}" @selected((int) old('location_id', $lot->location_id) === $location->id)>{{ $location->code }} — {{ $location->name }}</option>
                            @endforeach
                        </select>
                        @error('location_id') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="text-xs text-ink-faint mt-2">{{ __('ims.lot_info_hint') }}</p>
                <button type="submit" class="mt-4 rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">{{ __('ims.btn_save') }}</button>
            </form>
        @endif

        <div class="bg-surface border border-border rounded-xl p-5">
            <h2 class="font-semibold mb-3">{{ __('ims.photos_title') }}</h2>

            @if ($lot->photos->isNotEmpty())
                <ul class="flex flex-wrap gap-3 mb-4">
                    @foreach ($lot->photos as $photo)
                        <li>
                            <a href="{{ route('ims.lots.photos.show', [$lot, $photo]) }}" target="_blank">
                                <img src="{{ route('ims.lots.photos.show', [$lot, $photo]) }}" alt="{{ $item?->name_th }}" loading="lazy"
                                     class="w-24 h-24 rounded-lg object-cover border border-border">
                            </a>
                            @if ($loop->first)
                                <span class="block text-[11px] font-semibold text-success-ink text-center mt-1">{{ __('ims.photo_latest') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-ink-muted mb-4">{{ __('ims.photos_empty') }}</p>
            @endif

            @if ($canManage)
                <form method="POST" action="{{ route('ims.lots.photos.store', $lot) }}" enctype="multipart/form-data">
                    @csrf
                    <label for="photo" class="block text-sm font-medium mb-1">{{ __('ims.field_photo') }}</label>
                    <input id="photo" name="photo" type="file" accept="image/jpeg,image/png" required
                           class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-accent-soft file:text-accent-soft-ink file:font-semibold file:px-3 file:py-2">
                    @error('photo') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-ink-faint mt-2">{{ __('ims.photo_hint') }}</p>
                    <button type="submit" class="mt-4 rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">{{ __('ims.btn_upload') }}</button>
                </form>
            @endif
        </div>
    </div>

    {{-- Every other lot of the same item in this branch --}}
    <div class="bg-surface border border-border rounded-xl overflow-hidden">
        <h2 class="font-semibold px-5 pt-5 pb-3">{{ __('ims.siblings_title') }}</h2>
        @if ($siblings->isEmpty())
            <p class="px-5 pb-5 text-sm text-ink-muted">{{ __('ims.siblings_empty') }}</p>
        @else
            <div class="overflow-x-auto" tabindex="0">
                <table class="w-full text-sm">
                    <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="px-4 py-3 font-semibold">{{ __('ims.col_lot') }}</th>
                            <th class="px-4 py-3 font-semibold text-right">{{ __('ims.col_remaining') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('ims.col_location') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('ims.col_expiry') }}</th>
                            <th class="px-4 py-3 font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($siblings as $other)
                            @php $p = $other->packsRemaining(); @endphp
                            <tr>
                                <td class="px-4 py-3">{{ $other->lot_no ?: '—' }}</td>
                                <td class="px-4 py-3 text-right font-mono">
                                    @if ($p !== null)
                                        {{ $fmt($p) }} {{ $word }} × {{ $fmt($other->pack_size_base) }} {{ $unit }}
                                    @else
                                        {{ $fmt($other->qty_remaining_base) }} {{ $unit }}
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $other->location?->name ?: '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $other->expiry_date?->format('d/m/Y') ?: '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('ims.lots.edit', $other) }}" class="text-xs font-semibold text-accent hover:text-accent-strong">{{ __('ims.btn_details') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-layout>
