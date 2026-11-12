<x-layout>
    @php
        $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
        $me = auth()->user();
        $canManager = $me->can('managerDecideLoan', $loan);
        $canAdvisor = $me->can('advisorDecideLoan', $loan);
        // Step 3: once handed out the table shows what came back and what is still out.
        $handedOut = in_array($loan->status, ['ON_LOAN', 'PARTIALLY_RETURNED', 'CLOSED'], true);
        // A request still PENDING when the loan was closed is moot: it stays in the history, nothing is offered for it.
        $pendingRenewal = $loan->isOut() ? $loan->dueChanges->firstWhere('status', 'PENDING') : null;
    @endphp

    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('loans.show_title') }} <span class="font-mono">{{ $loan->doc_no }}</span></h1>
            <div class="mt-1">@include('loans.partials.status-badge', ['loan' => $loan])</div>
        </div>
        <div class="flex gap-2">
            @can('updateLoan', $loan)
                <a href="{{ route('loans.edit', $loan) }}" class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-4 py-2.5">{{ __('loans.btn_edit') }}</a>
            @endcan
            {{-- BR-L08: a blocked borrower's draft is submitted from the box below (it carries the reason), not from here. --}}
            @if ($blockingLoans === [])
                @can('submitLoan', $loan)
                    <form method="POST" action="{{ route('loans.submit', $loan) }}">
                        @csrf
                        <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">{{ __('loans.btn_submit') }}</button>
                    </form>
                @endcan
            @endif
            {{-- Step 3: the counter's actions and the printable slip. --}}
            @can('handOutLoan', $loan)
                <a href="{{ route('loans.handout.form', $loan) }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">{{ __('loans.btn_handout') }}</a>
            @endcan
            @can('receiveReturnLoan', $loan)
                <a href="{{ route('loans.return.form', $loan) }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">{{ __('loans.btn_return') }}</a>
            @endcan
            @can('printLoan', $loan)
                <a href="{{ route('loans.pdf', $loan) }}" target="_blank" rel="noopener" class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-4 py-2.5">{{ __('loans.btn_print') }}</a>
            @endcan
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-success-soft text-success-ink text-sm px-4 py-3">{{ session('status') }}</div>
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

    @if ($loan->status === 'DRAFT')
        <div class="mb-4 rounded-lg border border-border bg-surface-alt px-4 py-3 text-sm text-ink-muted">{{ __('loans.draft_note') }}</div>
    @endif
    @if ($loan->status === 'SUBMITTED' && $loan->isStudentBorrower())
        <div class="mb-4 rounded-lg border border-border bg-surface-alt px-4 py-3 text-sm text-ink-muted">{{ __('loans.waiting_advisor_note', ['advisor' => $loan->advisor?->full_name]) }}</div>
    @endif
    {{-- BR-L08 (step 3): the borrower has long-overdue loans, so this draft cannot be submitted, unless an approver of
         the branch who typed it in for the borrower allows this one request and says why (audited). --}}
    @if ($blockingLoans !== [])
        <div class="mb-4 rounded-lg border border-danger/40 bg-danger-soft text-danger-ink px-4 py-3 text-sm">
            <div class="font-semibold">{{ __('loans.overdue_block_title') }}</div>
            <div>{{ __('loans.overdue_block_body', ['days' => config('loans.overdue_block_days', 7), 'docs' => implode(', ', $blockingLoans)]) }}</div>
            @if ($mayOverrideBlock)
                @can('submitLoan', $loan)
                    <form method="POST" action="{{ route('loans.submit', $loan) }}" class="mt-3 space-y-2">
                        @csrf
                        <label for="overdue_override_reason" class="block text-xs font-medium">{{ __('loans.overdue_override_label') }}</label>
                        <input id="overdue_override_reason" name="overdue_override_reason" type="text" maxlength="500" class="w-full rounded-lg border border-border px-3 py-2 text-sm text-ink">
                        <p class="text-xs">{{ __('loans.overdue_override_hint') }}</p>
                        <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2">{{ __('loans.btn_submit') }}</button>
                    </form>
                @endcan
            @endif
        </div>
    @endif
    @if ($pendingRenewal)
        <div class="mb-4 rounded-lg border border-warning/40 bg-warning-soft text-warning-ink px-4 py-3 text-sm">{{ __('loans.renew_waiting_note', ['due' => $pendingRenewal->new_due_date->format('d/m/Y')]) }}</div>
    @endif
    {{-- Step 4 (BR-L12): everything is back but an incident still waits for its result, so the loan is not closed. --}}
    @if ($loan->isOut() && $loan->incidents->contains(fn ($i) => $i->isPending()))
        <div class="mb-4 rounded-lg border border-warning/40 bg-warning-soft text-warning-ink px-4 py-3 text-sm">{{ __('loans.incident_pending_loan_note') }}</div>
    @endif

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-5">
            <div class="bg-surface border border-border rounded-xl overflow-hidden">
                <div class="px-4 py-3 border-b border-border"><h2 class="text-sm font-semibold">{{ __('loans.section_items') }}</h2></div>
                <div class="overflow-x-auto" tabindex="0">
                    <table class="w-full text-sm">
                        <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th class="px-4 py-3 font-semibold">{{ __('loans.col_item') }}</th>
                                <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_requested') }}</th>
                                <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_approved') }}</th>
                                @if ($canManager)
                                    <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_available') }}</th>
                                @endif
                                @if ($handedOut)
                                    <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_returned') }}</th>
                                    <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_outstanding') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ __('loans.col_condition_out') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($loan->lines as $line)
                                <tr>
                                    <td class="px-4 py-3 align-top">
                                        <div class="font-medium">{{ $line->asset?->item?->name_th }}</div>
                                        <div class="text-xs font-mono text-ink-faint">{{ $line->asset?->item?->item_code }}@if ($line->asset?->asset_tag) · {{ $line->asset->asset_tag }}@endif</div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($line->qty) }}</td>
                                    <td class="px-4 py-3 align-top text-right font-mono">{{ $line->qty_approved === null ? '—' : $fmt($line->qty_approved) }}</td>
                                    @if ($canManager)
                                        <td class="px-4 py-3 align-top text-right font-mono">{{ $line->asset ? $fmt($line->asset->available()) : '—' }}</td>
                                    @endif
                                    @if ($handedOut)
                                        <td class="px-4 py-3 align-top text-right font-mono">
                                            {{ $fmt($line->qty_returned_ok) }}
                                            @if (bccomp($line->qty_returned_damaged, '0', 6) > 0)
                                                <div class="text-xs text-danger-ink font-sans">{{ __('loans.col_damaged') }} {{ $fmt($line->qty_returned_damaged) }}</div>
                                            @endif
                                            @if (bccomp($line->qty_lost, '0', 6) > 0)
                                                <div class="text-xs text-danger-ink font-sans">{{ __('loans.col_lost') }} {{ $fmt($line->qty_lost) }}</div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($line->outstanding()) }}</td>
                                        <td class="px-4 py-3 align-top">
                                            {{ $line->condition_out ? __('loans.condition_'.strtolower($line->condition_out)) : '—' }}
                                            @if ($line->condition_out_note)
                                                <div class="text-xs text-ink-muted">{{ $line->condition_out_note }}</div>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($canAdvisor)
                <form method="POST" action="{{ route('loans.advisor-decide', $loan) }}" class="bg-surface border border-border rounded-xl px-4 py-4 space-y-3">
                    @csrf
                    <h2 class="text-sm font-semibold">{{ __('loans.section_decide_advisor') }}</h2>
                    <p class="text-xs text-ink-muted">{{ __('loans.decide_advisor_hint') }}</p>
                    <div>
                        <label class="flex items-center gap-2 text-sm mb-1"><input type="radio" name="decision" value="APPROVE" checked> {{ __('loans.decision_approve') }}</label>
                        <label class="flex items-center gap-2 text-sm"><input type="radio" name="decision" value="REJECT"> {{ __('loans.decision_reject') }}</label>
                    </div>
                    <div>
                        <label for="advisor_reason" class="block text-xs font-medium mb-1">{{ __('loans.field_decision_reason') }}</label>
                        <textarea id="advisor_reason" name="reason" rows="2" maxlength="500" class="w-full rounded-lg border border-border px-3 py-2 text-sm">{{ old('reason') }}</textarea>
                    </div>
                    <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">{{ __('loans.btn_decide') }}</button>
                </form>
            @endif

            @if ($canManager)
                <form method="POST" action="{{ route('loans.manager-decide', $loan) }}" class="bg-surface border border-border rounded-xl px-4 py-4 space-y-3">
                    @csrf
                    <h2 class="text-sm font-semibold">{{ __('loans.section_decide_manager') }}</h2>
                    <p class="text-xs text-ink-muted">{{ __('loans.decide_manager_hint') }}</p>
                    @if ($loan->borrower_id === $me->id)
                        <div class="rounded-lg border border-warning/40 bg-warning-soft text-warning-ink px-3 py-2 text-xs">{{ __('loans.self_approval_note') }}</div>
                    @endif
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($loan->lines as $line)
                            <div>
                                <label for="qa_{{ $line->line_no }}" class="block text-xs font-medium mb-1">{{ $line->asset?->item?->name_th }} ({{ __('loans.col_requested') }} {{ $fmt($line->qty) }})</label>
                                <input id="qa_{{ $line->line_no }}" name="qty_approved[{{ $line->line_no }}]" type="number" min="1" max="{{ $fmt($line->qty) }}" step="1" value="{{ old('qty_approved.'.$line->line_no) }}"
                                       placeholder="{{ $fmt($line->qty) }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm font-mono">
                            </div>
                        @endforeach
                    </div>
                    <p class="text-xs text-ink-faint">{{ __('loans.qty_approved_hint') }}</p>
                    <div>
                        <label class="flex items-center gap-2 text-sm mb-1"><input type="radio" name="decision" value="APPROVE" checked> {{ __('loans.decision_approve') }}</label>
                        <label class="flex items-center gap-2 text-sm"><input type="radio" name="decision" value="REJECT"> {{ __('loans.decision_reject') }}</label>
                    </div>
                    <div>
                        <label for="manager_reason" class="block text-xs font-medium mb-1">{{ __('loans.field_decision_reason') }}</label>
                        <textarea id="manager_reason" name="reason" rows="2" maxlength="500" class="w-full rounded-lg border border-border px-3 py-2 text-sm">{{ old('reason') }}</textarea>
                    </div>
                    <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">{{ __('loans.btn_decide') }}</button>
                </form>
            @endif

            {{-- Step 3 (BR-L07): the borrower asks for a later due date; one request at a time. --}}
            @can('renewLoan', $loan)
                @if (! $pendingRenewal)
                    {{-- Every request counts (rejected ones too): once `max_renewal_requests` is used up the form is gone. --}}
                    @if ($loan->dueChanges->count() >= $renewRequestMax)
                        <div class="rounded-lg border border-border bg-surface-alt px-4 py-3 text-sm text-ink-muted">{{ __('loans.renew_request_limit_note', ['max' => $renewRequestMax]) }}</div>
                    @elseif ($latestRenewDue)
                        <form method="POST" action="{{ route('loans.renewals.store', $loan) }}" class="bg-surface border border-border rounded-xl px-4 py-4 space-y-3">
                            @csrf
                            <h2 class="text-sm font-semibold">{{ __('loans.section_renew') }}</h2>
                            <p class="text-xs text-ink-muted">{{ __('loans.renew_hint', ['latest' => $latestRenewDue->format('d/m/Y'), 'max' => config('loans.max_renewals', 3), 'used' => $loan->renew_count]) }}</p>
                            <p class="text-xs text-ink-muted">{{ $loan->dueChanges->isEmpty() ? __('loans.renew_first_note') : __('loans.renew_later_note') }}</p>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="new_due_date" class="block text-xs font-medium mb-1">{{ __('loans.field_new_due') }}</label>
                                    <input id="new_due_date" name="new_due_date" type="date" required min="{{ $loan->due_date->copy()->addDay()->format('Y-m-d') }}" max="{{ $latestRenewDue->format('Y-m-d') }}"
                                           value="{{ old('new_due_date', $latestRenewDue->format('Y-m-d')) }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label for="renew_reason" class="block text-xs font-medium mb-1">{{ __('loans.field_renew_reason') }}</label>
                                    <input id="renew_reason" name="reason" type="text" maxlength="500" value="{{ old('reason') }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                                    <p class="mt-1 text-xs text-ink-faint">{{ __('loans.renew_reason_hint') }}</p>
                                </div>
                            </div>
                            <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">{{ __('loans.btn_renew') }}</button>
                        </form>
                    @else
                        <div class="rounded-lg border border-border bg-surface-alt px-4 py-3 text-sm text-ink-muted">{{ __('loans.renew_unavailable_note') }}</div>
                    @endif
                @endif
            @endcan

            {{-- Step 3 (BR-L07): an approver of the branch decides the waiting request (the 2nd and later ones). --}}
            @if ($pendingRenewal)
                @can('decideRenewalLoan', $loan)
                    <form method="POST" action="{{ route('loans.renewals.decide', ['loan' => $loan, 'seq' => $pendingRenewal->seq]) }}" class="bg-surface border border-border rounded-xl px-4 py-4 space-y-3">
                        @csrf
                        <h2 class="text-sm font-semibold">{{ __('loans.section_renew_decide') }}</h2>
                        <p class="text-xs text-ink-muted">{{ __('loans.renew_decide_hint', ['old' => $pendingRenewal->old_due_date->format('d/m/Y'), 'new' => $pendingRenewal->new_due_date->format('d/m/Y')]) }}</p>
                        @if ($pendingRenewal->reason)
                            <p class="text-sm">{{ __('loans.field_renew_reason') }}: {{ $pendingRenewal->reason }}</p>
                        @endif
                        @if ($loan->borrower_id === $me->id)
                            <div class="rounded-lg border border-warning/40 bg-warning-soft text-warning-ink px-3 py-2 text-xs">{{ __('loans.self_renew_note') }}</div>
                        @endif
                        <div>
                            <label class="flex items-center gap-2 text-sm mb-1"><input type="radio" name="decision" value="APPROVE" checked> {{ __('loans.decision_approve') }}</label>
                            <label class="flex items-center gap-2 text-sm"><input type="radio" name="decision" value="REJECT"> {{ __('loans.decision_reject') }}</label>
                        </div>
                        <div>
                            <label for="renew_note" class="block text-xs font-medium mb-1">{{ __('loans.field_renew_note') }}</label>
                            <textarea id="renew_note" name="note" rows="2" maxlength="500" class="w-full rounded-lg border border-border px-3 py-2 text-sm">{{ old('note') }}</textarea>
                        </div>
                        <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">{{ __('loans.btn_decide') }}</button>
                    </form>
                @endcan
            @endif

            {{-- Step 3 (FR-LN-32, the document's side): what was handed out and taken back, from the loan ledger. --}}
            @if ($handedOut)
                <div class="bg-surface border border-border rounded-xl overflow-hidden">
                    <div class="px-4 py-3 border-b border-border"><h2 class="text-sm font-semibold">{{ __('loans.section_movements') }}</h2></div>
                    <div class="overflow-x-auto" tabindex="0">
                        <table class="w-full text-sm">
                            <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">{{ __('loans.col_when') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ __('loans.col_movement') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ __('loans.col_item') }}</th>
                                    <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_qty') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ __('loans.col_condition') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ __('loans.col_by') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ __('loans.col_note') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse ($loan->movements as $movement)
                                    <tr>
                                        <td class="px-4 py-3 align-top whitespace-nowrap">{{ $movement->created_at->format('d/m/Y H:i') }}</td>
                                        <td class="px-4 py-3 align-top">{{ __('loans.movement_'.strtolower($movement->movement_type)) }}</td>
                                        <td class="px-4 py-3 align-top">{{ $movement->asset?->item?->name_th }}@if ($movement->asset?->asset_tag) <span class="text-xs font-mono text-ink-faint">{{ $movement->asset->asset_tag }}</span>@endif</td>
                                        <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($movement->qty) }}</td>
                                        <td class="px-4 py-3 align-top">{{ $movement->condition_recorded ? __('loans.condition_'.strtolower($movement->condition_recorded)) : '—' }}</td>
                                        {{-- QA fix (ITM-2610-051): who recorded / decided / took in the replacement of a case is on the
                                             incident page, which the borrower and the on-behalf creator may not open (LoanAccess::canSeeIncidentFiles),
                                             so the person of an incident movement is hidden from them here too. The ledger has no external_ref column here. --}}
                                        <td class="px-4 py-3 align-top">{{ $movement->incident_id === null || $canSeeIncidents ? $movement->creator?->full_name : '—' }}</td>
                                        {{-- Step 4: the notes of incident movements (what happened, the decision) are for the branch's staff only. --}}
                                        <td class="px-4 py-3 align-top text-ink-muted">{{ $movement->incident_id === null || $canSeeIncidents ? $movement->note : '' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="px-4 py-8 text-center text-ink-muted">{{ __('loans.no_movements') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @can('cancelLoan', $loan)
                <form method="POST" action="{{ route('loans.cancel', $loan) }}" class="bg-surface border border-border rounded-xl px-4 py-4 space-y-3">
                    @csrf
                    <h2 class="text-sm font-semibold">{{ __('loans.section_cancel') }}</h2>
                    <div>
                        <label for="cancel_reason" class="block text-xs font-medium mb-1">{{ __('loans.field_cancel_reason') }}</label>
                        <input id="cancel_reason" name="reason" type="text" maxlength="500" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                        <p class="mt-1 text-xs text-ink-faint">{{ __('loans.cancel_reason_hint') }}</p>
                    </div>
                    <button type="submit" class="rounded-lg border border-danger/40 text-danger-ink hover:bg-danger-soft text-sm font-semibold px-5 py-2.5">{{ __('loans.btn_cancel') }}</button>
                </form>
            @endcan
        </div>

        <div class="space-y-5">
            <div class="bg-surface border border-border rounded-xl px-4 py-4">
                <h2 class="text-sm font-semibold mb-3">{{ __('loans.section_request') }}</h2>
                <dl class="text-sm space-y-2">
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_borrower') }}</dt><dd class="text-right">{{ $loan->borrower?->full_name }}</dd></div>
                    @if ($loan->isOnBehalf())
                        <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_created_by') }}</dt><dd class="text-right">{{ $loan->creator?->full_name }}</dd></div>
                    @endif
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.col_lab') }}</dt><dd class="text-right">{{ $loan->lab?->code }} {{ $loan->lab?->name_th }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_purpose') }}</dt><dd class="text-right">{{ __('loans.purpose_'.strtolower($loan->purpose_type)) }}</dd></div>
                    @if ($loan->purpose_detail)
                        <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_purpose_detail') }}</dt><dd class="text-right">{{ $loan->purpose_detail }}</dd></div>
                    @endif
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_loan_date') }}</dt><dd class="text-right">{{ $loan->loan_date->format('d/m/Y') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_due_date') }}</dt><dd class="text-right">{{ $loan->due_date->format('d/m/Y') }}</dd></div>
                    @if ($loan->original_due_date->ne($loan->due_date))
                        <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_original_due') }}</dt><dd class="text-right">{{ $loan->original_due_date->format('d/m/Y') }}</dd></div>
                    @endif
                    @if ($loan->handed_out_at)
                        <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_handed_out_at') }}</dt><dd class="text-right">{{ $loan->handed_out_at->format('d/m/Y H:i') }}</dd></div>
                    @endif
                    @if ($loan->closed_at)
                        <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_closed_at') }}</dt><dd class="text-right">{{ $loan->closed_at->format('d/m/Y H:i') }}</dd></div>
                    @endif
                    @if ($loan->isStudentBorrower())
                        <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_advisor_name') }}</dt><dd class="text-right">{{ $loan->advisor?->full_name }}</dd></div>
                    @endif
                    @if ($loan->remark)
                        <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_remark') }}</dt><dd class="text-right">{{ $loan->remark }}</dd></div>
                    @endif
                    @if ($loan->status === 'REJECTED' && $loan->reject_reason)
                        <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_reject_reason') }}</dt><dd class="text-right">{{ $loan->reject_reason }}</dd></div>
                    @endif
                    @if ($loan->status === 'CANCELLED')
                        <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_cancel_reason') }}</dt><dd class="text-right">{{ $loan->cancel_reason ?: '—' }}</dd></div>
                    @endif
                </dl>
            </div>

            <div class="bg-surface border border-border rounded-xl px-4 py-4">
                <h2 class="text-sm font-semibold mb-3">{{ __('loans.section_approvals') }}</h2>
                @forelse ($loan->approvals as $approval)
                    <div class="text-sm mb-2">
                        <div class="font-medium">{{ __('loans.step_'.strtolower($approval->step)) }}: {{ __('loans.decision_'.strtolower($approval->decision)) }}</div>
                        <div class="text-xs text-ink-faint">{{ $approval->actor?->full_name }} · {{ $approval->acted_at->format('d/m/Y H:i') }}</div>
                        @if ($approval->reason)
                            <div class="text-xs text-ink-muted">{{ $approval->reason }}</div>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-ink-muted">{{ __('loans.no_approvals_yet') }}</p>
                @endforelse
            </div>

            {{-- Step 4: the damage / loss cases of this document. Everyone who may open the document sees that a case
                 exists and where it stands; only the branch's staff (never the borrower) get the link to its page,
                 where the description, the files, the reference and the value are. --}}
            @if ($loan->incidents->isNotEmpty())
                <div class="bg-surface border border-border rounded-xl px-4 py-4">
                    <h2 class="text-sm font-semibold mb-3">{{ __('loans.section_incidents') }}</h2>
                    @foreach ($loan->incidents as $incident)
                        <div class="text-sm mb-2">
                            <div class="font-medium">{{ __('loans.incident_line', ['kind' => __('loans.incident_kind_'.strtolower($incident->kind)), 'qty' => $fmt($incident->qty), 'status' => __('loans.incident_resolution_'.strtolower($incident->resolution))]) }}</div>
                            <div class="text-xs text-ink-faint">{{ $incident->created_at->format('d/m/Y H:i') }}</div>
                            @if ($canSeeIncidents)
                                <a href="{{ route('loans.incidents.show', $incident) }}" class="text-xs font-semibold text-accent hover:text-accent-strong">{{ __('loans.btn_open_incident') }}</a>
                            @elsecan('attachFile', $incident)
                                {{-- The counter person who recorded it but may not open its page (they are the borrower /
                                     typed the loan in): they can still hand in a photo or the letter, without seeing any file. --}}
                                <form method="POST" action="{{ route('loans.incidents.files.store', $incident) }}" enctype="multipart/form-data" class="mt-2 space-y-2">
                                    @csrf
                                    <label for="doc_type_{{ $incident->id }}" class="sr-only">{{ __('loans.field_file_type') }}</label>
                                    <select id="doc_type_{{ $incident->id }}" name="doc_type" class="w-full rounded-lg border border-border bg-surface px-2 py-1.5 text-xs">
                                        <option value="PHOTO">{{ __('loans.file_type_photo') }}</option>
                                        <option value="LOAN_DOC">{{ __('loans.file_type_loan_doc') }}</option>
                                    </select>
                                    <label for="file_{{ $incident->id }}" class="sr-only">{{ __('loans.field_file') }}</label>
                                    <input id="file_{{ $incident->id }}" name="file" type="file" required accept=".pdf,.jpg,.jpeg,.png" class="w-full text-xs">
                                    <button type="submit" class="rounded-lg border border-border hover:bg-surface-alt text-xs font-semibold px-3 py-1.5">{{ __('loans.btn_upload') }}</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Step 3 (FR-LN-15): every change of the due date that was asked for, with its result. --}}
            @if ($handedOut)
                <div class="bg-surface border border-border rounded-xl px-4 py-4">
                    <h2 class="text-sm font-semibold mb-3">{{ __('loans.section_due_history') }}</h2>
                    @forelse ($loan->dueChanges as $change)
                        <div class="text-sm mb-2">
                            <div class="font-medium">{{ __('loans.due_change_line', ['seq' => $change->seq, 'old' => $change->old_due_date->format('d/m/Y'), 'new' => $change->new_due_date->format('d/m/Y')]) }}</div>
                            <div class="text-xs text-ink-faint">
                                {{ __('loans.due_status_'.strtolower($change->status)) }}
                                @if ($change->status === 'APPROVED' && $change->approved_by === null) · {{ __('loans.due_auto') }} @endif
                                · {{ __('loans.due_requested_by', ['name' => $change->requester?->full_name]) }} {{ $change->created_at->format('d/m/Y H:i') }}
                            </div>
                            @if ($change->approver)
                                <div class="text-xs text-ink-faint">{{ __('loans.due_decided_by', ['name' => $change->approver->full_name]) }} {{ $change->acted_at?->format('d/m/Y H:i') }}</div>
                            @endif
                            @if ($change->reason)
                                <div class="text-xs text-ink-muted">{{ $change->reason }}</div>
                            @endif
                            @if ($change->decision_note)
                                <div class="text-xs text-ink-muted">{{ $change->decision_note }}</div>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-ink-muted">{{ __('loans.no_due_changes') }}</p>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
</x-layout>
