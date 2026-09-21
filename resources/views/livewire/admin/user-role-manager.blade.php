<div>
    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('admin.users_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('admin.users_subtitle') }}</p>
    </div>

    <div class="flex flex-wrap gap-2 mb-5 border-b border-border pb-4">
        @foreach ($this->tabs as $key => $meta)
            <button type="button" wire:click="selectTab('{{ $key }}')"
                    class="px-3 py-1.5 rounded-lg text-sm font-semibold transition-colors {{ $tab === (string) $key ? 'bg-accent text-white' : 'bg-surface-alt text-ink-muted hover:text-ink' }}">
                {{ $meta['label'] }}
                <span class="ml-1 opacity-75">({{ $meta['count'] }})</span>
            </button>
        @endforeach
    </div>

    <div class="mb-4">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('admin.search_placeholder') }}"
               class="w-full max-w-sm rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
    </div>

    <div class="bg-surface border border-border rounded-xl overflow-hidden">
        <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">{{ __('admin.col_user') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('nav.users_roles') }}</th>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('admin.col_lab') }}</th>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('admin.col_status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($users as $user)
                        <tr wire:key="user-{{ $user->id }}">
                            <td class="px-4 py-3 align-top">
                                <div class="font-medium">{{ $user->full_name }}</div>
                                <div class="text-xs text-ink-muted">{{ $user->username }} · {{ $user->email }}</div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($this->roles as $role)
                                        @php $active = $user->roles->contains('id', $role->id); @endphp
                                        <button
                                            type="button"
                                            wire:click="toggleRole({{ $user->id }}, {{ $role->id }})"
                                            @class([
                                                'px-2.5 py-1 rounded-full text-xs font-semibold border transition-colors',
                                                'bg-accent-soft text-accent-soft-ink border-accent-soft' => $active,
                                                'bg-surface text-ink-faint border-border hover:border-ink-faint' => ! $active,
                                            ])
                                        >
                                            {{ $role->name_th }}
                                        </button>
                                    @endforeach

                                    @if ($user->roles->isEmpty())
                                        <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-warning-soft text-warning-ink">
                                            {{ __('admin.no_role_badge') }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 align-top whitespace-nowrap">
                                <select wire:change="setLab({{ $user->id }}, $event.target.value || null)"
                                        class="rounded-lg border border-border bg-surface px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-accent">
                                    <option value="" @selected($user->lab_id === null)>{{ __('admin.no_lab') }}</option>
                                    @foreach ($this->labs as $lab)
                                        <option value="{{ $lab->id }}" @selected($user->lab_id === $lab->id)>{{ $lab->name_th }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-4 py-3 align-top whitespace-nowrap">
                                <button
                                    type="button"
                                    wire:click="toggleActive({{ $user->id }})"
                                    @class([
                                        'px-2.5 py-1 rounded-full text-xs font-semibold',
                                        'bg-success-soft text-success-ink' => $user->is_active,
                                        'bg-danger-soft text-danger-ink' => ! $user->is_active,
                                    ])
                                >
                                    {{ $user->is_active ? __('admin.active') : __('admin.inactive') }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-ink-muted text-sm">
                                {{ __('admin.no_results') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
</div>
