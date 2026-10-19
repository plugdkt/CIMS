<x-layout>
    @include('ims._tabs')
    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('ims.places_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('ims.places_subtitle') }}</p>
    </div>

    <form method="GET" action="{{ route('ims.places.index') }}" class="flex flex-wrap items-center gap-3 mb-4">
        @if ($labs->isNotEmpty())
            <label for="lab" class="sr-only">{{ __('labs.index_title') }}</label>
            <select id="lab" name="lab" class="rounded-lg border border-border px-3 py-2 text-sm">
                @foreach ($labs as $lab)
                    <option value="{{ $lab->id }}" @selected($lab->id === $labId)>{{ $lab->name_th }}</option>
                @endforeach
            </select>
        @endif
        <label for="search" class="sr-only">{{ __('ims.places_search') }}</label>
        <input id="search" name="search" type="search" value="{{ $search }}" placeholder="{{ __('ims.places_search') }}"
               class="w-80 rounded-lg border border-border px-3 py-2 text-sm">
        <button type="submit" class="rounded-lg bg-accent text-white text-sm font-semibold px-4 py-2">{{ __('ims.places_find') }}</button>
        @if ($search !== '')
            <a href="{{ route('ims.places.index', array_filter(['lab' => $labs->isNotEmpty() ? $labId : null])) }}" class="text-sm text-ink-muted hover:text-ink">{{ __('ims.places_clear') }}</a>
        @endif
    </form>

    @if ($unplaced > 0)
        <div class="mb-4 rounded-lg border border-warning/40 bg-warning-soft text-warning-ink px-4 py-3 text-sm flex flex-wrap items-center gap-3">
            <span class="font-semibold">⚠️ {{ __('ims.unplaced_banner', ['count' => $unplaced]) }}</span>
            @can('ims.manage')
                <a href="{{ route('ims.lots.locate') }}" class="rounded-lg bg-success hover:bg-success-ink text-white text-xs font-semibold px-3 py-1.5">{{ __('ims.locate_open') }}</a>
            @endcan
        </div>
    @endif

    @if ($places === [])
        <p class="rounded-xl border border-border bg-surface px-5 py-8 text-center text-ink-muted text-sm">{{ $search !== '' ? __('ims.places_none_found') : __('ims.places_none') }}</p>
    @else
        <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($places as $p)
                <li>
                    <a href="{{ route('ims.places.show', $p['location']) }}"
                       class="block h-full rounded-xl border border-border bg-surface hover:border-accent hover:shadow-sm px-4 py-3 transition">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="font-semibold leading-snug">{{ $p['location']->name }}</div>
                                @if ($p['depth'] > 0)
                                    <div class="text-xs text-ink-faint truncate">{{ $p['path'] }}</div>
                                @endif
                                <div class="font-mono text-[11px] text-ink-faint">{{ $p['location']->code }}</div>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="font-mono text-xl font-bold {{ $p['count'] > 0 ? 'text-accent-strong' : 'text-ink-faint' }}">{{ $p['count'] }}</div>
                                <div class="text-[11px] text-ink-faint">{{ __('ims.places_kinds') }}</div>
                            </div>
                        </div>
                        @if ($p['matches'] !== [])
                            <ul class="mt-2 text-xs text-accent-strong space-y-0.5">
                                @foreach ($p['matches'] as $m)
                                    <li>• {{ $m }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</x-layout>
