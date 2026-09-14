@extends('layouts.guest')

@section('title', __('auth.pending_lab_title'))

@section('content')
    <div class="text-center">
        <div class="mx-auto w-12 h-12 rounded-full bg-gold-soft text-gold-ink flex items-center justify-center mb-4">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path d="M12 3.5 19 6v6c0 4.2-2.9 7.5-7 8.5-4.1-1-7-4.3-7-8.5V6Z"/>
                <path d="M12 8.5v4.5M12 15.8v.2"/>
            </svg>
        </div>

        <h1 class="font-display text-lg font-bold mb-2">{{ __('auth.pending_lab_title') }}</h1>
        <p class="text-sm text-ink-muted leading-relaxed mb-4">{{ __('auth.pending_lab_body') }}</p>

        <div class="text-xs text-ink-faint border-t border-border pt-4 mt-4">
            {{ __('auth.pending_role_signed_in_as') }}
            <span class="font-medium text-ink">{{ auth()->user()?->username }}</span>
        </div>

        <form method="GET" action="{{ route('logout') }}" class="mt-4">
            <button type="submit" class="text-sm font-semibold text-accent hover:text-accent-strong">
                {{ __('auth.logout') }}
            </button>
        </form>
    </div>
@endsection
