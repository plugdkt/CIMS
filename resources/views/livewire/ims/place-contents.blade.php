<div>
    @php $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp

    @if ($canManage)
        <section class="bg-surface border-2 border-accent rounded-xl p-5 mb-6">
            <h2 class="font-semibold">{{ __('ims.add_here_title') }}</h2>
            <p class="text-xs text-ink-muted mb-3">{{ __('ims.add_here_hint_live') }}</p>
            <label for="add" class="sr-only">{{ __('ims.add_here_title') }}</label>
            <input id="add" type="search" wire:model.live.debounce.250ms="term" autocomplete="off" placeholder="{{ __('ims.add_here_placeholder') }}"
                   class="w-full max-w-lg rounded-lg border border-border px-3 py-2 text-sm">

            @if (trim($term) !== '')
                <div class="mt-4">
                    @if ($candidates->isEmpty())
                        <p class="text-sm text-ink-muted">
                            {{ __('ims.add_here_none') }}
                            <a href="{{ route('ims.receipts.create') }}" class="font-semibold text-accent hover:text-accent-strong">{{ __('ims.add_here_new_receipt') }}</a>
                        </p>
                    @else
                        <ul class="divide-y divide-border border border-border rounded-lg">
                            @foreach ($candidates as $lot)
                                <li class="flex items-center gap-3 px-3 py-2" wire:key="cand-{{ $lot->id }}">
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-semibold truncate">{{ $lot->item?->name_th }}</div>
                                        <div class="text-xs text-ink-muted">
                                            <span class="font-mono">{{ $lot->item?->item_code }}</span>
                                            @if ($lot->lot_no) · Lot {{ $lot->lot_no }} @endif
                                            · {{ $fmt($lot->qty_remaining_base) }} {{ $lot->item?->baseUnit?->code }}
                                            · {{ __('ims.add_here_now_at') }} {{ $lot->location?->name ?: __('ims.no_location') }}
                                        </div>
                                    </div>
                                    <button type="button" wire:click="add({{ $lot->id }})" wire:loading.attr="disabled"
                                            class="shrink-0 rounded-lg bg-success hover:bg-success-ink text-white text-sm font-bold px-4 py-1.5">+ {{ __('ims.add_here_btn') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </section>
    @endif

    <div class="flex flex-wrap items-center gap-3 mb-5">
        <label for="filter" class="sr-only">{{ __('ims.places_search_here') }}</label>
        <input id="filter" type="search" wire:model.live.debounce.250ms="filter" autocomplete="off" placeholder="{{ __('ims.places_search_here') }}"
               class="w-80 max-w-full rounded-lg border border-border px-3 py-2 text-sm">
        @if ($canManage)
            <a href="{{ route('ims.lots.locate') }}" class="ml-auto rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-4 py-2">{{ __('ims.locate_open') }}</a>
        @endif
    </div>

    <div class="grid grid-cols-2 gap-3 mb-5 max-w-md">
        <div class="rounded-xl border border-border bg-surface-alt px-4 py-3">
            <div class="text-xs text-ink-muted">{{ __('ims.places_in_ims') }}</div>
            <div class="font-mono text-2xl font-bold">{{ count($ims) }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface-alt px-4 py-3">
            <div class="text-xs text-ink-muted">{{ __('ims.places_in_working') }}</div>
            <div class="font-mono text-2xl font-bold">{{ count($working) }}</div>
        </div>
    </div>

    <section class="bg-surface border border-border rounded-xl overflow-hidden mb-6">
        <h2 class="font-semibold px-5 pt-4 pb-3">{{ __('ims.places_ims_title') }}</h2>
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_item_code') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_item') }}</th>
                        <th class="px-4 py-3 font-semibold">Lot</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('ims.col_remaining') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_expiry') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_location') }}</th>
                        @if ($canManage) <th class="px-4 py-3"><span class="sr-only">{{ __('ims.remove_here') }}</span></th> @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($ims as $row)
                        <tr wire:key="ims-{{ $row['lot_id'] }}-{{ $row['group_id'] ?? 'lot' }}">
                            <td class="px-4 py-3 font-mono text-xs">{{ $row['code'] }}</td>
                            <td class="px-4 py-3">{{ $row['name'] }}</td>
                            <td class="px-4 py-3">{{ $row['lot_no'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-right font-mono whitespace-nowrap">
                                @if ($row['packs'] !== null && $row['size'] !== null)
                                    {{ $fmt($row['packs']) }} {{ $row['word'] }} × {{ $fmt($row['size']) }} {{ $row['unit'] }}
                                @else
                                    {{ $fmt($row['qty']) }} {{ $row['unit'] }}
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $row['expiry'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-xs text-ink-muted">{{ $row['place'] }}</td>
                            @if ($canManage)
                                <td class="px-4 py-3 text-right">
                                    <button type="button"
                                            @if ($row['group_id'] !== null) wire:click="removeGroup({{ $row['group_id'] }})" @else wire:click="removeLot({{ $row['lot_id'] }})" @endif
                                            wire:confirm="{{ __('ims.remove_here_confirm', ['name' => $row['name']]) }}"
                                            class="rounded-lg border border-danger/40 text-danger-ink hover:bg-danger-soft text-xs font-semibold px-3 py-1.5">{{ __('ims.remove_here') }}</button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canManage ? 7 : 6 }}" class="px-4 py-6 text-center text-ink-muted">{{ __('ims.places_empty_ims') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="bg-surface border border-border rounded-xl overflow-hidden">
        <h2 class="font-semibold px-5 pt-4 pb-3">{{ __('ims.places_working_title') }}</h2>
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_item_code') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_item') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('stock.col_barcode') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('ims.col_remaining') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_expiry') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_location') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($working as $row)
                        <tr wire:key="wk-{{ $row['barcode'] }}">
                            <td class="px-4 py-3 font-mono text-xs">{{ $row['code'] }}</td>
                            <td class="px-4 py-3">{{ $row['name'] }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $row['barcode'] }}</td>
                            <td class="px-4 py-3 text-right font-mono whitespace-nowrap">{{ $fmt($row['qty']) }} {{ $row['unit'] }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $row['expiry'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-xs text-ink-muted">{{ $row['place'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-ink-muted">{{ __('ims.places_empty_working') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
