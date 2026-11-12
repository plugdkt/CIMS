<x-layout>
    {{-- ITM-2610-051 step 4 (FR-LN-23/24): one damage / loss case: details, files, and recording its result.
         Only the branch's staff and ADMIN reach this page (LoanIncidentPolicy::view), never the borrower. --}}
    @php
        $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
        $asset = $incident->line?->asset;
        $lost = $incident->kind === 'LOST';
    @endphp

    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('loans.incident_title') }} <span class="font-mono">{{ $loan?->doc_no }}</span></h1>
            <div class="mt-1">
                <span class="inline-block rounded px-2 py-0.5 text-xs font-semibold {{ $incident->isPending() ? 'bg-warning-soft text-warning-ink' : 'bg-success-soft text-success-ink' }}">{{ __('loans.incident_resolution_'.strtolower($incident->resolution)) }}</span>
            </div>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('loans.incidents.index') }}" class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-4 py-2.5">{{ __('loans.btn_back') }}</a>
            @if ($loan)
                <a href="{{ route('loans.show', $loan) }}" class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-4 py-2.5">{{ __('loans.show_title') }}</a>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-success-soft text-success-ink text-sm px-4 py-3">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach (array_unique($errors->all()) as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-5">
            <div class="bg-surface border border-border rounded-xl px-4 py-4">
                <h2 class="text-sm font-semibold mb-3">{{ __('loans.section_incident') }}</h2>
                <dl class="text-sm space-y-2">
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.col_item') }}</dt><dd class="text-right">{{ $asset?->item?->name_th }} <span class="text-xs font-mono text-ink-faint">{{ $asset?->item?->item_code }}@if ($asset?->asset_tag) · {{ $asset->asset_tag }}@endif</span></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.col_kind') }}</dt><dd class="text-right">{{ __('loans.incident_kind_'.strtolower($incident->kind)) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.col_qty') }}</dt><dd class="text-right font-mono">{{ $fmt($incident->qty) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_description') }}</dt><dd class="text-right">{{ $incident->description }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_borrower') }}</dt><dd class="text-right">{{ $loan?->borrower?->full_name }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_recorded_by') }}</dt><dd class="text-right">{{ $incident->recorder?->full_name }} · {{ $incident->created_at->format('d/m/Y H:i') }}</dd></div>
                    @unless ($incident->isPending())
                        <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_resolution') }}</dt><dd class="text-right">{{ __('loans.incident_resolution_'.strtolower($incident->resolution)) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_disposition') }}</dt><dd class="text-right">{{ $incident->disposition ? __('loans.incident_disposition_'.strtolower($incident->disposition)) : '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_incident_ref') }}</dt><dd class="text-right">{{ $incident->external_ref ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_resolution_note') }}</dt><dd class="text-right">{{ $incident->resolution_note }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_resolved_by') }}</dt><dd class="text-right">{{ $incident->resolver?->full_name }} · {{ $incident->resolved_at?->format('d/m/Y H:i') }}</dd></div>
                        @if ($canSeeValue && $incident->value_snapshot !== null)
                            <div class="flex justify-between gap-3"><dt class="text-ink-faint">{{ __('loans.field_value_snapshot') }}</dt><dd class="text-right font-mono">{{ number_format((float) $incident->value_snapshot, 2) }}</dd></div>
                        @endif
                    @endunless
                </dl>
                @unless ($incident->isPending())
                    <p class="mt-3 text-xs text-ink-faint">{{ __('loans.incident_final_note') }}</p>
                @endunless
            </div>

            @if ($incident->movements->isNotEmpty())
                <div class="bg-surface border border-border rounded-xl overflow-hidden">
                    <div class="px-4 py-3 border-b border-border"><h2 class="text-sm font-semibold">{{ __('loans.section_log') }}</h2></div>
                    <div class="overflow-x-auto" tabindex="0">
                        <table class="w-full text-sm">
                            <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">{{ __('loans.col_when') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ __('loans.col_movement') }}</th>
                                    <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_qty') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ __('loans.col_by') }}</th>
                                    <th class="px-4 py-3 font-semibold">{{ __('loans.approved_by') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach ($incident->movements as $movement)
                                    <tr>
                                        <td class="px-4 py-3 align-top whitespace-nowrap">{{ $movement->created_at->format('d/m/Y H:i') }}</td>
                                        <td class="px-4 py-3 align-top">{{ __('loans.movement_'.strtolower($movement->movement_type)) }}</td>
                                        <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($movement->qty) }}</td>
                                        <td class="px-4 py-3 align-top">{{ $movement->creator?->full_name }}</td>
                                        <td class="px-4 py-3 align-top">{{ $movement->approver?->full_name ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if ($canResolve)
                <form method="POST" action="{{ route('loans.incidents.resolve', $incident) }}" class="bg-surface border border-border rounded-xl px-4 py-4 space-y-3">
                    @csrf
                    <h2 class="text-sm font-semibold">{{ __('loans.section_resolve') }}</h2>
                    <p class="text-xs text-ink-muted">{{ __('loans.resolve_hint') }}</p>
                    <p class="text-xs text-ink-muted">{{ __($lost ? 'loans.resolve_rules_lost' : 'loans.resolve_rules_damaged') }}</p>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="resolution" class="block text-xs font-medium mb-1">{{ __('loans.field_resolution') }}</label>
                            <select id="resolution" name="resolution" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                                @foreach ($lost ? ['COMPENSATED', 'WAIVED', 'REPLACED'] : ['REPAIRED', 'COMPENSATED', 'WAIVED', 'REPLACED'] as $resolution)
                                    <option value="{{ $resolution }}" @selected(old('resolution') === $resolution)>{{ __('loans.incident_resolution_'.strtolower($resolution)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        @unless ($lost)
                            <div>
                                <label for="disposition" class="block text-xs font-medium mb-1">{{ __('loans.field_disposition') }}</label>
                                <select id="disposition" name="disposition" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                                    <option value="">{{ __('loans.receiver_choose') }}</option>
                                    @foreach (['RETURN_TO_SERVICE', 'WRITE_OFF'] as $disposition)
                                        <option value="{{ $disposition }}" @selected(old('disposition') === $disposition)>{{ __('loans.incident_disposition_'.strtolower($disposition)) }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-ink-faint">{{ __('loans.disposition_hint') }}</p>
                            </div>
                            <div>
                                <label for="condition_after" class="block text-xs font-medium mb-1">{{ __('loans.field_condition_after') }}</label>
                                <select id="condition_after" name="condition_after" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                                    <option value="">{{ __('loans.receiver_choose') }}</option>
                                    @foreach (['GOOD', 'FAIR'] as $condition)
                                        <option value="{{ $condition }}" @selected(old('condition_after') === $condition)>{{ __('loans.condition_'.strtolower($condition)) }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-ink-faint">{{ __('loans.condition_after_hint') }}</p>
                            </div>
                        @endunless
                        <div>
                            <label for="external_ref" class="block text-xs font-medium mb-1">{{ __('loans.field_incident_ref') }}</label>
                            <input id="external_ref" name="external_ref" type="text" maxlength="255" value="{{ old('external_ref') }}" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                            <p class="mt-1 text-xs text-ink-faint">{{ __('loans.incident_ref_hint') }}</p>
                        </div>
                        <div>
                            <label for="receiver_id" class="block text-xs font-medium mb-1">{{ __('loans.field_receiver') }}</label>
                            <select id="receiver_id" name="receiver_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                                <option value="">{{ __('loans.receiver_choose') }}</option>
                                @foreach ($receivers as $receiver)
                                    <option value="{{ $receiver->id }}" @selected((string) old('receiver_id') === (string) $receiver->id)>{{ $receiver->full_name }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-ink-faint">{{ __('loans.receiver_hint') }}</p>
                        </div>
                    </div>
                    <div>
                        <label for="resolution_note" class="block text-xs font-medium mb-1">{{ __('loans.field_resolution_note') }}</label>
                        <textarea id="resolution_note" name="resolution_note" rows="3" maxlength="500" required class="w-full rounded-lg border border-border px-3 py-2 text-sm">{{ old('resolution_note') }}</textarea>
                        <p class="mt-1 text-xs text-ink-faint">{{ __('loans.resolution_note_hint') }}</p>
                    </div>
                    <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">{{ __('loans.btn_resolve') }}</button>
                </form>
            @elseif ($resolveProblem)
                <div class="rounded-lg border border-border bg-surface-alt px-4 py-3 text-sm text-ink-muted">{{ __($resolveProblem) }}</div>
            @endif
        </div>

        <div class="space-y-5">
            <div class="bg-surface border border-border rounded-xl px-4 py-4">
                <h2 class="text-sm font-semibold mb-3">{{ __('loans.section_incident_files') }}</h2>
                @forelse ($incident->attachments as $attachment)
                    <div class="text-sm mb-2">
                        <div class="font-medium break-all">{{ $attachment->original_name }}</div>
                        <div class="text-xs text-ink-faint">{{ __('loans.file_type_'.strtolower($attachment->doc_type)) }} · {{ $attachment->uploader?->full_name }} · {{ $attachment->created_at?->format('d/m/Y H:i') }}</div>
                        <a href="{{ route('loans.incidents.files.show', ['loan_incident' => $incident, 'attachment' => $attachment]) }}" class="text-xs font-semibold text-accent hover:text-accent-strong">{{ __('loans.btn_download') }}</a>
                    </div>
                @empty
                    <p class="text-sm text-ink-muted">{{ __('loans.incident_files_empty') }}</p>
                @endforelse

                @if ($canAttach)
                    <form method="POST" action="{{ route('loans.incidents.files.store', $incident) }}" enctype="multipart/form-data" class="mt-4 space-y-3 border-t border-border pt-4">
                        @csrf
                        <div>
                            <label for="doc_type" class="block text-xs font-medium mb-1">{{ __('loans.field_file_type') }}</label>
                            <select id="doc_type" name="doc_type" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                                <option value="PHOTO" @selected(old('doc_type') === 'PHOTO')>{{ __('loans.file_type_photo') }}</option>
                                <option value="LOAN_DOC" @selected(old('doc_type') === 'LOAN_DOC')>{{ __('loans.file_type_loan_doc') }}</option>
                            </select>
                        </div>
                        <div>
                            <label for="file" class="block text-xs font-medium mb-1">{{ __('loans.field_file') }}</label>
                            <input id="file" name="file" type="file" required accept=".pdf,.jpg,.jpeg,.png" class="w-full text-sm">
                        </div>
                        <p class="text-xs text-ink-faint">{{ __('loans.incident_files_hint') }}</p>
                        <button type="submit" class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-4 py-2">{{ __('loans.btn_upload') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-layout>
