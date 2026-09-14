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
                  selectedItem: '{{ old('item_id', $selectedItem?->id ?? '') }}',
                  itemsData: {{ Js::from($items->mapWithKeys(fn($it) => [$it->id => ['unit_id' => $it->base_unit_id, 'expiry_date' => $it->expiry_date?->toDateString()]])) }},
                  onItemChange() {
                      const it = this.itemsData[this.selectedItem];
                      if (it) {
                          if (it.unit_id) {
                              const unitEl = document.getElementById('unit_id');
                              if (unitEl) unitEl.value = it.unit_id;
                          }
                          if (it.expiry_date) {
                              const expEl = document.getElementById('expiry_date');
                              if (expEl && !expEl.value) expEl.value = it.expiry_date;
                          }
                      }
                  }
              }"
              class="bg-surface border border-border rounded-xl p-6 space-y-6">
            @csrf

            {{-- 1. เลือกสารเคมี/วัสดุ --}}
            <div>
                <label class="block text-sm font-semibold mb-1" for="item_id">
                    {{ __('stock.field_item') }} <span class="text-danger">*</span>
                </label>
                <select name="item_id" id="item_id"
                        x-model="selectedItem"
                        @change="onItemChange()"
                        class="w-full rounded-lg border border-border bg-surface px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent" required>
                    <option value="">{{ __('stock.select_item') }}</option>
                    @foreach ($items as $item)
                        <option value="{{ $item->id }}" @selected((int) old('item_id', $selectedItem?->id) === $item->id)>
                            {{ $item->item_code ? '['.$item->item_code.'] ' : '' }}{{ $item->name_th }} {{ $item->name_en ? '('.$item->name_en.')' : '' }}
                        </option>
                    @endforeach
                </select>
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
                               placeholder="เช่น 1000">
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
                                   placeholder="เช่น 2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" for="qty_per_container">
                                {{ __('stock.field_qty_per_container') }} <span class="text-danger">*</span>
                            </label>
                            <input type="number" step="0.000001" min="0.000001" name="qty_per_container" id="qty_per_container"
                                   value="{{ old('qty_per_container') }}"
                                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent"
                                   placeholder="เช่น 500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">
                                {{ __('stock.field_unit') }} <span class="text-danger">*</span>
                            </label>
                            <div class="pt-2 text-sm text-ink-muted font-medium">
                                (ใช้หน่วยนับเดียวกับด้านบน)
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
                           placeholder="เช่น LOT-2026-A">
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
