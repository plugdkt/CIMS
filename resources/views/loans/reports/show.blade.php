<x-layout>
    <div class="mb-5">
        <a href="{{ route('loans.reports.index', array_filter(['lab_id' => $isAdmin ? request('lab_id') : null])) }}" class="text-xs text-accent hover:text-accent-strong">&larr; {{ __('loans.report.title') }}</a>
        <h1 class="font-display text-lg font-bold mt-1">{{ __('loans.report.name_'.$report) }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('loans.report.desc_'.$report) }}</p>
    </div>

    @unless ($hasLab)
        <div class="mb-5 rounded-lg border border-warning/40 bg-warning-soft text-warning-ink px-4 py-3 text-sm">{{ __('loans.report.no_lab') }}</div>
    @endunless

    @if ($errors->any())
        <div class="mb-5 rounded-lg border border-danger/40 bg-danger-soft text-danger-ink px-4 py-3 text-sm" role="alert">
            @foreach ($errors->all() as $message)
                <p>{{ $message }}</p>
            @endforeach
        </div>
    @endif

    <form method="GET" action="{{ route('loans.reports.show', $report) }}" class="flex flex-wrap items-end gap-3 mb-5">
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
        @if (in_array('borrower', $filters, true))
            <div>
                <label for="borrower" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.filter_borrower') }}</label>
                <input id="borrower" name="borrower" type="search" maxlength="100" value="{{ request('borrower') }}" class="w-full sm:w-44 rounded-lg border border-border px-3 py-2 text-sm">
            </div>
        @endif
        @if (in_array('item', $filters, true))
            <div>
                <label for="item" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.report.filter_item') }}</label>
                <input id="item" name="item" type="search" maxlength="100" value="{{ request('item') }}" class="w-full sm:w-44 rounded-lg border border-border px-3 py-2 text-sm">
            </div>
        @endif
        @if (in_array('min_days', $filters, true))
            <div>
                <label for="min_days" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.report.filter_min_days') }}</label>
                <input id="min_days" name="min_days" type="number" min="0" max="3650" step="1" value="{{ request('min_days') }}" class="w-full sm:w-32 rounded-lg border border-border px-3 py-2 text-sm">
            </div>
        @endif
        @if (in_array('kind', $filters, true))
            <div>
                <label for="kind" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.col_kind') }}</label>
                <select id="kind" name="kind" class="rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <option value="">{{ __('loans.filter_all') }}</option>
                    @foreach (['DAMAGED', 'LOST'] as $kind)
                        <option value="{{ $kind }}" @selected(request('kind') === $kind)>{{ __('loans.incident_kind_'.strtolower($kind)) }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if (in_array('month', $filters, true))
            <div>
                <label for="month" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.report.filter_month') }}</label>
                <input id="month" name="month" type="month" value="{{ request('month', now()->format('Y-m')) }}" class="rounded-lg border border-border px-3 py-2 text-sm">
            </div>
        @endif
        @if (in_array('from', $filters, true))
            <div>
                <label for="from" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.report.filter_from') }}</label>
                <input id="from" name="from" type="date" value="{{ request('from') }}" class="rounded-lg border border-border px-3 py-2 text-sm">
            </div>
            <div>
                <label for="to" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.filter_to') }}</label>
                <input id="to" name="to" type="date" value="{{ request('to') }}" class="rounded-lg border border-border px-3 py-2 text-sm">
            </div>
        @endif
        <button type="submit" class="rounded-lg border border-border text-sm font-semibold px-4 py-2 hover:bg-surface-alt">{{ __('loans.report.btn_show') }}</button>
        {{-- The export repeats exactly the filters of this page; the download is recorded in the audit log. --}}
        <a href="{{ route('loans.reports.export', array_merge(['report' => $report], request()->only(['lab_id', 'from', 'to', 'month', 'borrower', 'item', 'min_days', 'kind']))) }}"
           class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2">{{ __('loans.report.btn_export') }}</a>
    </form>

    <p class="text-xs text-ink-muted mb-3">{{ __('loans.report.export_note') }}</p>

    @if ($table->truncated)
        <div class="mb-3 rounded-lg border border-border bg-surface-alt px-4 py-3 text-sm text-ink-muted">{{ __('loans.report.truncated', ['rows' => number_format($pageRows)]) }}</div>
    @endif

    <div class="bg-surface border border-border rounded-xl overflow-hidden">
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        @foreach ($table->headings as $heading)
                            <th scope="col" class="px-4 py-3 font-semibold whitespace-nowrap">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($table->rows as $row)
                        <tr>
                            @foreach ($row as $cell)
                                <td class="px-4 py-2 align-top">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($table->headings) }}" class="px-4 py-8 text-center text-ink-muted">{{ __('loans.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layout>
