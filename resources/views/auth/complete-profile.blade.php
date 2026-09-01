@extends('layouts.guest')

@section('title', __('auth.complete_profile_title'))

@section('content')
    <h1 class="font-display text-lg font-bold mb-1">{{ __('auth.complete_profile_title') }}</h1>
    <p class="text-sm text-ink-muted mb-5">{{ __('auth.complete_profile_body') }}</p>

    <form method="POST" action="{{ route('account.complete-profile') }}" class="space-y-4">
        @csrf

        <div>
            <label for="person_type" class="block text-sm font-medium mb-1">{{ __('auth.field_person_type') }}</label>
            <select id="person_type" name="person_type"
                    class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                <option value="">—</option>
                <option value="LECTURER" @selected(old('person_type') === 'LECTURER')>{{ __('auth.field_person_type_lecturer') }}</option>
                <option value="STAFF" @selected(old('person_type') === 'STAFF')>{{ __('auth.field_person_type_staff') }}</option>
                <option value="STUDENT" @selected(old('person_type') === 'STUDENT')>{{ __('auth.field_person_type_student') }}</option>
            </select>
            @error('person_type') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="phone" class="block text-sm font-medium mb-1">{{ __('auth.field_phone') }}</label>
            <input type="text" id="phone" name="phone" value="{{ old('phone') }}"
                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
            @error('phone') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="person_code" class="block text-sm font-medium mb-1">{{ __('auth.field_person_code') }}</label>
            <input type="text" id="person_code" name="person_code" value="{{ old('person_code') }}"
                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
            @error('person_code') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="program" class="block text-sm font-medium mb-1">{{ __('auth.field_program') }}</label>
            <input type="text" id="program" name="program" value="{{ old('program') }}"
                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
            @error('program') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="faculty" class="block text-sm font-medium mb-1">{{ __('auth.field_faculty') }}</label>
            <input type="text" id="faculty" name="faculty" value="{{ old('faculty') }}"
                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
            @error('faculty') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>

        <div id="advisor-field" class="hidden">
            <label for="advisor_id" class="block text-sm font-medium mb-1">{{ __('auth.field_advisor') }}</label>
            <select id="advisor_id" name="advisor_id"
                    class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                <option value="">—</option>
                @foreach ($advisors as $advisor)
                    <option value="{{ $advisor->id }}" @selected((string) old('advisor_id') === (string) $advisor->id)>
                        {{ $advisor->full_name }}
                    </option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-ink-faint">{{ __('auth.field_advisor_help') }}</p>
            @error('advisor_id') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="w-full rounded-lg bg-accent hover:bg-accent-strong text-white font-semibold text-sm py-2.5">
            {{ __('auth.save') }}
        </button>
    </form>

    {{-- Plain vanilla JS (nonce'd, no Alpine) — the CSP forbids unsafe-eval, which Alpine's
         expression evaluator needs. See CLAUDE.md. --}}
    <script nonce="{{ request()->attributes->get('csp_nonce') }}">
        (function () {
            var personType = document.getElementById('person_type');
            var advisorField = document.getElementById('advisor-field');

            function sync() {
                advisorField.classList.toggle('hidden', personType.value !== 'STUDENT');
            }

            personType.addEventListener('change', sync);
            sync();
        })();
    </script>
@endsection
