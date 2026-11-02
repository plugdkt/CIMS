<x-layout>
    <div class="mb-6">
        <h1 class="font-display text-lg font-bold">{{ __('home.overview_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('home.overview_subtitle') }}</p>
    </div>

    @if ($approvalCount === null && $dispenseCount === null && ! isset($belowReorderCount, $expiringCount))
        <p class="rounded-lg bg-surface-alt text-ink-muted text-sm px-4 py-3">{{ __('home.overview_empty') }}</p>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        @if ($approvalCount !== null)
            <a href="{{ route('requisitions.manage', ['tab' => 'waiting']) }}" class="block bg-surface border border-border rounded-xl p-5 hover:border-accent transition-colors">
                <div class="text-xs text-ink-muted">{{ __('home.overview_approval') }}</div>
                <div class="font-display text-2xl font-bold mt-1 {{ $approvalCount > 0 ? 'text-warning' : '' }}">{{ $approvalCount }}</div>
            </a>
        @endif
        @if ($dispenseCount !== null)
            <a href="{{ route('requisitions.manage', ['tab' => 'to_issue']) }}" class="block bg-surface border border-border rounded-xl p-5 hover:border-accent transition-colors">
                <div class="text-xs text-ink-muted">{{ __('home.overview_dispense') }}</div>
                <div class="font-display text-2xl font-bold mt-1">{{ $dispenseCount }}</div>
            </a>
        @endif
        @if (isset($belowReorderCount))
            <a href="{{ route('reports.index', ['tab' => 'below_reorder']) }}" class="block bg-surface border border-border rounded-xl p-5 hover:border-accent transition-colors">
                <div class="text-xs text-ink-muted">{{ __('home.below_reorder') }}</div>
                <div class="font-display text-2xl font-bold mt-1 {{ $belowReorderCount > 0 ? 'text-warning' : '' }}">{{ $belowReorderCount }}</div>
            </a>
        @endif
        @if (isset($expiringCount))
            <a href="{{ route('reports.index', ['tab' => 'expiring_stock']) }}" class="block bg-surface border border-border rounded-xl p-5 hover:border-accent transition-colors">
                <div class="text-xs text-ink-muted">{{ __('home.expiring_soon') }}</div>
                <div class="font-display text-2xl font-bold mt-1 {{ $expiringCount > 0 ? 'text-danger' : '' }}">{{ $expiringCount }}</div>
            </a>
        @endif
    </div>

    @if (isset($belowReorderItems) || isset($expiringContainers))
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
            @if (isset($belowReorderItems))
                <div class="bg-surface border border-border rounded-xl p-5">
                    <h2 class="font-semibold text-sm mb-3">{{ __('home.below_reorder_list_title') }}</h2>
                    @if ($belowReorderItems->isEmpty())
                        <p class="text-xs text-ink-faint">{{ __('home.below_reorder_empty') }}</p>
                    @else
                        <ul class="space-y-2">
                            @foreach ($belowReorderItems as $row)
                                <li class="flex items-center justify-between text-sm gap-2">
                                    <a href="{{ route('items.show', $row['item']) }}" class="truncate hover:text-accent">{{ $row['item']->name_th }}</a>
                                    <span class="text-warning whitespace-nowrap">
                                        {{ rtrim(rtrim((string) $row['balance'], '0'), '.') }} / {{ rtrim(rtrim((string) $row['item']->reorder_point_base, '0'), '.') }} {{ $row['item']->baseUnit?->code }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                        @if ($belowReorderCount > $belowReorderItems->count())
                            <a href="{{ route('reports.index', ['tab' => 'below_reorder']) }}" class="block mt-3 text-xs font-semibold text-accent hover:text-accent-strong">
                                {{ __('home.view_all', ['count' => $belowReorderCount]) }}
                            </a>
                        @endif
                    @endif
                </div>
            @endif

            @if (isset($expiringContainers))
                <div class="bg-surface border border-border rounded-xl p-5">
                    <h2 class="font-semibold text-sm mb-3">{{ __('home.expiring_list_title') }}</h2>
                    @if ($expiringContainers->isEmpty())
                        <p class="text-xs text-ink-faint">{{ __('home.expiring_empty') }}</p>
                    @else
                        <ul class="space-y-2">
                            @foreach ($expiringContainers as $container)
                                <li class="flex items-center justify-between text-sm gap-2">
                                    <span class="truncate">{{ $container->item?->name_th }}</span>
                                    <span class="text-danger whitespace-nowrap">{{ $container->expiry_date?->format('d/m/Y') }}</span>
                                </li>
                            @endforeach
                        </ul>
                        @if ($expiringCount > $expiringContainers->count())
                            <a href="{{ route('reports.index', ['tab' => 'expiring_stock']) }}" class="block mt-3 text-xs font-semibold text-accent hover:text-accent-strong">
                                {{ __('home.view_all', ['count' => $expiringCount]) }}
                            </a>
                        @endif
                    @endif
                </div>
            @endif
        </div>
    @endif

    @if (isset($topItems) && isset($monthlySeries))
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
            <div class="bg-surface border border-border rounded-xl p-5">
                <h2 class="font-semibold text-sm mb-3">{{ __('home.top_items_title') }}</h2>
                @if ($topItems->isEmpty())
                    <p class="text-xs text-ink-faint">{{ __('home.top_items_empty') }}</p>
                @else
                    <ol class="space-y-2">
                        @foreach ($topItems as $row)
                            <li class="flex items-center justify-between text-sm gap-2">
                                <span class="truncate">{{ $loop->iteration }}. {{ $row['item']->name_th }}</span>
                                <span class="text-ink-muted whitespace-nowrap text-right">
                                    {{ $row['issue_count'] }} {{ __('home.issue_count_unit') }}
                                    <span class="text-ink-faint">
                                        ({{ rtrim(rtrim((string) $row['qty_issued'], '0'), '.') }} {{ $row['item']->baseUnit?->code }})
                                    </span>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>

            <div class="bg-surface border border-border rounded-xl p-5">
                <h2 class="font-semibold text-sm mb-3">{{ __('home.monthly_chart_title') }}</h2>
                @php
                    $maxCount = max(1, $monthlySeries->max('count'));
                @endphp
                <svg viewBox="0 0 360 140" class="w-full" role="img" aria-label="{{ __('home.monthly_chart_title') }}">
                    @foreach ($monthlySeries as $i => $row)
                        @php
                            $barWidth = 22;
                            $gap = 8;
                            $x = $i * ($barWidth + $gap) + 4;
                            $barHeight = (int) round(($row['count'] / $maxCount) * 90);
                            $y = 100 - $barHeight;
                        @endphp
                        <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barWidth }}" height="{{ $barHeight }}" rx="2" fill="#6d28d9"></rect>
                        <text x="{{ $x + $barWidth / 2 }}" y="112" text-anchor="middle" font-size="8" fill="currentColor" class="text-ink-faint">{{ $row['month']->format('m') }}</text>
                        <text x="{{ $x + $barWidth / 2 }}" y="{{ max(10, $y - 3) }}" text-anchor="middle" font-size="8" fill="currentColor">{{ $row['count'] }}</text>
                    @endforeach
                </svg>
            </div>
        </div>
    @endif
</x-layout>
