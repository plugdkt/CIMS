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
            @endif
        </div>
    @endif

    <p class="text-xs text-ink-faint mt-4">{{ __('chemicals.source_note') }}</p>
</div>
