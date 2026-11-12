<x-layout>
    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('loans.branch_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('loans.branch_subtitle') }}</p>
    </div>

    <form method="GET" action="{{ route('loans.branch') }}" class="flex flex-wrap items-end gap-3 mb-5">
        <div>
            <label for="q" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.filter_doc_no') }}</label>
            <input id="q" name="q" type="search" value="{{ request('q') }}" class="w-full sm:w-44 rounded-lg border border-border px-3 py-2 text-sm">
        </div>
        <div>
            <label for="status" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.col_status') }}</label>
            <select id="status" name="status" class="rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                <option value="">{{ __('loans.filter_all') }}</option>
                @foreach (['SUBMITTED', 'ADVISOR_APPROVED', 'APPROVED', 'ON_LOAN', 'PARTIALLY_RETURNED', 'CLOSED', 'REJECTED', 'CANCELLED'] as $st)
                    <option value="{{ $st }}" @selected(request('status') === $st)>{{ __('loans.loan_status_'.strtolower($st)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="borrower" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.filter_borrower') }}</label>
            <input id="borrower" name="borrower" type="search" value="{{ request('borrower') }}" class="w-full sm:w-44 rounded-lg border border-border px-3 py-2 text-sm">
        </div>
        <div>
            <label for="item" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.filter_item') }}</label>
            <input id="item" name="item" type="search" value="{{ request('item') }}" class="w-full sm:w-44 rounded-lg border border-border px-3 py-2 text-sm">
        </div>
        <div>
            <label for="from" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.filter_from') }}</label>
            <input id="from" name="from" type="date" value="{{ request('from') }}" class="rounded-lg border border-border px-3 py-2 text-sm">
        </div>
        <div>
            <label for="to" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.filter_to') }}</label>
            <input id="to" name="to" type="date" value="{{ request('to') }}" class="rounded-lg border border-border px-3 py-2 text-sm">
        </div>
        @if ($isAdmin)
            <div>
                <label for="lab_id" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.col_lab') }}</label>
                <select id="lab_id" name="lab_id" class="rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <option value="">{{ __('loans.filter_all_labs') }}</option>
                    @foreach ($labs as $lab)
                        <option value="{{ $lab->id }}" @selected((string) request('lab_id') === (string) $lab->id)>{{ $lab->code }} {{ $lab->name_th }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <label class="flex items-center gap-2 text-sm py-2">
            <input type="checkbox" name="overdue" value="1" @checked(request('overdue') === '1')> {{ __('loans.overdue_only') }}
        </label>
        <button type="submit" class="rounded-lg border border-border text-sm font-semibold px-4 py-2 hover:bg-surface-alt">{{ __('loans.btn_search') }}</button>
    </form>

    <div class="bg-surface border border-border rounded-xl overflow-hidden">
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">{{ __('loans.col_doc_no') }}</th>
                        @if ($isAdmin)
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_lab') }}</th>
                        @endif
                        <th class="px-4 py-3 font-semibold">{{ __('loans.field_borrower') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('loans.col_items') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('loans.col_loan_date') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('loans.col_due') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('loans.col_status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($loans as $loan)
                        <tr>
                            <td class="px-4 py-3 align-top"><a href="{{ route('loans.show', $loan) }}" class="font-mono font-semibold text-accent hover:text-accent-strong">{{ $loan->doc_no }}</a></td>
                            @if ($isAdmin)
                                <td class="px-4 py-3 align-top">{{ $loan->lab?->code }}</td>
                            @endif
                            <td class="px-4 py-3 align-top">{{ $loan->borrower?->full_name }}</td>
                            <td class="px-4 py-3 align-top">@include('loans.partials.lines-summary', ['loan' => $loan])</td>
                            <td class="px-4 py-3 align-top">{{ $loan->loan_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 align-top">{{ $loan->due_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 align-top">@include('loans.partials.status-badge', ['loan' => $loan])</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $isAdmin ? 7 : 6 }}" class="px-4 py-8 text-center text-ink-muted">{{ __('loans.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $loans->links() }}</div>
</x-layout>
