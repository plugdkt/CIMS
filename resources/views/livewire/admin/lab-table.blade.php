<div>
    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h1 class="font-display text-lg font-bold">{{ __('labs.index_title') }}</h1>
            <p class="text-sm text-ink-muted mt-1">{{ __('labs.index_subtitle') }}</p>
        </div>
        <a href="{{ route('admin.labs.create') }}" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5 whitespace-nowrap">
            {{ __('labs.new_lab') }}
        </a>
    </div>

    <div class="bg-surface border border-border rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                    <tr>
                        <th class="px-4 py-3 font-semibold">{{ __('labs.col_code') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('labs.col_name') }}</th>
                        <th class="px-4 py-3 font-semibold">{{ __('labs.col_faculty') }}</th>
                        <th class="px-4 py-3 font-semibold whitespace-nowrap">{{ __('labs.col_status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($labs as $lab)
                        <tr wire:key="lab-{{ $lab->id }}">
                            <td class="px-4 py-3 align-top font-mono text-xs text-ink-muted">{{ $lab->code }}</td>
                            <td class="px-4 py-3 align-top font-medium">{{ $lab->name_th }}</td>
                            <td class="px-4 py-3 align-top text-ink-muted">{{ $lab->faculty }}</td>
                            <td class="px-4 py-3 align-top whitespace-nowrap">
                                <span class="px-2.5 py-1 rounded-full text-xs font-semibold {{ $lab->is_active ? 'bg-success-soft text-success-ink' : 'bg-danger-soft text-danger-ink' }}">
                                    {{ $lab->is_active ? __('labs.active') : __('labs.inactive') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 align-top text-right">
                                <a href="{{ route('admin.labs.edit', $lab) }}" class="text-xs font-semibold text-accent hover:text-accent-strong">
                                    {{ __('labs.edit') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-ink-muted text-sm">
                                {{ __('labs.no_results') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
