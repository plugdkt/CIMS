<x-layout>
    @include('ims._tabs')
    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('ims_transfer.review_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('ims_transfer.review_hint') }}</p>
    </div>

    <div class="bg-surface-alt rounded-xl p-4 mb-4 text-sm">
        <div>{{ __('ims_transfer.field_doc_no') }}: <span class="font-mono font-semibold">{{ $docNo }}</span></div>
        @if ($remark)
            <div class="text-ink-muted mt-1">{{ __('ims_transfer.field_remark') }}: {{ $remark }}</div>
        @endif
        <div class="text-ink-muted mt-1">{{ __('ims_transfer.review_count', ['count' => count($lines)]) }}</div>
    </div>

    @if (collect($lines)->contains('dup', true))
        <div class="mb-4 rounded-lg border border-warning/40 bg-warning-soft text-warning-ink text-sm px-4 py-3" role="alert">
            {{ __('ims_transfer.review_dup_banner') }}
        </div>
    @endif

    <div class="mb-4 rounded-lg border border-border bg-surface text-sm px-4 py-3 text-ink-muted">{{ __('ims_transfer.irreversible') }}</div>

    <div class="bg-surface border border-border rounded-xl overflow-hidden mb-5">
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">#</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims_transfer.col_item') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims_transfer.col_lot') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('ims_transfer.col_qty') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('ims_transfer.col_left_after') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims_transfer.col_location') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($lines as $i => $line)
                        <tr>
                            <td class="px-4 py-3 text-ink-faint">{{ $i + 1 }}</td>
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs text-ink-faint">{{ $line['code'] }}</span>
                                {{ $line['name'] }}
                            </td>
                            <td class="px-4 py-3">
                                {{ $line['lot_no'] ?: '—' }}
                                @if ($line['dup'])
                                    <span class="block text-xs text-warning-ink">{{ __('ims_transfer.lot_already_moved_warning') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono">
                                {{ $line['packs'] }} {{ __('ims_transfer.pack_word') }}
                                <span class="block text-xs text-ink-faint">
                                    @if ($line['pack'] !== null)
                                        {{ __('ims_transfer.review_packs', ['packs' => $line['packs'], 'size' => $line['pack'], 'unit' => $line['unit'], 'total' => $line['qty']]) }}
                                    @else
                                        = {{ $line['qty'] }} {{ $line['unit'] }}
                                    @endif
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right font-mono">{{ $line['remaining_after'] }} {{ $line['unit'] }}</td>
                            <td class="px-4 py-3">{{ $line['location'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <form method="POST" action="{{ route('ims.transfers.store') }}" x-data="{ submitting: false }" @submit="submitting = true" class="flex flex-wrap items-center gap-3">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="ims_doc_no" value="{{ $docNo }}">
        <input type="hidden" name="remark" value="{{ $remark }}">
        @foreach ($rows as $i => $row)
            <input type="hidden" name="rows[{{ $i }}][lot]" value="{{ $row['lot'] }}">
            <input type="hidden" name="rows[{{ $i }}][qty]" value="{{ $row['qty'] }}">
            <input type="hidden" name="rows[{{ $i }}][location]" value="{{ $row['location'] }}">
        @endforeach

        <button type="submit" :disabled="submitting" class="rounded-lg bg-success hover:bg-success-ink text-white text-sm font-bold px-6 py-2.5 disabled:opacity-60">
            {{ __('ims_transfer.btn_confirm') }}
        </button>
        <a href="{{ route('ims.transfers.create', ['draft' => 1]) }}" class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-4 py-2.5">{{ __('ims_transfer.btn_edit') }}</a>
    </form>
</x-layout>
