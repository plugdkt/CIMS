<x-layout>
    @php $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp

    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('shop.title') }}</h1>
            <p class="text-sm text-ink-muted mt-1">{{ __('shop.subtitle') }}</p>
        </div>
        <a href="{{ route('shop.cart') }}" class="relative rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5 whitespace-nowrap">
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

    {{-- One tab per item type that has something on the shelf --}}
    <nav class="flex gap-1 border-b border-border mb-4 overflow-x-auto" tabindex="0" aria-label="{{ __('shop.tabs_label') }}">
        @php $allActive = $categoryId === null; @endphp
        <a href="{{ route('shop.index', array_filter(['search' => $search])) }}"
           @class(['px-4 py-2.5 text-sm font-semibold -mb-px border-b-2 whitespace-nowrap', 'border-accent text-accent' => $allActive, 'border-transparent text-ink-muted hover:text-ink' => ! $allActive])
           @if ($allActive) aria-current="page" @endif>{{ __('shop.all_categories') }} <span class="text-xs font-normal">({{ $counts->sum() }})</span></a>
        @foreach ($categories as $category)
            @if (($counts[$category->id] ?? 0) > 0 || $categoryId === $category->id)
                @php $active = $categoryId === $category->id; @endphp
                <a href="{{ route('shop.index', array_filter(['category' => $category->id, 'search' => $search])) }}"
                   @class(['px-4 py-2.5 text-sm font-semibold -mb-px border-b-2 whitespace-nowrap', 'border-accent text-accent' => $active, 'border-transparent text-ink-muted hover:text-ink' => ! $active])
                   @if ($active) aria-current="page" @endif>{{ $category->name_th }} <span class="text-xs font-normal">({{ $counts[$category->id] ?? 0 }})</span></a>
            @endif
        @endforeach
    </nav>

    <form method="GET" action="{{ route('shop.index') }}" class="flex flex-wrap items-center gap-3 mb-5">
        @if ($categoryId !== null)
            <input type="hidden" name="category" value="{{ $categoryId }}">
        @endif
        <label for="search" class="sr-only">{{ __('shop.search_placeholder') }}</label>
        <input id="search" name="search" type="search" value="{{ $search }}" placeholder="{{ __('shop.search_placeholder') }}"
               class="w-full sm:w-80 rounded-lg border border-border px-3 py-2 text-sm">
        <button type="submit" class="rounded-lg border border-border text-sm font-semibold px-4 py-2 hover:bg-surface-alt">{{ __('shop.btn_search') }}</button>
    </form>

    @if ($items->isEmpty())
        <p class="text-center text-ink-muted text-sm py-16">{{ __('shop.empty') }}</p>
    @else
        <div class="grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($items as $item)
                @php
                    $s = $stock[$item->id];
                    $unit = $item->baseUnit?->code;
                    $options = $units->get($item->baseUnit?->dimension, collect());
                @endphp
                <div class="flex flex-col bg-surface border border-border rounded-xl overflow-hidden hover:shadow-md transition-shadow">
                    <a href="{{ route('shop.show', $item->ulid) }}" class="block h-36 bg-surface-alt flex items-center justify-center" aria-label="{{ $item->name_th }}">
                        @if (isset($photos[$item->id]))
                            <img src="{{ $photos[$item->id] }}" alt="{{ $item->name_th }}" loading="lazy" class="w-full h-full object-cover">
                        @else
                            <svg class="w-12 h-12 text-ink-faint" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3h6M10 3v5.2L5.5 17a2.5 2.5 0 0 0 2.2 3.7h8.6a2.5 2.5 0 0 0 2.2-3.7L14 8.2V3"/></svg>
                        @endif
                    </a>
                    <div class="p-4 flex flex-col gap-2 grow">
                        <div>
                            <a href="{{ route('shop.show', $item->ulid) }}" class="font-semibold leading-snug hover:text-accent">{{ $item->name_th }}</a>
                            <div class="text-xs text-ink-faint font-mono mt-0.5">{{ $item->item_code }}@if ($item->category) · {{ $item->category->name_th }}@endif</div>
                        </div>
                        <div class="text-sm">
                            <span class="inline-block rounded bg-success-soft text-success-ink text-xs font-semibold px-2 py-0.5">{{ __('shop.in_stock') }}</span>
                            <span class="ml-1 font-mono font-semibold">{{ $fmt($s['total']) }} {{ $unit }}</span>
                            <div class="text-xs text-ink-faint mt-1">
                                {{ __('shop.from_working') }} {{ $fmt($s['working']) }} · {{ __('shop.from_ims') }} {{ $fmt($s['ims']) }}
                            </div>
                        </div>
                        <form method="POST" action="{{ route('shop.cart.add') }}" class="mt-auto pt-2 flex items-end gap-2">
                            @csrf
                            <input type="hidden" name="item" value="{{ $item->ulid }}">
                            <div class="w-20">
                                <label for="qty-{{ $item->id }}" class="block text-[11px] text-ink-faint mb-0.5">{{ __('shop.field_qty') }}</label>
                                <input id="qty-{{ $item->id }}" name="qty" type="number" step="any" min="0" value="1" required
                                       class="w-full rounded-lg border border-border px-2 py-1.5 text-sm">
                            </div>
                            <div class="w-20">
                                <label for="unit-{{ $item->id }}" class="block text-[11px] text-ink-faint mb-0.5">{{ __('shop.field_unit') }}</label>
                                <select id="unit-{{ $item->id }}" name="unit_id" class="w-full rounded-lg border border-border px-1 py-1.5 text-sm">
                                    @foreach ($options as $option)
                                        <option value="{{ $option->id }}" @selected($option->id === $item->base_unit_id)>{{ $option->code }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="grow rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-3 py-2">{{ __('shop.btn_add') }}</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-5">{{ $items->links() }}</div>
    @endif
</x-layout>
