{{-- Sub-navigation of the IMS area: the stock itself and the purchase documents that fill it. --}}
<nav class="flex gap-1 border-b border-border mb-5" aria-label="{{ __('ims.tabs_label') }}">
    <a href="{{ route('ims.lots.index') }}"
       @class(['px-4 py-2.5 text-sm font-semibold -mb-px border-b-2', 'border-accent text-accent' => request()->routeIs('ims.lots.*'), 'border-transparent text-ink-muted hover:text-ink' => ! request()->routeIs('ims.lots.*')])
       @if (request()->routeIs('ims.lots.*')) aria-current="page" @endif>{{ __('ims.tab_lots') }}</a>
    <a href="{{ route('ims.places.index') }}"
       @class(['px-4 py-2.5 text-sm font-semibold -mb-px border-b-2', 'border-accent text-accent' => request()->routeIs('ims.places.*'), 'border-transparent text-ink-muted hover:text-ink' => ! request()->routeIs('ims.places.*')])
       @if (request()->routeIs('ims.places.*')) aria-current="page" @endif>{{ __('ims.tab_places') }}</a>
    @can('viewAny', App\Models\ImsReceipt::class)
        <a href="{{ route('ims.receipts.index') }}"
           @class(['px-4 py-2.5 text-sm font-semibold -mb-px border-b-2', 'border-accent text-accent' => request()->routeIs('ims.receipts.*'), 'border-transparent text-ink-muted hover:text-ink' => ! request()->routeIs('ims.receipts.*')])
           @if (request()->routeIs('ims.receipts.*')) aria-current="page" @endif>{{ __('ims.tab_receipts') }}</a>
    @endcan
</nav>
