<div>
    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('requisitions.index_title') }}</h1>
            <p class="text-sm text-ink-muted mt-1">{{ __('requisitions.index_subtitle') }}</p>
        </div>
        @can('create', App\Models\Requisition::class)
            <a href="{{ route('requisitions.create') }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5 whitespace-nowrap">
                {{ __('requisitions.new_requisition') }}
            </a>
        @endcan
    </div>

    <div class="bg-surface border border-border rounded-xl overflow-hidden">
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">{{ __('requisitions.col_doc_no') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('requisitions.col_doc_date') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('requisitions.col_requester') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('requisitions.col_lab') }}</th>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('requisitions.col_status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($requisitions as $requisition)
                        <tr wire:key="req-{{ $requisition->id }}">
                            <td class="px-4 py-3 align-top font-mono text-xs"><a href="{{ route('requisitions.show', $requisition) }}" class="text-accent hover:text-accent-strong font-semibold">{{ $requisition->doc_no }}</a></td>
                            <td class="px-4 py-3 align-top text-ink-muted">{{ $requisition->doc_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 align-top">{{ $requisition->requester?->full_name }}</td>
                            <td class="px-4 py-3 align-top text-ink-muted">{{ $requisition->lab?->name_th }}</td>
                            <td class="px-4 py-3 align-top whitespace-nowrap">
                                @php
                                    $badge = match ($requisition->status) {
                                        'ISSUED' => 'bg-success-soft text-success-ink',
                                        'REJECTED', 'CANCELLED' => 'bg-danger-soft text-danger-ink',
                                        'APPROVED', 'PARTIALLY_ISSUED', 'ADVISOR_APPROVED' => 'bg-accent-soft text-accent-soft-ink',
                                        default => 'bg-neutral-soft text-neutral-ink',
                                    };
                                @endphp
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold {{ $badge }}">
                                    {{ __($requisition->statusLabelKey()) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 align-top text-right whitespace-nowrap">
                                @if ($requisition->status === 'DRAFT' && auth()->user()->can('submit', $requisition))
                                    <a href="{{ route('requisitions.show', $requisition) }}" class="inline-block rounded-lg bg-accent hover:bg-accent-strong text-white text-xs font-semibold px-3 py-1.5">{{ __('requisitions.act_continue_draft') }}</a>
                                @elsecan('advisorDecide', $requisition)
                                    <a href="{{ route('requisitions.show', $requisition) }}" class="inline-block rounded-lg bg-accent hover:bg-accent-strong text-white text-xs font-semibold px-3 py-1.5">{{ __('requisitions.act_advisor_decide') }}</a>
                                @elseif ($requisition->status === 'SUBMITTED' && $requisition->requester_status === 'STUDENT')
                                    <span class="text-xs text-ink-muted">{{ __('requisitions.act_waiting_advisor') }}</span>
                                @elseif (in_array($requisition->status, ['SUBMITTED', 'ADVISOR_APPROVED'], true))
                                    <span class="text-xs text-ink-muted">{{ __('requisitions.act_waiting_decision') }}</span>
                                @elseif (in_array($requisition->status, ['APPROVED', 'PARTIALLY_ISSUED'], true))
                                    <span class="text-xs text-ink-muted">{{ __('requisitions.act_waiting_issue') }}</span>
                                @else
                                    <a href="{{ route('requisitions.show', $requisition) }}" class="text-xs font-semibold text-accent hover:text-accent-strong">{{ __('requisitions.act_view') }}</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-ink-muted text-sm">
                                {{ __('requisitions.no_results') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $requisitions->links() }}
    </div>
</div>
