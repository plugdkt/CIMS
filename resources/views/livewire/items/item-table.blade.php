<div>
    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('items.index_title') }}</h1>
            <p class="text-sm text-ink-muted mt-1">{{ __('items.index_subtitle') }}</p>
        </div>
        @if ($canManage)
            <a href="{{ route('items.create') }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5 whitespace-nowrap">
                {{ __('items.new_item') }}
            </a>
        @endif
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
                                {{ __('items.no_results') }}
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
