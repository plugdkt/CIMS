<x-layout>
    <div class="max-w-4xl">
        <div class="mb-5">
            <a href="{{ route('stock-takes.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('stock_takes.back_to_list') }}</a>
            <div class="flex items-center justify-between mt-1">
                <h1 class="font-display text-lg font-bold">{{ $stockTake->doc_no }}</h1>
                @php
                    $badge = match ($stockTake->status) {
                        'APPROVED' => 'bg-success-soft text-success-ink',
                        'CANCELLED' => 'bg-danger-soft text-danger-ink',
                        'PENDING_APPROVAL' => 'bg-accent-soft text-accent-soft-ink',
                        default => 'bg-neutral-soft text-neutral-ink',
                    };
                @endphp
                <span class="px-2.5 py-1 rounded-full text-xs font-semibold whitespace-nowrap {{ $badge }}">
                    {{ __('stock_takes.status_'.strtolower($stockTake->status)) }}
                </span>
            </div>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded-lg bg-success-soft text-success-ink text-sm px-4 py-3">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="bg-surface border border-border rounded-xl p-6">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('stock_takes.field_lab') }}</dt>
                    <dd>{{ $stockTake->lab?->name_th }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('stock_takes.field_count_date') }}</dt>
                    <dd>{{ $stockTake->count_date->format('d/m/Y') }}</dd>
                </div>
            </dl>
        </div>

        @if ($canCount)
            <div class="mt-6">
                <a href="{{ route('stock-takes.scan', $stockTake) }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5 inline-block">
                    {{ __('stock_takes.go_to_scan') }}
                </a>
            </div>
        @endif

        <div class="bg-surface border border-border rounded-xl p-6 mt-6">
            <h2 class="font-display text-base font-bold mb-3">{{ __('stock_takes.lines_title') }}</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="px-3 py-2 font-semibold">{{ __('stock_takes.col_barcode') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('stock_takes.field_item') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('stock_takes.col_system_qty') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('stock_takes.col_counted_qty') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('stock_takes.col_diff') }}</th>
                            <th class="px-3 py-2 font-semibold">{{ __('stock_takes.col_counted_by') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($stockTake->lines as $line)
                            <tr>
                                <td class="px-3 py-2 align-top font-mono text-xs">{{ $line->container?->barcode }}</td>
                                <td class="px-3 py-2 align-top">{{ $line->container?->item?->name_th }}</td>
                                <td class="px-3 py-2 align-top">{{ $line->system_qty_base }}</td>
                                <td class="px-3 py-2 align-top">{{ $line->counted_qty_base ?? '—' }}</td>
                                <td class="px-3 py-2 align-top {{ $line->diff_base && (float) $line->diff_base !== 0.0 ? 'text-danger-ink font-semibold' : '' }}">
                                    {{ $line->diff_base ?? '—' }}
                                </td>
                                <td class="px-3 py-2 align-top text-ink-muted">{{ $line->countedBy?->full_name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex items-center gap-3 mt-6">
            @if ($canSubmit)
                <form method="POST" action="{{ route('stock-takes.submit', $stockTake) }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">
                        {{ __('stock_takes.submit_for_approval') }}
                    </button>
                </form>
            @endif
            @if ($canApprove)
                <form method="POST" action="{{ route('stock-takes.approve', $stockTake) }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">
                        {{ __('stock_takes.approve') }}
                    </button>
                </form>
            @endif
            @if ($canCancel)
                <form method="POST" action="{{ route('stock-takes.cancel', $stockTake) }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-5 py-2.5">
                        {{ __('stock_takes.cancel') }}
                    </button>
                </form>
            @endif
        </div>
    </div>
</x-layout>
