<div>
    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('locations.index_title') }}</h1>
            <p class="text-sm text-ink-muted mt-1">{{ __('locations.index_subtitle') }}</p>
        </div>
        @if ($canManage)
            <a href="{{ route('locations.create') }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5 whitespace-nowrap">
                {{ __('locations.new_location') }}
            </a>
        @endif
    </div>

    @if ($noOwnLab)
        <div class="rounded-lg bg-warning-soft text-warning-ink text-sm px-4 py-3">
            {{ __('locations.no_own_lab') }}
        </div>
    @else
        <div class="bg-surface border border-border rounded-xl p-4">
            @forelse ($roots as $root)
                @include('livewire.locations._node', ['location' => $root, 'byParent' => $byParent, 'conflictIds' => $conflictIds, 'canManage' => $canManage, 'depth' => 0])
            @empty
                <p class="text-sm text-ink-muted px-2 py-4">{{ __('locations.no_results') }}</p>
            @endforelse
        </div>
    @endif
</div>
