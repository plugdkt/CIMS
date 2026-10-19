<x-layout>
    @include('ims._tabs')
    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('ims.lots_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('ims.lots_subtitle') }}</p>
    </div>

    @if (session('label_container_ids'))
        <div class="mb-5 rounded-xl border border-accent/40 bg-accent-soft/30 p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <h4 class="text-sm font-bold text-accent-strong">{{ __('ims.print_labels') }}</h4>
            <div class="flex items-center gap-2">
                <a href="{{ route('stock-in.labels', ['size' => '40x25', 'ids' => session('label_container_ids')]) }}" target="_blank"
                   class="px-3 py-1.5 rounded-lg bg-surface border border-border text-xs font-semibold hover:bg-surface-alt">40×25 mm</a>
                <a href="{{ route('stock-in.labels', ['size' => '50x30', 'ids' => session('label_container_ids')]) }}" target="_blank"
                   class="px-3 py-1.5 rounded-lg bg-surface border border-border text-xs font-semibold hover:bg-surface-alt">50×30 mm</a>
            </div>
        </div>
    @endif

    @if ($unplacedCount > 0)
        <div class="mb-4 rounded-lg border border-warning/40 bg-warning-soft text-warning-ink px-4 py-3 text-sm flex flex-wrap items-center gap-3">
            <span class="font-semibold">⚠️ {{ __('ims.unplaced_banner', ['count' => $unplacedCount]) }}</span>
            @unless ($noLocation)
                <a href="{{ route('ims.lots.index', ['no_location' => 1]) }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-xs font-semibold px-3 py-1.5">{{ __('ims.unplaced_show') }}</a>
            @endunless
        </div>
    @endif

    <form method="GET" action="{{ route('ims.lots.index') }}" class="flex flex-wrap items-center gap-3 mb-4">
        <label for="search" class="sr-only">{{ __('ims.search_placeholder') }}</label>
        <input id="search" name="search" type="search" value="{{ $search }}" placeholder="{{ __('ims.search_placeholder') }}"
               class="w-72 rounded-lg border border-border px-3 py-2 text-sm">
        <label for="category" class="sr-only">{{ __('ims.col_category') }}</label>
        <select id="category" name="category" class="rounded-lg border border-border px-3 py-2 text-sm">
            <option value="">{{ __('items.filter_all_categories') }}</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name_th }}</option>
            @endforeach
        </select>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="show_empty" value="1" @checked($showEmpty)>
            {{ __('ims.show_empty') }}
        </label>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="no_location" value="1" @checked($noLocation)>
            {{ __('ims.filter_no_location') }}
        </label>
        <button type="submit" class="rounded-lg bg-accent text-white text-sm font-semibold px-4 py-2">OK</button>
    </form>

    <div class="bg-surface border border-border rounded-xl overflow-hidden">
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold"><span class="sr-only">{{ __('ims.col_photo') }}</span></th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_item_code') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_item') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_lot') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_location') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('ims.col_packs_x_size') }}</th>
                        <th class="px-4 py-3 font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($lots as $lot)
                        @php $unit = $lot->item?->baseUnit?->code; @endphp
                        <tr>
                            <td class="px-4 py-3">
                                @if ($photo = $lot->photos->first())
                                    <img src="{{ route('ims.lots.photos.show', [$lot, $photo]) }}" alt="{{ $lot->item?->name_th }}" loading="lazy"
                                         class="w-10 h-10 rounded object-cover border border-border">
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $lot->item?->item_code }}</td>
                            <td class="px-4 py-3">{{ $lot->item?->name_th }}</td>
                            <td class="px-4 py-3">{{ $lot->lot_no ?: '—' }}</td>
                            <td class="px-4 py-3">@if ($lot->location) {{ $lot->location->name }} @elseif ($lot->qty_remaining_base > 0) <span class="text-xs font-semibold text-warning-ink">{{ __('ims.no_location') }}</span> @else — @endif</td>
                            <td class="px-4 py-3 text-right font-mono">
                                @php
                                    $packs = $lot->packsRemaining();
                                    $baseText = (rtrim(rtrim((string) $lot->qty_remaining_base, '0'), '.') ?: '0').' '.$unit;
                                @endphp
                                @if ($packs !== null)
                                    <span class="font-semibold">{{ rtrim(rtrim($packs, '0'), '.') ?: '0' }} {{ $lot->item?->packagingWord() }} × {{ rtrim(rtrim((string) $lot->pack_size_base, '0'), '.') }} {{ $unit }}</span>
                                    <span class="block text-xs text-ink-faint">{{ __('ims.total_label') }} {{ $baseText }}</span>
                                @else
                                    <span class="font-semibold">{{ $baseText }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @can('view', $lot)
                                    <a href="{{ route('ims.lots.edit', $lot) }}" class="text-xs font-semibold text-accent hover:text-accent-strong mr-3">
                                        {{ __('ims.btn_details') }}
                                    </a>
                                @endcan
                                @can('transfer', $lot)
                                    <a href="{{ route('ims.lots.transfer.form', $lot) }}" class="text-xs font-semibold text-accent hover:text-accent-strong whitespace-nowrap">
                                        {{ __('ims.btn_transfer') }}
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-ink-muted text-sm">{{ __('ims.no_lots') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $lots->links() }}</div>
</x-layout>
