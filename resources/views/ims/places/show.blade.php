<x-layout>
    @include('ims._tabs')

    <div class="mb-5">
        <a href="{{ route('ims.places.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('ims.places_back') }}</a>
        <h1 class="font-display text-lg font-bold mt-1">{{ $place->name }}</h1>
        <p class="text-xs text-ink-faint">{{ $path }} · <span class="font-mono">{{ $place->code }}</span></p>
    </div>

    <livewire:ims.place-contents :location-id="$place->id" />
</x-layout>
