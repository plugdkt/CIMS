<x-layout>
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
                        <th class="px-4 py-3 font-semibold text-right">{{ __('ims.col_qty') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_unit') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('ims.col_price') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_expiry') }}</th>
                        <th class="px-4 py-3 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($receipt->lines as $line)
                        <tr>
                            <td class="px-4 py-3 text-ink-muted">{{ $line->line_no }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $line->item?->item_code ?? $line->item_code_raw }}</td>
                            <td class="px-4 py-3">{{ $line->item?->name_th ?? $line->name_raw }}</td>
                            <td class="px-4 py-3">{{ $line->lot_no ?: '—' }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ rtrim(rtrim((string) $line->qty, '0'), '.') }}</td>
                            <td class="px-4 py-3">{{ $line->unit?->code }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ $line->unit_price !== null ? number_format((float) $line->unit_price, 2) : '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $line->expiry_date?->format('d/m/Y') ?: '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                @can('update', $receipt)
                                    <form method="POST" action="{{ route('ims.receipts.lines.destroy', [$receipt, $line]) }}">
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
                <label for="qty" class="block text-sm font-medium mb-1">{{ __('ims.field_qty') }}</label>
                <input id="qty" name="qty" type="number" step="any" min="0" required value="{{ old('qty') }}"
                       class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                @error('qty') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="unit_id" class="block text-sm font-medium mb-1">{{ __('ims.field_unit') }}</label>
                <select id="unit_id" name="unit_id" required class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}" @selected((int) old('unit_id') === $unit->id)>{{ $unit->code }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="unit_price" class="block text-sm font-medium mb-1">{{ __('ims.field_price') }}</label>
                <input id="unit_price" name="unit_price" type="number" step="any" min="0" value="{{ old('unit_price') }}"
                       class="w-full rounded-lg border border-border px-3 py-2 text-sm">
            </div>
            <div>
                <label for="expiry_date" class="block text-sm font-medium mb-1">{{ __('ims.field_expiry') }}</label>
                <input id="expiry_date" name="expiry_date" type="date" value="{{ old('expiry_date') }}"
                       class="w-full rounded-lg border border-border px-3 py-2 text-sm">
            </div>
            <div class="col-span-2">
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
