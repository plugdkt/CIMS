<x-layout>
    {{-- ITM-2610-051 step 4 (FR-LN-24): the branch's damage / loss cases. No value, no file on this page. --}}
    @php $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp

    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('loans.incidents_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('loans.incidents_subtitle') }}</p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-success-soft text-success-ink text-sm px-4 py-3">{{ session('status') }}</div>
    @endif

    @php
        $sections = [
            ['title' => __('loans.incidents_pending_section'), 'rows' => $pending, 'resolved' => false],
            ['title' => __('loans.incidents_resolved_section'), 'rows' => $resolved, 'resolved' => true],
        ];
    @endphp

    @foreach ($sections as $section)
        <h2 class="text-sm font-semibold mb-2 mt-6">{{ $section['title'] }}</h2>
        <div class="bg-surface border border-border rounded-xl overflow-hidden">
            <div class="overflow-x-auto" tabindex="0">
                <table class="w-full text-sm">
                    <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_doc_no') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_lab') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_item') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_kind') }}</th>
                            <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_qty') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_recorded_at') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_result') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($section['rows'] as $incident)
                            <tr>
                                <td class="px-4 py-3 align-top font-mono">{{ $incident->loan?->doc_no }}</td>
                                <td class="px-4 py-3 align-top">{{ $incident->loan?->lab?->code }}</td>
                                <td class="px-4 py-3 align-top">
                                    <div class="font-medium">{{ $incident->line?->asset?->item?->name_th }}</div>
                                    <div class="text-xs font-mono text-ink-faint">{{ $incident->line?->asset?->item?->item_code }}@if ($incident->line?->asset?->asset_tag) · {{ $incident->line->asset->asset_tag }}@endif</div>
                                </td>
                                <td class="px-4 py-3 align-top">{{ __('loans.incident_kind_'.strtolower($incident->kind)) }}</td>
                                <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($incident->qty) }}</td>
                                <td class="px-4 py-3 align-top whitespace-nowrap">{{ $incident->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-3 align-top">
                                    {{ __('loans.incident_resolution_'.strtolower($incident->resolution)) }}
                                    @if ($section['resolved'] && $incident->disposition)
                                        <div class="text-xs text-ink-faint">{{ __('loans.incident_disposition_'.strtolower($incident->disposition)) }} · {{ $incident->resolved_at?->format('d/m/Y') }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top text-right whitespace-nowrap">
                                    <a href="{{ route('loans.incidents.show', $incident) }}" class="rounded-lg border border-border hover:bg-surface-alt text-xs font-semibold px-3 py-1.5">{{ __('loans.btn_open') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-8 text-center text-ink-muted">{{ __('loans.incidents_empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($section['resolved'])
            <div class="mt-3">{{ $resolved->links() }}</div>
        @endif
    @endforeach
</x-layout>
