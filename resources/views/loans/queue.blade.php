<x-layout>
    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('loans.queue_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('loans.queue_subtitle') }}</p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-success-soft text-success-ink text-sm px-4 py-3">{{ session('status') }}</div>
    @endif

    @php
        $sections = [
            ['key' => 'advisor', 'title' => __('loans.queue_advisor_section'), 'rows' => $advisorQueue, 'show' => auth()->user()->hasPermission('loan.approve_advisor')],
            ['key' => 'manager', 'title' => __('loans.queue_manager_section'), 'rows' => $managerQueue, 'show' => auth()->user()->hasPermission('loan.approve') && auth()->user()->lab_id !== null],
        ];
    @endphp

    @foreach ($sections as $section)
        @if ($section['show'])
            <h2 class="text-sm font-semibold mb-2 mt-6">{{ $section['title'] }} <span class="text-ink-faint font-normal">({{ $section['rows']->count() }})</span></h2>
            @if ($section['key'] === 'manager')
                <p class="text-xs text-ink-muted mb-2">{{ __('loans.queue_manager_note') }}</p>
            @endif
            <div class="bg-surface border border-border rounded-xl overflow-hidden">
                <div class="overflow-x-auto" tabindex="0">
                    <table class="w-full text-sm">
                        <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th class="px-4 py-3 font-semibold">{{ __('loans.col_doc_no') }}</th>
                                <th class="px-4 py-3 font-semibold">{{ __('loans.field_borrower') }}</th>
                                <th class="px-4 py-3 font-semibold">{{ __('loans.col_items') }}</th>
                                <th class="px-4 py-3 font-semibold">{{ __('loans.col_due') }}</th>
                                <th class="px-4 py-3 font-semibold">{{ __('loans.col_submitted') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($section['rows'] as $loan)
                                <tr>
                                    <td class="px-4 py-3 align-top"><a href="{{ route('loans.show', $loan) }}" class="font-mono font-semibold text-accent hover:text-accent-strong">{{ $loan->doc_no }}</a></td>
                                    <td class="px-4 py-3 align-top">{{ $loan->borrower?->full_name }}</td>
                                    <td class="px-4 py-3 align-top">@include('loans.partials.lines-summary', ['loan' => $loan])</td>
                                    <td class="px-4 py-3 align-top">{{ $loan->due_date->format('d/m/Y') }}</td>
                                    <td class="px-4 py-3 align-top">{{ $loan->submitted_at?->format('d/m/Y H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-8 text-center text-ink-muted">{{ __('loans.queue_empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endforeach
</x-layout>
