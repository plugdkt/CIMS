@extends('layouts.guest')

@section('title', __('loans.signed_title'))

@section('content')
    <div class="text-center">
        <h1 class="font-display text-lg font-bold mb-2">{{ __('loans.signed_title') }}</h1>
        <p class="text-sm {{ $failed ? 'text-danger-ink' : 'text-success-ink' }}">{{ $status }}</p>
    </div>
@endsection
