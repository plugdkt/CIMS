<div>
    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('goods_receipts.index_title') }}</h1>
            <p class="text-sm text-ink-muted mt-1">{{ __('goods_receipts.index_subtitle') }}</p>
        </div>
        <a href="{{ route('goods-receipts.create') }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5 whitespace-nowrap">
            {{ __('goods_receipts.new_grn') }}
        </a>
    </div>

    <div class="bg-surface border border-border rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">{{ __('goods_receipts.col_doc_no') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('goods_receipts.col_receipt_date') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('goods_receipts.col_supplier') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('goods_receipts.col_lab') }}</th>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('goods_receipts.col_status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($goodsReceipts as $goodsReceipt)
                        <tr wire:key="grn-{{ $goodsReceipt->id }}">
                            <td class="px-4 py-3 align-top font-mono text-xs text-ink-muted">{{ $goodsReceipt->doc_no }}</td>
                            <td class="px-4 py-3 align-top text-ink-muted">{{ $goodsReceipt->receipt_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 align-top">{{ $goodsReceipt->supplier }}</td>
                            <td class="px-4 py-3 align-top text-ink-muted">{{ $goodsReceipt->lab?->name_th }}</td>
                            <td class="px-4 py-3 align-top whitespace-nowrap">
                                @php
                                    $badge = match ($goodsReceipt->status) {
                                        'CONFIRMED' => 'bg-success-soft text-success-ink',
                                        'CANCELLED' => 'bg-danger-soft text-danger-ink',
                                        default => 'bg-neutral-soft text-neutral-ink',
                                    };
                                @endphp
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold {{ $badge }}">
                                    {{ __('goods_receipts.status_'.strtolower($goodsReceipt->status)) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 align-top text-right">
                                <a href="{{ route('goods-receipts.show', $goodsReceipt) }}" class="text-xs font-semibold text-accent hover:text-accent-strong">
                                    {{ __('goods_receipts.view') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-ink-muted text-sm">
                                {{ __('goods_receipts.no_results') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $goodsReceipts->links() }}
    </div>
</div>
