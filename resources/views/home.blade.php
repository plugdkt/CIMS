<x-layout>
    <div class="mb-6">
        <h1 class="font-display text-lg font-bold">{{ __('home.greeting', ['name' => auth()->user()->full_name]) }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('home.subtitle') }}</p>
    </div>

    @if ($cards === [])
        <p class="rounded-lg bg-surface-alt text-ink-muted text-sm px-4 py-3">{{ __('home.no_access') }}</p>
    @else
        {{-- Cards are plain links (not buttons) so keyboard, screen readers and "open in new tab" all work. --}}
        @php
            $icons = [
                'chem' => 'M9 3h6M10 3v6l-5 9a2 2 0 0 0 1.8 3h10.4A2 2 0 0 0 19 18l-5-9V3M7.5 15h9',
                'supply' => 'M3 7.5 12 3l9 4.5M3 7.5V17l9 4 9-4V7.5M3 7.5l9 4.5m9-4.5-9 4.5m0 0V21',
                'loan' => 'M4 8h13l-3-3M20 16H7l3 3',
                'ops' => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
                'adm' => 'M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
            ];
            $main = array_values(array_filter($cards, fn ($c) => $c['tone'] === 'main'));
            $ops = array_values(array_filter($cards, fn ($c) => $c['key'] === 'ops'));
            $adm = array_values(array_filter($cards, fn ($c) => $c['key'] === 'adm'));
        @endphp

        @foreach ([['cards' => $main, 'label' => null], ['cards' => $ops, 'label' => 'nav.group_ops'], ['cards' => $adm, 'label' => 'nav.group_adm']] as $group)
            @if ($group['cards'] !== [])
                @if ($group['label'] !== null)
                    <h2 class="text-xs font-semibold tracking-wide text-ink-faint mt-6 mb-2">{{ __($group['label']) }}</h2>
                @endif
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-2">
                    @foreach ($group['cards'] as $card)
                        <a href="{{ $card['url'] }}"
                           @class([
                               'flex flex-col gap-2 rounded-xl border p-5 min-h-[9rem] transition-colors hover:border-accent focus-visible:border-accent focus-visible:outline-none',
                               'bg-surface border-border' => $card['tone'] === 'main',
                               'bg-gold-soft border-gold' => $card['tone'] === 'ops',
                               'bg-neutral-soft border-border' => $card['tone'] === 'adm',
                           ])>
                            <span class="w-11 h-11 rounded-xl bg-accent-soft text-accent-soft-ink flex items-center justify-center">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icons[$card['key']] }}"/></svg>
                            </span>
                            <span class="font-display font-bold text-base">{{ $card['title'] }}</span>
                            <span class="text-sm text-ink-muted">{{ $card['desc'] }}</span>
                            @if ($card['badges'] !== [])
                                <span class="mt-auto flex flex-wrap gap-1.5 pt-1">
                                    @foreach ($card['badges'] as $badge)
                                        <span @class([
                                            'rounded-full text-xs font-bold px-2.5 py-0.5 whitespace-nowrap',
                                            'bg-neutral-soft text-neutral-ink' => $badge['tone'] === 'n',
                                            'bg-warning-soft text-warning-ink' => $badge['tone'] === 'w',
                                            'bg-accent-soft text-accent-soft-ink' => $badge['tone'] === 'p',
                                        ])>{{ $badge['text'] }}</span>
                                    @endforeach
                                </span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endif
        @endforeach
    @endif
</x-layout>
