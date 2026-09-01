@props(['code', 'size' => 40])

{{--
    Simplified, hand-drawn GHS pictograms (own SVG, not the official UN/CLP artwork) —
    the recognizable red-bordered diamond with a plain black glyph for each hazard
    class. User-approved 2026-09-01: own SVG rather than sourcing official pictogram
    image files.
--}}
<svg viewBox="0 0 100 100" width="{{ $size }}" height="{{ $size }}" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="{{ config("ghs.pictograms.$code", $code) }}">
    <rect x="50" y="4" width="65" height="65" rx="6" fill="white" stroke="#d90429" stroke-width="7"
          transform="rotate(45 50 50)" />

    @switch($code)
        @case('GHS01')
            {{-- Explosive: burst / radiating lines from a center --}}
            <g stroke="black" stroke-width="4" stroke-linecap="round">
                <line x1="50" y1="50" x2="50" y2="22" />
                <line x1="50" y1="50" x2="72" y2="34" />
                <line x1="50" y1="50" x2="78" y2="50" />
                <line x1="50" y1="50" x2="72" y2="66" />
                <line x1="50" y1="50" x2="50" y2="78" />
                <line x1="50" y1="50" x2="28" y2="66" />
                <line x1="50" y1="50" x2="22" y2="50" />
                <line x1="50" y1="50" x2="28" y2="34" />
            </g>
            <circle cx="50" cy="50" r="6" fill="black" />
            @break

        @case('GHS02')
            {{-- Flammable: simple flame --}}
            <path d="M50 22 C40 38 34 46 34 58 a16 16 0 0 0 32 0 c0 -7 -4 -12 -8 -16 c1 6 -2 9 -5 9 c-4 0 -6 -4 -4 -10 c1 -4 3 -7 1 -13 z"
                  fill="black" />
            @break

        @case('GHS03')
            {{-- Oxidizing: flame over a circle --}}
            <circle cx="50" cy="66" r="12" fill="black" />
            <path d="M50 20 C43 32 39 38 39 46 a11 11 0 0 0 22 0 c0 -5 -3 -8 -5 -11 c0.5 4 -1.5 6 -3.5 6 c-3 0 -4 -3 -3 -7 c0.7 -3 2 -5 0.5 -8 z"
                  fill="black" />
            @break

        @case('GHS04')
            {{-- Compressed gas: cylinder --}}
            <rect x="41" y="30" width="18" height="42" rx="4" fill="none" stroke="black" stroke-width="4" />
            <rect x="45" y="20" width="10" height="10" fill="black" />
            @break

        @case('GHS05')
            {{-- Corrosive: liquid dripping onto a surface and a hand --}}
            <path d="M38 24 h8 l-1 14 a3 3 0 0 1 -6 0 z" fill="black" />
            <path d="M58 24 h8 l-1 14 a3 3 0 0 1 -6 0 z" fill="black" />
            <rect x="24" y="60" width="30" height="6" fill="black" />
            <path d="M24 66 l-4 12 h10 z" fill="black" />
            <path d="M60 46 c6 4 10 10 8 16 c-2 6 -9 8 -13 4 c6 0 8 -4 7 -8 c-1 -4 -4 -7 -2 -12 z" fill="black" />
            @break

        @case('GHS06')
            {{-- Toxic: skull and crossbones --}}
            <circle cx="50" cy="42" r="15" fill="black" />
            <circle cx="44" cy="40" r="3.5" fill="white" />
            <circle cx="56" cy="40" r="3.5" fill="white" />
            <path d="M45 50 l2 6 h6 l2 -6 z" fill="white" />
            <g stroke="black" stroke-width="4" stroke-linecap="round">
                <line x1="30" y1="66" x2="70" y2="80" />
                <line x1="70" y1="66" x2="30" y2="80" />
            </g>
            @break

        @case('GHS07')
            {{-- Harmful / irritant: exclamation mark --}}
            <rect x="46.5" y="24" width="7" height="30" rx="3" fill="black" />
            <circle cx="50" cy="64" r="5" fill="black" />
            @break

        @case('GHS08')
            {{-- Health hazard: person silhouette with a burst on the chest --}}
            <circle cx="50" cy="30" r="7" fill="black" />
            <path d="M38 66 c0 -14 6 -22 12 -22 s12 8 12 22 z" fill="black" />
            <g stroke="white" stroke-width="3" stroke-linecap="round">
                <line x1="50" y1="38" x2="50" y2="50" />
                <line x1="44" y1="44" x2="56" y2="44" />
            </g>
            @break

        @case('GHS09')
            {{-- Environmental hazard: tree and fish --}}
            <path d="M32 40 l6 -14 l6 14 z" fill="black" />
            <rect x="36" y="40" width="4" height="16" fill="black" />
            <path d="M56 58 c8 -8 18 -8 24 -2 c-6 2 -8 4 -8 6 c0 2 2 4 8 6 c-6 6 -16 6 -24 -2 c-3 1 -6 1 -8 0 l4 -4 l-4 -4 c2 -1 5 -1 8 0 z"
                  fill="black" />
        @break
    @endswitch
</svg>
