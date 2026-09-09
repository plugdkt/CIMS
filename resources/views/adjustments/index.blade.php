<x-layout>
    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('adjustments.index_title') }}</h1>
            <p class="text-sm text-ink-muted mt-1">{{ __('adjustments.index_subtitle') }}</p>
        </div>
        <a href="{{ route('adjustments.create') }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5 whitespace-nowrap">
            {{ __('adjustments.new_adjustment') }}
        </a>
    </div>

    <div class="bg-surface border border-border rounded-xl overflow-hidden">
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">{{ __('adjustments.col_date') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('adjustments.col_item') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('adjustments.col_container') }}</th>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('adjustments.col_type') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('adjustments.col_qty') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('adjustments.col_remark') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('adjustments.col_created_by') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('adjustments.col_approved_by') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($adjustments as $row)
                        <tr>
                            <td class="px-4 py-3 align-top text-ink-muted whitespace-nowrap">{{ $row->created_at->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-3 align-top">{{ $row->item?->name_th }}</td>
                            <td class="px-4 py-3 align-top font-mono text-xs text-ink-muted">{{ $row->container?->barcode }}</td>
                            <td class="px-4 py-3 align-top whitespace-nowrap">
                                @php
                                    $badge = $row->txn_type === 'ADJUST_IN' ? 'bg-success-soft text-success-ink' : 'bg-danger-soft text-danger-ink';
                                @endphp
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold {{ $badge }}">
                                    {{ __('adjustments.type_'.strtolower($row->txn_type)) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 align-top text-right font-mono">
                                {{ $row->txn_type === 'ADJUST_IN' ? $row->qty_in_base : $row->qty_out_base }}
                            </td>
                            <td class="px-4 py-3 align-top">{{ $row->remark }}</td>
                            <td class="px-4 py-3 align-top">{{ $row->creator?->full_name }}</td>
                            <td class="px-4 py-3 align-top">{{ $row->approver?->full_name }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-ink-muted text-sm">
                                {{ __('adjustments.no_results') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $adjustments->links() }}
    </div>
</x-layout>
