<div>
    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('stock_takes.index_title') }}</h1>
            <p class="text-sm text-ink-muted mt-1">{{ __('stock_takes.index_subtitle') }}</p>
        </div>
        @can('create', App\Models\StockTake::class)
            <a href="{{ route('stock-takes.create') }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5 whitespace-nowrap">
                {{ __('stock_takes.new_round') }}
            </a>
        @endcan
    </div>

    <div class="bg-surface border border-border rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">{{ __('stock_takes.col_doc_no') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('stock_takes.col_count_date') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('stock_takes.col_lab') }}</th>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('stock_takes.col_status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($stockTakes as $stockTake)
                        <tr wire:key="st-{{ $stockTake->id }}">
                            <td class="px-4 py-3 align-top font-mono text-xs text-ink-muted">{{ $stockTake->doc_no }}</td>
                            <td class="px-4 py-3 align-top text-ink-muted">{{ $stockTake->count_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 align-top">{{ $stockTake->lab?->name_th }}</td>
                            <td class="px-4 py-3 align-top whitespace-nowrap">
                                @php
                                    $badge = match ($stockTake->status) {
                                        'APPROVED' => 'bg-success-soft text-success-ink',
                                        'CANCELLED' => 'bg-danger-soft text-danger-ink',
                                        'PENDING_APPROVAL' => 'bg-accent-soft text-accent-soft-ink',
                                        default => 'bg-neutral-soft text-neutral-ink',
                                    };
                                @endphp
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold {{ $badge }}">
                                    {{ __('stock_takes.status_'.strtolower($stockTake->status)) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 align-top text-right">
                                <a href="{{ route('stock-takes.show', $stockTake) }}" class="text-xs font-semibold text-accent hover:text-accent-strong">
                                    {{ __('stock_takes.view') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-ink-muted text-sm">
                                {{ __('stock_takes.no_results') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $stockTakes->links() }}
    </div>
</div>
