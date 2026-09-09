<x-layout>
    <div class="max-w-4xl">
        <div class="mb-5">
            <a href="{{ route('goods-receipts.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('goods_receipts.back_to_list') }}</a>
            <div class="flex items-center justify-between mt-1">
                <h1 class="font-display text-lg font-bold">{{ $goodsReceipt->doc_no }}</h1>
                @php
                    $badge = match ($goodsReceipt->status) {
                        'CONFIRMED' => 'bg-success-soft text-success-ink',
                        'CANCELLED' => 'bg-danger-soft text-danger-ink',
                        default => 'bg-neutral-soft text-neutral-ink',
                    };
                @endphp
                <span class="px-2.5 py-1 rounded-full text-xs font-semibold whitespace-nowrap {{ $badge }}">
                    {{ __('goods_receipts.status_'.strtolower($goodsReceipt->status)) }}
                </span>
            </div>
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

        <div class="bg-surface border border-border rounded-xl p-6">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('goods_receipts.field_receipt_date') }}</dt>
                    <dd>{{ $goodsReceipt->receipt_date->format('d/m/Y') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('goods_receipts.field_lab') }}</dt>
                    <dd>{{ $goodsReceipt->lab?->name_th }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('goods_receipts.field_po_no') }}</dt>
                    <dd>{{ $goodsReceipt->po_no ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('goods_receipts.field_invoice_no') }}</dt>
                    <dd>{{ $goodsReceipt->invoice_no ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('goods_receipts.field_supplier') }}</dt>
                    <dd>{{ $goodsReceipt->supplier ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('goods_receipts.col_received_by') }}</dt>
                    <dd>{{ $goodsReceipt->receivedBy?->full_name }}</dd>
                </div>
            </dl>
        </div>

        @if ($goodsReceipt->status === 'CONFIRMED')
            <div class="bg-surface border border-border rounded-xl p-6 mt-6">
                <h2 class="font-display text-base font-bold mb-1">{{ __('goods_receipts.labels_title') }}</h2>
                <p class="text-xs text-ink-faint mb-3">{{ __('goods_receipts.labels_hint') }}</p>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('goods-receipts.labels', [$goodsReceipt, '40x25']) }}" target="_blank"
                       class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-4 py-2.5">
                        {{ __('goods_receipts.print_labels_40x25') }}
                    </a>
                    <a href="{{ route('goods-receipts.labels', [$goodsReceipt, '50x30']) }}" target="_blank"
                       class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-4 py-2.5">
                        {{ __('goods_receipts.print_labels_50x30') }}
                    </a>
                </div>
            </div>
        @endif

        <div class="bg-surface border border-border rounded-xl p-6 mt-6">
            <h2 class="font-display text-base font-bold mb-3">{{ __('goods_receipts.lines_title') }}</h2>

            @if ($goodsReceipt->items->isNotEmpty())
                <div class="overflow-x-auto mb-4" tabindex="0">
                    <table class="w-full text-sm">
                        <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th class="px-3 py-2 font-semibold">#</th>
                                <th class="px-3 py-2 font-semibold">{{ __('goods_receipts.field_item') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('goods_receipts.field_container_count') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('goods_receipts.field_qty_per_container') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('goods_receipts.field_qty_total_base') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('goods_receipts.field_lot_no') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('goods_receipts.field_expiry_date') }}</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($goodsReceipt->items as $line)
                                <tr>
                                    <td class="px-3 py-2 align-top text-ink-muted">{{ $line->line_no }}</td>
                                    <td class="px-3 py-2 align-top">{{ $line->item?->name_th }}</td>
                                    <td class="px-3 py-2 align-top">{{ $line->container_count }}</td>
                                    <td class="px-3 py-2 align-top">{{ $line->qty_per_container }} {{ $line->unit?->code }}</td>
                                    <td class="px-3 py-2 align-top font-mono text-xs">{{ $line->qty_total_base }} ({{ $line->item?->baseUnit?->code }})</td>
                                    <td class="px-3 py-2 align-top">{{ $line->lot_no ?: '—' }}</td>
                                    <td class="px-3 py-2 align-top">{{ $line->expiry_date?->format('d/m/Y') ?: '—' }}</td>
                                    <td class="px-3 py-2 align-top text-right">
                                        @if ($canEdit)
                                            <form method="POST" action="{{ route('goods-receipts.items.destroy', [$goodsReceipt, $line]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-xs font-semibold text-danger hover:text-danger-ink">
                                                    {{ __('goods_receipts.remove_line') }}
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-ink-muted mb-4">{{ __('goods_receipts.no_lines') }}</p>
            @endif

            @if ($canEdit)
                <form method="POST" action="{{ route('goods-receipts.items.store', $goodsReceipt) }}" class="grid grid-cols-1 sm:grid-cols-3 gap-3 border-t border-border pt-4">
                    @csrf
                    <div class="sm:col-span-3">
                        <label class="block text-xs font-medium mb-1" for="item_id">{{ __('goods_receipts.field_item') }}</label>
                        <select name="item_id" id="item_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                            <option value="">{{ __('items.select_placeholder') }}</option>
                            @foreach ($items as $item)
                                <option value="{{ $item->id }}">{{ $item->name_th }} ({{ $item->item_code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" for="container_count">{{ __('goods_receipts.field_container_count') }}</label>
                        <input type="number" name="container_count" id="container_count" min="1" value="1" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" for="qty_per_container">{{ __('goods_receipts.field_qty_per_container') }}</label>
                        <input type="text" name="qty_per_container" id="qty_per_container" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" for="unit_id">{{ __('goods_receipts.field_unit') }}</label>
                        <select name="unit_id" id="unit_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                            <option value="">{{ __('items.select_placeholder') }}</option>
                            @foreach ($units as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->code }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" for="lot_no">{{ __('goods_receipts.field_lot_no') }}</label>
                        <input type="text" name="lot_no" id="lot_no" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" for="expiry_date">{{ __('goods_receipts.field_expiry_date') }}</label>
                        <input type="date" name="expiry_date" id="expiry_date" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    </div>
                    <div class="sm:col-span-3">
                        <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">
                            {{ __('goods_receipts.add_line') }}
                        </button>
                    </div>
                </form>
            @endif
        </div>

        @if ($canEdit)
            <div class="flex items-center gap-3 mt-6">
                <form method="POST" action="{{ route('goods-receipts.confirm', $goodsReceipt) }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5"
                            @disabled($goodsReceipt->items->isEmpty())>
                        {{ __('goods_receipts.confirm') }}
                    </button>
                </form>
                <form method="POST" action="{{ route('goods-receipts.cancel', $goodsReceipt) }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-5 py-2.5">
                        {{ __('goods_receipts.cancel') }}
                    </button>
                </form>
            </div>
        @endif
    </div>
</x-layout>
