<x-layout>
    <div class="mb-6">
        <h1 class="font-display text-lg font-bold">{{ __('home.greeting', ['name' => auth()->user()->full_name]) }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('home.subtitle') }}</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-surface border border-border rounded-xl p-5">
            <div class="text-xs text-ink-muted">{{ __('home.pending_requisitions') }}</div>
            <div class="font-display text-2xl font-bold mt-1">{{ $pendingRequisitions }}</div>
        </div>
        @if (isset($belowReorderCount))
            <div class="bg-surface border border-border rounded-xl p-5">
                <div class="text-xs text-ink-muted">{{ __('home.below_reorder') }}</div>
                <div class="font-display text-2xl font-bold mt-1 {{ $belowReorderCount > 0 ? 'text-warning' : '' }}">{{ $belowReorderCount }}</div>
            </div>
        @endif
        @if (isset($expiringCount))
            <div class="bg-surface border border-border rounded-xl p-5">
                <div class="text-xs text-ink-muted">{{ __('home.expiring_soon') }}</div>
                <div class="font-display text-2xl font-bold mt-1 {{ $expiringCount > 0 ? 'text-danger' : '' }}">{{ $expiringCount }}</div>
            </div>
        @endif
    </div>

    @if (isset($topItems) && isset($monthlySeries))
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
            <div class="bg-surface border border-border rounded-xl p-5">
                <h2 class="font-semibold text-sm mb-3">{{ __('home.top_items_title') }}</h2>
                @if ($topItems->isEmpty())
                    <p class="text-xs text-ink-faint">{{ __('home.top_items_empty') }}</p>
                @else
                    <ol class="space-y-2">
                        @foreach ($topItems as $row)
                            <li class="flex items-center justify-between text-sm">
                                <span class="truncate pr-2">{{ $loop->iteration }}. {{ $row['item']->name_th }}</span>
                                <span class="text-ink-muted whitespace-nowrap">{{ $row['issue_count'] }} {{ __('home.issue_count_unit') }}</span>
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

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-2xl">
        @can('viewAny', App\Models\Item::class)
            <a href="{{ route('items.index') }}" class="block bg-surface border border-border rounded-xl p-5 hover:border-accent transition-colors">
                <div class="w-9 h-9 rounded-lg bg-accent-soft text-accent-soft-ink flex items-center justify-center mb-3">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M10 3h4M10.5 3v6.5L5.8 18a1.6 1.6 0 0 0 1.4 2.4h9.6a1.6 1.6 0 0 0 1.4-2.4l-4.7-8.5V3"/></svg>
                </div>
                <div class="font-semibold text-sm">{{ __('nav.items') }}</div>
                <div class="text-xs text-ink-muted mt-1">{{ __('home.items_desc') }}</div>
            </a>
        @endcan

        @can('viewAny', App\Models\User::class)
            <a href="{{ route('admin.users.index') }}" class="block bg-surface border border-border rounded-xl p-5 hover:border-accent transition-colors">
                <div class="w-9 h-9 rounded-lg bg-accent-soft text-accent-soft-ink flex items-center justify-center mb-3">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="8.3" r="3.3"/><path d="M5 20c1-3.6 4-5.5 7-5.5s6 1.9 7 5.5"/></svg>
                </div>
                <div class="font-semibold text-sm">{{ __('nav.users_roles') }}</div>
                <div class="text-xs text-ink-muted mt-1">{{ __('home.users_desc') }}</div>
            </a>
        @endcan
    </div>
</x-layout>
