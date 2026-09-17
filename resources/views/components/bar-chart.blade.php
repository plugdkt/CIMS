@props(['bars' => [], 'label' => '', 'suffix' => ''])

@php
    $maxValue = max(1, collect($bars)->max('value') ?? 0);
    $count = max(1, count($bars));
    $barWidth = max(14, min(40, (int) floor(320 / $count) - 8));
@endphp

@if (empty($bars))
    <p class="text-xs text-ink-faint">{{ __('reports.chart_no_data') }}</p>
@else
    <svg viewBox="0 0 360 150" class="w-full" role="img" aria-label="{{ $label }}">
        @foreach ($bars as $i => $bar)
            @php
                $x = $i * ($barWidth + 8) + 8;
                $barHeight = (int) round(($bar['value'] / $maxValue) * 90);
                $y = 110 - $barHeight;
                $displayValue = rtrim(rtrim((string) $bar['value'], '0'), '.').$suffix;
            @endphp
            <g>
                <title>{{ $bar['label'] }}: {{ $displayValue }}</title>
                <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barWidth }}" height="{{ max(1, $barHeight) }}" rx="2" fill="#6d28d9"></rect>
                <text x="{{ $x + $barWidth / 2 }}" y="{{ max(10, $y - 3) }}" text-anchor="middle" font-size="8" fill="currentColor">{{ $displayValue }}</text>
                <text x="{{ $x + $barWidth / 2 }}" y="128" text-anchor="middle" font-size="7" fill="currentColor" class="text-ink-faint">{{ \Illuminate\Support\Str::limit($bar['label'], 10, '…') }}</text>
            </g>
        @endforeach
    </svg>
@endif
