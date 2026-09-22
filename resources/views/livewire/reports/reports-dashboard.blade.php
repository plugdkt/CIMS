<div>
    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('reports.index_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('reports.index_subtitle') }}</p>
    </div>

    @php
        $tabs = [
            'item_stock_summary' => __('reports.item_stock_summary_title'),
            'item_issue_history' => __('reports.item_issue_history_title'),
            'usage_summary' => __('reports.usage_summary_title'),
            'expiring_stock' => __('reports.expiring_stock_title'),
            'below_reorder' => __('reports.below_reorder_title'),
            'dead_stock' => __('reports.dead_stock_title'),
            'controlled_substances' => __('reports.controlled_substances_title'),
            'stock_take_variance' => __('reports.stock_take_variance_title'),
        ];
    @endphp

    <div class="flex flex-wrap gap-2 mb-5 border-b border-border pb-4">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="selectTab('{{ $key }}')"
                    class="px-3 py-1.5 rounded-lg text-sm font-semibold transition-colors {{ $tab === $key ? 'bg-accent text-white' : 'bg-surface-alt text-ink-muted hover:text-ink' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Filters — live, no submit button --}}
    <div class="bg-surface border border-border rounded-xl p-5 mb-5">
        @switch($tab)
            @case('item_stock_summary')
                <p class="text-xs text-ink-muted mb-3">{{ __('reports.item_stock_summary_desc') }}</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <input type="date" wire:model.live="itemStockFrom" aria-label="{{ __('reports.field_from') }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <input type="date" wire:model.live="itemStockTo" aria-label="{{ __('reports.field_to') }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    @if ($restrictedLabId === null)
                        <select wire:model.live="labId" aria-label="{{ __('reports.field_lab') }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                            <option value="">{{ __('reports.all_labs') }}</option>
                            @foreach ($labs as $lab)
                                <option value="{{ $lab->id }}">{{ $lab->name_th }}</option>
                            @endforeach
                        </select>
                    @else
                        <p class="text-xs text-ink-faint self-center">{{ __('reports.restricted_to_own_lab') }}</p>
                    @endif
                </div>
                @break

            @case('item_issue_history')
                <p class="text-xs text-ink-muted mb-3">{{ __('reports.item_issue_history_desc') }}</p>
                @if ($historyItem)
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                        <div>
                            <div class="font-semibold text-sm">{{ $historyItem->name_th }}</div>
                            <div class="text-xs text-ink-faint">{{ $historyItem->item_code }}</div>
                        </div>
                        <button type="button" wire:click="$set('historyItemUlid', null)"
                                class="text-xs font-semibold text-accent hover:text-accent-strong">
                            {{ __('reports.item_issue_history_change') }}
                        </button>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <input type="date" wire:model.live="historyFrom" aria-label="{{ __('reports.field_from') }}"
                               class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                        <input type="date" wire:model.live="historyTo" aria-label="{{ __('reports.field_to') }}"
                               class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                        @if ($restrictedLabId === null)
                            <select wire:model.live="labId" aria-label="{{ __('reports.field_lab') }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                                <option value="">{{ __('reports.all_labs') }}</option>
                                @foreach ($labs as $lab)
                                    <option value="{{ $lab->id }}">{{ $lab->name_th }}</option>
                                @endforeach
                            </select>
                        @else
                            <p class="text-xs text-ink-faint self-center">{{ __('reports.restricted_to_own_lab') }}</p>
                        @endif
                    </div>
                @else
                    <label for="history-item-search" class="block text-xs text-ink-muted mb-2">{{ __('reports.item_issue_history_pick') }}</label>
                    <input type="text" id="history-item-search" wire:model.live.debounce.400ms="historyItemSearch"
                           placeholder="{{ __('reports.item_issue_history_search') }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <div class="mt-3 max-h-64 overflow-y-auto" tabindex="0">
                        <ul class="divide-y divide-border">
                            @foreach ($historyCandidates as $candidate)
                                <li>
                                    <button type="button" wire:click="$set('historyItemUlid', '{{ $candidate->ulid }}')"
                                            class="w-full text-left px-2 py-2 hover:bg-surface-alt rounded-lg">
                                        <span class="text-sm">{{ $candidate->name_th }}</span>
                                        <span class="text-xs text-ink-faint ml-2">{{ $candidate->item_code }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @break

            @case('usage_summary')
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    <input type="text" wire:model.live.debounce.400ms="usageRequesterName" placeholder="{{ __('reports.field_requester') }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <input type="text" wire:model.live.debounce.400ms="usagePurposeDetail" placeholder="{{ __('reports.field_purpose_detail') }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <input type="text" wire:model.live.debounce.400ms="usageFaculty" placeholder="{{ __('reports.field_faculty') }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <input type="date" wire:model.live="usageFrom" aria-label="{{ __('reports.field_from') }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <input type="date" wire:model.live="usageTo" aria-label="{{ __('reports.field_to') }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                </div>
                @break

            @case('expiring_stock')
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <input type="date" wire:model.live="expiringFrom" aria-label="{{ __('reports.field_from') }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <input type="date" wire:model.live="expiringTo" aria-label="{{ __('reports.field_to') }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    @if ($restrictedLabId === null)
                        <select wire:model.live="labId" aria-label="{{ __('reports.field_lab') }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                            <option value="">{{ __('reports.all_labs') }}</option>
                            @foreach ($labs as $lab)
                                <option value="{{ $lab->id }}">{{ $lab->name_th }}</option>
                            @endforeach
                        </select>
                    @else
                        <p class="text-xs text-ink-faint self-center">{{ __('reports.restricted_to_own_lab') }}</p>
                    @endif
                </div>
                @break

            @case('below_reorder')
            @case('dead_stock')
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    @if ($restrictedLabId === null)
                        <select wire:model.live="labId" aria-label="{{ __('reports.field_lab') }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                            <option value="">{{ __('reports.all_labs') }}</option>
                            @foreach ($labs as $lab)
                                <option value="{{ $lab->id }}">{{ $lab->name_th }}</option>
                            @endforeach
                        </select>
                    @else
                        <p class="text-xs text-ink-faint self-center">{{ __('reports.restricted_to_own_lab') }}</p>
                    @endif
                </div>
                @break

            @case('controlled_substances')
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <input type="date" wire:model.live="controlledFrom" aria-label="{{ __('reports.field_from') }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <input type="date" wire:model.live="controlledTo" aria-label="{{ __('reports.field_to') }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    @if ($restrictedLabId === null)
                        <select wire:model.live="labId" aria-label="{{ __('reports.field_lab') }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                            <option value="">{{ __('reports.all_labs') }}</option>
                            @foreach ($labs as $lab)
                                <option value="{{ $lab->id }}">{{ $lab->name_th }}</option>
                            @endforeach
                        </select>
                    @else
                        <p class="text-xs text-ink-faint self-center">{{ __('reports.restricted_to_own_lab') }}</p>
                    @endif
                </div>
                @break

            @case('stock_take_variance')
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    @if ($stockTakes->isEmpty())
                        <p class="text-xs text-ink-faint">{{ __('reports.no_results') }}</p>
                    @else
                        <select wire:model.live="stockTakeUlid" aria-label="{{ __('reports.field_stock_take') }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                            <option value="">{{ __('items.select_placeholder') }}</option>
                            @foreach ($stockTakes as $stockTake)
                                <option value="{{ $stockTake->ulid }}">{{ $stockTake->doc_no }} — {{ $stockTake->lab?->name_th }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
                @break
        @endswitch
    </div>

    {{-- Export — same download routes as before, reflecting the current filters --}}
    <div class="flex flex-wrap gap-2 mb-5">
        @switch($tab)
            @case('item_stock_summary')
                <a href="{{ route('reports.item-stock-summary.excel', ['from' => $itemStockFrom, 'to' => $itemStockTo, 'lab_id' => $labId]) }}"
                   class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2">{{ __('reports.download_excel') }}</a>
                @break
            @case('item_issue_history')
                @if ($historyItem)
                    <a href="{{ route('reports.item-issue-history.excel', ['item' => $historyItem, 'from' => $historyFrom, 'to' => $historyTo, 'lab_id' => $labId]) }}"
                       class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2">{{ __('reports.download_excel') }}</a>
                @endif
                @break
            @case('usage_summary')
                <a href="{{ route('reports.usage-summary.excel', ['requester_name' => $usageRequesterName, 'purpose_detail' => $usagePurposeDetail, 'faculty' => $usageFaculty, 'from' => $usageFrom, 'to' => $usageTo, 'lab_id' => $labId]) }}"
                   class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2">{{ __('reports.download_excel') }}</a>
                @break
            @case('expiring_stock')
                <a href="{{ route('reports.expiring-stock.excel', ['from' => $expiringFrom, 'to' => $expiringTo, 'lab_id' => $labId]) }}"
                   class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2">{{ __('reports.download_excel') }}</a>
                @break
            @case('below_reorder')
                <a href="{{ route('reports.below-reorder-point.excel', ['lab_id' => $labId]) }}"
                   class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2">{{ __('reports.download_excel') }}</a>
                @break
            @case('dead_stock')
                <a href="{{ route('reports.dead-stock.excel', ['lab_id' => $labId]) }}"
                   class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2">{{ __('reports.download_excel') }}</a>
                @break
            @case('controlled_substances')
                <a href="{{ route('reports.controlled-substances.excel', ['from' => $controlledFrom, 'to' => $controlledTo, 'lab_id' => $labId]) }}"
                   class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2">{{ __('reports.download_excel') }}</a>
                <a href="{{ route('reports.controlled-substances.pdf', ['from' => $controlledFrom, 'to' => $controlledTo, 'lab_id' => $labId]) }}"
                   class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-4 py-2">{{ __('reports.download_pdf') }}</a>
                @break
            @case('stock_take_variance')
                @if ($stockTakeUlid)
                    <a href="{{ url('reports/stock-takes').'/'.$stockTakeUlid.'/excel' }}"
                       class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2">{{ __('reports.download_excel') }}</a>
                    <a href="{{ url('reports/stock-takes').'/'.$stockTakeUlid.'/pdf' }}"
                       class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-4 py-2">{{ __('reports.download_pdf') }}</a>
                @endif
                @break
        @endswitch
    </div>

    @if ($tab === 'stock_take_variance' && ! $stockTakeUlid)
        <p class="text-sm text-ink-muted">{{ __('reports.select_stock_take_first') }}</p>
    @elseif ($tab === 'item_issue_history' && ! $historyItem)
        <p class="text-sm text-ink-muted">{{ __('reports.item_issue_history_pick') }}</p>
    @else
        @if ($tab === 'item_issue_history' && $historySummary)
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">
                <div class="bg-surface border border-border rounded-xl p-5">
                    <div class="text-xs text-ink-muted">{{ __('reports.summary_total_issued') }}</div>
                    <div class="font-display text-2xl font-bold mt-1">
                        {{ rtrim(rtrim((string) $historySummary['issued'], '0'), '.') ?: '0' }}
                        <span class="text-sm font-normal text-ink-muted">{{ $historySummary['unit'] }}</span>
                    </div>
                </div>
                <div class="bg-surface border border-border rounded-xl p-5">
                    <div class="text-xs text-ink-muted">{{ __('reports.summary_balance') }}</div>
                    <div class="font-display text-2xl font-bold mt-1 {{ (float) $historySummary['balance'] <= 0.0 ? 'text-danger' : '' }}">
                        {{ rtrim(rtrim((string) $historySummary['balance'], '0'), '.') ?: '0' }}
                        <span class="text-sm font-normal text-ink-muted">{{ $historySummary['unit'] }}</span>
                    </div>
                </div>
            </div>
        @endif
        {{-- Table --}}
        <div class="bg-surface border border-border rounded-xl p-5">
            <h2 class="font-semibold text-sm mb-3">{{ __('reports.table_title') }}</h2>
            @if ($rows->isEmpty())
                <p class="text-xs text-ink-faint">
                    {{ $tab === 'item_issue_history' ? __('reports.item_issue_history_empty') : __('reports.no_results') }}
                </p>
            @else
                <div class="overflow-x-auto" tabindex="0">
                    <table class="w-full text-xs">
                        @switch($tab)
                            @case('item_issue_history')
                                <thead class="text-left text-ink-faint uppercase tracking-wide">
                                    <tr>
                                        <th class="py-1 pr-3">{{ __('reports.col_date') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_doc_no') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_requester') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_faculty') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_qty_issued') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach ($rows as $row)
                                        @php
                                            $line = $row->requisitionItem;
                                            $requisition = $line?->requisition;
                                        @endphp
                                        <tr>
                                            <td class="py-1 pr-3 whitespace-nowrap">{{ $row->issued_at?->format('d/m/Y') }}</td>
                                            <td class="py-1 pr-3 font-mono whitespace-nowrap">{{ $requisition?->doc_no }}</td>
                                            <td class="py-1 pr-3">{{ $requisition?->requester?->full_name }}</td>
                                            <td class="py-1 pr-3">{{ $requisition?->faculty }}</td>
                                            <td class="py-1 pr-3 whitespace-nowrap">
                                                {{ rtrim(rtrim((string) $row->qty_issued_base, '0'), '.') }}
                                                {{ $historyItem?->baseUnit?->code }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                @break

                            @case('item_stock_summary')
                                <thead class="text-left text-ink-faint uppercase tracking-wide">
                                    <tr>
                                        <th class="py-1 pr-3">{{ __('reports.col_item_code') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_item') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_used_qty') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_current_balance') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_stock_status') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach ($rows as $row)
                                        @php $item = $row['item']; @endphp
                                        <tr>
                                            <td class="py-1 pr-3 font-mono">{{ $item->item_code }}</td>
                                            <td class="py-1 pr-3">{{ $item->name_th }}</td>
                                            <td class="py-1 pr-3">{{ rtrim(rtrim((string) $row['used_qty_base'], '0'), '.') }} {{ $item->baseUnit?->code }}</td>
                                            <td class="py-1 pr-3 {{ (float) $row['remaining_base'] <= 0.0 ? 'text-danger font-semibold' : '' }}">
                                                {{ rtrim(rtrim((string) $row['remaining_base'], '0'), '.') }} {{ $item->baseUnit?->code }}
                                            </td>
                                            <td class="py-1 pr-3">
                                                @if ($row['low_stock'])
                                                    <span class="inline-flex items-center gap-1 text-danger font-semibold whitespace-nowrap">
                                                        ⚠️ {{ __('reports.low_stock_warning', ['percent' => rtrim(rtrim((string) $row['remaining_percent'], '0'), '.')]) }}
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                @break

                            @case('usage_summary')
                                <thead class="text-left text-ink-faint uppercase tracking-wide">
                                    <tr>
                                        <th class="py-1 pr-3">{{ __('reports.col_date') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_doc_no') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_requester') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_faculty') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_purpose_type') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_item') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_qty_issued') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach ($rows as $row)
                                        @php $requisition = $row->requisitionItem?->requisition; @endphp
                                        <tr>
                                            <td class="py-1 pr-3">{{ $row->issued_at?->format('d/m/Y') }}</td>
                                            <td class="py-1 pr-3 font-mono">{{ $requisition?->doc_no }}</td>
                                            <td class="py-1 pr-3">{{ $requisition?->requester?->full_name }}</td>
                                            <td class="py-1 pr-3">{{ $requisition?->faculty }}</td>
                                            <td class="py-1 pr-3">{{ $requisition ? __('requisitions.purpose_type_'.strtolower($requisition->purpose_type)) : '' }}</td>
                                            <td class="py-1 pr-3">{{ $row->requisitionItem?->item?->name_th }}</td>
                                            <td class="py-1 pr-3">{{ rtrim(rtrim((string) $row->qty_issued_base, '0'), '.') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                @break

                            @case('expiring_stock')
                                <thead class="text-left text-ink-faint uppercase tracking-wide">
                                    <tr>
                                        <th class="py-1 pr-3">{{ __('reports.col_item') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_barcode') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_lot_no') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_expiry_date') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_remaining_qty') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_lab') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_status') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach ($rows as $row)
                                        <tr>
                                            <td class="py-1 pr-3">{{ $row->item?->name_th }}</td>
                                            <td class="py-1 pr-3 font-mono">{{ $row->barcode }}</td>
                                            <td class="py-1 pr-3">{{ $row->lot_no ?: '—' }}</td>
                                            <td class="py-1 pr-3">{{ $row->expiry_date?->format('d/m/Y') }}</td>
                                            <td class="py-1 pr-3">{{ rtrim(rtrim((string) $row->remaining_qty_base, '0'), '.') }}</td>
                                            <td class="py-1 pr-3">{{ $row->labNameOrEmpty() ?: '—' }}</td>
                                            <td class="py-1 pr-3">{{ __('reports.status_'.strtolower($row->status)) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                @break

                            @case('below_reorder')
                                <thead class="text-left text-ink-faint uppercase tracking-wide">
                                    <tr>
                                        <th class="py-1 pr-3">{{ __('reports.col_item_code') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_item') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_current_balance') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_reorder_point') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach ($rows as $row)
                                        @php $item = $row['item']; @endphp
                                        <tr>
                                            <td class="py-1 pr-3 font-mono">{{ $item->item_code }}</td>
                                            <td class="py-1 pr-3">{{ $item->name_th }}</td>
                                            <td class="py-1 pr-3 text-danger">{{ rtrim(rtrim((string) $row['current_balance_base'], '0'), '.') }} {{ $item->baseUnit?->code }}</td>
                                            <td class="py-1 pr-3">{{ rtrim(rtrim((string) $item->reorder_point_base, '0'), '.') }} {{ $item->baseUnit?->code }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                @break

                            @case('dead_stock')
                                <thead class="text-left text-ink-faint uppercase tracking-wide">
                                    <tr>
                                        <th class="py-1 pr-3">{{ __('reports.col_item') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_barcode') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_lab') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_remaining_qty') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_last_movement') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach ($rows as $row)
                                        @php $container = $row['container']; @endphp
                                        <tr>
                                            <td class="py-1 pr-3">{{ $container->item?->name_th }}</td>
                                            <td class="py-1 pr-3 font-mono">{{ $container->barcode }}</td>
                                            <td class="py-1 pr-3">{{ $container->labNameOrEmpty() ?: '—' }}</td>
                                            <td class="py-1 pr-3">{{ rtrim(rtrim((string) $container->remaining_qty_base, '0'), '.') }}</td>
                                            <td class="py-1 pr-3">{{ $row['last_movement_date'] ? \Illuminate\Support\Carbon::parse($row['last_movement_date'])->format('d/m/Y') : __('reports.never_moved') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                @break

                            @case('controlled_substances')
                                <thead class="text-left text-ink-faint uppercase tracking-wide">
                                    <tr>
                                        <th class="py-1 pr-3">{{ __('reports.col_date') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_item') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_control_class') }}</th>
                                        <th class="py-1 pr-3">{{ __('ledger.col_txn_type') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_qty_in') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_qty_out') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_balance') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_created_by') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach ($rows as $row)
                                        <tr>
                                            <td class="py-1 pr-3">{{ $row->txn_date?->format('d/m/Y') }}</td>
                                            <td class="py-1 pr-3">{{ $row->item?->name_th }}</td>
                                            <td class="py-1 pr-3">{{ $row->item?->control_class }}</td>
                                            <td class="py-1 pr-3">{{ __('ledger.txn_'.strtolower($row->txn_type)) }}</td>
                                            <td class="py-1 pr-3">{{ rtrim(rtrim((string) $row->qty_in_base, '0'), '.') }}</td>
                                            <td class="py-1 pr-3">{{ rtrim(rtrim((string) $row->qty_out_base, '0'), '.') }}</td>
                                            <td class="py-1 pr-3">{{ rtrim(rtrim((string) $row->balance_base, '0'), '.') }}</td>
                                            <td class="py-1 pr-3">{{ $row->creator?->full_name }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                @break

                            @case('stock_take_variance')
                                <thead class="text-left text-ink-faint uppercase tracking-wide">
                                    <tr>
                                        <th class="py-1 pr-3">{{ __('reports.col_item') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_barcode') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_system_qty') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_counted_qty') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_diff') }}</th>
                                        <th class="py-1 pr-3">{{ __('reports.col_counted_by') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach ($rows as $row)
                                        <tr>
                                            <td class="py-1 pr-3">{{ $row->container?->item?->name_th }}</td>
                                            <td class="py-1 pr-3 font-mono">{{ $row->container?->barcode }}</td>
                                            <td class="py-1 pr-3">{{ rtrim(rtrim((string) $row->system_qty_base, '0'), '.') }}</td>
                                            <td class="py-1 pr-3">{{ $row->counted_qty_base !== null ? rtrim(rtrim((string) $row->counted_qty_base, '0'), '.') : __('reports.not_counted') }}</td>
                                            <td class="py-1 pr-3">{{ $row->diff_base !== null ? rtrim(rtrim((string) $row->diff_base, '0'), '.') : '—' }}</td>
                                            <td class="py-1 pr-3">{{ $row->countedBy?->full_name ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                @break
                        @endswitch
                    </table>
                </div>
                <p class="text-xs text-ink-faint mt-2">{{ __('reports.showing_of_total', ['shown' => $rows->count(), 'total' => $total]) }}</p>
            @endif
        </div>
    @endif
</div>
