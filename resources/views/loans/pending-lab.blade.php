<x-layout>
    <div class="max-w-xl">
        <h1 class="font-display text-lg font-bold mb-2">{{ __('loans.pending_lab_title') }}</h1>
        <p class="text-sm text-ink-muted leading-relaxed mb-4">{{ __('loans.pending_lab_body') }}</p>

        <div class="rounded-lg border border-border bg-surface-alt px-4 py-3 text-sm mb-4">
            {{ __('loans.pending_lab_how') }}
        </div>

        <a href="{{ route('account.complete-profile') }}" class="inline-block rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">{{ __('loans.pending_lab_action') }}</a>
    </div>
</x-layout>
