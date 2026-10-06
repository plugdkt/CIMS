<x-layout>
    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('ims.receipts_title') }}</h1>
            <p class="text-sm text-ink-muted mt-1">{{ __('ims.receipts_subtitle') }}</p>
        </div>
        @can('create', App\Models\ImsReceipt::class)
            <a href="{{ route('ims.receipts.create') }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5 whitespace-nowrap">
                {{ __('ims.new_receipt') }}
            </a>
        @endcan
    </div>

    <div class="bg-surface border border-border rounded-xl overflow-hidden">
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_doc_no') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_fiscal_year') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_round') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_branch') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('ims.col_lines') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_status') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_created_by') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_created_at') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($receipts as $receipt)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('ims.receipts.show', $receipt) }}" class="font-semibold text-accent hover:text-accent-strong">
                                    {{ $receipt->doc_no ?: '—' }}
                                </a>
                            </td>
                            <td class="px-4 py-3">{{ $receipt->fiscal_year ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $receipt->purchase_round ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $receipt->lab?->name_th }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ $receipt->lines_count }}</td>
                            <td class="px-4 py-3">{{ __('ims.status_'.strtolower($receipt->status)) }}</td>
                            <td class="px-4 py-3">{{ $receipt->creator?->full_name }}</td>
                            <td class="px-4 py-3 text-ink-muted whitespace-nowrap">{{ $receipt->created_at?->format('d/m/Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-ink-muted text-sm">{{ __('ims.no_receipts') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $receipts->links() }}</div>
</x-layout>
