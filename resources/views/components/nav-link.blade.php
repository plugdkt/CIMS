@props(['route', 'active' => false])

<a href="{{ $route }}"
   {{ $attributes->class([
        'flex items-center gap-3 pl-3 pr-2 py-2.5 rounded-lg text-base border-l-[3px] transition-colors',
        'bg-accent-soft text-accent-soft-ink font-semibold border-accent' => $active,
        'text-ink-muted font-medium border-transparent hover:bg-surface-alt hover:text-ink' => ! $active,
   ]) }}>
    <span class="shrink-0 {{ $active ? 'text-accent' : 'text-ink-faint' }}">{{ $icon }}</span>
    <span>{{ $slot }}</span>
</a>
