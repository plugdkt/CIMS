@php
    $children = $byParent->get($location->id, collect());
    $hasConflict = in_array($location->id, $conflictIds, true);
@endphp
<div class="{{ $depth > 0 ? 'ml-6 mt-2 border-l border-border pl-4' : 'mt-2 first:mt-0' }}">
    <div class="flex items-center justify-between gap-3 py-2">
        <div class="min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
                <span class="font-medium">{{ $location->name }}</span>
                <span class="font-mono text-xs text-ink-muted">{{ $location->code }}</span>
                <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-surface-alt text-ink-muted whitespace-nowrap">
                    {{ __('locations.level_'.strtolower($location->level_type)) }}
                </span>
                @if ($location->storage_class)
                    <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-accent-soft text-accent-soft-ink whitespace-nowrap">
                        {{ $location->storage_class }}
                    </span>
                @endif
                @if ($hasConflict)
                    <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-warning-soft text-warning-ink whitespace-nowrap" title="{{ __('locations.conflict_hint') }}">
                        ⚠ {{ __('locations.conflict_badge') }}
                    </span>
                @endif
            </div>
            @if ($location->lab)
                <div class="text-xs text-ink-faint mt-0.5">{{ $location->lab->name_th }}</div>
            @endif
        </div>
        @if ($canManage)
            <a href="{{ route('locations.edit', $location) }}" class="text-xs font-semibold text-accent hover:text-accent-strong whitespace-nowrap">
                {{ __('locations.edit') }}
            </a>
        @endif
    </div>

    @foreach ($children as $child)
        @include('livewire.locations._node', ['location' => $child, 'byParent' => $byParent, 'conflictIds' => $conflictIds, 'canManage' => $canManage, 'depth' => $depth + 1])
    @endforeach
</div>
