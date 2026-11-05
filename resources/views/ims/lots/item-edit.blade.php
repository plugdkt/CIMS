<x-layout>
    @include('ims._tabs')
    @php
        $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
        $kind = App\Domain\Shared\ItemType::forItem($item);
        $field = 'w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent';
    @endphp

    <a href="{{ route('ims.lots.index', ['search' => $item->item_code]) }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('ims.back_to_lots') }}</a>

    <div class="mt-1 mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('ims.item_edit.title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('ims.item_edit.subtitle') }}</p>
        <p class="text-sm mt-2 flex flex-wrap items-center gap-2">
            <span class="font-semibold">{{ $item->name_th }}</span>
            <span class="font-mono text-xs">{{ $item->item_code }}</span>
            <x-item-kind-badge :kind="$kind" />
            <span class="text-xs text-ink-faint">{{ __('ims.item_edit.lot_context', ['lot' => $lot->lot_no ?: '—']) }}</span>
        </p>
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

    <form method="POST" action="{{ route('ims.lots.item.update', $lot) }}" class="bg-surface border border-border rounded-xl p-6 space-y-5 mb-6">
        @csrf
        @method('PUT')
        <h2 class="font-display text-base font-bold">{{ __('ims.item_edit.section_editable') }}</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-semibold mb-1" for="category_id">{{ __('items.field_category') }} <span class="text-danger">*</span></label>
                @include('items._category-select', ['categories' => $categories, 'selected' => old('category_id', $item->category_id)])
            </div>
            <div>
                <label class="block text-sm font-semibold mb-1" for="name_th">{{ __('items.field_name_th') }} <span class="text-danger">*</span></label>
                <input type="text" name="name_th" id="name_th" value="{{ old('name_th', $item->name_th) }}" class="{{ $field }}" required>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="name_en">{{ __('items.field_name_en') }}</label>
                <input type="text" name="name_en" id="name_en" value="{{ old('name_en', $item->name_en) }}" class="{{ $field }}">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="cas_no">{{ __('items.field_cas_no') }}</label>
                <input type="text" name="cas_no" id="cas_no" value="{{ old('cas_no', $item->cas_no) }}" class="{{ $field }}">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="formula">{{ __('items.field_formula') }}</label>
                <input type="text" name="formula" id="formula" value="{{ old('formula', $item->formula) }}" class="{{ $field }}">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="brand">{{ __('items.field_brand') }}</label>
                <input type="text" name="brand" id="brand" value="{{ old('brand', $item->brand) }}" class="{{ $field }}">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="grade">{{ __('items.field_grade') }}</label>
                <input type="text" name="grade" id="grade" value="{{ old('grade', $item->grade) }}" class="{{ $field }}">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="physical_state">{{ __('items.field_physical_state') }}</label>
                <select name="physical_state" id="physical_state" class="{{ $field }}">
                    <option value="">{{ __('items.select_placeholder') }}</option>
                    @foreach (['liquid', 'solid', 'powder', 'solution', 'gas', 'crystal', 'pellet'] as $state)
                        <option value="{{ $state }}" @selected(old('physical_state', $item->physical_state) === $state)>{{ __('items.state_'.$state) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1" for="storage_class">{{ __('items.field_storage_class') }}</label>
                <input type="text" name="storage_class" id="storage_class" value="{{ old('storage_class', $item->storage_class) }}" class="{{ $field }}">
            </div>
        </div>

        <div>
            <label class="block text-sm font-semibold mb-1" for="specification">{{ __('items.field_specification') }}</label>
            <textarea name="specification" id="specification" rows="6" class="{{ $field }} leading-relaxed">{{ old('specification', $item->specification) }}</textarea>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-xl bg-accent hover:bg-accent-strong text-white text-sm font-bold py-2.5 px-5">{{ __('ims.item_edit.btn_save') }}</button>
            <a href="{{ route('ims.lots.index', ['search' => $item->item_code]) }}" class="rounded-xl border border-border hover:bg-surface-alt text-ink-muted hover:text-ink text-sm font-medium py-2.5 px-4">{{ __('items.btn_cancel') }}</a>
        </div>
    </form>

    {{-- What stays in the items register: it changes how stored quantities are converted, so it is read-only here. --}}
    <div class="bg-surface-alt/40 border border-border rounded-xl p-5">
        <h2 class="font-display text-sm font-bold">{{ __('ims.item_edit.section_readonly') }}</h2>
        <dl class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm mt-3">
            <div><dt class="text-xs text-ink-faint">{{ __('ims.item_edit.field_item_code') }}</dt><dd class="font-mono">{{ $item->item_code }}</dd></div>
            <div><dt class="text-xs text-ink-faint">{{ __('ims.item_edit.field_base_unit') }}</dt><dd>{{ $item->baseUnit?->name_th ?: '—' }}</dd></div>
            <div><dt class="text-xs text-ink-faint">{{ __('ims.item_edit.field_package_size') }}</dt><dd>@if ($item->package_size !== null) {{ $fmt($item->package_size) }} {{ $item->packageUnit?->code }} @else — @endif</dd></div>
        </dl>
        <p class="text-xs text-ink-muted mt-3">{{ __('ims.item_edit.readonly_hint') }}</p>
    </div>
</x-layout>
