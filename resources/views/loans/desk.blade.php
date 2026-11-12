<x-layout>
    {{-- ITM-2610-051 step 3 (FR-LN-17): the counter's queue of the user's own branch. No prices on this page. --}}
    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('loans.desk_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('loans.desk_subtitle') }}</p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-success-soft text-success-ink text-sm px-4 py-3">{{ session('status') }}</div>
    @endif
    @unless ($canHandle)
        <div class="mb-4 rounded-lg border border-border bg-surface-alt px-4 py-3 text-sm text-ink-muted">{{ __('loans.desk_view_only') }}</div>
    @endunless

    {{-- A plain GET form: a USB barcode scanner types the code and presses Enter, no script needed. --}}
    <form method="GET" action="{{ route('loans.desk') }}" class="flex flex-wrap items-end gap-3 mb-2">
        <div>
            <label for="q" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.desk_scan_label') }}</label>
            <input id="q" name="q" type="search" value="{{ $term }}" maxlength="100" autofocus autocomplete="off" class="w-full sm:w-80 rounded-lg border border-border px-3 py-2 text-sm font-mono">
        </div>
        <button type="submit" class="rounded-lg border border-border text-sm font-semibold px-4 py-2 hover:bg-surface-alt">{{ __('loans.btn_search') }}</button>
        @if ($term !== '')
            <a href="{{ route('loans.desk') }}" class="text-sm font-semibold text-accent hover:text-accent-strong">{{ __('loans.btn_clear') }}</a>
        @endif
    </form>
    <p class="text-xs text-ink-faint mb-5">{{ __('loans.desk_scan_hint') }}</p>

    {{-- Step 4: the branch's damage / loss cases (a count only, no names). --}}
    @if ($canBrowseIncidents)
        <p class="mb-5"><a href="{{ route('loans.incidents.index') }}" class="text-sm font-semibold text-accent hover:text-accent-strong">{{ __('loans.desk_incidents_link', ['count' => $pendingIncidents]) }}</a></p>
    @endif

    @php
        $sections = [
            ['title' => __('loans.desk_handout_section'), 'rows' => $toHandOut, 'action' => 'handout'],
            ['title' => __('loans.desk_out_section'), 'rows' => $out, 'action' => 'return'],
        ];
    @endphp

    @foreach ($sections as $section)
        <h2 class="text-sm font-semibold mb-2 mt-6">{{ $section['title'] }} <span class="text-ink-faint font-normal">({{ $section['rows']->count() }})</span></h2>
        <div class="bg-surface border border-border rounded-xl overflow-hidden">
            <div class="overflow-x-auto" tabindex="0">
                <table class="w-full text-sm">
                    <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_doc_no') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.field_borrower') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_items') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_loan_date') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_due') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_status') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($section['rows'] as $loan)
                            <tr>
                                <td class="px-4 py-3 align-top"><a href="{{ route('loans.show', $loan) }}" class="font-mono font-semibold text-accent hover:text-accent-strong">{{ $loan->doc_no }}</a></td>
                                <td class="px-4 py-3 align-top">{{ $loan->borrower?->full_name }}</td>
                                <td class="px-4 py-3 align-top">@include('loans.partials.lines-summary', ['loan' => $loan])</td>
                                <td class="px-4 py-3 align-top">{{ $loan->loan_date->format('d/m/Y') }}</td>
                                <td class="px-4 py-3 align-top">{{ $loan->due_date->format('d/m/Y') }}</td>
                                <td class="px-4 py-3 align-top">@include('loans.partials.status-badge', ['loan' => $loan])</td>
                                <td class="px-4 py-3 align-top text-right whitespace-nowrap">
                                    @if ($section['action'] === 'handout')
                                        @can('handOutLoan', $loan)
                                            <a href="{{ route('loans.handout.form', $loan) }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-xs font-semibold px-3 py-1.5">{{ __('loans.btn_handout') }}</a>
                                        @endcan
                                    @else
                                        @can('receiveReturnLoan', $loan)
                                            <a href="{{ route('loans.return.form', $loan) }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-xs font-semibold px-3 py-1.5">{{ __('loans.btn_return') }}</a>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-ink-muted">{{ __('loans.desk_empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</x-layout>
