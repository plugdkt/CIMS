<x-layout>
    @php
        $fmt = fn ($v) => $v === null ? '—' : (rtrim(rtrim((string) $v, '0'), '.') ?: '0');
        $summary = $receipt->import_summary;
    @endphp

    <a href="{{ route('ims.receipts.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('ims.back') }}</a>

    <div class="flex items-start justify-between gap-4 mt-1 mb-5">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('ims.show_title') }} {{ $receipt->doc_no }}</h1>
            <p class="text-sm text-ink-muted mt-1">
                {{ $receipt->lab?->name_th }} · {{ __('ims.col_fiscal_year') }} {{ $receipt->fiscal_year ?: '—' }}
                · {{ __('ims.col_round') }} {{ $receipt->purchase_round ?: '—' }}
                · {{ __('ims.status_'.strtolower($receipt->status)) }}
            </p>
            @if ($receipt->source_file_path)
                <a href="{{ route('ims.receipts.source', $receipt) }}" target="_blank" class="text-sm font-semibold text-accent hover:text-accent-strong">
                    {{ __('ims.source_file') }}: {{ $receipt->source_file_name }}
                </a>
            @endif
        </div>
    </div>

    @error('confirm')
        <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">{{ $message }}</div>
    @enderror

    @if ($summary)
        <div class="mb-6 bg-surface border border-border rounded-xl p-5">
            <h2 class="font-semibold mb-2">{{ __('ims.import_title') }}</h2>
            <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div><dt class="text-xs text-ink-faint">{{ __('ims.import_period') }}</dt><dd>{{ $summary['period_from'] ?? '—' }} – {{ $summary['period_to'] ?? '—' }}</dd></div>
                <div><dt class="text-xs text-ink-faint">{{ __('ims.import_custodian') }}</dt><dd class="font-mono">{{ $summary['custodian'] ?? '—' }}</dd></div>
                <div><dt class="text-xs text-ink-faint">{{ __('ims.import_rows_total') }}</dt><dd>{{ $summary['rows_total'] }}</dd></div>
                <div><dt class="text-xs text-ink-faint">{{ __('ims.import_imported') }}</dt><dd class="font-semibold">{{ $summary['imported'] }}</dd></div>
                <div><dt class="text-xs text-ink-faint">{{ __('ims.import_no_stock') }}</dt><dd>{{ $summary['no_stock'] }}</dd></div>
                <div><dt class="text-xs text-ink-faint">{{ __('ims.import_unmatched') }}</dt><dd>{{ count($summary['unmatched']) }}</dd></div>
            </dl>

            @if (! empty($summary['unbalanced']))
                <p class="mt-3 text-sm text-warning-ink">{{ __('ims.import_unbalanced') }}: <span class="font-mono">{{ implode(', ', $summary['unbalanced']) }}</span></p>
            @endif

            @if (! empty($summary['unmatched']))
                <details class="mt-3">
                    <summary class="cursor-pointer text-sm font-semibold text-accent">{{ __('ims.import_unmatched') }} ({{ count($summary['unmatched']) }})</summary>
                    <p class="text-xs text-ink-faint mt-2">{{ __('ims.import_unmatched_hint') }}</p>
                    <div class="overflow-x-auto mt-2" tabindex="0">
                        <table class="text-sm">
                            <thead class="text-left text-xs text-ink-faint">
                                <tr>
                                    <th class="pr-6 py-1 font-semibold">{{ __('ims.import_col_code') }}</th>
                                    <th class="pr-6 py-1 font-semibold text-right">{{ __('ims.import_col_packs') }}</th>
                                    <th class="pr-6 py-1 font-semibold text-right">{{ __('ims.import_col_price') }}</th>
                                    <th class="py-1 font-semibold">{{ __('ims.import_col_page') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($summary['unmatched'] as $miss)
                                    <tr>
                                        <td class="pr-6 py-1 font-mono">{{ $miss['code'] }}</td>
                                        <td class="pr-6 py-1 text-right font-mono">{{ $fmt($miss['pack_qty']) }}</td>
                                        <td class="pr-6 py-1 text-right font-mono">{{ $miss['unit_price'] !== null ? number_format((float) $miss['unit_price'], 2) : '—' }}</td>
                                        <td class="py-1">{{ $miss['page'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endif
        </div>
    @endif

    <h2 class="font-semibold mb-2">{{ __('ims.lines_title') }}</h2>
    <div class="bg-surface border border-border rounded-xl overflow-hidden mb-6">
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">#</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_item_code') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_item') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_lot') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('ims.col_pack_qty') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('ims.col_price_per_pack') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('ims.col_total_qty') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_expiry') }}</th>
                        <th class="px-4 py-3 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($receipt->lines as $line)
                        @php $resolved = $line->resolvedQuantity(); @endphp
                        <tr>
                            <td class="px-4 py-3 text-ink-muted align-top">{{ $line->line_no }}</td>
                            <td class="px-4 py-3 font-mono text-xs align-top">{{ $line->item?->item_code ?? $line->item_code_raw }}</td>
                            <td class="px-4 py-3 align-top">{{ $line->item?->name_th ?? $line->name_raw }}</td>
                            <td class="px-4 py-3 align-top">{{ $line->lot_no ?: '—' }}</td>
                            <td class="px-4 py-3 text-right font-mono align-top">{{ $fmt($line->pack_qty) }}</td>
                            <td class="px-4 py-3 text-right font-mono align-top">{{ $line->unit_price !== null ? number_format((float) $line->unit_price, 2) : '—' }}</td>
                            <td class="px-4 py-3 text-right font-mono align-top">
                                @if ($resolved)
                                    {{ $fmt($resolved['qty']) }} {{ $resolved['unit']->code }}
                                @else
                                    <span class="text-warning-ink text-xs font-sans">{{ __('ims.needs_qty') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap align-top">{{ $line->expiry_date?->format('d/m/Y') ?: '—' }}</td>
                            <td class="px-4 py-3 text-right align-top whitespace-nowrap">
                                @can('update', $receipt)
                                    <details class="inline-block text-left">
                                        <summary class="cursor-pointer text-xs font-semibold text-accent">{{ __('ims.btn_edit') }}</summary>
                                        <form method="POST" action="{{ route('ims.receipts.lines.update', [$receipt, $line]) }}"
                                              class="mt-2 grid grid-cols-2 gap-2 w-80 bg-surface-alt rounded-lg p-3">
                                            @csrf @method('PUT')
                                            <div>
                                                <label for="lot_no-{{ $line->id }}" class="block text-xs mb-1">{{ __('ims.field_lot_no') }}</label>
                                                <input id="lot_no-{{ $line->id }}" name="lot_no" type="text" maxlength="64" value="{{ $line->lot_no }}" class="w-full rounded border border-border px-2 py-1 text-sm">
                                            </div>
                                            <div>
                                                <label for="expiry_date-{{ $line->id }}" class="block text-xs mb-1">{{ __('ims.field_expiry') }}</label>
                                                <input id="expiry_date-{{ $line->id }}" name="expiry_date" type="date" value="{{ $line->expiry_date?->toDateString() }}" class="w-full rounded border border-border px-2 py-1 text-sm">
                                            </div>
                                            <div>
                                                <label for="pack_qty-{{ $line->id }}" class="block text-xs mb-1">{{ __('ims.field_pack_qty') }}</label>
                                                <input id="pack_qty-{{ $line->id }}" name="pack_qty" type="number" step="any" min="0" required value="{{ $line->pack_qty }}" class="w-full rounded border border-border px-2 py-1 text-sm">
                                            </div>
                                            <div>
                                                <label for="unit_price-{{ $line->id }}" class="block text-xs mb-1">{{ __('ims.field_price_per_pack') }}</label>
                                                <input id="unit_price-{{ $line->id }}" name="unit_price" type="number" step="any" min="0" value="{{ $line->unit_price }}" class="w-full rounded border border-border px-2 py-1 text-sm">
                                            </div>
                                            <div>
                                                <label for="qty-{{ $line->id }}" class="block text-xs mb-1">{{ __('ims.field_total_qty_optional') }}</label>
                                                <input id="qty-{{ $line->id }}" name="qty" type="number" step="any" min="0" value="{{ $line->qty }}" class="w-full rounded border border-border px-2 py-1 text-sm">
                                            </div>
                                            <div>
                                                <label for="unit_id-{{ $line->id }}" class="block text-xs mb-1">{{ __('ims.field_unit') }}</label>
                                                <select id="unit_id-{{ $line->id }}" name="unit_id" class="w-full rounded border border-border px-2 py-1 text-sm">
                                                    <option value="">—</option>
                                                    @foreach ($units as $unit)
                                                        <option value="{{ $unit->id }}" @selected($line->unit_id === $unit->id)>{{ $unit->code }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-span-2">
                                                <button type="submit" class="rounded bg-accent text-white text-xs font-semibold px-3 py-1.5">{{ __('ims.btn_save') }}</button>
                                            </div>
                                        </form>
                                    </details>
                                    <form method="POST" action="{{ route('ims.receipts.lines.destroy', [$receipt, $line]) }}" class="inline-block ml-2">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs font-semibold text-danger hover:underline">{{ __('ims.btn_remove') }}</button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-ink-muted text-sm">{{ __('ims.no_lines') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @can('update', $receipt)
        <form method="POST" action="{{ route('ims.receipts.lines.store', $receipt) }}"
              class="bg-surface border border-border rounded-xl p-5 mb-6 grid grid-cols-2 md:grid-cols-4 gap-4">
            @csrf
            <div>
                <label for="item_code" class="block text-sm font-medium mb-1">{{ __('ims.field_item_code') }}</label>
                <input id="item_code" name="item_code" type="text" maxlength="64" required value="{{ old('item_code') }}"
                       class="w-full rounded-lg border border-border px-3 py-2 text-sm font-mono">
                @error('item_code') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="lot_no" class="block text-sm font-medium mb-1">{{ __('ims.field_lot_no') }}</label>
                <input id="lot_no" name="lot_no" type="text" maxlength="64" value="{{ old('lot_no') }}"
                       class="w-full rounded-lg border border-border px-3 py-2 text-sm">
            </div>
            <div>
                <label for="pack_qty" class="block text-sm font-medium mb-1">{{ __('ims.field_pack_qty') }}</label>
                <input id="pack_qty" name="pack_qty" type="number" step="any" min="0" required value="{{ old('pack_qty') }}"
                       class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                @error('pack_qty') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="unit_price" class="block text-sm font-medium mb-1">{{ __('ims.field_price_per_pack') }}</label>
                <input id="unit_price" name="unit_price" type="number" step="any" min="0" value="{{ old('unit_price') }}"
                       class="w-full rounded-lg border border-border px-3 py-2 text-sm">
            </div>
            <div>
                <label for="qty" class="block text-sm font-medium mb-1">{{ __('ims.field_total_qty_optional') }}</label>
                <input id="qty" name="qty" type="number" step="any" min="0" value="{{ old('qty') }}"
                       class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                @error('qty') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="unit_id" class="block text-sm font-medium mb-1">{{ __('ims.field_unit') }}</label>
                <select id="unit_id" name="unit_id" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                    <option value="">—</option>
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}" @selected((int) old('unit_id') === $unit->id)>{{ $unit->code }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="expiry_date" class="block text-sm font-medium mb-1">{{ __('ims.field_expiry') }}</label>
                <input id="expiry_date" name="expiry_date" type="date" value="{{ old('expiry_date') }}"
                       class="w-full rounded-lg border border-border px-3 py-2 text-sm">
            </div>
            <div>
                <label for="remark" class="block text-sm font-medium mb-1">{{ __('ims.field_remark') }}</label>
                <input id="remark" name="remark" type="text" maxlength="255" value="{{ old('remark') }}"
                       class="w-full rounded-lg border border-border px-3 py-2 text-sm">
            </div>
            <div class="col-span-2 md:col-span-4">
                <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">
                    {{ __('ims.btn_add_line') }}
                </button>
            </div>
        </form>

        <div class="flex items-center gap-3">
            <form method="POST" action="{{ route('ims.receipts.confirm', $receipt) }}">
                @csrf
                <button type="submit" class="rounded-lg bg-success hover:bg-success-ink text-white text-sm font-semibold px-4 py-2.5">
                    {{ __('ims.btn_confirm') }}
                </button>
            </form>
            <form method="POST" action="{{ route('ims.receipts.cancel', $receipt) }}">
                @csrf
                <button type="submit" class="rounded-lg border border-border text-sm font-semibold px-4 py-2.5 hover:bg-surface-alt">
                    {{ __('ims.btn_cancel') }}
                </button>
            </form>
        </div>
        <p class="text-xs text-ink-faint mt-2">{{ __('ims.confirm_hint') }}</p>
    @endcan
</x-layout>
