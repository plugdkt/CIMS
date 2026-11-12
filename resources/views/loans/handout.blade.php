<x-layout>
    {{-- ITM-2610-051 step 3 (FR-LN-20): check the lines, write down each line's condition, one confirm button. --}}
    @php $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp

    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('loans.handout_title') }} <span class="font-mono">{{ $loan->doc_no }}</span></h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('loans.handout_hint') }}</p>
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
        <div><span class="text-ink-faint">{{ __('loans.col_lab') }}:</span> {{ $loan->lab?->code }} {{ $loan->lab?->name_th }}</div>
        <div><span class="text-ink-faint">{{ __('loans.field_loan_date') }}:</span> {{ $loan->loan_date->format('d/m/Y') }} · <span class="text-ink-faint">{{ __('loans.field_due_date') }}:</span> {{ $loan->due_date->format('d/m/Y') }}</div>
    </div>

    <form method="POST" action="{{ route('loans.handout', $loan) }}">
        @csrf
        <div class="bg-surface border border-border rounded-xl overflow-hidden">
            <div class="overflow-x-auto" tabindex="0">
                <table class="w-full text-sm">
                    <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_item') }}</th>
                            <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_handed_out') }}</th>
                            <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_available') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.field_condition_out') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.field_condition_note') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($loan->lines as $line)
                            <tr>
                                <td class="px-4 py-3 align-top">
                                    <div class="font-medium">{{ $line->asset?->item?->name_th }}</div>
                                    <div class="text-xs font-mono text-ink-faint">{{ $line->asset?->item?->item_code }}@if ($line->asset?->asset_tag) · {{ $line->asset->asset_tag }}@endif</div>
                                </td>
                                <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($line->qtyOut()) }}</td>
                                <td class="px-4 py-3 align-top text-right font-mono">{{ $line->asset ? $fmt($line->asset->available()) : '—' }}</td>
                                <td class="px-4 py-3 align-top">
                                    <label for="cond_{{ $line->line_no }}" class="sr-only">{{ __('loans.field_condition_out') }}</label>
                                    <select id="cond_{{ $line->line_no }}" name="lines[{{ $line->line_no }}][condition]" required class="rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                                        @foreach (['GOOD', 'FAIR', 'DAMAGED'] as $condition)
                                            <option value="{{ $condition }}" @selected(old('lines.'.$line->line_no.'.condition', 'GOOD') === $condition)>{{ __('loans.condition_'.strtolower($condition)) }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <label for="note_{{ $line->line_no }}" class="sr-only">{{ __('loans.field_condition_note') }}</label>
                                    <input id="note_{{ $line->line_no }}" name="lines[{{ $line->line_no }}][note]" type="text" maxlength="255" value="{{ old('lines.'.$line->line_no.'.note') }}" class="w-full min-w-[12rem] rounded-lg border border-border px-3 py-2 text-sm">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <p class="mt-2 text-xs text-ink-faint">{{ __('loans.condition_note_hint') }}</p>

        <div class="mt-5 flex gap-3">
            <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">{{ __('loans.btn_confirm_handout') }}</button>
            <a href="{{ route('loans.show', $loan) }}" class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-5 py-2.5">{{ __('loans.btn_back') }}</a>
        </div>
    </form>
</x-layout>
