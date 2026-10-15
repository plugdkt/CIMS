<x-layout>
    @php $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp

    <a href="{{ route('shop.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('shop.back_to_shop') }}</a>
    <h1 class="font-display text-lg font-bold mt-1 mb-5">{{ __('shop.cart_title') }}</h1>

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

    @if ($lines === [])
        <div class="text-center py-16">
            <p class="text-ink-muted text-sm mb-4">{{ __('shop.cart_empty') }}</p>
            <a href="{{ route('shop.index') }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">{{ __('shop.back_to_shop') }}</a>
        </div>
    @else
        <div class="bg-surface border border-border rounded-xl overflow-hidden mb-6">
            <div class="overflow-x-auto" tabindex="0">
                <table class="w-full text-sm">
                    <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="px-4 py-3 font-semibold">{{ __('shop.col_item') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('shop.col_qty') }}</th>
                            <th class="px-4 py-3 font-semibold text-right">{{ __('shop.col_available') }}</th>
                            <th class="px-4 py-3 font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($lines as $line)
                            @php $item = $line['item']; @endphp
                            <tr>
                                <td class="px-4 py-3 align-top">
                                    <div class="font-medium">{{ $item->name_th }}</div>
                                    <div class="text-xs font-mono text-ink-faint">{{ $item->item_code }}</div>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <form method="POST" action="{{ route('shop.cart.update', $item->ulid) }}" class="flex items-center gap-2">
                                        @csrf @method('PUT')
                                        <label for="cq-{{ $item->id }}" class="sr-only">{{ __('shop.col_qty') }}</label>
                                        <input id="cq-{{ $item->id }}" name="qty" type="number" step="any" min="0" required value="{{ $line['qty'] }}"
                                               class="w-24 rounded-lg border border-border px-2 py-1.5 text-sm">
                                        <span class="text-ink-muted">{{ $line['unit']->code }}</span>
                                        <button type="submit" class="text-xs font-semibold text-accent hover:text-accent-strong">{{ __('shop.btn_update') }}</button>
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-right font-mono align-top">{{ $fmt($line['stock']['total']) }} {{ $item->baseUnit?->code }}</td>
                                <td class="px-4 py-3 text-right align-top">
                                    <form method="POST" action="{{ route('shop.cart.remove', $item->ulid) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs font-semibold text-danger hover:underline">{{ __('shop.btn_remove') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <form method="POST" action="{{ route('shop.checkout') }}" class="bg-surface border border-border rounded-xl p-6 space-y-4 max-w-2xl">
            @csrf
            <h2 class="font-display text-base font-bold">{{ __('shop.checkout_title') }}</h2>
            <p class="text-xs text-ink-faint">{{ __('shop.checkout_hint') }}</p>

            <div>
                <span class="block text-sm font-medium mb-1">{{ __('requisitions.field_request_type') }}</span>
                <div class="flex gap-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="request_type[]" value="CHEMICAL" @checked(in_array('CHEMICAL', old('request_type', [])))>
                        {{ __('requisitions.request_type_chemical') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="request_type[]" value="CONSUMABLE" @checked(in_array('CONSUMABLE', old('request_type', [])))>
                        {{ __('requisitions.request_type_consumable') }}
                    </label>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="purpose_type">{{ __('requisitions.field_purpose_type') }}</label>
                <select name="purpose_type" id="purpose_type" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                    <option value="">{{ __('items.select_placeholder') }}</option>
                    <option value="TEACHING" @selected(old('purpose_type') === 'TEACHING')>{{ __('requisitions.purpose_type_teaching') }}</option>
                    <option value="RESEARCH" @selected(old('purpose_type') === 'RESEARCH')>{{ __('requisitions.purpose_type_research') }}</option>
                    <option value="OTHER" @selected(old('purpose_type') === 'OTHER')>{{ __('requisitions.purpose_type_other') }}</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1" for="purpose_detail">{{ __('requisitions.field_purpose_detail') }}</label>
                <input type="text" name="purpose_detail" id="purpose_detail" value="{{ old('purpose_detail') }}"
                       placeholder="{{ __('requisitions.field_purpose_detail_hint') }}"
                       class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <button type="submit" class="rounded-lg bg-success hover:bg-success-ink text-white text-base font-bold px-6 py-3 shadow-md focus:outline-none focus:ring-4 focus:ring-success-soft">
                {{ __('shop.btn_checkout') }}
            </button>
        </form>
    @endif
</x-layout>
