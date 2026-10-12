<x-layout>
    @include('ims._tabs')
    @php $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp

    <a href="{{ route('ims.lots.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('ims.back_to_lots') }}</a>

    <div class="mt-1 mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('ims.manage_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">
            <span class="font-mono">{{ $lot->item?->item_code }}</span> {{ $lot->item?->name_th }}
            · {{ __('ims.col_lot') }} {{ $lot->lot_no ?: '—' }}
            · {{ __('ims.col_remaining') }} {{ $fmt($lot->qty_remaining_base) }} {{ $lot->item?->baseUnit?->code }}
        </p>
    </div>

    <dl class="bg-surface border border-border rounded-xl p-5 mb-6 grid grid-cols-2 md:grid-cols-5 gap-4 text-sm">
        <div><dt class="text-xs text-ink-faint">{{ __('ims.col_category') }}</dt><dd>{{ $lot->item?->category?->name_th ?: '—' }}</dd></div>
        <div><dt class="text-xs text-ink-faint">{{ __('ims.col_fiscal_year') }} / {{ __('ims.col_round') }}</dt><dd>{{ $lot->fiscal_year ?: '—' }} / {{ $lot->purchase_round ?: '—' }}</dd></div>
        <div><dt class="text-xs text-ink-faint">{{ __('ims.col_doc_no') }}</dt><dd>{{ $lot->doc_no ?: '—' }}</dd></div>
        <div><dt class="text-xs text-ink-faint">{{ __('ims.col_price_per_pack') }}</dt><dd class="font-mono">{{ $lot->unit_price !== null ? number_format((float) $lot->unit_price, 2) : '—' }}</dd></div>
        <div><dt class="text-xs text-ink-faint">{{ __('ims.col_received_qty') }}</dt><dd class="font-mono">{{ $fmt($lot->qty_received_base) }} {{ $lot->item?->baseUnit?->code }}</dd></div>
    </dl>

    @cannot('manage', $lot)
        <p class="text-sm text-ink-muted mb-6">{{ __('ims.location_label') }}: {{ $lot->location?->name ?: '—' }}</p>
    @endcannot

    <div class="grid gap-6 md:grid-cols-2">
        @can('manage', $lot)
        <form method="POST" action="{{ route('ims.lots.update', $lot) }}" class="bg-surface border border-border rounded-xl p-5">
            @csrf @method('PUT')
            <h2 class="font-semibold mb-3">{{ __('ims.col_location') }}</h2>
            <label for="location_id" class="block text-sm font-medium mb-1">{{ __('ims.field_storage_location') }}</label>
            <select id="location_id" name="location_id" class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                <option value="">—</option>
                @foreach ($locations as $location)
                    <option value="{{ $location->id }}" @selected((int) old('location_id', $lot->location_id) === $location->id)>{{ $location->code }} — {{ $location->name }}</option>
                @endforeach
            </select>
            @error('location_id') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
            <p class="text-xs text-ink-faint mt-2">{{ __('ims.storage_location_hint') }}</p>
            <button type="submit" class="mt-4 rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">{{ __('ims.btn_save') }}</button>
        </form>
        @endcan

        <div class="bg-surface border border-border rounded-xl p-5">
            <h2 class="font-semibold mb-3">{{ __('ims.photos_title') }}</h2>

            @if ($lot->photos->isNotEmpty())
                <ul class="flex flex-wrap gap-3 mb-4">
                    @foreach ($lot->photos as $photo)
                        <li>
                            <a href="{{ route('ims.lots.photos.show', [$lot, $photo]) }}" target="_blank">
                                <img src="{{ route('ims.lots.photos.show', [$lot, $photo]) }}" alt="{{ $lot->item?->name_th }}" loading="lazy"
                                     class="w-24 h-24 rounded-lg object-cover border border-border">
                            </a>
                            @if ($loop->first)
                                <span class="block text-[11px] font-semibold text-success-ink text-center mt-1">{{ __('ims.photo_latest') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-ink-muted mb-4">{{ __('ims.photos_empty') }}</p>
            @endif

            @can('manage', $lot)
            <form method="POST" action="{{ route('ims.lots.photos.store', $lot) }}" enctype="multipart/form-data">
                @csrf
                <label for="photo" class="block text-sm font-medium mb-1">{{ __('ims.field_photo') }}</label>
                <input id="photo" name="photo" type="file" accept="image/jpeg,image/png" required
                       class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-accent-soft file:text-accent-soft-ink file:font-semibold file:px-3 file:py-2">
                @error('photo') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                <p class="text-xs text-ink-faint mt-2">{{ __('ims.photo_hint') }}</p>
                <button type="submit" class="mt-4 rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">{{ __('ims.btn_upload') }}</button>
            </form>
            @endcan
        </div>
    </div>
</x-layout>
