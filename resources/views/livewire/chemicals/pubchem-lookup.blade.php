<div class="max-w-2xl">
    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('chemicals.lookup_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('chemicals.lookup_subtitle') }}</p>
    </div>

    <form wire:submit="search" class="bg-surface border border-border rounded-xl p-6 space-y-4">
        <div class="flex gap-4">
            <label class="flex items-center gap-2 text-sm">
                <input type="radio" wire:model="by" value="cas"> {{ __('chemicals.by_cas') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="radio" wire:model="by" value="name"> {{ __('chemicals.by_name') }}
            </label>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1" for="query">{{ __('chemicals.field_query') }}</label>
            <input type="text" wire:model="query" id="query"
                   placeholder="{{ $by === 'cas' ? __('chemicals.query_placeholder_cas') : __('chemicals.query_placeholder_name') }}"
                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
            @error('query') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">
            {{ __('chemicals.btn_search') }}
        </button>
    </form>

    @if ($searched)
        <div class="bg-surface border border-border rounded-xl p-6 mt-6">
            @if (! $found)
                <p class="text-sm text-ink-muted">{{ __('chemicals.not_found') }}</p>
            @else
                @if ($sanitizedQuery)
                    <div class="mb-4 rounded-xl bg-accent-soft text-accent-ink border border-accent/20 p-3 text-xs flex items-center gap-2">
                        <svg class="w-4 h-4 text-accent shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span>{{ __('chemicals.sanitized_search_hint', ['original' => $query, 'sanitized' => $sanitizedQuery]) }}</span>
                    </div>
                @endif
                <h2 class="font-display text-base font-bold mb-3">{{ $title }}</h2>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm mb-5">
                    <div>
                        <dt class="text-xs text-ink-faint">{{ __('chemicals.col_pubchem_cid') }}</dt>
                        <dd>{{ $cid ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-faint">{{ __('chemicals.col_molecular_formula') }}</dt>
                        <dd>{{ $molecularFormula ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-faint">{{ __('chemicals.col_molecular_weight') }}</dt>
                        <dd>{{ $molecularWeight ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-ink-faint">{{ __('chemicals.col_signal_word') }}</dt>
                        <dd>{{ $signalWord ?: '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-ink-faint">{{ __('chemicals.col_iupac_name') }}</dt>
                        <dd>{{ $iupacName ?: '—' }}</dd>
                    </div>
                </dl>

                @if (empty($ghsCodes) && empty($hStatements) && empty($pStatements))
                    <p class="text-sm text-ink-muted">{{ __('chemicals.no_hazard_data') }}</p>
                @else
                    @if (! empty($ghsCodes))
                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-ink-faint mb-2">{{ __('chemicals.col_ghs') }}</h3>
                            <div class="flex flex-wrap gap-4">
                                @foreach ($ghsCodes as $code)
                                    <div class="flex flex-col items-center gap-1">
                                        <x-ghs-icon :code="$code" :size="40" />
                                        <span class="text-[11px] text-ink-muted">{{ $code }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if (! empty($hStatements))
                        <div class="mb-4">
                            <h3 class="text-xs font-semibold text-ink-faint mb-2">{{ __('chemicals.col_h_statements') }}</h3>
                            <ul class="space-y-1 text-sm">
                                @foreach ($hStatements as $code)
                                    <li><span class="font-mono text-xs text-ink-muted">{{ $code }}</span> {{ config("ghs.hazard_statements.$code", $code) }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (! empty($pStatements))
                        <div>
                            <h3 class="text-xs font-semibold text-ink-faint mb-2">{{ __('chemicals.col_p_statements') }}</h3>
                            <ul class="space-y-1 text-sm">
                                @foreach ($pStatements as $code)
                                    <li><span class="font-mono text-xs text-ink-muted">{{ $code }}</span> {{ config("ghs.precautionary_statements.$code", $code) }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endif

                @if ($matchedItemId)
                    <div class="mt-6 pt-5 border-t border-border">
                        <div class="rounded-xl bg-success-soft border border-success/30 p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div class="flex items-center gap-2 text-sm text-success-ink">
                                <svg class="w-5 h-5 text-success shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <div>
                                    <span class="font-semibold">{{ __('chemicals.item_exists_in_system') }}</span>
                                    <span class="font-mono text-xs ml-1 font-bold">[{{ $matchedItemCode }}]</span>
                                    <span class="ml-1">{{ $matchedItemName }}</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="{{ route('items.show', $matchedItemUlid) }}" class="rounded-lg bg-surface border border-border text-ink hover:bg-surface-alt text-xs font-semibold px-3 py-1.5 shadow-2xs whitespace-nowrap">
                                    {{ __('chemicals.item_exists_view') }}
                                </a>
                                @if ($matchedItem)
                                    @can('update', $matchedItem)
                                        <button type="button" wire:click="syncExistingItem" wire:loading.attr="disabled"
                                                wire:confirm="{{ __('chemicals.sync_btn_confirm') }}"
                                                class="rounded-lg bg-accent hover:bg-accent-strong text-white text-xs font-semibold px-3 py-1.5 shadow-2xs whitespace-nowrap flex items-center gap-1.5">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                            {{ __('chemicals.item_exists_sync') }}
                                        </button>
                                    @endcan
                                @endif
                            </div>
                        </div>
                    </div>
                @else
                    @can('create', App\Models\Item::class)
                        <div class="mt-6 pt-5 border-t border-border flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <span class="text-xs text-ink-muted">{{ __('chemicals.add_to_registry_hint') }}</span>
                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="syncToRegistry" wire:loading.attr="disabled"
                                        wire:confirm="{{ __('chemicals.sync_1click_confirm') }}"
                                        class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2 flex items-center justify-center gap-2 shadow-2xs whitespace-nowrap">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                    {{ __('chemicals.sync_1click_btn') }}
                                </button>
                                <a href="{{ route('items.create', [
                                    'cas_no' => $by === 'cas' ? $query : '',
                                    'name_en' => $title,
                                    'formula' => $molecularFormula,
                                    'autofill' => 1,
                                ]) }}" class="rounded-lg border border-border bg-surface hover:bg-surface-alt text-ink text-sm font-semibold px-3.5 py-2 flex items-center justify-center gap-1.5 shadow-2xs whitespace-nowrap">
                                    {{ __('chemicals.add_to_registry') }}
                                </a>
                            </div>
                        </div>
                    @endcan
                @endif
            @endif
        </div>
    @endif

    <p class="text-xs text-ink-faint mt-4">{{ __('chemicals.source_note') }}</p>
</div>
