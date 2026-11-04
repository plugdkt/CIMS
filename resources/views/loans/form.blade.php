<x-layout>
    @php
        $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
        $editing = $loan !== null;
        $borrowerParam = request('borrower');
    @endphp

    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ $editing ? __('loans.form_edit_title', ['doc_no' => $loan->doc_no]) : __('loans.form_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('loans.form_subtitle') }}</p>
    </div>

    @if ($error)
        <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">{{ $error }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Counter staff: borrow for somebody else of the branch (FR-LN-14). A GET search, so nothing is saved by it. --}}
    @if ($canOnBehalf)
        <div class="mb-5 rounded-xl border border-border bg-surface px-4 py-4">
            <h2 class="text-sm font-semibold mb-1">{{ __('loans.on_behalf_section') }}</h2>
            <p class="text-xs text-ink-muted mb-3">{{ __('loans.on_behalf_hint') }}</p>
            <form method="GET" action="{{ route('loans.create') }}" class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="bq" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.field_borrower_search') }}</label>
                    <input id="bq" name="bq" type="search" value="{{ $bq }}" class="w-full sm:w-72 rounded-lg border border-border px-3 py-2 text-sm">
                </div>
                <button type="submit" class="rounded-lg border border-border text-sm font-semibold px-4 py-2 hover:bg-surface-alt">{{ __('loans.btn_search') }}</button>
                @if ($onBehalf)
                    <a href="{{ route('loans.create') }}" class="text-sm font-semibold text-accent hover:text-accent-strong">{{ __('loans.btn_borrow_self') }}</a>
                @endif
            </form>
            @if ($candidates->isNotEmpty())
                <ul class="mt-3 divide-y divide-border text-sm">
                    @foreach ($candidates as $candidate)
                        <li class="py-1.5 flex items-center justify-between gap-3">
                            <span>{{ $candidate->full_name }} <span class="text-xs text-ink-faint">{{ $candidate->lab?->code }}</span></span>
                            <a href="{{ route('loans.create', ['borrower' => $candidate->ulid]) }}" class="text-xs font-semibold text-accent hover:text-accent-strong">{{ __('loans.btn_pick') }}</a>
                        </li>
                    @endforeach
                </ul>
            @elseif ($bq !== '')
                <p class="mt-3 text-xs text-ink-muted">{{ __('loans.no_candidates') }}</p>
            @endif
        </div>
    @endif

    <div class="mb-5 rounded-xl border border-border bg-surface-alt px-4 py-3 text-sm">
        <div><span class="text-ink-faint">{{ __('loans.field_borrower') }}:</span> <span class="font-medium">{{ $context->user->full_name }}</span>
            @if ($onBehalf) <span class="text-xs text-ink-faint">({{ __('loans.on_behalf_badge') }})</span> @endif
        </div>
        <div><span class="text-ink-faint">{{ __('loans.col_lab') }}:</span> {{ $borrowerLab?->code }} {{ $borrowerLab?->name_th }}</div>
        @if ($context->isStudent())
            <div class="text-xs text-ink-muted mt-1">{{ __('loans.student_needs_advisor') }}</div>
        @endif
    </div>

    <form method="GET" action="{{ $editing ? route('loans.edit', $loan) : route('loans.create') }}" class="flex flex-wrap items-end gap-3 mb-4">
        @if ($borrowerParam) <input type="hidden" name="borrower" value="{{ $borrowerParam }}"> @endif
        <div>
            <label for="q" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.filter_search') }}</label>
            <input id="q" name="q" type="search" value="{{ $term }}" class="w-full sm:w-72 rounded-lg border border-border px-3 py-2 text-sm">
        </div>
        <button type="submit" class="rounded-lg border border-border text-sm font-semibold px-4 py-2 hover:bg-surface-alt">{{ __('loans.btn_search') }}</button>
        <p class="text-xs text-ink-faint">{{ __('loans.search_resets_hint') }}</p>
    </form>

    <form method="POST" action="{{ $editing ? route('loans.update', $loan) : route('loans.store') }}" class="space-y-5">
        @csrf
        @if ($editing) @method('PUT') @endif
        @if ($borrowerParam && ! $editing) <input type="hidden" name="borrower" value="{{ $borrowerParam }}"> @endif

        <div class="bg-surface border border-border rounded-xl overflow-hidden">
            <div class="px-4 py-3 border-b border-border">
                <h2 class="text-sm font-semibold">{{ __('loans.section_items') }}</h2>
                <p class="text-xs text-ink-muted">{{ __('loans.items_hint') }}</p>
            </div>
            <div class="overflow-x-auto" tabindex="0">
                <table class="w-full text-sm">
                    <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_item') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_location') }}</th>
                            <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_available') }}</th>
                            <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_qty_want') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($assets as $asset)
                            @php $available = $asset->available(); @endphp
                            <tr>
                                <td class="px-4 py-3 align-top">
                                    <div class="font-medium">{{ $asset->item?->name_th }}</div>
                                    <div class="text-xs font-mono text-ink-faint">{{ $asset->item?->item_code }}@if ($asset->asset_tag) · {{ $asset->asset_tag }}@endif</div>
                                </td>
                                <td class="px-4 py-3 align-top">{{ $asset->location?->name ?: '—' }}</td>
                                <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($available) }}</td>
                                <td class="px-4 py-3 align-top text-right">
                                    <label class="sr-only" for="qty_{{ $asset->ulid }}">{{ __('loans.col_qty_want') }} {{ $asset->item?->name_th }}</label>
                                    <input id="qty_{{ $asset->ulid }}" name="qty[{{ $asset->ulid }}]" type="number" min="0" max="{{ $fmt($available) }}" step="1" inputmode="numeric"
                                           value="{{ old('qty.'.$asset->ulid, $existing[$asset->ulid] ?? '') }}"
                                           class="w-24 rounded-lg border border-border px-2 py-1.5 text-sm text-right font-mono">
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-center text-ink-muted">{{ __('loans.no_assets_to_borrow') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-surface border border-border rounded-xl px-4 py-4 grid gap-4 sm:grid-cols-2">
            <div>
                <label for="purpose_type" class="block text-xs font-medium mb-1">{{ __('loans.field_purpose') }}</label>
                <select id="purpose_type" name="purpose_type" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    @foreach (['TEACHING', 'RESEARCH', 'OTHER'] as $purpose)
                        <option value="{{ $purpose }}" @selected(old('purpose_type', $loan?->purpose_type) === $purpose)>{{ __('loans.purpose_'.strtolower($purpose)) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="purpose_detail" class="block text-xs font-medium mb-1">{{ __('loans.field_purpose_detail') }}</label>
                <input id="purpose_detail" name="purpose_detail" type="text" maxlength="500" value="{{ old('purpose_detail', $loan?->purpose_detail) }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                <p class="mt-1 text-xs text-ink-faint">{{ __('loans.purpose_detail_hint') }}</p>
            </div>
            <div>
                <label for="loan_date" class="block text-xs font-medium mb-1">{{ __('loans.field_loan_date') }}</label>
                <input id="loan_date" name="loan_date" type="date" value="{{ old('loan_date', $loan?->loan_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
            </div>
            <div>
                <label for="due_date" class="block text-xs font-medium mb-1">{{ __('loans.field_due_date') }}</label>
                <input id="due_date" name="due_date" type="date" value="{{ old('due_date', $loan?->due_date?->format('Y-m-d') ?? now()->addDays($defaultDays)->format('Y-m-d')) }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                <p class="mt-1 text-xs text-ink-faint">{{ __('loans.due_hint', ['default' => $defaultDays, 'max' => $maxDays]) }}</p>
            </div>
            <div class="sm:col-span-2">
                <label for="remark" class="block text-xs font-medium mb-1">{{ __('loans.field_remark') }}</label>
                <textarea id="remark" name="remark" rows="2" maxlength="500" class="w-full rounded-lg border border-border px-3 py-2 text-sm">{{ old('remark', $loan?->remark) }}</textarea>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" name="action" value="draft" class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-5 py-2.5">{{ __('loans.btn_save_draft') }}</button>
            <button type="submit" name="action" value="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">{{ __('loans.btn_save_submit') }}</button>
            @if ($editing)
                <a href="{{ route('loans.show', $loan) }}" class="text-sm text-ink-muted hover:text-ink">{{ __('loans.btn_back') }}</a>
            @endif
        </div>
    </form>
</x-layout>
