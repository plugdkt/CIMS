<div>
    @php
        $exportQuery = array_filter([
            'from' => $dateFrom,
            'to' => $dateTo,
            'type' => $txnType,
            'receiver' => $receiverName,
            'container' => $containerBarcode,
            'unit' => $displayUnitId,
        ]);
    @endphp

    <div class="mb-5">
        <a href="{{ route('items.show', $item) }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('ledger.back_to_item') }}</a>
        <div class="flex items-center justify-between mt-1">
            <h1 class="font-display text-lg font-bold">{{ __('ledger.title') }}</h1>
            <div class="flex items-center gap-3">
                <a href="{{ route('items.ledger.export.pdf', $item) }}?{{ http_build_query($exportQuery) }}" target="_blank"
                   class="text-xs font-semibold text-accent hover:text-accent-strong whitespace-nowrap">
                    {{ __('ledger.export_pdf') }}
                </a>
                <a href="{{ route('items.ledger.export.excel', $item) }}?{{ http_build_query($exportQuery) }}"
                   class="text-xs font-semibold text-accent hover:text-accent-strong whitespace-nowrap">
                    {{ __('ledger.export_excel') }}
                </a>
            </div>
        </div>
    </div>

    <div class="bg-surface border border-border rounded-xl p-6 mb-6">
        <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
            <div>
                <dt class="text-xs text-ink-faint">{{ __('ledger.header_category') }}</dt>
                <dd>{{ $item->category?->name_th }}</dd>
            </div>
            <div class="col-span-2">
                <dt class="text-xs text-ink-faint">{{ __('ledger.header_item') }}</dt>
                <dd class="font-medium">{{ $item->name_th }} <span class="text-ink-muted font-mono text-xs">({{ $item->item_code }})</span></dd>
            </div>
            <div>
                <dt class="text-xs text-ink-faint">{{ __('ledger.header_brand') }}</dt>
                <dd>{{ $item->brand ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-ink-faint">{{ __('ledger.header_grade') }}</dt>
                <dd>{{ $item->grade ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-ink-faint">{{ __('ledger.header_physical_state') }}</dt>
                <dd>
                    @if ($item->physical_state)
                        @php
                            $stateBadge = match($item->physical_state) {
                                'liquid' => ['label' => __('items.state_liquid'), 'icon' => '💧'],
                                'powder' => ['label' => __('items.state_powder'), 'icon' => '🧂'],
                                'solid' => ['label' => __('items.state_solid'), 'icon' => '🧊'],
                                'solution' => ['label' => __('items.state_solution'), 'icon' => '🧪'],
                                'gas' => ['label' => __('items.state_gas'), 'icon' => '💨'],
                                'crystal' => ['label' => __('items.state_crystal'), 'icon' => '💎'],
                                'pellet' => ['label' => __('items.state_pellet'), 'icon' => '⚪'],
                                default => ['label' => $item->physical_state, 'icon' => '•'],
                            };
                        @endphp
                        <span>{{ $stateBadge['icon'] }} {{ $stateBadge['label'] }}</span>
                    @else
                        <span>—</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs text-ink-faint">{{ __('ledger.header_package_size') }}</dt>
                <dd>{{ $item->package_size ?: '—' }} {{ $item->packageUnit?->code }}</dd>
            </div>
            <div>
                <dt class="text-xs text-ink-faint">{{ __('ledger.header_sub_unit') }}</dt>
                <dd>{{ $item->subUnit?->code ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-ink-faint">{{ __('ledger.header_unit') }}</dt>
                <dd>{{ $item->baseUnit?->code }}</dd>
            </div>
        </dl>
    </div>

    <div class="bg-surface border border-border rounded-xl p-4 mb-4">
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            <div>
                <label class="block text-xs font-medium mb-1" for="ledger_date_from">{{ __('ledger.filter_date_from') }}</label>
                <input type="date" id="ledger_date_from" wire:model.live="dateFrom" class="w-full rounded-lg border border-border bg-surface px-2 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" for="ledger_date_to">{{ __('ledger.filter_date_to') }}</label>
                <input type="date" id="ledger_date_to" wire:model.live="dateTo" class="w-full rounded-lg border border-border bg-surface px-2 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" for="ledger_txn_type">{{ __('ledger.filter_txn_type') }}</label>
                <select id="ledger_txn_type" wire:model.live="txnType" class="w-full rounded-lg border border-border bg-surface px-2 py-1.5 text-sm">
                    <option value="">{{ __('ledger.filter_all_types') }}</option>
                    @foreach (['OPENING', 'RECEIVE', 'ISSUE', 'RETURN', 'ADJUST_IN', 'ADJUST_OUT', 'DISPOSE', 'TRANSFER_IN', 'TRANSFER_OUT'] as $type)
                        <option value="{{ $type }}">{{ __('ledger.txn_'.strtolower($type)) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" for="ledger_receiver">{{ __('ledger.filter_receiver') }}</label>
                <input type="text" id="ledger_receiver" wire:model.live.debounce.300ms="receiverName" class="w-full rounded-lg border border-border bg-surface px-2 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" for="ledger_container">{{ __('ledger.filter_container') }}</label>
                <input type="text" id="ledger_container" wire:model.live.debounce.300ms="containerBarcode" class="w-full rounded-lg border border-border bg-surface px-2 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" for="ledger_display_unit">{{ __('ledger.filter_display_unit') }}</label>
                <select id="ledger_display_unit" wire:model.live="displayUnitId" class="w-full rounded-lg border border-border bg-surface px-2 py-1.5 text-sm">
                    @foreach ($availableUnits as $unit)
                        <option value="{{ $unit->id }}">{{ $unit->code }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="bg-surface border border-border rounded-xl overflow-hidden">
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-3 py-2 font-semibold whitespace-nowrap">{{ __('ledger.col_date') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('ledger.col_txn_type') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('ledger.col_issuer') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('ledger.col_receiver') }}</th>
                        <th class="px-3 py-2 font-semibold text-right">{{ __('ledger.col_in') }}</th>
                        <th class="px-3 py-2 font-semibold text-right">{{ __('ledger.col_out') }}</th>
                        <th class="px-3 py-2 font-semibold text-right">{{ __('ledger.col_balance') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('ledger.col_signature') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('ledger.col_remark') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($rows as $row)
                        <tr wire:key="ledger-{{ $row->id }}">
                            <td class="px-3 py-2 align-top whitespace-nowrap">{{ $row->txnDate->format('d/m/Y') }}</td>
                            <td class="px-3 py-2 align-top whitespace-nowrap">{{ __('ledger.txn_'.strtolower($row->txnType)) }}</td>
                            <td class="px-3 py-2 align-top">{{ $row->issuerName ?: '—' }}</td>
                            <td class="px-3 py-2 align-top">{{ $row->receiverName ?: '—' }}</td>
                            <td class="px-3 py-2 align-top text-right font-mono">{{ $row->qtyIn !== null ? "{$row->qtyIn} {$displayUnit->code}" : '—' }}</td>
                            <td class="px-3 py-2 align-top text-right font-mono">{{ $row->qtyOut !== null ? "{$row->qtyOut} {$displayUnit->code}" : '—' }}</td>
                            <td class="px-3 py-2 align-top text-right font-mono">{{ $row->balance }} {{ $displayUnit->code }}</td>
                            <td class="px-3 py-2 align-top whitespace-nowrap">
                                @if ($row->signed)
                                    <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-success-soft text-success-ink">{{ __('ledger.signed_yes') }}</span>
                                @else
                                    {{ __('ledger.signed_no') }}
                                @endif
                            </td>
                            <td class="px-3 py-2 align-top text-ink-muted">{{ $row->remark ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-ink-muted text-sm">
                                {{ __('ledger.no_results') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $rows->links() }}
    </div>
</div>
