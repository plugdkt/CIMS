<x-layout>
    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('loans.mine_title') }}</h1>
            <p class="text-sm text-ink-muted mt-1">{{ __('loans.mine_subtitle') }}</p>
        </div>
        @can('createLoan', App\Models\Loan::class)
            <a href="{{ route('loans.create') }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5 whitespace-nowrap">{{ __('loans.btn_new_loan') }}</a>
        @endcan
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-success-soft text-success-ink text-sm px-4 py-3">{{ session('status') }}</div>
    @endif

    @unless ($hasLab)
        <div class="mb-4 rounded-lg border border-warning/40 bg-warning-soft text-warning-ink px-4 py-3 text-sm">
            {{ __('loans.no_lab_note') }}
            <a href="{{ route('loans.pending-lab') }}" class="underline font-semibold">{{ __('loans.no_lab_link') }}</a>
        </div>
    @endunless

    <form method="GET" action="{{ route('loans.mine') }}" class="flex flex-wrap items-end gap-3 mb-5">
        <div>
            <label for="q" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.filter_doc_no') }}</label>
            <input id="q" name="q" type="search" value="{{ request('q') }}" class="w-full sm:w-56 rounded-lg border border-border px-3 py-2 text-sm">
        </div>
        <div>
            <label for="status" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.col_status') }}</label>
            <select id="status" name="status" class="rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                <option value="">{{ __('loans.filter_all') }}</option>
                @foreach (['DRAFT', 'SUBMITTED', 'ADVISOR_APPROVED', 'APPROVED', 'ON_LOAN', 'PARTIALLY_RETURNED', 'CLOSED', 'REJECTED', 'CANCELLED'] as $st)
                    <option value="{{ $st }}" @selected(request('status') === $st)>{{ __('loans.loan_status_'.strtolower($st)) }}</option>
                @endforeach
            </select>
        </div>
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
                        <th class="px-4 py-3 font-semibold">{{ __('loans.col_items') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('loans.col_loan_date') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('loans.col_due') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('loans.col_status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($loans as $loan)
                        <tr>
                            <td class="px-4 py-3 align-top">
                                <a href="{{ route('loans.show', $loan) }}" class="font-mono font-semibold text-accent hover:text-accent-strong">{{ $loan->doc_no }}</a>
                                @if ($loan->borrower_id !== auth()->id())
                                    <div class="text-xs text-ink-faint">{{ __('loans.for_borrower', ['name' => $loan->borrower?->full_name]) }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top">@include('loans.partials.lines-summary', ['loan' => $loan])</td>
                            <td class="px-4 py-3 align-top">{{ $loan->loan_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 align-top">{{ $loan->due_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 align-top">@include('loans.partials.status-badge', ['loan' => $loan])</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-ink-muted">{{ __('loans.empty_mine') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $loans->links() }}</div>
</x-layout>
