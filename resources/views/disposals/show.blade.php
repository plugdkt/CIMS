<x-layout>
    <div class="max-w-2xl">
        <div class="mb-5">
            <a href="{{ route('disposals.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('disposals.back_to_list') }}</a>
            <div class="flex items-center justify-between mt-1">
                <h1 class="font-display text-lg font-bold">{{ $disposal->doc_no }}</h1>
                @php
                    $badge = match ($disposal->status) {
                        'APPROVED' => 'bg-success-soft text-success-ink',
                        'REJECTED' => 'bg-danger-soft text-danger-ink',
                        default => 'bg-neutral-soft text-neutral-ink',
                    };
                @endphp
                <span class="px-2.5 py-1 rounded-full text-xs font-semibold whitespace-nowrap {{ $badge }}">
                    {{ __('disposals.status_'.strtolower($disposal->status)) }}
                </span>
            </div>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded-lg bg-success-soft text-success-ink text-sm px-4 py-3">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="bg-surface border border-border rounded-xl p-6">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('disposals.field_item') }}</dt>
                    <dd>{{ $disposal->container?->item?->name_th }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('disposals.field_barcode') }}</dt>
                    <dd class="font-mono">{{ $disposal->container?->barcode }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('disposals.field_qty') }}</dt>
                    <dd>{{ $disposal->qty_base }} ({{ $disposal->container?->item?->baseUnit?->code }})</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('disposals.field_reason') }}</dt>
                    <dd>{{ __('disposals.reason_'.strtolower($disposal->reason)) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('disposals.field_method') }}</dt>
                    <dd>{{ $disposal->method ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('disposals.field_disposal_date') }}</dt>
                    <dd>{{ $disposal->disposal_date->format('d/m/Y') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('disposals.field_requested_by') }}</dt>
                    <dd>{{ $disposal->requestedBy?->full_name }}</dd>
                </div>
                @if ($disposal->approved_by !== null)
                    <div>
                        <dt class="text-xs text-ink-faint">{{ __('disposals.field_approved_by') }}</dt>
                        <dd>{{ $disposal->approvedBy?->full_name }} ({{ $disposal->approved_at?->format('d/m/Y H:i') }})</dd>
                    </div>
                @endif
            </dl>
        </div>

        @if ($canDecide)
            <div class="flex items-center gap-3 mt-6">
                <form method="POST" action="{{ route('disposals.approve', $disposal) }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">
                        {{ __('disposals.approve') }}
                    </button>
                </form>
                <form method="POST" action="{{ route('disposals.reject', $disposal) }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-5 py-2.5">
                        {{ __('disposals.reject') }}
                    </button>
                </form>
            </div>
        @endif
    </div>
</x-layout>
