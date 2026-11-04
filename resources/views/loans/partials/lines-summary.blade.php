@php $fmtQty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
<ul class="space-y-0.5">
    @foreach ($loan->lines as $line)
        <li>
            {{ $line->asset?->item?->name_th }}
            <span class="font-mono text-ink-faint">× {{ $fmtQty($line->qty_approved ?? $line->qty) }}</span>
        </li>
    @endforeach
</ul>
