@extends('layouts.guest')

@section('title', __('privacy.notice_title'))

@section('content')
    <h1 class="font-display text-lg font-bold mb-1">{{ __('privacy.notice_title') }}</h1>
    <p class="text-xs text-warning-ink bg-warning-soft rounded-lg px-3 py-2 mb-4">{{ __('privacy.notice_disclaimer') }}</p>

    <div class="text-sm text-ink leading-relaxed space-y-4 max-h-96 overflow-y-auto pr-1" tabindex="0">
        <div>
            <h2 class="font-semibold text-sm mb-1">{{ __('privacy.section_controller_title') }}</h2>
            <p class="text-ink-muted">{{ __('privacy.section_controller_body') }}</p>
        </div>
        <div>
            <h2 class="font-semibold text-sm mb-1">{{ __('privacy.section_data_title') }}</h2>
            <p class="text-ink-muted">{{ __('privacy.section_data_body') }}</p>
        </div>
        <div>
            <h2 class="font-semibold text-sm mb-1">{{ __('privacy.section_purpose_title') }}</h2>
            <p class="text-ink-muted">{{ __('privacy.section_purpose_body') }}</p>
        </div>
        <div>
            <h2 class="font-semibold text-sm mb-1">{{ __('privacy.section_retention_title') }}</h2>
            <p class="text-ink-muted">{{ __('privacy.section_retention_body') }}</p>
        </div>
        <div>
            <h2 class="font-semibold text-sm mb-1">{{ __('privacy.section_rights_title') }}</h2>
            <p class="text-ink-muted">{{ __('privacy.section_rights_body') }}</p>
        </div>
    </div>

    @if ($alreadyConsented)
        <p class="text-xs text-success-ink bg-success-soft rounded-lg px-3 py-2 mt-4">
            {{ __('privacy.already_consented', ['date' => $user->privacy_consent_at?->format('d/m/Y H:i')]) }}
        </p>
        <a href="{{ url('/') }}" class="block text-center mt-4 rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">
            {{ __('auth.app_name') }}
        </a>
    @else
        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('privacy-notice.accept') }}" class="mt-4 space-y-4">
            @csrf
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="agree" value="1" class="mt-1">
                <span>{{ __('privacy.consent_checkbox') }}</span>
            </label>
            <button type="submit" class="w-full rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">
                {{ __('privacy.accept_button') }}
            </button>
        </form>
    @endif

    <div class="text-xs text-ink-faint border-t border-border pt-4 mt-4 text-center">
        <span>{{ auth()->user()?->username }}</span>
        <form method="GET" action="{{ route('logout') }}" class="inline">
            <button type="submit" class="ml-2 font-semibold text-accent hover:text-accent-strong">
                {{ __('auth.logout') }}
            </button>
        </form>
    </div>
@endsection
