<x-layout>
    @php $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp

    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('loans.registry_title') }}</h1>
            <p class="text-sm text-ink-muted mt-1">{{ __('loans.registry_subtitle') }}</p>
        </div>
        @can('registerAsset', App\Models\LoanAsset::class)
            <a href="{{ route('loans.assets.create') }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5 whitespace-nowrap">{{ __('loans.btn_register') }}</a>
        @endcan
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-success-soft text-success-ink text-sm px-4 py-3">{{ session('status') }}</div>
    @endif

    <form method="GET" action="{{ route('loans.assets.index') }}" class="flex flex-wrap items-end gap-3 mb-5">
        <div>
            <label for="q" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.filter_search') }}</label>
            <input id="q" name="q" type="search" value="{{ request('q') }}" class="w-full sm:w-72 rounded-lg border border-border px-3 py-2 text-sm">
        </div>
        <div>
            <label for="status" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.col_status') }}</label>
            <select id="status" name="status" class="rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                <option value="">{{ __('loans.filter_all') }}</option>
                <option value="ACTIVE" @selected(request('status') === 'ACTIVE')>{{ __('loans.status_active') }}</option>
                <option value="RETIRED" @selected(request('status') === 'RETIRED')>{{ __('loans.status_retired') }}</option>
            </select>
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
                        <th class="px-4 py-3 font-semibold">{{ __('loans.col_mode') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_total') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_on_loan') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_unavailable') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_available') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('loans.col_status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($assets as $asset)
                        <tr>
                            <td class="px-4 py-3 align-top">
                                <a href="{{ route('loans.assets.show', $asset) }}" class="font-medium text-accent hover:text-accent-strong">{{ $asset->item?->name_th }}</a>
                                <div class="text-xs font-mono text-ink-faint">{{ $asset->item?->item_code }}@if ($asset->asset_tag) · {{ $asset->asset_tag }}@endif</div>
                            </td>
                            @if ($isAdmin)
                                <td class="px-4 py-3 align-top">{{ $asset->lab?->code }}</td>
                            @endif
                            <td class="px-4 py-3 align-top">{{ __('loans.mode_'.strtolower($asset->tracking_mode)) }}</td>
                            <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($asset->qty_total) }}</td>
                            <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($asset->qty_on_loan) }}</td>
                            <td class="px-4 py-3 align-top text-right font-mono">{{ $fmt($asset->qty_unavailable) }}</td>
                            <td class="px-4 py-3 align-top text-right font-mono font-semibold">{{ $fmt($asset->available()) }}</td>
                            <td class="px-4 py-3 align-top">{{ $asset->status === 'ACTIVE' ? __('loans.status_active') : __('loans.status_retired') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $isAdmin ? 8 : 7 }}" class="px-4 py-8 text-center text-ink-muted">{{ __('loans.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $assets->links() }}</div>
</x-layout>
