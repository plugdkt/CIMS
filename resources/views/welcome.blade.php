@extends('layouts.guest')

@section('title', __('auth.app_name'))

@section('content')
    <div class="text-center">
        <h1 class="font-display text-lg font-bold mb-2">{{ __('auth.app_name') }}</h1>
        <p class="text-sm text-ink-muted mb-4">{{ __('auth.app_tagline') }}</p>
        <a href="{{ route('login') }}" class="block w-full rounded-lg bg-accent hover:bg-accent-strong text-white font-semibold text-sm py-2.5">
            {{ __('auth.login') }}
        </a>
    </div>
@endsection
