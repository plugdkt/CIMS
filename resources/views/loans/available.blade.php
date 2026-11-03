<x-layout>
    @php $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp

    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('loans.available_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('loans.available_subtitle') }}</p>
    </div>

    <form method="GET" action="{{ route('loans.available') }}" class="flex flex-wrap items-end gap-3 mb-5">
        <div>
            <label for="q" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.filter_search') }}</label>
            <input id="q" name="q" type="search" value="{{ request('q') }}" class="w-full sm:w-72 rounded-lg border border-border px-3 py-2 text-sm">
        </div>
        @if ($isAdmin)
            <div>
                <label for="lab_id" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.col_lab') }}</label>
                <select id="lab_id" name="lab_id" class="rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <option value="">{{ __('loans.filter_all_labs') }}</option>
                    @foreach ($labs as $lab)
                        <option value="{{ $lab->id }}" @selected((string) request('lab_id') === (string) $lab->id)>{{ $lab->code }} {{ $lab->name_th }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <button type="submit" class="rounded-lg border border-border text-sm font-semibold px-4 py-2 hover:bg-surface-alt">{{ __('loans.btn_search') }}</button>
    </form>

    <div class="bg-surface border border-border rounded-xl overflow-hidden">
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">{{ __('loans.col_item') }}</th>
                        @if ($isAdmin)
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_lab') }}</th>
                        @endif
                        <th class="px-4 py-3 font-semibold">{{ __('loans.col_location') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_total') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_on_loan') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_unavailable') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_available') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($assets as $asset)
                        @php $available = $asset->available(); @endphp
                        <tr>
                            <td class="px-4 py-3 align-top">
                                <div class="font-medium">{{ $asset->item?->name_th }}</div>
                                <div class="text-xs font-mono text-ink-faint">{{ $asset->item?->item_code }}@if ($asset->asset_tag) · {{ $asset->asset_tag }}@endif</div>
                            </td>
                            @if ($isAdmin)
                                <td class="px-4 py-3 align-top">{{ $asset->lab?->code }}</td>
                            @endif
                            <td class="px-4 py-3 align-top">{{ $asset->location?->name ?: '—' }}</td>
                            <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($asset->qty_total) }}</td>
                            <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($asset->qty_on_loan) }}</td>
                            <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($asset->qty_unavailable) }}</td>
                            <td class="px-4 py-3 align-top text-right">
                                <span @class([
                                    'inline-block rounded px-2 py-0.5 text-xs font-bold font-mono',
                                    'bg-success-soft text-success-ink' => bccomp($available, '0', 6) > 0,
                                    'bg-danger-soft text-danger-ink' => bccomp($available, '0', 6) <= 0,
                                ])>{{ $fmt($available) }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $isAdmin ? 7 : 6 }}" class="px-4 py-8 text-center text-ink-muted">{{ __('loans.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $assets->links() }}</div>
</x-layout>
