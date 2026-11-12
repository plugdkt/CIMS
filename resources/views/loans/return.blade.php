<x-layout>
    {{-- ITM-2610-051 step 3 (FR-LN-21/22): take pieces back line by line; a partial return is fine. --}}
    @php $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp

    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('loans.return_title') }} <span class="font-mono">{{ $loan->doc_no }}</span></h1>
        <div class="mt-1">@include('loans.partials.status-badge', ['loan' => $loan])</div>
        <p class="text-sm text-ink-muted mt-2">{{ __('loans.return_hint') }}</p>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach (array_unique($errors->all()) as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @if ($loan->borrower_id === auth()->id())
        <div class="mb-4 rounded-lg border border-warning/40 bg-warning-soft text-warning-ink px-4 py-3 text-sm">{{ __('loans.self_handout_note') }}</div>
    @endif

    <div class="mb-5 rounded-xl border border-border bg-surface-alt px-4 py-3 text-sm">
        <div><span class="text-ink-faint">{{ __('loans.field_borrower') }}:</span> <span class="font-medium">{{ $loan->borrower?->full_name }}</span></div>
        <div><span class="text-ink-faint">{{ __('loans.field_handed_out_at') }}:</span> {{ $loan->handed_out_at?->format('d/m/Y H:i') }} · <span class="text-ink-faint">{{ __('loans.field_due_date') }}:</span> {{ $loan->due_date->format('d/m/Y') }}</div>
    </div>

    {{--
        The scan box works with a USB barcode scanner (it types the asset tag and presses Enter): the matching line's
        quantity goes up by one, never above what is still out. Enter in the scan box never submits the form. Without
        JavaScript the page still works: type the quantities by hand.
    --}}
    <div x-data="{
            code: '',
            miss: false,
            scan() {
                const code = this.code.trim();
                this.code = '';
                if (code === '') { return; }
                const input = Array.from(this.$root.querySelectorAll('input[data-tag]')).find((el) => el.dataset.tag === code);
                this.miss = ! input;
                if (input) {
                    const max = parseInt(input.max || '1', 10);
                    input.value = Math.min(max, parseInt(input.value || '0', 10) + 1);
                }
                this.$refs.scan.focus();
            },
        }">
        <div class="mb-4">
            <label for="scan" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.return_scan_label') }}</label>
            <input id="scan" x-ref="scan" x-model="code" x-on:keydown.enter.prevent="scan()" type="text" maxlength="64" autocomplete="off" autofocus class="w-full sm:w-80 rounded-lg border border-border px-3 py-2 text-sm font-mono">
            <p class="mt-1 text-xs text-ink-faint">{{ __('loans.return_scan_hint') }}</p>
            <p x-show="miss" x-cloak class="mt-1 text-xs text-danger-ink">{{ __('loans.return_scan_not_found') }}</p>
        </div>

        <form method="POST" action="{{ route('loans.return', $loan) }}">
            @csrf
            <div class="bg-surface border border-border rounded-xl overflow-hidden">
                <div class="overflow-x-auto" tabindex="0">
                    <table class="w-full text-sm">
                        <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th class="px-4 py-3 font-semibold">{{ __('loans.col_item') }}</th>
                                <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_handed_out') }}</th>
                                <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_outstanding') }}</th>
                                <th class="px-4 py-3 font-semibold">{{ __('loans.col_condition_out') }}</th>
                                <th class="px-4 py-3 font-semibold">{{ __('loans.field_return_qty') }}</th>
                                <th class="px-4 py-3 font-semibold">{{ __('loans.field_condition_in') }}</th>
                                <th class="px-4 py-3 font-semibold">{{ __('loans.field_return_note') }}</th>
                                <th class="px-4 py-3 font-semibold">{{ __('loans.field_qty_damaged') }}</th>
                                <th class="px-4 py-3 font-semibold">{{ __('loans.field_qty_lost') }}</th>
                                <th class="px-4 py-3 font-semibold">{{ __('loans.field_incident_note') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($loan->lines as $line)
                                @php $left = $fmt($line->outstanding()); @endphp
                                <tr>
                                    <td class="px-4 py-3 align-top">
                                        <div class="font-medium">{{ $line->asset?->item?->name_th }}</div>
                                        <div class="text-xs font-mono text-ink-faint">{{ $line->asset?->item?->item_code }}@if ($line->asset?->asset_tag) · {{ $line->asset->asset_tag }}@endif</div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($line->qtyOut()) }}</td>
                                    <td class="px-4 py-3 align-top text-right font-mono">{{ $left }}</td>
                                    <td class="px-4 py-3 align-top">
                                        {{ $line->condition_out ? __('loans.condition_'.strtolower($line->condition_out)) : '—' }}
                                        @if ($line->condition_out_note)
                                            <div class="text-xs text-ink-muted">{{ $line->condition_out_note }}</div>
                                        @endif
                                    </td>
                                    @if ($left === '0')
                                        <td class="px-4 py-3 align-top text-ink-faint" colspan="6">{{ __('loans.col_returned') }}</td>
                                    @else
                                        <td class="px-4 py-3 align-top">
                                            <label for="qty_{{ $line->line_no }}" class="sr-only">{{ __('loans.field_return_qty') }}</label>
                                            <input id="qty_{{ $line->line_no }}" name="lines[{{ $line->line_no }}][qty]" type="number" min="0" max="{{ $left }}" step="1" inputmode="numeric"
                                                   value="{{ old('lines.'.$line->line_no.'.qty') }}" data-tag="{{ $line->asset?->asset_tag }}"
                                                   class="w-24 rounded-lg border border-border px-3 py-2 text-sm font-mono">
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <label for="cond_{{ $line->line_no }}" class="sr-only">{{ __('loans.field_condition_in') }}</label>
                                            <select id="cond_{{ $line->line_no }}" name="lines[{{ $line->line_no }}][condition]" class="rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                                                @foreach (['GOOD', 'FAIR', 'DAMAGED'] as $condition)
                                                    <option value="{{ $condition }}" @selected(old('lines.'.$line->line_no.'.condition', $line->condition_out ?? 'GOOD') === $condition)>{{ __('loans.condition_'.strtolower($condition)) }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <label for="note_{{ $line->line_no }}" class="sr-only">{{ __('loans.field_return_note') }}</label>
                                            <input id="note_{{ $line->line_no }}" name="lines[{{ $line->line_no }}][note]" type="text" maxlength="255" value="{{ old('lines.'.$line->line_no.'.note') }}" class="w-full min-w-[12rem] rounded-lg border border-border px-3 py-2 text-sm">
                                        </td>
                                        {{-- Step 4 (FR-LN-21/23): pieces that came back damaged / will not come back become an incident. --}}
                                        <td class="px-4 py-3 align-top">
                                            <label for="dmg_{{ $line->line_no }}" class="sr-only">{{ __('loans.field_qty_damaged') }}</label>
                                            <input id="dmg_{{ $line->line_no }}" name="lines[{{ $line->line_no }}][qty_damaged]" type="number" min="0" max="{{ $left }}" step="1" inputmode="numeric"
                                                   value="{{ old('lines.'.$line->line_no.'.qty_damaged') }}" class="w-20 rounded-lg border border-border px-3 py-2 text-sm font-mono">
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <label for="lost_{{ $line->line_no }}" class="sr-only">{{ __('loans.field_qty_lost') }}</label>
                                            <input id="lost_{{ $line->line_no }}" name="lines[{{ $line->line_no }}][qty_lost]" type="number" min="0" max="{{ $left }}" step="1" inputmode="numeric"
                                                   value="{{ old('lines.'.$line->line_no.'.qty_lost') }}" class="w-20 rounded-lg border border-border px-3 py-2 text-sm font-mono">
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <label for="inc_{{ $line->line_no }}" class="sr-only">{{ __('loans.field_incident_note') }}</label>
                                            <input id="inc_{{ $line->line_no }}" name="lines[{{ $line->line_no }}][incident_note]" type="text" maxlength="500" value="{{ old('lines.'.$line->line_no.'.incident_note') }}" class="w-full min-w-[12rem] rounded-lg border border-border px-3 py-2 text-sm">
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="mt-2 text-xs text-ink-faint">{{ __('loans.return_note_hint') }}</p>
            <p class="mt-1 text-xs text-ink-faint">{{ __('loans.return_incident_note') }}</p>
            <p class="mt-1 text-xs text-ink-faint">{{ __('loans.incident_note_hint') }}</p>

            <div class="mt-5 flex gap-3">
                <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">{{ __('loans.btn_confirm_return') }}</button>
                <a href="{{ route('loans.show', $loan) }}" class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-5 py-2.5">{{ __('loans.btn_back') }}</a>
            </div>
        </form>
    </div>
</x-layout>
