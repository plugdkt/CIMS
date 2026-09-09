<x-layout>
    <div class="max-w-2xl">
        <div class="mb-5">
            <h1 class="font-display text-lg font-bold">{{ __('privacy.my_data_title') }}</h1>
            <p class="text-sm text-ink-muted mt-1">{{ __('privacy.my_data_subtitle') }}</p>
        </div>

        <div class="bg-surface border border-border rounded-xl overflow-hidden mb-4">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-border">
                    @php
                        $rows = [
                            'field_full_name' => $user->full_name,
                            'field_username' => $user->username,
                            'field_email' => $user->email,
                            'field_phone' => $user->phone_encrypted,
                            'field_person_code' => $user->person_code_encrypted,
                            'field_person_type' => $user->person_type,
                            'field_program' => $user->program,
                            'field_faculty' => $user->faculty,
                            'field_lab' => $user->lab?->name_th,
                            'field_advisor' => $user->advisor?->full_name,
                            'field_roles' => $user->roles->pluck('name_th')->implode(', '),
                            'field_privacy_consent_at' => $user->privacy_consent_at?->format('d/m/Y H:i'),
                            'field_last_login_at' => $user->last_login_at?->format('d/m/Y H:i'),
                        ];
                    @endphp
                    @foreach ($rows as $labelKey => $value)
                        <tr>
                            <td class="px-4 py-2.5 text-ink-muted whitespace-nowrap w-1/3">{{ __('privacy.'.$labelKey) }}</td>
                            <td class="px-4 py-2.5">{{ $value ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <a href="{{ route('account.my-data.export') }}" class="inline-block rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5 mb-2">
            {{ __('privacy.my_data_export') }}
        </a>
        <p class="text-xs text-ink-faint mb-6">{{ __('privacy.my_data_correction_note') }}</p>

        <h2 class="font-semibold text-sm mb-3">{{ __('privacy.my_requisitions_title') }}</h2>
        <div class="bg-surface border border-border rounded-xl overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">{{ __('reports.col_doc_no') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('reports.col_date') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('requisitions.col_status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($requisitions as $requisition)
                        <tr>
                            <td class="px-4 py-3 font-mono text-xs text-ink-muted">{{ $requisition->doc_no }}</td>
                            <td class="px-4 py-3 text-ink-muted">{{ $requisition->doc_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-3">{{ $requisition->status }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-8 text-center text-ink-muted text-sm">
                                {{ __('privacy.no_requisitions') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layout>
