@extends('layouts.guest')

@section('title', __('loans.signed_title'))

@section('content')
    @php $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
    <h1 class="font-display text-lg font-bold mb-1">{{ __('loans.signed_title') }}</h1>
    <p class="text-sm text-ink-muted mb-4 font-mono">{{ $loan->doc_no }}</p>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Only what the advisor needs to decide: who, what, when. No phone, student code or other personal detail. --}}
    <dl class="text-sm mb-5 space-y-2">
        <div class="flex justify-between gap-3">
            <dt class="text-ink-faint">{{ __('loans.field_borrower') }}</dt>
            <dd class="text-right">{{ $loan->borrower?->full_name }}</dd>
        </div>
        <div class="flex justify-between gap-3">
            <dt class="text-ink-faint">{{ __('loans.field_purpose') }}</dt>
            <dd class="text-right">{{ __('loans.purpose_'.strtolower($loan->purpose_type)) }}</dd>
        </div>
        <div class="flex justify-between gap-3">
            <dt class="text-ink-faint">{{ __('loans.field_loan_date') }}</dt>
            <dd class="text-right">{{ $loan->loan_date->format('d/m/Y') }}</dd>
        </div>
        <div class="flex justify-between gap-3">
            <dt class="text-ink-faint">{{ __('loans.field_due_date') }}</dt>
            <dd class="text-right">{{ $loan->due_date->format('d/m/Y') }}</dd>
        </div>
    </dl>

    <ul class="text-sm mb-5 list-disc list-inside space-y-0.5">
        @foreach ($loan->lines as $line)
            <li>{{ $line->asset?->item?->name_th }} × <span class="font-mono">{{ $fmt($line->qty) }}</span></li>
        @endforeach
    </ul>

    <form method="POST" action="{{ url()->full() }}" class="space-y-4">
        @csrf

        <div>
            <label class="flex items-center gap-2 text-sm mb-2">
                <input type="radio" name="decision" value="APPROVE" checked>
                {{ __('loans.decision_approve') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="radio" name="decision" value="REJECT">
                {{ __('loans.decision_reject') }}
            </label>
        </div>

        <div>
            <label class="block text-xs font-medium mb-1" for="reason">{{ __('loans.field_decision_reason') }}</label>
            <textarea name="reason" id="reason" rows="2" maxlength="500" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">{{ old('reason') }}</textarea>
        </div>

        <button type="submit" class="w-full rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold py-2.5">
            {{ __('loans.btn_decide') }}
        </button>
    </form>
@endsection
