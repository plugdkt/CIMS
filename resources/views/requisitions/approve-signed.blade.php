@extends('layouts.guest')

@section('title', __('requisitions.approve_page_title'))

@section('content')
    <h1 class="font-display text-lg font-bold mb-1">{{ __('requisitions.approve_page_title') }}</h1>
    <p class="text-sm text-ink-muted mb-4">{{ $requisition->doc_no }}</p>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <dl class="text-sm mb-5 space-y-2">
        <div class="flex justify-between">
            <dt class="text-ink-faint">{{ __('requisitions.field_requester_name') }}</dt>
            <dd>{{ $requisition->requester?->full_name }}</dd>
        </div>
        <div class="flex justify-between">
            <dt class="text-ink-faint">{{ __('requisitions.field_doc_date') }}</dt>
            <dd>{{ $requisition->doc_date->format('d/m/Y') }}</dd>
        </div>
        <div class="flex justify-between">
            <dt class="text-ink-faint">{{ __('requisitions.field_purpose_type') }}</dt>
            <dd>{{ __('requisitions.purpose_type_'.strtolower($requisition->purpose_type)) }}</dd>
        </div>
    </dl>

    <form method="POST" action="{{ url()->full() }}" class="space-y-4">
        @csrf

        <div>
            <label class="flex items-center gap-2 text-sm mb-2">
                <input type="radio" name="decision" value="APPROVE" checked>
                {{ __('requisitions.approve_decision') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="radio" name="decision" value="REJECT">
                {{ __('requisitions.reject_decision') }}
            </label>
        </div>

        <div>
            <label class="block text-xs font-medium mb-1" for="reason">{{ __('requisitions.field_reject_reason') }}</label>
            <textarea name="reason" id="reason" rows="2" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">{{ old('reason') }}</textarea>
        </div>

        <button type="submit" class="w-full rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold py-2.5">
            {{ __('requisitions.submit_decision') }}
        </button>
    </form>
@endsection
