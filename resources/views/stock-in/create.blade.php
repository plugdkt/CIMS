<x-layout>
    <div class="max-w-3xl">
        <div class="mb-5">
            <a href="{{ $selectedItem ? route('items.show', $selectedItem) : route('items.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('items.back_to_list') }}</a>
            <h1 class="font-display text-xl font-bold mt-1">
                {{ __('stock.title_stock_in') }}
            </h1>
            <p class="text-xs text-ink-muted mt-0.5">
                {{ __('stock.subtitle_stock_in') }}
            </p>
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

        <form method="POST" action="{{ route('stock-in.store') }}"
              x-data="{
                  trackingType: '{{ old('tracking_type', 'BULK') }}',
                  selectedItemId: {{ (int) old('item_id', $selectedItem?->id ?? 0) ?: 'null' }},
                  search: '',
                  isOpen: false,
                  items: {{ Js::from($items->map(fn($it) => [
                      'id' => $it->id,
                      'ulid' => $it->ulid,
                      'code' => $it->item_code ?? '',
                      'name_th' => $it->name_th,
                      'name_en' => $it->name_en ?? '',
                      'cas_no' => $it->cas_no ?? '',
                      'unit_id' => $it->base_unit_id,
                      'expiry_date' => $it->expiry_date?->toDateString(),
                  ])) }},
                  get selectedItem() {
                      return this.items.find(i => i.id === this.selectedItemId) || null;
                  },
                  get filteredItems() {
                      const q = this.search.trim().toLowerCase();
                      if (!q) {
                          return this.items.slice(0, 50);
                      }
                      return this.items.filter(i => {
                          return (i.name_th && i.name_th.toLowerCase().includes(q))
                              || (i.name_en && i.name_en.toLowerCase().includes(q))
                              || (i.code && i.code.toLowerCase().includes(q))
                              || (i.cas_no && i.cas_no.toLowerCase().includes(q));
                      }).slice(0, 50);
                  },
                  selectItem(item) {
                      this.selectedItemId = item.id;
                      this.search = '';
                      this.isOpen = false;
                      if (item.unit_id) {
                          const unitEl = document.getElementById('unit_id');
                          if (unitEl) unitEl.value = item.unit_id;
                      }
                      if (item.expiry_date) {
                          const expEl = document.getElementById('expiry_date');
                          if (expEl && !expEl.value) expEl.value = item.expiry_date;
                      }
                  },
                  clearItem() {
                      this.selectedItemId = null;
                      this.search = '';
                      this.isOpen = true;
                      this.$nextTick(() => {
                          this.$refs.searchInput?.focus();
                      });
                  }
              }"
              @submit="if (!selectedItemId) { $event.preventDefault(); alert('{{ __('stock.validation.item_required') }}'); isOpen = true; $refs.searchInput?.focus(); }"
              class="bg-surface border border-border rounded-xl p-6 space-y-6">
            @csrf

            {{-- 1. เลือกสารเคมี/วัสดุ (Searchable Combobox) --}}
            <div>
                <label class="block text-sm font-semibold mb-1">
                    {{ __('stock.field_item') }} <span class="text-danger">*</span>
                </label>

                <input type="hidden" name="item_id" :value="selectedItemId || ''">

                {{-- แสดงสารที่เลือกอยู่ --}}
                <div x-show="selectedItem" class="flex items-center justify-between p-4 rounded-xl border border-accent/40 bg-accent-soft/25 shadow-2xs">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="p-2.5 rounded-lg bg-accent/15 text-accent shrink-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <template x-if="selectedItem?.code">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-mono font-medium bg-surface text-ink-muted border border-border" x-text="selectedItem.code"></span>
                                </template>
                                <span class="font-bold text-ink text-sm sm:text-base truncate" x-text="selectedItem?.name_th"></span>
                            </div>
                            <div class="text-xs text-ink-muted mt-0.5 flex items-center gap-3 flex-wrap">
                                <template x-if="selectedItem?.name_en">
                                    <span class="italic" x-text="selectedItem.name_en"></span>
                                </template>
                                <template x-if="selectedItem?.cas_no">
                                    <span class="font-mono bg-surface px-1.5 py-0.5 rounded border border-border/60" x-text="'CAS: ' + selectedItem.cas_no"></span>
                                </template>
                            </div>
                        </div>
                    </div>
                    <button type="button" @click="clearItem()" class="shrink-0 ml-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-border bg-surface hover:bg-surface-alt text-xs font-medium text-ink-muted hover:text-ink transition-colors shadow-2xs">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        <span>{{ __('stock.change_item') }}</span>
                    </button>
                </div>

                {{-- กล่องค้นหาสารเคมี (Search input + Autocomplete dropdown) --}}
                <div x-show="!selectedItem" class="relative" @click.outside="isOpen = false">
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-ink-muted">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                        <input type="text"
                               x-ref="searchInput"
                               x-model="search"
                               @focus="isOpen = true"
                               @keydown.escape="isOpen = false"
                               placeholder="{{ __('stock.search_item_placeholder') }}"
                               class="w-full rounded-xl border border-border bg-surface pl-10 pr-10 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent transition-all shadow-2xs">
                        <template x-if="search.length > 0">
                            <button type="button" @click="search = ''; $refs.searchInput?.focus()" class="absolute inset-y-0 right-0 pr-3 flex items-center text-ink-muted hover:text-ink">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </template>
                    </div>

                    {{-- Dropdown เมนูรายการสารที่ค้นพบ --}}
                    <div x-show="isOpen"
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="opacity-0 scale-98"
                         x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-75"
                         x-transition:leave-start="opacity-100 scale-100"
                         x-transition:leave-end="opacity-0 scale-98"
                         class="absolute z-30 left-0 right-0 mt-1.5 max-h-72 overflow-y-auto rounded-xl border border-border bg-surface shadow-xl divide-y divide-border/60"
                         style="display: none;">
                        <template x-for="item in filteredItems" :key="item.id">
                            <div @click="selectItem(item)"
                                 class="p-3 hover:bg-accent-soft/30 cursor-pointer transition-colors flex items-center justify-between group">
                                <div class="min-w-0 pr-3">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <template x-if="item.code">
                                            <span class="font-mono text-xs font-semibold px-1.5 py-0.5 rounded bg-surface-alt text-ink-muted border border-border/80" x-text="item.code"></span>
                                        </template>
                                        <span class="text-sm font-semibold text-ink group-hover:text-accent transition-colors" x-text="item.name_th"></span>
                                    </div>
                                    <div class="text-xs text-ink-muted mt-0.5 flex items-center gap-3 flex-wrap">
                                        <template x-if="item.name_en">
                                            <span x-text="item.name_en"></span>
                                        </template>
                                        <template x-if="item.cas_no">
                                            <span class="font-mono" x-text="'CAS: ' + item.cas_no"></span>
                                        </template>
                                    </div>
                                </div>
                                <div class="text-xs text-accent opacity-0 group-hover:opacity-100 transition-opacity font-medium shrink-0 flex items-center gap-1">
                                    <span>{{ __('stock.btn_select') }}</span>
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                    </svg>
                                </div>
                            </div>
                        </template>
                        <template x-if="filteredItems.length === 0">
                            <div class="p-6 text-center text-sm text-ink-muted">
                                <p>{{ __('stock.search_item_not_found') }}</p>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Fallback สำหรับ No-JS --}}
                <noscript>
                    <select name="item_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2.5 text-sm mt-2" required>
                        <option value="">{{ __('stock.select_item') }}</option>
                        @foreach ($items as $item)
                            <option value="{{ $item->id }}" @selected((int) old('item_id', $selectedItem?->id) === $item->id)>
                                {{ $item->item_code ? '['.$item->item_code.'] ' : '' }}{{ $item->name_th }} {{ $item->name_en ? '('.$item->name_en.')' : '' }}
                            </option>
                        @endforeach
                    </select>
                </noscript>
            </div>

            {{-- 2. รูปแบบการจัดเก็บ (Tracking Mode) --}}
            <div>
                <label class="block text-sm font-semibold mb-2">
                    {{ __('stock.field_tracking_type') }} <span class="text-danger">*</span>
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="relative flex flex-col p-4 rounded-xl border cursor-pointer transition-all"
                           :class="trackingType === 'BULK' ? 'border-accent bg-accent-soft/40 shadow-sm' : 'border-border bg-surface hover:bg-surface-alt'">
                        <div class="flex items-center gap-2.5">
                            <input type="radio" name="tracking_type" value="BULK" x-model="trackingType" class="text-accent focus:ring-accent">
                            <span class="text-sm font-semibold text-ink">{{ __('stock.type_bulk') }}</span>
                        </div>
                        <p class="text-xs text-ink-muted mt-2 pl-6">
                            {{ __('stock.type_bulk_hint') }}
                        </p>
                    </label>

                    <label class="relative flex flex-col p-4 rounded-xl border cursor-pointer transition-all"
                           :class="trackingType === 'CONTAINER' ? 'border-accent bg-accent-soft/40 shadow-sm' : 'border-border bg-surface hover:bg-surface-alt'">
                        <div class="flex items-center gap-2.5">
                            <input type="radio" name="tracking_type" value="CONTAINER" x-model="trackingType" class="text-accent focus:ring-accent">
                            <span class="text-sm font-semibold text-ink">{{ __('stock.type_container') }}</span>
                        </div>
                        <p class="text-xs text-ink-muted mt-2 pl-6">
                            {{ __('stock.type_container_hint') }}
                        </p>
                    </label>
                </div>
            </div>

            {{-- 3. ปริมาณที่รับเข้า (Conditional inputs) --}}
            <div class="p-4 rounded-xl bg-surface-alt/60 border border-border/80">
                {{-- กรณี BULK --}}
                <div x-show="trackingType === 'BULK'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1" for="qty">
                            {{ __('stock.field_qty') }} <span class="text-danger">*</span>
                        </label>
                        <input type="number" step="0.000001" min="0.000001" name="qty" id="qty"
                               value="{{ old('qty') }}"
                               class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent"
                               placeholder="{{ __('stock.qty_bulk_placeholder') }}">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" for="unit_id">
                            {{ __('stock.field_unit') }} <span class="text-danger">*</span>
                        </label>
                        <select name="unit_id" id="unit_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent" required>
                            <option value="">{{ __('stock.select_unit') }}</option>
                            @foreach ($units as $unit)
                                <option value="{{ $unit->id }}" @selected((int) old('unit_id', $selectedItem?->base_unit_id) === $unit->id)>
                                    {{ $unit->name_th }} ({{ $unit->code }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- กรณี CONTAINER --}}
                <div x-show="trackingType === 'CONTAINER'" class="space-y-4" style="display: none;">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1" for="container_count">
                                {{ __('stock.field_container_count') }} <span class="text-danger">*</span>
                            </label>
                            <input type="number" min="1" step="1" name="container_count" id="container_count"
                                   value="{{ old('container_count', '1') }}"
                                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent"
                                   placeholder="{{ __('stock.container_count_placeholder') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" for="qty_per_container">
                                {{ __('stock.field_qty_per_container') }} <span class="text-danger">*</span>
                            </label>
                            <input type="number" step="0.000001" min="0.000001" name="qty_per_container" id="qty_per_container"
                                   value="{{ old('qty_per_container') }}"
                                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent"
                                   placeholder="{{ __('stock.qty_per_container_placeholder') }}">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">
                                {{ __('stock.field_unit') }} <span class="text-danger">*</span>
                            </label>
                            <div class="pt-2 text-sm text-ink-muted font-medium">
                                {{ __('stock.unit_same_as_above') }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 4. สถานที่จัดเก็บ และ รายละเอียดอื่นๆ --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1" for="location_id">
                        {{ __('stock.field_location') }} <span class="text-danger">*</span>
                    </label>
                    <select name="location_id" id="location_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent" required>
                        <option value="">{{ __('stock.select_location') }}</option>
                        @foreach ($locations as $loc)
                            <option value="{{ $loc->id }}" @selected((int) old('location_id') === $loc->id)>
                                {{ $loc->name }} ({{ $loc->code }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="expiry_date">
                        {{ __('stock.field_expiry_date') }}
                    </label>
                    <input type="date" name="expiry_date" id="expiry_date"
                           value="{{ old('expiry_date', $selectedItem?->expiry_date?->toDateString()) }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1" for="lot_no">
                        {{ __('stock.field_lot_no') }}
                    </label>
                    <input type="text" name="lot_no" id="lot_no" value="{{ old('lot_no') }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent"
                           placeholder="{{ __('stock.lot_no_placeholder') }}">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="remark">
                        {{ __('stock.field_remark') }}
                    </label>
                    <input type="text" name="remark" id="remark" value="{{ old('remark') }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent"
                           placeholder="{{ __('stock.remark_placeholder') }}">
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                <a href="{{ $selectedItem ? route('items.show', $selectedItem) : route('items.index') }}"
                   class="rounded-lg border border-border hover:bg-surface-alt text-ink-muted text-sm font-medium px-4 py-2">
                    {{ __('stock.btn_cancel') }}
                </a>
                <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2">
                    {{ __('stock.btn_submit') }}
                </button>
            </div>
        </form>
    </div>
</x-layout>
