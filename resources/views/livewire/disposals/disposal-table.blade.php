<div>
    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('disposals.index_title') }}</h1>
            <p class="text-sm text-ink-muted mt-1">{{ __('disposals.index_subtitle') }}</p>
        </div>
        @can('create', App\Models\Disposal::class)
            <a href="{{ route('disposals.create') }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5 whitespace-nowrap">
                {{ __('disposals.new_disposal') }}
            </a>
        @endcan
    </div>

    <div class="bg-surface border border-border rounded-xl overflow-hidden">
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">{{ __('disposals.col_doc_no') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('disposals.col_item') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('disposals.col_reason') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('disposals.col_disposal_date') }}</th>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('disposals.col_status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($disposals as $disposal)
                        <tr wire:key="dsp-{{ $disposal->id }}">
                            <td class="px-4 py-3 align-top font-mono text-xs text-ink-muted">{{ $disposal->doc_no }}</td>
                            <td class="px-4 py-3 align-top">{{ $disposal->container?->item?->name_th }}</td>
                            <td class="px-4 py-3 align-top">{{ __('disposals.reason_'.strtolower($disposal->reason)) }}</td>
                            <td class="px-4 py-3 align-top text-ink-muted">{{ $disposal->disposal_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 align-top whitespace-nowrap">
                                @php
                                    $badge = match ($disposal->status) {
                                        'APPROVED' => 'bg-success-soft text-success-ink',
                                        'REJECTED' => 'bg-danger-soft text-danger-ink',
                                        default => 'bg-neutral-soft text-neutral-ink',
                                    };
                                @endphp
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold {{ $badge }}">
                                    {{ __('disposals.status_'.strtolower($disposal->status)) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 align-top text-right">
                                <a href="{{ route('disposals.show', $disposal) }}" class="text-xs font-semibold text-accent hover:text-accent-strong">
                                    {{ __('disposals.view') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-ink-muted text-sm">
                                {{ __('disposals.no_results') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $disposals->links() }}
    </div>
</div>
