{{-- Category picker grouped by KIND (chemical / consumable / science-loan): the kind of an item comes from its category. --}}
@php
    $byKind = $categories->groupBy(fn ($category) => $category->kind());
@endphp
<select name="category_id" id="category_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent" required>
    <option value="">{{ __('items.select_placeholder') }}</option>
    @foreach (App\Domain\Shared\ItemType::all() as $kindSlug)
        @if ($byKind->has($kindSlug))
            <optgroup label="{{ App\Domain\Shared\ItemType::label($kindSlug) }}">
                @foreach ($byKind[$kindSlug] as $category)
                    <option value="{{ $category->id }}" @selected((int) $selected === $category->id)>{{ $category->name_th }}</option>
                @endforeach
            </optgroup>
        @endif
    @endforeach
</select>
<p class="text-xs text-ink-muted mt-1.5">{{ __('items.kind_hint') }}</p>
