<div>
    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('items.index_title') }}</h1>
            <p class="text-sm text-ink-muted mt-1">{{ __('items.index_subtitle') }}</p>
        </div>
        <div class="flex items-center gap-2">
            @can('viewAny', App\Models\Item::class)
                <a href="{{ route('chemicals.lookup') }}" class="rounded-lg border border-border hover:bg-surface-alt text-ink text-sm font-semibold px-3.5 py-2.5 whitespace-nowrap flex items-center gap-1.5 shadow-2xs">
                    <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    {{ __('chemicals.lookup_menu') }}
                </a>
            @endcan
            @if ($canManage)
                <a href="{{ route('items.create') }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5 whitespace-nowrap shadow-2xs">
                    {{ __('items.new_item') }}
                </a>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="mb-5 rounded-xl bg-success-soft text-success-ink text-sm px-4 py-3 border border-success/20 flex items-center gap-2">
            <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <div class="mb-4">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('items.search_placeholder') }}"
               class="w-full max-w-sm rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
    </div>

    <div class="bg-surface border border-border rounded-xl overflow-hidden">
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">{{ __('items.col_code') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('items.col_name') }}</th>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('items.col_cas') }}</th>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('items.col_grade') }}</th>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('items.col_physical_state') }}</th>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('items.col_package_size') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('items.col_category') }}</th>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('items.col_status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($items as $item)
                        <tr wire:key="item-{{ $item->id }}" class="hover:bg-surface-alt/40 transition-colors">
                            <td class="px-4 py-3 align-top font-mono text-xs text-ink-muted whitespace-nowrap font-medium">
                                {{ $item->item_code }}
                            </td>
                            <td class="px-4 py-3 align-top min-w-[220px]">
                                <div class="font-medium text-ink">{{ $item->name_th }}</div>
                                @if ($item->name_en)
                                    <div class="text-xs text-ink-muted mt-0.5">{{ $item->name_en }}</div>
                                @endif
                                <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                                    @if ($item->formula)
                                        <span class="inline-block font-mono text-[11px] px-1.5 py-0.5 rounded bg-surface-alt border border-border text-ink-muted" title="{{ __('items.field_formula') }}">
                                            {{ $item->formula }}
                                        </span>
                                    @endif
                                    @if ($item->brand)
                                        <span class="inline-block text-[11px] px-1.5 py-0.5 rounded bg-surface-alt text-ink-faint border border-border/60" title="{{ __('items.field_brand') }}">
                                            {{ $item->brand }}
                                        </span>
                                    @endif
                                    @if (! empty($item->ghs_codes))
                                        <div class="flex items-center gap-1 ml-0.5">
                                            @foreach ($item->ghs_codes as $code)
                                                <x-ghs-icon :code="$code" :size="18" />
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 align-top whitespace-nowrap">
                                @if ($item->cas_no)
                                    <span class="inline-block font-mono text-xs px-2 py-0.5 rounded bg-surface-alt border border-border/80 text-ink font-medium">
                                        {{ $item->cas_no }}
                                    </span>
                                @else
                                    <span class="text-xs text-ink-faint">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top whitespace-nowrap text-xs">
                                @if ($item->grade)
                                    <span class="inline-block text-xs px-2 py-0.5 rounded-md bg-accent-soft text-accent-strong font-semibold border border-accent/20">
                                        {{ $item->grade }}
                                    </span>
                                @else
                                    <span class="text-xs text-ink-faint">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top whitespace-nowrap text-xs">
                                @if ($item->physical_state)
                                    @php
                                        $stateBadge = match($item->physical_state) {
                                            'liquid' => ['bg' => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800', 'label' => __('items.state_liquid'), 'icon' => '💧'],
                                            'powder' => ['bg' => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800', 'label' => __('items.state_powder'), 'icon' => '🧂'],
                                            'solid' => ['bg' => 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700', 'label' => __('items.state_solid'), 'icon' => '🧊'],
                                            'gas' => ['bg' => 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800', 'label' => __('items.state_gas'), 'icon' => '💨'],
                                            'solution' => ['bg' => 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-950/40 dark:text-purple-300 dark:border-purple-800', 'label' => __('items.state_solution'), 'icon' => '🧪'],
                                            'crystal' => ['bg' => 'bg-cyan-50 text-cyan-700 border-cyan-200 dark:bg-cyan-950/40 dark:text-cyan-300 dark:border-cyan-800', 'label' => __('items.state_crystal'), 'icon' => '💎'],
                                            'pellet' => ['bg' => 'bg-orange-50 text-orange-700 border-orange-200 dark:bg-orange-950/40 dark:text-orange-300 dark:border-orange-800', 'label' => __('items.state_pellet'), 'icon' => '⚪'],
                                            default => ['bg' => 'bg-surface-alt text-ink border-border', 'label' => $item->physical_state, 'icon' => '•'],
                                        };
                                    @endphp
                                    <span class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-md border {{ $stateBadge['bg'] }} font-medium">
                                        <span>{{ $stateBadge['icon'] }}</span>
                                        <span>{{ $stateBadge['label'] }}</span>
                                    </span>
                                @else
                                    <span class="text-xs text-ink-faint">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top whitespace-nowrap text-xs">
                                @if ($item->package_size)
                                    <span class="font-semibold text-ink text-sm">
                                        {{ rtrim(rtrim((string) $item->package_size, '0'), '.') }}
                                    </span>
                                    <span class="text-ink-muted ml-0.5">
                                        {{ $item->baseUnit?->name_th ?? $item->baseUnit?->code ?? '' }}
                                    </span>
                                @elseif ($item->baseUnit)
                                    <span class="text-ink-muted">
                                        {{ $item->baseUnit->name_th }}
                                    </span>
                                @else
                                    <span class="text-ink-faint">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top text-xs text-ink-muted whitespace-nowrap">
                                {{ $item->category?->name_th }}
                            </td>
                            <td class="px-4 py-3 align-top whitespace-nowrap">
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold {{ $item->is_active ? 'bg-success-soft text-success-ink' : 'bg-danger-soft text-danger-ink' }}">
                                    {{ $item->is_active ? __('items.active') : __('items.inactive') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 align-top text-right whitespace-nowrap">
                                <a href="{{ route('items.show', $item) }}" class="text-xs font-semibold text-accent hover:text-accent-strong">
                                    {{ __('items.view') }}
                                </a>
                                @if ($canManage)
                                    <a href="{{ route('items.edit', $item) }}" class="ml-3 text-xs font-semibold text-accent hover:text-accent-strong">
                                        {{ __('items.edit') }}
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-ink-muted text-sm">
                                <div class="flex flex-col items-center justify-center gap-1">
                                    <span>{{ __('items.no_results') }}</span>
                                    @if (trim($search) !== '')
                                        @can('viewAny', App\Models\Item::class)
                                            <div class="mt-2 text-xs text-ink-muted flex items-center justify-center gap-1.5">
                                                <span>{{ __('chemicals.registry_not_found_hint') }}</span>
                                                <a href="{{ route('chemicals.lookup', ['q' => trim($search), 'by' => preg_match('/^\d+-\d+-\d+$/', trim($search)) ? 'cas' : 'name']) }}"
                                                   class="font-semibold text-accent hover:text-accent-strong inline-flex items-center gap-1 underline underline-offset-2">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                                    {{ __('chemicals.registry_search_pubchem') }} "{{ trim($search) }}" &rarr;
                                                </a>
                                            </div>
                                        @endcan
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $items->links() }}
    </div>
</div>
