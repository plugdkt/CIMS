<x-layout>
    @php
        $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
        $me = auth()->user();
        $canManager = $me->can('managerDecideLoan', $loan);
        $canAdvisor = $me->can('advisorDecideLoan', $loan);
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
            @can('submitLoan', $loan)
                <form method="POST" action="{{ route('loans.submit', $loan) }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">{{ __('loans.btn_submit') }}</button>
                </form>
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
        </div>
    </div>
</x-layout>
