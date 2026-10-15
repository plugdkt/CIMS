<x-layout>
    @php
        $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
        $unit = $item->baseUnit?->code;
        $word = $item->packagingWord();
        $states = ['liquid', 'solid', 'powder', 'solution', 'gas', 'crystal', 'pellet'];
    @endphp

    <div class="flex items-start justify-between gap-4 mb-4">
        <a href="{{ route('shop.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('shop.back_to_shop') }}</a>
        <a href="{{ route('shop.cart') }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5 whitespace-nowrap">
            {{ __('shop.cart') }}
            @if ($cartCount > 0)
                <span class="ml-1 inline-block rounded-full bg-white text-accent text-xs font-bold px-2 py-0.5">{{ $cartCount }}</span>
            @endif
        </a>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-success-soft text-success-ink text-sm px-4 py-3">{{ session('status') }}</div>
    @endif
    @error('cart')
        <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">{{ $message }}</div>
    @enderror

    <div class="grid gap-6 md:grid-cols-5 mb-6">
        {{-- Photos --}}
        <div class="md:col-span-2">
            <div class="aspect-square bg-surface-alt border border-border rounded-xl overflow-hidden flex items-center justify-center">
                @if ($photos !== [])
                    <img id="main-photo" src="{{ $photos[0] }}" alt="{{ $item->name_th }}" class="w-full h-full object-contain">
                @else
                    <svg class="w-20 h-20 text-ink-faint" fill="none" stroke="currentColor" stroke-width="1.3" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3h6M10 3v5.2L5.5 17a2.5 2.5 0 0 0 2.2 3.7h8.6a2.5 2.5 0 0 0 2.2-3.7L14 8.2V3"/></svg>
                @endif
            </div>
            @if (count($photos) > 1)
                <ul class="flex flex-wrap gap-2 mt-3">
                    @foreach ($photos as $src)
                        <li>
                            <a href="{{ $src }}" target="_blank">
                                <img src="{{ $src }}" alt="{{ $item->name_th }}" loading="lazy" class="w-16 h-16 rounded-lg object-cover border border-border">
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Title, availability, add to cart --}}
        <div class="md:col-span-3 space-y-4">
            <div>
                <h1 class="font-display text-xl font-bold leading-snug">{{ $item->name_th }}</h1>
                @if ($item->name_en)
                    <p class="text-sm text-ink-muted">{{ $item->name_en }}</p>
                @endif
                <p class="text-xs font-mono text-ink-faint mt-1">{{ $item->item_code }}@if ($item->category) · {{ $item->category->name_th }}@endif</p>
                @if ($item->is_controlled)
                    <p class="mt-2 inline-block rounded bg-warning-soft text-warning-ink text-xs font-semibold px-2 py-1">{{ __('shop.controlled') }}</p>
                @endif
            </div>

            <div class="bg-surface border border-border rounded-xl p-5">
                <div class="flex items-center gap-2">
                    <span class="rounded bg-success-soft text-success-ink text-xs font-semibold px-2 py-0.5">{{ __('shop.in_stock') }}</span>
                    <span class="font-mono font-bold text-lg">{{ $fmt($stock['total']) }} {{ $unit }}</span>
                </div>
                <p class="text-xs text-ink-faint mt-1">
                    {{ __('shop.from_working') }} {{ $fmt($stock['working']) }} {{ $unit }} · {{ __('shop.from_ims') }} {{ $fmt($stock['ims']) }} {{ $unit }}
                </p>

                <form method="POST" action="{{ route('shop.cart.add') }}" class="mt-4 flex items-end gap-3">
                    @csrf
                    <input type="hidden" name="item" value="{{ $item->ulid }}">
                    <div class="w-28">
                        <label for="qty" class="block text-xs font-medium mb-1">{{ __('shop.field_qty') }}</label>
                        <input id="qty" name="qty" type="number" step="any" min="0" value="1" required class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                    </div>
                    <div class="w-24">
                        <label for="unit_id" class="block text-xs font-medium mb-1">{{ __('shop.field_unit') }}</label>
                        <select id="unit_id" name="unit_id" class="w-full rounded-lg border border-border px-2 py-2 text-sm">
                            @foreach ($options as $option)
                                <option value="{{ $option->id }}" @selected($option->id === $item->base_unit_id)>{{ $option->code }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">{{ __('shop.btn_add') }}</button>
                </form>
            </div>

            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                @if ($item->cas_no)
                    <div><dt class="text-xs text-ink-faint">{{ __('items.field_cas_no') }}</dt><dd class="font-mono">{{ $item->cas_no }}</dd></div>
                @endif
                @if ($item->formula)
                    <div><dt class="text-xs text-ink-faint">{{ __('items.field_formula') }}</dt><dd>{{ $item->formula }}</dd></div>
                @endif
                @if ($item->grade)
                    <div><dt class="text-xs text-ink-faint">{{ __('items.field_grade') }}</dt><dd>{{ $item->grade }}</dd></div>
                @endif
                @if ($item->brand)
                    <div><dt class="text-xs text-ink-faint">{{ __('items.field_brand') }}</dt><dd>{{ $item->brand }}</dd></div>
                @endif
                @if ($item->physical_state && in_array($item->physical_state, $states, true))
                    <div><dt class="text-xs text-ink-faint">{{ __('items.field_physical_state') }}</dt><dd>{{ __('items.state_'.$item->physical_state) }}</dd></div>
                @endif
                @if ($item->package_size)
                    <div><dt class="text-xs text-ink-faint">{{ __('items.field_package_size') }}</dt><dd>{{ $fmt($item->package_size) }} {{ $item->packageUnit?->code ?? $unit }}</dd></div>
                @endif
            </dl>
        </div>
    </div>

    {{-- Where it sits and when it expires --}}
    <div class="bg-surface border border-border rounded-xl overflow-hidden mb-6">
        <h2 class="font-semibold px-5 pt-5 pb-3">{{ __('shop.where_title') }}</h2>
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">{{ __('shop.col_source') }}</th>
                        <th class="px-4 py-3 font-semibold text-right">{{ __('shop.col_amount') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_location') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('ims.col_expiry') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($bottles as $row)
                        <tr>
                            <td class="px-4 py-3">{{ __('shop.from_working') }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ $row['count'] }} {{ __('shop.bottles') }} · {{ $fmt($row['qty']) }} {{ $unit }}</td>
                            <td class="px-4 py-3">{{ $row['location'] ?: '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $row['expiry']?->format('d/m/Y') ?: '—' }}</td>
                        </tr>
                    @endforeach
                    @foreach ($lots as $lot)
                        @php
                            $size = $lot->pack_size_base !== null ? $fmt($lot->pack_size_base) : null;
                            $unplaced = $lot->unplacedPacks();
                        @endphp
                        @foreach ($lot->groups as $group)
                            <tr>
                                <td class="px-4 py-3">{{ __('shop.from_ims') }}@if ($lot->lot_no) <span class="text-ink-faint">· Lot {{ $lot->lot_no }}</span>@endif</td>
                                <td class="px-4 py-3 text-right font-mono">{{ $fmt($group->pack_count) }} {{ $word }} × {{ $size }} {{ $unit }}</td>
                                <td class="px-4 py-3">{{ $group->location?->name ?: '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $group->expiry_date?->format('d/m/Y') ?: '—' }}</td>
                            </tr>
                        @endforeach
                        @if ($lot->groups->isEmpty() || bccomp($unplaced, '0', 4) > 0)
                            <tr>
                                <td class="px-4 py-3">{{ __('shop.from_ims') }}@if ($lot->lot_no) <span class="text-ink-faint">· Lot {{ $lot->lot_no }}</span>@endif</td>
                                <td class="px-4 py-3 text-right font-mono">
                                    @if ($size !== null)
                                        {{ $fmt($lot->groups->isEmpty() ? ($lot->packsRemaining() ?? '0') : $unplaced) }} {{ $word }} × {{ $size }} {{ $unit }}
                                    @else
                                        {{ $fmt($lot->qty_remaining_base) }} {{ $unit }}
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $lot->location?->name ?: '—' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $lot->expiry_date?->format('d/m/Y') ?: '—' }}</td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if (! empty($item->ghs_codes) || ! empty($item->h_statements) || ! empty($item->p_statements))
        <div class="bg-surface border border-border rounded-xl p-5 mb-6">
            <h2 class="font-semibold mb-3">{{ __('items.ghs_section_title') }}</h2>
            @if (! empty($item->ghs_codes))
                <div class="flex flex-wrap gap-3 mb-4">
                    @foreach ($item->ghs_codes as $code)
                        <x-ghs-icon :code="$code" :size="48" />
                    @endforeach
                </div>
            @endif
            @if (! empty($item->h_statements))
                <ul class="text-sm space-y-1 mb-3">
                    @foreach ($item->h_statements as $code)
                        <li><span class="font-mono text-xs text-ink-muted">{{ $code }}</span> {{ config("ghs.hazard_statements.$code", $code) }}</li>
                    @endforeach
                </ul>
            @endif
            @if (! empty($item->p_statements))
                <ul class="text-sm space-y-1">
                    @foreach ($item->p_statements as $code)
                        <li><span class="font-mono text-xs text-ink-muted">{{ $code }}</span> {{ config("ghs.precautionary_statements.$code", $code) }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if ($item->specification)
        <div class="bg-surface border border-border rounded-xl p-5">
            <h2 class="font-semibold mb-2">{{ __('items.field_specification') }}</h2>
            <div class="text-sm whitespace-pre-line text-ink">{{ $item->specification }}</div>
        </div>
    @endif
</x-layout>
