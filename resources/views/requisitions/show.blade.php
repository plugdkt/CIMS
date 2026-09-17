<x-layout>
    <div class="max-w-4xl">
        <div class="mb-5">
            <a href="{{ route('requisitions.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('requisitions.back_to_list') }}</a>
            <div class="flex items-center justify-between mt-1">
                <h1 class="font-display text-lg font-bold">{{ $requisition->doc_no }}</h1>
                @php
                    $badge = match ($requisition->status) {
                        'ISSUED' => 'bg-success-soft text-success-ink',
                        'REJECTED', 'CANCELLED' => 'bg-danger-soft text-danger-ink',
                        'APPROVED', 'PARTIALLY_ISSUED', 'ADVISOR_APPROVED' => 'bg-accent-soft text-accent-soft-ink',
                        default => 'bg-neutral-soft text-neutral-ink',
                    };
                @endphp
                <span class="px-2.5 py-1 rounded-full text-xs font-semibold whitespace-nowrap {{ $badge }}">
                    {{ __('requisitions.status_'.strtolower($requisition->status)) }}
                </span>
            </div>
            <div class="flex items-center gap-4 mt-1">
                <a href="{{ route('requisitions.pdf', $requisition) }}" target="_blank" class="text-xs font-semibold text-accent hover:text-accent-strong">
                    {{ __('requisitions.print_pdf') }}
                </a>
                @can('issue', $requisition)
                    <a href="{{ route('requisitions.issue.create', $requisition) }}" class="text-xs font-semibold text-accent hover:text-accent-strong">
                        {{ __('requisitions.go_to_issue') }}
                    </a>
                @endcan
            </div>
        </div>

        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="bg-surface border border-border rounded-xl p-6">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('requisitions.field_doc_date') }}</dt>
                    <dd>{{ $requisition->doc_date->format('d/m/Y') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('requisitions.field_lab') }}</dt>
                    <dd>{{ $requisition->lab?->name_th }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('requisitions.field_requester_name') }}</dt>
                    <dd>{{ $requisition->requester?->full_name }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('requisitions.field_advisor') }}</dt>
                    <dd>{{ $requisition->advisor?->full_name ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('requisitions.field_purpose_type') }}</dt>
                    <dd>{{ __('requisitions.purpose_type_'.strtolower($requisition->purpose_type)) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('requisitions.field_purpose_detail') }}</dt>
                    <dd>{{ $requisition->purpose_detail ?: '—' }}</dd>
                </div>
            </dl>
        </div>

        <div class="bg-surface border border-border rounded-xl p-6 mt-6">
            <h2 class="font-display text-base font-bold mb-3">{{ __('requisitions.lines_title') }}</h2>

            @if ($requisition->items->isNotEmpty())
                <div class="overflow-x-auto mb-4" tabindex="0">
                    <table class="w-full text-sm">
                        <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th class="px-3 py-2 font-semibold">#</th>
                                <th class="px-3 py-2 font-semibold">{{ __('requisitions.field_item') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('requisitions.field_qty_requested') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('requisitions.field_reference_doc') }}</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($requisition->items as $line)
                                <tr>
                                    <td class="px-3 py-2 align-top text-ink-muted">{{ $line->line_no }}</td>
                                    <td class="px-3 py-2 align-top">
                                        <div class="font-medium text-ink">{{ $line->item?->name_th }}</div>
                                        <div class="flex items-center gap-1.5 mt-0.5">
                                            @if ($line->item?->item_code)
                                                <span class="font-mono text-[11px] text-ink-muted">{{ $line->item->item_code }}</span>
                                            @endif
                                            @if ($line->item?->grade)
                                                <span class="inline-block text-[11px] px-1.5 py-0.2 rounded bg-accent-soft text-accent-strong font-semibold border border-accent/20">{{ $line->item->grade }}</span>
                                            @endif
                                            @if ($line->item?->physical_state)
                                                @php
                                                    $stateBadge = match($line->item->physical_state) {
                                                        'liquid' => ['icon' => '💧', 'label' => __('items.state_liquid')],
                                                        'powder' => ['icon' => '🧂', 'label' => __('items.state_powder')],
                                                        'solid' => ['icon' => '🧊', 'label' => __('items.state_solid')],
                                                        'solution' => ['icon' => '🧪', 'label' => __('items.state_solution')],
                                                        'gas' => ['icon' => '💨', 'label' => __('items.state_gas')],
                                                        'crystal' => ['icon' => '💎', 'label' => __('items.state_crystal')],
                                                        'pellet' => ['icon' => '⚪', 'label' => __('items.state_pellet')],
                                                        default => ['icon' => '•', 'label' => $line->item->physical_state],
                                                    };
                                                @endphp
                                                <span class="text-[11px] text-ink-muted">({{ $stateBadge['icon'] }} {{ $stateBadge['label'] }})</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 align-top">{{ $line->qty_requested }} {{ $line->unit?->code }}</td>
                                    <td class="px-3 py-2 align-top">{{ $line->reference_doc ?: '—' }}</td>
                                    <td class="px-3 py-2 align-top text-right">
                                        @if ($canEdit)
                                            <form method="POST" action="{{ route('requisitions.items.destroy', [$requisition, $line]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-xs font-semibold text-danger hover:text-danger-ink">
                                                    {{ __('requisitions.remove_line') }}
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-ink-muted mb-4">{{ __('requisitions.no_lines') }}</p>
            @endif

            @if ($canEdit)
                <form method="POST" action="{{ route('requisitions.items.store', $requisition) }}"
                      x-data="{
                          balance: null, unit: null, itemId: '', unitId: '', itemDimension: null,
                          query: '', results: [], open: false, activeIndex: -1,
                          {{-- @json() alone leaves literal quotes in the JSON (its HEX_* options only
                               escape quote characters inside string *content*, not JSON's own required
                               structural quotes) — those broke this double-quoted x-data attribute wide
                               open, spilling the rest of the expression as visible page text. Js::from()
                               is the safe primitive for embedding arbitrary data in an HTML attribute. --}}
                          units: {{ \Illuminate\Support\Js::from($units->map(fn ($u) => ['id' => (string) $u->id, 'code' => $u->code, 'dimension' => $u->dimension])) }},
                          async loadBalance(ulid) {
                              if (!ulid) { this.balance = null; return; }
                              const res = await fetch(`{{ url('requisitions/items') }}/${ulid}/balance`);
                              const data = await res.json();
                              this.balance = data.balance; this.unit = data.unit;
                          },
                          async search() {
                              this.itemId = ''; this.balance = null;
                              const term = this.query.trim();
                              const res = await fetch(`{{ route('requisitions.items.search') }}?q=${encodeURIComponent(term)}`);
                              this.results = await res.json();
                              this.open = true;
                              this.activeIndex = -1;
                          },
                          selectItem(item) {
                              this.itemId = item.id; this.query = item.label;
                              this.results = []; this.open = false; this.activeIndex = -1;
                              // Default to (and restrict) the unit dropdown to this item's own
                              // dimension — asking in kg instead of g is fine, asking in mL for a
                              // by-mass item isn't a choice a requester should have to reason about.
                              this.itemDimension = item.dimension;
                              this.unitId = item.baseUnitId !== null ? String(item.baseUnitId) : '';
                              this.loadBalance(item.ulid);
                          },
                          moveActive(delta) {
                              if (!this.open || this.results.length === 0) return;
                              this.activeIndex = (this.activeIndex + delta + this.results.length) % this.results.length;
                          },
                          chooseActive() {
                              if (this.activeIndex >= 0 && this.results[this.activeIndex]) {
                                  this.selectItem(this.results[this.activeIndex]);
                              }
                          },
                          visibleUnits() {
                              return this.itemDimension === null ? this.units : this.units.filter(u => u.dimension === this.itemDimension);
                          },
                      }"
                      class="grid grid-cols-1 sm:grid-cols-3 gap-3 border-t border-border pt-4">
                    @csrf
                    <div class="sm:col-span-3 relative">
                        <label class="block text-xs font-medium mb-1" for="item_search">{{ __('requisitions.field_item') }}</label>
                        <input type="hidden" name="item_id" :value="itemId">
                        <input type="text" id="item_search" autocomplete="off" x-model="query"
                               @input.debounce.300ms="search()"
                               @keydown.arrow-down.prevent="moveActive(1)"
                               @keydown.arrow-up.prevent="moveActive(-1)"
                               @keydown.enter.prevent="chooseActive()"
                               @keydown.escape="open = false"
                               @focus="search()"
                               @click.outside="open = false"
                               role="combobox" aria-expanded="open" aria-controls="item-search-listbox" aria-autocomplete="list"
                               :aria-activedescendant="activeIndex >= 0 ? `item-search-option-${activeIndex}` : null"
                               placeholder="{{ __('requisitions.field_item_search_placeholder') }}"
                               class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                        <ul x-show="open" id="item-search-listbox" role="listbox"
                            class="absolute z-10 mt-1 w-full max-h-64 overflow-y-auto rounded-lg border border-border bg-surface shadow-lg text-sm" tabindex="0">
                            <template x-for="(item, index) in results" :key="item.id">
                                <li :id="`item-search-option-${index}`" role="option" :aria-selected="index === activeIndex"
                                    @click="selectItem(item)" @mouseenter="activeIndex = index"
                                    class="px-3 py-2 cursor-pointer"
                                    :class="index === activeIndex ? 'bg-accent-soft text-accent-strong' : 'hover:bg-surface-alt'"
                                    x-text="item.label"></li>
                            </template>
                            <li x-show="results.length === 0" class="px-3 py-2 text-ink-muted">{{ __('requisitions.item_search_no_results') }}</li>
                        </ul>
                        {{-- FR-RQ-05: current balance next to the selected item, real-time. --}}
                        <p class="text-xs text-ink-muted mt-1" x-show="balance !== null">
                            {{ __('requisitions.current_balance') }}: <span x-text="balance"></span> <span x-text="unit"></span>
                        </p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" for="qty_requested">{{ __('requisitions.field_qty_requested') }}</label>
                        <input type="text" name="qty_requested" id="qty_requested" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" for="unit_id">{{ __('requisitions.field_unit') }}</label>
                        <select name="unit_id" id="unit_id" x-model="unitId" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                            <option value="">{{ __('items.select_placeholder') }}</option>
                            <template x-for="u in visibleUnits()" :key="u.id">
                                <option :value="u.id" x-text="u.code"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" for="reference_doc">{{ __('requisitions.field_reference_doc') }}</label>
                        <input type="text" name="reference_doc" id="reference_doc" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    </div>
                    <div class="sm:col-span-3">
                        <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">
                            {{ __('requisitions.add_line') }}
                        </button>
                    </div>
                </form>
            @endif
        </div>

        @if ($canEdit)
            <div class="flex items-center gap-3 mt-6">
                <form method="POST" action="{{ route('requisitions.submit', $requisition) }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5"
                            @disabled($requisition->items->isEmpty())>
                        {{ __('requisitions.submit') }}
                    </button>
                </form>
                <form method="POST" action="{{ route('requisitions.cancel', $requisition) }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-5 py-2.5">
                        {{ __('requisitions.cancel') }}
                    </button>
                </form>
            </div>
        @elseif ($requisition->status === 'SUBMITTED' && auth()->id() === $requisition->requester_id)
            <div class="mt-6">
                <form method="POST" action="{{ route('requisitions.cancel', $requisition) }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-5 py-2.5">
                        {{ __('requisitions.cancel') }}
                    </button>
                </form>
            </div>
        @endif

        @can('advisorDecide', $requisition)
            <div class="bg-surface border border-border rounded-xl p-6 mt-6">
                <h2 class="font-display text-base font-bold mb-3">{{ __('requisitions.advisor_decision_title') }}</h2>
                <form method="POST" action="{{ route('requisitions.advisor-decide', $requisition) }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="flex items-center gap-2 text-sm mb-2">
                            <input type="radio" name="decision" value="APPROVE" checked>
                            {{ __('requisitions.approve_decision') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="decision" value="REJECT">
                            {{ __('requisitions.reject_decision') }}
                        </label>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" for="advisor_reason">{{ __('requisitions.field_reject_reason') }}</label>
                        <textarea name="reason" id="advisor_reason" rows="2" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">{{ old('reason') }}</textarea>
                    </div>
                    <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">
                        {{ __('requisitions.submit_decision') }}
                    </button>
                </form>
            </div>
        @endcan

        @can('scientistDecide', $requisition)
            <div class="bg-surface border border-border rounded-xl p-6 mt-6">
                <h2 class="font-display text-base font-bold mb-3">{{ __('requisitions.scientist_decision_title') }}</h2>
                <form method="POST" action="{{ route('requisitions.scientist-decide', $requisition) }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="flex items-center gap-2 text-sm mb-2">
                            <input type="radio" name="decision" value="APPROVE" checked>
                            {{ __('requisitions.scientist_approve_decision') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="radio" name="decision" value="REJECT">
                            {{ __('requisitions.scientist_reject_decision') }}
                        </label>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" for="scientist_reason">{{ __('requisitions.field_reject_reason') }}</label>
                        <textarea name="reason" id="scientist_reason" rows="2" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">{{ old('reason') }}</textarea>
                    </div>
                    <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">
                        {{ __('requisitions.submit_decision') }}
                    </button>
                </form>
            </div>
        @endcan
    </div>
</x-layout>
