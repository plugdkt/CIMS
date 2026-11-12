@php
    $tone = match ($loan->status) {
        'APPROVED', 'CLOSED' => 'bg-success-soft text-success-ink',
        'REJECTED', 'CANCELLED' => 'bg-danger-soft text-danger-ink',
        'SUBMITTED', 'ADVISOR_APPROVED' => 'bg-warning-soft text-warning-ink',
        default => 'bg-surface-alt text-ink-muted',
    };
@endphp
<span class="inline-block rounded px-2 py-0.5 text-xs font-semibold {{ $tone }}">{{ __($loan->statusLabelKey()) }}</span>
{{-- Step 3 (BR-L08): "overdue" is computed from the status and the due date, never stored. --}}
@if ($loan->isOverdue())
    <span class="inline-block rounded px-2 py-0.5 text-xs font-semibold bg-danger-soft text-danger-ink">{{ __('loans.overdue_badge', ['days' => $loan->daysOverdue()]) }}</span>
@endif
