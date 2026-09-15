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
                        <th class="px-4 py-3 font-semibold">{{ __('items.col_category') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('items.col_unit') }}</th>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('items.col_status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($items as $item)
                        <tr wire:key="item-{{ $item->id }}">
                            <td class="px-4 py-3 align-top font-mono text-xs text-ink-muted">{{ $item->item_code }}</td>
                            <td class="px-4 py-3 align-top">
                                <div class="font-medium">{{ $item->name_th }}</div>
                                @if ($item->name_en || $item->cas_no)
                                    <div class="text-xs text-ink-muted">{{ $item->name_en }} @if($item->cas_no) · CAS {{ $item->cas_no }} @endif</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top text-ink-muted">{{ $item->category?->name_th }}</td>
                            <td class="px-4 py-3 align-top text-ink-muted">{{ $item->baseUnit?->name_th }}</td>
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
                            <td colspan="6" class="px-4 py-8 text-center text-ink-muted text-sm">
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
