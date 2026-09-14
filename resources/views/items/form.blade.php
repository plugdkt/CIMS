<x-layout>
    <div class="max-w-7xl mx-auto">
        <div class="mb-6 flex items-center justify-between">
            <div>
                <a href="{{ route('items.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink flex items-center gap-1">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    {{ __('items.back_to_list') }}
                </a>
                <h1 class="font-display text-xl font-bold text-ink mt-1">
                    {{ $item->exists ? __('items.edit_title') : __('items.create_title') }}
                </h1>
            </div>
            @if ($item->exists)
                <a href="{{ route('items.show', $item) }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-border bg-surface hover:bg-surface-alt text-xs font-semibold text-ink shadow-2xs">
                    {{ __('items.view_details') }} &rarr;
                </a>
            @endif
        </div>

        @if ($errors->any())
            <div class="mb-6 rounded-xl bg-danger-soft text-danger-ink text-sm p-4 border border-danger/20">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $item->exists ? route('items.update', $item) : route('items.store') }}"
              class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            @csrf
            @if ($item->exists) @method('PUT') @endif

            {{-- คอลัมน์ซ้าย: ข้อมูลสารเคมี สเปกกลาง และการจัดเก็บ (7 คอลัมน์) --}}
            <div class="lg:col-span-7 space-y-6">
                {{-- กล่องที่ 1: ข้อมูลทั่วไปและคุณลักษณะเฉพาะ --}}
                <div class="bg-surface border border-border rounded-xl p-6 space-y-5 shadow-2xs">
                    <div class="border-b border-border pb-3">
                        <h2 class="font-display text-base font-bold text-ink flex items-center gap-2">
                            <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            {{ __('items.section_general') }}
                        </h2>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold mb-1" for="item_code">
                                {{ __('items.field_item_code') }} <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="item_code" id="item_code" value="{{ old('item_code', $item->item_code) }}"
                                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent" required>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1" for="category_id">
                                {{ __('items.field_category') }} <span class="text-danger">*</span>
                            </label>
                            <select name="category_id" id="category_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent" required>
                                <option value="">{{ __('items.select_placeholder') }}</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected((int) old('category_id', $item->category_id) === $category->id)>{{ $category->name_th }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold mb-1" for="name_th">
                                {{ __('items.field_name_th') }} <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="name_th" id="name_th" value="{{ old('name_th', $item->name_th) }}"
                                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" for="name_en">{{ __('items.field_name_en') }}</label>
                            <input type="text" name="name_en" id="name_en" value="{{ old('name_en', $item->name_en) }}"
                                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1" for="cas_no">{{ __('items.field_cas_no') }}</label>
                            <input type="text" name="cas_no" id="cas_no" value="{{ old('cas_no', $item->cas_no) }}"
                                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent"
                                   placeholder="เช่น 1310-73-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" for="formula">{{ __('items.field_formula') }}</label>
                            <input type="text" name="formula" id="formula" value="{{ old('formula', $item->formula) }}"
                                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent"
                                   placeholder="เช่น NaOH">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1" for="brand">{{ __('items.field_brand') }}</label>
                            <input type="text" name="brand" id="brand" value="{{ old('brand', $item->brand) }}"
                                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent"
                                   placeholder="เช่น Merck, Sigma-Aldrich">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" for="grade">{{ __('items.field_grade') }}</label>
                            <input type="text" name="grade" id="grade" value="{{ old('grade', $item->grade) }}"
                                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent"
                                   placeholder="เช่น AR, HPLC, ACS">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-1" for="specification">
                            {{ __('items.field_specification') }}
                        </label>
                        <textarea name="specification" id="specification" rows="5"
                                  class="w-full rounded-lg border border-border bg-surface px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent leading-relaxed"
                                  placeholder="{{ __('items.specification_placeholder') }}">{{ old('specification', $item->specification) }}</textarea>
                    </div>

                    <div class="pt-1">
                        <label class="block text-sm font-medium mb-1" for="base_unit_id">
                            {{ __('items.field_base_unit_optional') }}
                        </label>
                        <select name="base_unit_id" id="base_unit_id" class="w-full sm:max-w-xs rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                            <option value="">{{ __('items.select_placeholder') }}</option>
                            @foreach ($units as $unit)
                                <option value="{{ $unit->id }}" @selected((int) old('base_unit_id', $item->base_unit_id) === $unit->id)>
                                    {{ $unit->name_th }} ({{ $unit->code }})
                                </option>
                            @endforeach
                        </select>
                        <p class="text-xs text-ink-muted mt-1.5">
                            {{ __('items.field_base_unit_hint') }}
                        </p>
                    </div>
                </div>

                {{-- กล่องที่ 2: การจัดเก็บและการควบคุม --}}
                <div class="bg-surface border border-border rounded-xl p-6 space-y-4 shadow-2xs">
                    <div class="border-b border-border pb-3">
                        <h2 class="font-display text-base font-bold text-ink flex items-center gap-2">
                            <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                            {{ __('items.section_storage_control') }}
                        </h2>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1" for="storage_class">{{ __('items.field_storage_class') }}</label>
                            <input type="text" name="storage_class" id="storage_class" value="{{ old('storage_class', $item->storage_class) }}"
                                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent"
                                   placeholder="เช่น 3 (Flammable liquids)">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1" for="shelf_life_days_after_open">{{ __('items.field_shelf_life') }}</label>
                            <input type="number" min="0" step="1" name="shelf_life_days_after_open" id="shelf_life_days_after_open" value="{{ old('shelf_life_days_after_open', $item->shelf_life_days_after_open) }}"
                                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent"
                                   placeholder="เช่น 180">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                        <label class="flex items-center gap-2 text-sm p-3 rounded-lg border border-border bg-surface-alt/40 cursor-pointer">
                            <input type="hidden" name="is_controlled" value="0">
                            <input type="checkbox" name="is_controlled" value="1" @checked(old('is_controlled', $item->is_controlled)) class="rounded border-border text-accent focus:ring-accent">
                            <span class="font-medium text-ink">{{ __('items.field_is_controlled') }}</span>
                        </label>
                        <label class="flex items-center gap-2 text-sm p-3 rounded-lg border border-border bg-surface-alt/40 cursor-pointer">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $item->exists ? $item->is_active : true)) class="rounded border-border text-accent focus:ring-accent">
                            <span class="font-medium text-ink">{{ __('items.field_is_active') }}</span>
                        </label>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1" for="control_class">{{ __('items.field_control_class') }}</label>
                        <input type="text" name="control_class" id="control_class" value="{{ old('control_class', $item->control_class) }}"
                               class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent"
                               placeholder="เช่น ยุทธภัณฑ์, วัตถุอันตรายชนิดที่ 3">
                    </div>
                </div>
            </div>

            {{-- คอลัมน์ขวา: ความปลอดภัย GHS, H/P Statements และปุ่มบันทึก (5 คอลัมน์) --}}
            <div class="lg:col-span-5 space-y-6">
                {{-- กล่องบันทึกข้อมูล (Action Card) --}}
                <div class="bg-surface border border-border rounded-xl p-5 shadow-2xs">
                    <h3 class="font-display text-sm font-bold text-ink mb-1">{{ __('items.section_actions') }}</h3>
                    <p class="text-xs text-ink-muted mb-4">{{ __('items.actions_hint') }}</p>
                    <div class="flex items-center gap-3">
                        <button type="submit" class="flex-1 rounded-xl bg-accent hover:bg-accent-strong text-white text-sm font-bold py-2.5 px-4 shadow-sm transition-colors text-center">
                            {{ __('items.save') }}
                        </button>
                        <a href="{{ route('items.index') }}" class="rounded-xl border border-border hover:bg-surface-alt text-ink-muted hover:text-ink text-sm font-medium py-2.5 px-4 transition-colors text-center">
                            {{ __('items.btn_cancel') }}
                        </a>
                    </div>
                </div>

                {{-- กล่อง GHS Pictograms --}}
                <div class="bg-surface border border-border rounded-xl p-6 shadow-2xs">
                    <div class="border-b border-border pb-3 mb-4">
                        <h2 class="font-display text-base font-bold text-ink flex items-center gap-2">
                            <svg class="w-4 h-4 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            {{ __('items.ghs_section_title') }}
                        </h2>
                    </div>
                    <div class="grid grid-cols-3 gap-2.5">
                        @foreach (config('ghs.pictograms') as $code => $name)
                            <label class="flex flex-col items-center gap-1 rounded-xl border border-border p-2.5 text-center cursor-pointer hover:border-accent has-[:checked]:border-accent has-[:checked]:bg-accent-soft/30 transition-all">
                                <input type="checkbox" name="ghs_codes[]" value="{{ $code }}" class="rounded border-border text-accent focus:ring-accent"
                                       @checked(in_array($code, old('ghs_codes', $item->ghs_codes ?? []), true))>
                                <x-ghs-icon :code="$code" :size="36" />
                                <span class="text-[11px] font-mono text-ink-muted leading-tight">{{ $code }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- กล่อง H-Statements --}}
                <div class="bg-surface border border-border rounded-xl p-6 shadow-2xs" x-data="{ search: '' }">
                    <div class="border-b border-border pb-3 mb-3">
                        <h2 class="font-display text-sm font-bold text-ink">{{ __('items.h_statements_section_title') }}</h2>
                    </div>
                    <input type="text" x-model="search" placeholder="{{ __('items.search_statements_placeholder') }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-1.5 text-xs mb-2.5 focus:outline-none focus:ring-2 focus:ring-accent">
                    <div class="max-h-48 overflow-y-auto rounded-lg border border-border divide-y divide-border/60 text-xs" tabindex="0">
                        @foreach (config('ghs.hazard_statements') as $code => $text)
                            <label class="flex items-start gap-2 px-2.5 py-2 hover:bg-surface-alt cursor-pointer"
                                   x-show="@js(mb_strtolower("$code $text")).includes(search.toLowerCase())">
                                <input type="checkbox" name="h_statements[]" value="{{ $code }}" class="mt-0.5 rounded border-border text-accent focus:ring-accent"
                                       @checked(in_array($code, old('h_statements', $item->h_statements ?? []), true))>
                                <span class="leading-snug"><span class="font-mono font-semibold text-ink-muted">{{ $code }}</span> {{ $text }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- กล่อง P-Statements --}}
                <div class="bg-surface border border-border rounded-xl p-6 shadow-2xs" x-data="{ search: '' }">
                    <div class="border-b border-border pb-3 mb-3">
                        <h2 class="font-display text-sm font-bold text-ink">{{ __('items.p_statements_section_title') }}</h2>
                    </div>
                    <input type="text" x-model="search" placeholder="{{ __('items.search_statements_placeholder') }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-1.5 text-xs mb-2.5 focus:outline-none focus:ring-2 focus:ring-accent">
                    <div class="max-h-48 overflow-y-auto rounded-lg border border-border divide-y divide-border/60 text-xs" tabindex="0">
                        @foreach (config('ghs.precautionary_statements') as $code => $text)
                            <label class="flex items-start gap-2 px-2.5 py-2 hover:bg-surface-alt cursor-pointer"
                                   x-show="@js(mb_strtolower("$code $text")).includes(search.toLowerCase())">
                                <input type="checkbox" name="p_statements[]" value="{{ $code }}" class="mt-0.5 rounded border-border text-accent focus:ring-accent"
                                       @checked(in_array($code, old('p_statements', $item->p_statements ?? []), true))>
                                <span class="leading-snug"><span class="font-mono font-semibold text-ink-muted">{{ $code }}</span> {{ $text }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
        </form>

        {{-- SDS Attachments (ถ้าเป็นโหมดแก้ไข) --}}
        @if ($item->exists)
            <div class="bg-surface border border-border rounded-xl p-6 mt-8 shadow-2xs">
                <h2 class="font-display text-base font-bold mb-1">{{ __('attachments.sds_title') }}</h2>
                <p class="text-xs text-ink-faint mb-4">{{ __('attachments.allowed_types_hint') }}</p>

                @if ($sdsAttachments->isNotEmpty())
                    <ul class="divide-y divide-border mb-4">
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
                    <p class="text-sm text-ink-muted mb-4">{{ __('attachments.sds_empty') }}</p>
                @endif

                @can('update', $item)
                    <form method="POST" action="{{ route('items.attachments.store', $item) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <input type="hidden" name="doc_type" value="SDS">
                        <div class="flex-1 min-w-[200px]">
                            <label class="block text-xs font-medium mb-1" for="sds_file">{{ __('attachments.field_file') }}</label>
                            <input type="file" name="file" id="sds_file" required class="w-full text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" for="sds_revised_date">{{ __('attachments.field_revised_date') }}</label>
                            <input type="date" name="revised_date" id="sds_revised_date" class="rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                        </div>
                        <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">
                            {{ __('attachments.upload') }}
                        </button>
                    </form>
                @endcan
            </div>
        @endif
    </div>
</x-layout>
