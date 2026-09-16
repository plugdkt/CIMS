<div>
    <div class="flex items-start justify-between gap-4 mb-5 flex-wrap">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('stock.index_title') }}</h1>
            <p class="text-sm text-ink-muted mt-1">{{ __('stock.index_subtitle') }}</p>
        </div>
        @if ($canStockIn)
            <a href="{{ route('stock-in.create') }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5 whitespace-nowrap shadow-2xs">
                {{ __('stock.btn_stock_in') }}
            </a>
        @endif
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('stock.search_placeholder') }}"
               class="w-full max-w-sm rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">

        <select wire:model.live="locationId" class="rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
            <option value="">{{ __('stock.filter_location_all') }}</option>
            @foreach ($locations as $location)
                <option value="{{ $location->id }}">{{ $location->name }}</option>
            @endforeach
        </select>

        @if ($labs !== null)
            <select wire:model.live="labId" class="rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                <option value="">{{ __('stock.filter_lab_all') }}</option>
                @foreach ($labs as $lab)
                    <option value="{{ $lab->id }}">{{ $lab->name_th }}</option>
                @endforeach
            </select>
        @endif
    </div>

    @if ($labs === null && auth()->user()?->lab_id === null)
        <div class="mb-5 rounded-xl bg-warning-soft text-warning-ink text-sm px-4 py-3 border border-warning/20">
            {{ __('stock.no_lab_assigned') }}
        </div>
    @endif

    <div class="bg-surface border border-border rounded-xl overflow-hidden">
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('stock.col_barcode') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('stock.col_item') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('stock.col_location') }}</th>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('stock.col_remaining') }}</th>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('stock.col_expiry') }}</th>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('stock.col_container_status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($containers as $container)
                        <tr wire:key="container-{{ $container->id }}" class="hover:bg-surface-alt/40 transition-colors">
                            <td class="px-4 py-3 align-top font-mono text-xs text-ink-muted whitespace-nowrap">
                                {{ $container->barcode }}
                            </td>
                            <td class="px-4 py-3 align-top min-w-[220px]">
                                <div class="font-medium text-ink">{{ $container->item?->name_th }}</div>
                                <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                    <span class="font-mono text-[11px] text-ink-muted">{{ $container->item?->item_code }}</span>
                                    @if ($container->item?->grade)
                                        <span class="inline-block text-[11px] px-1.5 py-0.5 rounded bg-accent-soft text-accent-strong font-semibold border border-accent/20">
                                            {{ $container->item->grade }}
                                        </span>
                                    @endif
                                    @if ($container->item?->physical_state)
                                        @php
                                            $stateBadge = match ($container->item->physical_state) {
                                                'liquid' => ['bg' => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800', 'label' => __('items.state_liquid'), 'icon' => '💧'],
                                                'powder' => ['bg' => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800', 'label' => __('items.state_powder'), 'icon' => '🧂'],
                                                'solid' => ['bg' => 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700', 'label' => __('items.state_solid'), 'icon' => '🧊'],
                                                'gas' => ['bg' => 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800', 'label' => __('items.state_gas'), 'icon' => '💨'],
                                                'solution' => ['bg' => 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-950/40 dark:text-purple-300 dark:border-purple-800', 'label' => __('items.state_solution'), 'icon' => '🧪'],
                                                'crystal' => ['bg' => 'bg-cyan-50 text-cyan-700 border-cyan-200 dark:bg-cyan-950/40 dark:text-cyan-300 dark:border-cyan-800', 'label' => __('items.state_crystal'), 'icon' => '💎'],
                                                'pellet' => ['bg' => 'bg-orange-50 text-orange-700 border-orange-200 dark:bg-orange-950/40 dark:text-orange-300 dark:border-orange-800', 'label' => __('items.state_pellet'), 'icon' => '⚪'],
                                                default => ['bg' => 'bg-surface-alt text-ink border-border', 'label' => $container->item->physical_state, 'icon' => '•'],
                                            };
                                        @endphp
                                        <span class="inline-flex items-center gap-1 text-[11px] px-1.5 py-0.5 rounded-md border {{ $stateBadge['bg'] }} font-medium">
                                            <span>{{ $stateBadge['icon'] }}</span>
                                            <span>{{ $stateBadge['label'] }}</span>
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 align-top text-xs text-ink-muted">
                                {{ $container->location?->name ?? '—' }}
                            </td>
                            <td class="px-4 py-3 align-top whitespace-nowrap text-xs">
                                <span class="font-semibold text-ink text-sm">{{ rtrim(rtrim((string) $container->remaining_qty_base, '0'), '.') }}</span>
                                <span class="text-ink-muted ml-0.5">{{ $container->item?->baseUnit?->code }}</span>
                            </td>
                            <td class="px-4 py-3 align-top whitespace-nowrap text-xs">
                                @if ($container->expiry_date)
                                    @php
                                        $daysLeft = now()->startOfDay()->diffInDays($container->expiry_date, false);
                                        $expiryClass = match (true) {
                                            $daysLeft < 0 => 'text-danger-ink font-semibold',
                                            $daysLeft <= 30 => 'text-warning-ink font-semibold',
                                            default => 'text-ink-muted',
                                        };
                                    @endphp
                                    <span class="{{ $expiryClass }}">
                                        {{ $container->expiry_date->format('d/m/Y') }}
                                        @if ($daysLeft < 0)
                                            ({{ __('stock.expired_warning') }})
                                        @elseif ($daysLeft <= 30)
                                            ({{ __('stock.expiring_soon_warning') }})
                                        @endif
                                    </span>
                                @else
                                    <span class="text-ink-faint">{{ __('stock.no_expiry') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top whitespace-nowrap">
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold {{ $container->status === 'SEALED' ? 'bg-neutral-soft text-neutral-ink' : 'bg-accent-soft text-accent-soft-ink' }}">
                                    {{ $container->status === 'SEALED' ? __('stock.status_sealed') : __('stock.status_in_use') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 align-top text-right whitespace-nowrap">
                                <a href="{{ route('stock-in.labels', ['size' => '40x25', 'ids' => (string) $container->id]) }}" target="_blank"
                                   class="text-xs font-semibold text-accent hover:text-accent-strong">
                                    {{ __('stock.print_label') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-ink-muted text-sm">
                                {{ __('stock.no_results') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $containers->links() }}
    </div>
</div>
