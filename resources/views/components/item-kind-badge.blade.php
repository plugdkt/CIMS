@props(['kind'])

{{-- The kind of stock of an item (chemical / consumable / science-loan), as a small badge. --}}
@php
    $tone = match ($kind) {
        App\Domain\Shared\ItemType::CHEMICAL => 'bg-danger-soft text-danger-ink',
        App\Domain\Shared\ItemType::SCIENCE => 'bg-gold-soft text-gold-ink',
        default => 'bg-success-soft text-success-ink',
    };
@endphp
<span {{ $attributes->class(['inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold whitespace-nowrap', $tone]) }}>{{ App\Domain\Shared\ItemType::label($kind) }}</span>
