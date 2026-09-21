<div>
    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('labs.members_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('labs.members_subtitle', ['lab' => $manager->lab?->name_th]) }}</p>
    </div>

    @if ($manager->lab_id === null)
        <div class="rounded-lg bg-warning-soft text-warning-ink text-sm px-4 py-3">
            {{ __('labs.members_no_own_lab') }}
        </div>
    @else
        <div class="mb-4">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('labs.members_search_placeholder') }}"
                   class="w-full max-w-sm rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
        </div>

        <div class="bg-surface border border-border rounded-xl overflow-hidden">
            <div class="overflow-x-auto" tabindex="0">
                <table class="w-full text-sm">
                    <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="px-4 py-3 font-semibold">{{ __('labs.col_member') }}</th>
                            <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('labs.col_membership') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($candidates as $candidate)
                            @php $isMember = $candidate->lab_id === $manager->lab_id; @endphp
                            <tr wire:key="candidate-{{ $candidate->id }}">
                                <td class="px-4 py-3 align-top">
                                    <div class="font-medium">{{ $candidate->full_name }}</div>
                                    <div class="text-xs text-ink-muted">{{ $candidate->username }} · {{ $candidate->email }}</div>
                                    <div class="text-xs text-ink-faint mt-0.5">{{ $candidate->roles->pluck('name_th')->implode(', ') }}</div>
                                </td>
                                <td class="px-4 py-3 align-top whitespace-nowrap">
                                    @if ($isMember)
                                        <button type="button" wire:click="remove({{ $candidate->id }})"
                                                class="px-2.5 py-1 rounded-full text-xs font-semibold bg-success-soft text-success-ink">
                                            {{ __('labs.members_member_badge') }}
                                        </button>
                                    @else
                                        <button type="button" wire:click="assign({{ $candidate->id }})"
                                                class="px-2.5 py-1 rounded-full text-xs font-semibold bg-surface text-ink-faint border border-border hover:border-ink-faint">
                                            {{ __('labs.members_add_badge') }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="2" class="px-4 py-8 text-center text-ink-muted text-sm">
                                    {{ __('labs.members_no_results') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">
            {{ $candidates->links() }}
        </div>
    @endif
</div>
