<x-layout>
    <div class="max-w-3xl">
        <div class="mb-5">
            <a href="{{ route('items.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('items.back_to_list') }}</a>
            <div class="flex items-center justify-between mt-1">
                <h1 class="font-display text-lg font-bold">{{ $item->name_th }}</h1>
                <div class="flex items-center gap-3">
                    <a href="{{ route('stock-in.create', ['item_id' => $item->id]) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-accent text-white text-xs font-semibold hover:bg-accent-strong transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        {{ __('stock.btn_stock_in') }}
                    </a>
                    @can('viewAny', App\Models\StockLedger::class)
                        <a href="{{ route('items.ledger', $item) }}" class="text-xs font-semibold text-accent hover:text-accent-strong whitespace-nowrap">
                            {{ __('ledger.view_ledger') }}
                        </a>
                    @endcan
                    @can('update', $item)
                        <form method="POST" action="{{ route('items.sync-pubchem', $item) }}" class="inline"
                              onsubmit="return confirm('{{ __('chemicals.sync_btn_confirm') }}')">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-border bg-surface text-ink text-xs font-semibold hover:bg-surface-alt transition-colors shadow-2xs">
                                <svg class="w-3.5 h-3.5 text-accent" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                {{ __('chemicals.sync_btn') }}
                            </button>
                        </form>
                        <a href="{{ route('items.edit', $item) }}" class="text-xs font-semibold text-accent hover:text-accent-strong whitespace-nowrap">
                            {{ __('items.edit') }}
                        </a>
                    @endcan
                </div>
            </div>
        </div>

        @if (session('status'))
            <div class="mb-5 rounded-xl bg-success-soft text-success-ink text-sm px-4 py-3 border border-success/20 flex items-center gap-2">
                <svg class="w-4 h-4 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if (session('status_warning'))
            <div class="mb-5 rounded-xl bg-warning-soft text-warning-ink text-sm px-4 py-3 border border-warning/20 flex items-center gap-2">
                <svg class="w-4 h-4 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <span>{{ session('status_warning') }}</span>
            </div>
        @endif

        @if (session('label_container_ids'))
            <div class="mb-5 rounded-xl border border-accent/40 bg-accent-soft/30 p-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <h4 class="text-sm font-bold text-accent-strong">{{ __('stock.print_labels_title') }}</h4>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('stock-in.labels', ['size' => '40x25', 'ids' => session('label_container_ids')]) }}" target="_blank"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-surface border border-border text-xs font-semibold text-ink hover:bg-surface-alt shadow-sm">
                            <svg class="w-3.5 h-3.5 text-ink-muted" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                            {{ __('stock.print_label_size', ['size' => '40×25 mm']) }}
                        </a>
                        <a href="{{ route('stock-in.labels', ['size' => '50x30', 'ids' => session('label_container_ids')]) }}" target="_blank"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-surface border border-border text-xs font-semibold text-ink hover:bg-surface-alt shadow-sm">
                            <svg class="w-3.5 h-3.5 text-ink-muted" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                            {{ __('stock.print_label_size', ['size' => '50×30 mm']) }}
                        </a>
                    </div>
                </div>
            </div>
        @endif

        <div class="bg-surface border border-border rounded-xl p-6">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('items.field_item_code') }}</dt>
                    <dd class="font-mono">{{ $item->item_code }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('items.field_category') }}</dt>
                    <dd>{{ $item->category?->name_th }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('items.field_name_en') }}</dt>
                    <dd>{{ $item->name_en ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('items.field_cas_no') }}</dt>
                    <dd>{{ $item->cas_no ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('items.field_brand') }}</dt>
                    <dd>{{ $item->brand ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('items.field_grade') }}</dt>
                    <dd>{{ $item->grade ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('items.field_physical_state') }}</dt>
                    <dd>
                        @if ($item->physical_state)
                            @php
                                $stateName = match($item->physical_state) {
                                    'liquid' => __('items.state_liquid'),
                                    'solid' => __('items.state_solid'),
                                    'powder' => __('items.state_powder'),
                                    'solution' => __('items.state_solution'),
                                    'gas' => __('items.state_gas'),
                                    'crystal' => __('items.state_crystal'),
                                    'pellet' => __('items.state_pellet'),
                                    default => $item->physical_state,
                                };
                            @endphp
                            <span>{{ $stateName }}</span>
                        @else
                            <span>—</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('items.field_storage_class') }}</dt>
                    <dd>{{ $item->storage_class ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('items.col_unit') }}</dt>
                    <dd>{{ $item->baseUnit ? $item->baseUnit->name_th . ' ('.$item->baseUnit->code.')' : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('items.col_status') }}</dt>
                    <dd>
                        <span class="px-2.5 py-1 rounded-full text-xs font-semibold {{ $item->is_active ? 'bg-success-soft text-success-ink' : 'bg-danger-soft text-danger-ink' }}">
                            {{ $item->is_active ? __('items.active') : __('items.inactive') }}
                        </span>
                    </dd>
                </div>
            </dl>

            @if ($item->specification)
                <div class="mt-5 pt-5 border-t border-border">
                    <h3 class="text-xs font-bold text-ink-muted uppercase tracking-wider mb-1.5">{{ __('items.field_specification') }}</h3>
                    <div class="text-sm text-ink whitespace-pre-line bg-surface-alt/60 p-3.5 rounded-xl border border-border/80">{{ $item->specification }}</div>
                </div>
            @endif
        </div>

        <div class="bg-surface border border-border rounded-xl p-6 mt-6">
            <h2 class="font-display text-base font-bold mb-3">{{ __('items.inventory_section_title') }}</h2>
            @if ($containers->isNotEmpty())
                <div class="overflow-x-auto" tabindex="0">
                    <table class="w-full text-sm">
                        <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th class="px-3 py-2 font-semibold whitespace-nowrap">{{ __('stock.col_barcode') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('stock.col_location') }}</th>
                                <th class="px-3 py-2 font-semibold whitespace-nowrap">{{ __('stock.col_remaining') }}</th>
                                <th class="px-3 py-2 font-semibold whitespace-nowrap">{{ __('stock.col_expiry') }}</th>
                                <th class="px-3 py-2 font-semibold whitespace-nowrap">{{ __('stock.col_container_status') }}</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($containers as $container)
                                <tr>
                                    <td class="px-3 py-2 align-top font-mono text-xs text-ink-muted whitespace-nowrap">{{ $container->barcode }}</td>
                                    <td class="px-3 py-2 align-top text-xs text-ink-muted">{{ $container->location?->name ?? '—' }}</td>
                                    <td class="px-3 py-2 align-top whitespace-nowrap text-xs">
                                        <span class="font-semibold text-ink text-sm">{{ rtrim(rtrim((string) $container->remaining_qty_base, '0'), '.') }}</span>
                                        <span class="text-ink-muted ml-0.5">{{ $item->baseUnit?->code }}</span>
                                    </td>
                                    <td class="px-3 py-2 align-top whitespace-nowrap text-xs">
                                        @if ($container->expiry_date)
                                            @php
                                                $daysLeft = now()->startOfDay()->diffInDays($container->expiry_date, false);
                                                $expiryClass = match (true) {
                                                    $daysLeft < 0 => 'text-danger-ink font-semibold',
                                                    $daysLeft <= 30 => 'text-warning-ink font-semibold',
                                                    default => 'text-ink-muted',
                                                };
                                            @endphp
                                            <span class="{{ $expiryClass }}">{{ $container->expiry_date->format('d/m/Y') }}</span>
                                        @else
                                            <span class="text-ink-faint">{{ __('stock.no_expiry') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 align-top whitespace-nowrap">
                                        <span class="px-2.5 py-1 rounded-full text-xs font-semibold {{ $container->status === 'SEALED' ? 'bg-neutral-soft text-neutral-ink' : 'bg-accent-soft text-accent-soft-ink' }}">
                                            {{ $container->status === 'SEALED' ? __('stock.status_sealed') : __('stock.status_in_use') }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 align-top text-right whitespace-nowrap">
                                        <a href="{{ route('stock-in.labels', ['size' => '40x25', 'ids' => (string) $container->id]) }}" target="_blank"
                                           class="text-xs font-semibold text-accent hover:text-accent-strong">
                                            {{ __('stock.print_label') }}
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-ink-muted">{{ __('items.inventory_empty') }}</p>
            @endif
        </div>

        <div class="bg-surface border border-border rounded-xl p-6 mt-6">
            <h2 class="font-display text-base font-bold mb-3">{{ __('items.ghs_section_title') }}</h2>
            @if (! empty($item->ghs_codes))
                <div class="flex flex-wrap gap-4">
                    @foreach ($item->ghs_codes as $code)
                        <div class="flex flex-col items-center gap-1">
                            <x-ghs-icon :code="$code" :size="48" />
                            <span class="text-[11px] text-ink-muted">{{ $code }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-ink-muted">{{ __('items.no_ghs_selected') }}</p>
            @endif
        </div>

        <div class="bg-surface border border-border rounded-xl p-6 mt-6">
            <h2 class="font-display text-base font-bold mb-3">{{ __('items.h_statements_section_title') }}</h2>
            @if (! empty($item->h_statements))
                <ul class="space-y-1.5 text-sm">
                    @foreach ($item->h_statements as $code)
                        <li><span class="font-mono text-xs text-ink-muted">{{ $code }}</span> {{ config("ghs.hazard_statements.$code", $code) }}</li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-ink-muted">{{ __('items.no_h_statements') }}</p>
            @endif
        </div>

        <div class="bg-surface border border-border rounded-xl p-6 mt-6">
            <h2 class="font-display text-base font-bold mb-3">{{ __('items.p_statements_section_title') }}</h2>
            @if (! empty($item->p_statements))
                <ul class="space-y-1.5 text-sm">
                    @foreach ($item->p_statements as $code)
                        <li><span class="font-mono text-xs text-ink-muted">{{ $code }}</span> {{ config("ghs.precautionary_statements.$code", $code) }}</li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-ink-muted">{{ __('items.no_p_statements') }}</p>
            @endif
        </div>

        <div class="bg-surface border border-border rounded-xl p-6 mt-6">
            <h2 class="font-display text-base font-bold mb-1">{{ __('attachments.sds_title') }}</h2>
            @if ($sdsAttachments->isNotEmpty())
                <ul class="divide-y divide-border">
                    @foreach ($sdsAttachments as $index => $attachment)
                        <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium truncate">{{ $attachment->original_name }}</span>
                                    @if ($index === 0)
                                        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-success-soft text-success-ink whitespace-nowrap">
                                            {{ __('attachments.latest_badge') }}
                                        </span>
                                    @endif
                                </div>
                                <div class="text-xs text-ink-muted">
                                    {{ __('attachments.version_label', ['n' => $attachment->version]) }}
                                    · {{ __('attachments.uploaded_by', ['name' => $attachment->uploader?->full_name]) }}
                                </div>
                            </div>
                            <a href="{{ route('attachments.download', $attachment) }}" class="text-xs font-semibold text-accent hover:text-accent-strong whitespace-nowrap">
                                {{ __('attachments.download') }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-ink-muted">{{ __('attachments.sds_empty') }}</p>
            @endif
        </div>
    </div>
</x-layout>
