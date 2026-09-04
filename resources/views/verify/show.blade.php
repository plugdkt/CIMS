@extends('layouts.guest')

@section('title', __('requisitions.verify_page_title'))

@section('content')
    <div class="text-center">
        <h1 class="font-display text-lg font-bold mb-4">{{ __('requisitions.verify_page_title') }}</h1>
        <dl class="text-sm space-y-3 text-left">
            <div class="flex justify-between">
                <dt class="text-ink-faint">{{ __('requisitions.col_doc_no') }}</dt>
                <dd class="font-mono">{{ $docNo }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-ink-faint">{{ __('requisitions.col_doc_date') }}</dt>
                <dd>{{ $docDate->format('d/m/Y') }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-ink-faint">{{ __('requisitions.col_status') }}</dt>
                <dd>{{ __('requisitions.status_'.strtolower($status)) }}</dd>
            </div>
        </dl>
        <p class="text-xs text-ink-muted mt-6">{{ __('requisitions.verify_page_hint') }}</p>
    </div>
@endsection
