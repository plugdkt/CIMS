<x-layout>
    @include('ims._tabs')
    @php $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp

    <div class="mb-5">
        <a href="{{ route('ims.places.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('ims.places_back') }}</a>
        <h1 class="font-display text-lg font-bold mt-1">{{ $place->name }}</h1>
        <p class="text-xs text-ink-faint">{{ $path }} · <span class="font-mono">{{ $place->code }}</span></p>
    </div>

    <form method="GET" action="{{ route('ims.places.show', $place) }}" class="flex flex-wrap items-center gap-3 mb-5">
        <label for="search" class="sr-only">{{ __('ims.places_search_here') }}</label>
        <input id="search" name="search" type="search" value="{{ $search }}" placeholder="{{ __('ims.places_search_here') }}"
               class="w-80 rounded-lg border border-border px-3 py-2 text-sm">
        <button type="submit" class="rounded-lg bg-accent text-white text-sm font-semibold px-4 py-2">{{ __('ims.places_find') }}</button>
        @if ($canManage)
            <a href="{{ route('ims.lots.locate') }}" class="ml-auto rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-4 py-2">{{ __('ims.locate_open') }}</a>
        @endif
    </form>

    <div class="grid grid-cols-2 gap-3 mb-5 max-w-md">
        <div class="rounded-xl border border-border bg-surface-alt px-4 py-3">
            <div class="text-xs text-ink-muted">{{ __('ims.places_in_ims') }}</div>
            <div class="font-mono text-2xl font-bold">{{ count($ims) }}</div>
        </div>
        <div class="rounded-xl border border-border bg-surface-alt px-4 py-3">
            <div class="text-xs text-ink-muted">{{ __('ims.places_in_working') }}</div>
            <div class="font-mono text-2xl font-bold">{{ count($working) }}</div>
        </div>
    </div>

    <section class="bg-surface border border-border rounded-xl overflow-hidden mb-6">
        <h2 class="font-semibold px-5 pt-4 pb-3">{{ __('ims.places_ims_title') }}</h2>
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_item_code') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_item') }}</th>
                        <th class="px-4 py-3 font-semibold">Lot</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('ims.col_remaining') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_expiry') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_location') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($ims as $row)
                        <tr>
                            <td class="px-4 py-3 font-mono text-xs">{{ $row['code'] }}</td>
                            <td class="px-4 py-3">{{ $row['name'] }}</td>
                            <td class="px-4 py-3">{{ $row['lot_no'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-right font-mono whitespace-nowrap">
                                @if ($row['packs'] !== null && $row['size'] !== null)
                                    {{ $fmt($row['packs']) }} {{ $row['word'] }} × {{ $fmt($row['size']) }} {{ $row['unit'] }}
                                @else
                                    {{ $fmt($row['qty']) }} {{ $row['unit'] }}
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $row['expiry'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-xs text-ink-muted">{{ $row['place'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-ink-muted">{{ __('ims.places_empty_ims') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="bg-surface border border-border rounded-xl overflow-hidden">
        <h2 class="font-semibold px-5 pt-4 pb-3">{{ __('ims.places_working_title') }}</h2>
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_item_code') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_item') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('stock.col_barcode') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('ims.col_remaining') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_expiry') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_location') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($working as $row)
                        <tr>
                            <td class="px-4 py-3 font-mono text-xs">{{ $row['code'] }}</td>
                            <td class="px-4 py-3">{{ $row['name'] }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $row['barcode'] }}</td>
                            <td class="px-4 py-3 text-right font-mono whitespace-nowrap">{{ $fmt($row['qty']) }} {{ $row['unit'] }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $row['expiry'] ?: '—' }}</td>
                            <td class="px-4 py-3 text-xs text-ink-muted">{{ $row['place'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-ink-muted">{{ __('ims.places_empty_working') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layout>
