<x-layout>
    @php $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp

    {{-- ITM-2610-070: the borrowing cart (session only, for oneself). Built like resources/views/shop/cart.blade.php. --}}
    @can('browseAvailable', App\Models\LoanAsset::class)
        <a href="{{ route('loans.available') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('loans.cart.back_to_available') }}</a>
    @endcan
    <h1 class="font-display text-lg font-bold mt-1">{{ __('loans.cart.title') }}</h1>
    <p class="text-sm text-ink-muted mt-1 mb-5">{{ __('loans.cart.subtitle') }}</p>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-success-soft text-success-ink text-sm px-4 py-3">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($lines === [])
        <div class="text-center py-16">
            <p class="text-ink-muted text-sm mb-4">{{ __('loans.cart.empty') }}</p>
            @can('browseAvailable', App\Models\LoanAsset::class)
                <a href="{{ route('loans.available') }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">{{ __('loans.cart.back_to_available') }}</a>
            @endcan
        </div>
    @else
        <div class="bg-surface border border-border rounded-xl overflow-hidden mb-6">
            <div class="overflow-x-auto" tabindex="0">
                <table class="w-full text-sm">
                    <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_item') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.col_location') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('loans.cart.field_qty') }}</th>
                            <th class="px-4 py-3 font-semibold text-right">{{ __('loans.col_available') }}</th>
                            <th class="px-4 py-3 font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($lines as $line)
                            @php $asset = $line['asset']; @endphp
                            <tr>
                                <td class="px-4 py-3 align-top">
                                    @if ($asset !== null)
                                        <div class="font-medium">{{ $asset->item?->name_th }}</div>
                                        <div class="text-xs font-mono text-ink-faint">{{ $asset->item?->item_code }}@if ($asset->asset_tag) · {{ $asset->asset_tag }}@endif</div>
                                    @else
                                        <div class="text-ink-muted">{{ __('loans.cart.asset_gone') }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top">{{ $asset?->location?->name ?: '—' }}</td>
                                <td class="px-4 py-3 align-top">
                                    @if ($asset !== null)
                                    <form method="POST" action="{{ route('loans.cart.update', $line['ulid']) }}" class="flex items-center gap-2">
                                        @csrf @method('PUT')
                                        <label for="cq-{{ $line['ulid'] }}" class="sr-only">{{ __('loans.cart.field_qty') }}</label>
                                        <input id="cq-{{ $line['ulid'] }}" name="qty" type="number" min="1" step="1" inputmode="numeric" required value="{{ $line['qty'] }}"
                                               class="w-24 rounded-lg border border-border px-2 py-1.5 text-sm text-right font-mono">
                                        <button type="submit" class="text-xs font-semibold text-accent hover:text-accent-strong">{{ __('loans.cart.btn_update') }}</button>
                                    </form>
                                    @else
                                        <span class="text-ink-faint">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-mono align-top">
                                    {{ $asset !== null ? $fmt($asset->available()) : '—' }}
                                    @if ($line['problem'] !== null)
                                        <div class="mt-1 text-xs font-sans text-danger-ink">{{ $line['problem'] }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right align-top">
                                    <form method="POST" action="{{ route('loans.cart.remove', $line['ulid']) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs font-semibold text-danger hover:underline">{{ __('loans.cart.btn_remove') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-surface border border-border rounded-xl p-6 space-y-3 max-w-2xl">
            <p class="text-xs text-ink-faint">{{ __('loans.cart.proceed_hint') }}</p>
            <a href="{{ route('loans.create', ['cart' => 1]) }}" class="inline-block rounded-lg bg-success hover:bg-success-ink text-white text-base font-bold px-6 py-3 shadow-md focus:outline-none focus:ring-4 focus:ring-success-soft">
                {{ __('loans.cart.btn_proceed') }}
            </a>
        </div>
    @endif
</x-layout>
