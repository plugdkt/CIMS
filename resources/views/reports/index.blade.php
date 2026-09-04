<x-layout>
    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('reports.index_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('reports.index_subtitle') }}</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- สรุปการใช้ --}}
        <div class="bg-surface border border-border rounded-xl p-5">
            <h2 class="font-semibold text-sm mb-1">{{ __('reports.usage_summary_title') }}</h2>
            <p class="text-xs text-ink-muted mb-4">{{ __('reports.usage_summary_desc') }}</p>
            <form method="GET" action="{{ route('reports.usage-summary.excel') }}" class="space-y-3">
                <input type="text" name="requester_name" placeholder="{{ __('reports.field_requester') }}"
                       class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                <input type="text" name="purpose_detail" placeholder="{{ __('reports.field_purpose_detail') }}"
                       class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                <input type="text" name="faculty" placeholder="{{ __('reports.field_faculty') }}"
                       class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                <div class="grid grid-cols-2 gap-2">
                    <input type="date" name="from" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <input type="date" name="to" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                </div>
                <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2">
                    {{ __('reports.download_excel') }}
                </button>
            </form>
        </div>

        {{-- สารใกล้หมดอายุ --}}
        <div class="bg-surface border border-border rounded-xl p-5">
            <h2 class="font-semibold text-sm mb-1">{{ __('reports.expiring_stock_title') }}</h2>
            <p class="text-xs text-ink-muted mb-4">{{ __('reports.expiring_stock_desc') }}</p>
            <form method="GET" action="{{ route('reports.expiring-stock.excel') }}" class="space-y-3">
                <div class="grid grid-cols-2 gap-2">
                    <input type="date" name="from" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <input type="date" name="to" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                </div>
                <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2">
                    {{ __('reports.download_excel') }}
                </button>
            </form>
        </div>

        {{-- สารคงคลังต่ำกว่าจุดสั่งซื้อ --}}
        <div class="bg-surface border border-border rounded-xl p-5">
            <h2 class="font-semibold text-sm mb-1">{{ __('reports.below_reorder_title') }}</h2>
            <p class="text-xs text-ink-muted mb-4">{{ __('reports.below_reorder_desc') }}</p>
            <form method="GET" action="{{ route('reports.below-reorder-point.excel') }}" class="space-y-3">
                <select name="lab_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <option value="">{{ __('reports.all_labs') }}</option>
                    @foreach ($labs as $lab)
                        <option value="{{ $lab->id }}">{{ $lab->name_th }}</option>
                    @endforeach
                </select>
                <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2">
                    {{ __('reports.download_excel') }}
                </button>
            </form>
        </div>

        {{-- Dead stock --}}
        <div class="bg-surface border border-border rounded-xl p-5">
            <h2 class="font-semibold text-sm mb-1">{{ __('reports.dead_stock_title') }}</h2>
            <p class="text-xs text-ink-muted mb-4">{{ __('reports.dead_stock_desc') }}</p>
            <form method="GET" action="{{ route('reports.dead-stock.excel') }}" class="space-y-3">
                <select name="lab_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <option value="">{{ __('reports.all_labs') }}</option>
                    @foreach ($labs as $lab)
                        <option value="{{ $lab->id }}">{{ $lab->name_th }}</option>
                    @endforeach
                </select>
                <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2">
                    {{ __('reports.download_excel') }}
                </button>
            </form>
        </div>

        {{-- สารควบคุม --}}
        <div class="bg-surface border border-border rounded-xl p-5">
            <h2 class="font-semibold text-sm mb-1">{{ __('reports.controlled_substances_title') }}</h2>
            <p class="text-xs text-ink-muted mb-4">{{ __('reports.controlled_substances_desc') }}</p>
            <form method="GET" action="{{ route('reports.controlled-substances.excel') }}" class="space-y-3" id="controlled-form">
                <div class="grid grid-cols-2 gap-2">
                    <input type="date" name="from" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <input type="date" name="to" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2">
                        {{ __('reports.download_excel') }}
                    </button>
                    <button type="submit" formaction="{{ route('reports.controlled-substances.pdf') }}"
                            class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-4 py-2">
                        {{ __('reports.download_pdf') }}
                    </button>
                </div>
            </form>
        </div>

        {{-- ผลตรวจนับ + ผลต่าง --}}
        <div class="bg-surface border border-border rounded-xl p-5">
            <h2 class="font-semibold text-sm mb-1">{{ __('reports.stock_take_variance_title') }}</h2>
            <p class="text-xs text-ink-muted mb-4">{{ __('reports.stock_take_variance_desc') }}</p>
            @if ($stockTakes->isEmpty())
                <p class="text-xs text-ink-faint">{{ __('reports.no_results') }}</p>
            @else
                <div class="space-y-3">
                    <select id="stock-take-select" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                        @foreach ($stockTakes as $stockTake)
                            <option value="{{ $stockTake->ulid }}">{{ $stockTake->doc_no }} — {{ $stockTake->lab?->name_th }}</option>
                        @endforeach
                    </select>
                    <div class="flex gap-2">
                        <a id="stock-take-excel-link" href="#" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2">
                            {{ __('reports.download_excel') }}
                        </a>
                        <a id="stock-take-pdf-link" href="#" class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-4 py-2">
                            {{ __('reports.download_pdf') }}
                        </a>
                    </div>
                </div>
                <script nonce="{{ request()->attributes->get('csp_nonce') }}">
                    (function () {
                        var select = document.getElementById('stock-take-select');
                        var excelLink = document.getElementById('stock-take-excel-link');
                        var pdfLink = document.getElementById('stock-take-pdf-link');
                        function update() {
                            var ulid = select.value;
                            excelLink.href = '{{ url('reports/stock-takes') }}/' + ulid + '/excel';
                            pdfLink.href = '{{ url('reports/stock-takes') }}/' + ulid + '/pdf';
                        }
                        select.addEventListener('change', update);
                        update();
                    })();
                </script>
            @endif
        </div>

    </div>
</x-layout>
