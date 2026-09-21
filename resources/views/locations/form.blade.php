<x-layout>
    <div class="max-w-2xl">
        <div class="mb-5">
            <a href="{{ route('locations.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('locations.back_to_list') }}</a>
            <h1 class="font-display text-lg font-bold mt-1">
                {{ $location->exists ? __('locations.edit_title') : __('locations.create_title') }}
            </h1>
        </div>

        @if (! empty(session('conflicts')))
            <div class="mb-4 rounded-lg bg-warning-soft text-warning-ink text-sm px-4 py-3">
                {{ __('locations.conflict_warning', ['classes' => implode(', ', session('conflicts'))]) }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($lab === null)
            <div class="rounded-lg bg-warning-soft text-warning-ink text-sm px-4 py-3">
                {{ __('locations.no_own_lab') }}
            </div>
        @else
        <form method="POST" action="{{ $location->exists ? route('locations.update', $location) : route('locations.store') }}" class="bg-surface border border-border rounded-xl p-6 space-y-4">
            @csrf
            @if ($location->exists) @method('PUT') @endif
            <input type="hidden" name="lab_id" value="{{ $lab->id }}">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1" for="code">{{ __('locations.field_code') }}</label>
                    <input type="text" name="code" id="code" value="{{ old('code', $location->code) }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="name">{{ __('locations.field_name') }}</label>
                    <input type="text" name="name" id="name" value="{{ old('name', $location->name) }}"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1" for="level_type">{{ __('locations.field_level_type') }}</label>
                    <select name="level_type" id="level_type" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                        <option value="">{{ __('items.select_placeholder') }}</option>
                        @foreach (['BUILDING', 'ROOM', 'CABINET', 'SHELF'] as $level)
                            <option value="{{ $level }}" @selected(old('level_type', $location->level_type) === $level)>
                                {{ __('locations.level_'.strtolower($level)) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1" for="parent_id">{{ __('locations.field_parent') }}</label>
                    <select name="parent_id" id="parent_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                        <option value="">{{ __('locations.no_parent') }}</option>
                        @foreach ($parents as $parent)
                            <option value="{{ $parent->id }}" @selected((int) old('parent_id', $location->parent_id) === $parent->id)>
                                {{ $parent->name }} ({{ __('locations.level_'.strtolower($parent->level_type)) }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1" for="storage_class">{{ __('locations.field_storage_class') }}</label>
                    <input type="text" name="storage_class" id="storage_class" value="{{ old('storage_class', $location->storage_class) }}"
                           placeholder="ACID / BASE / FLAMMABLE / OXIDIZER / TOXIC / FOOD_GRADE / GENERAL"
                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                </div>
                <div>
                    <span class="block text-sm font-medium mb-1">{{ __('locations.field_lab') }}</span>
                    <p class="w-full rounded-lg border border-border bg-surface-alt px-3 py-2 text-sm text-ink-muted">{{ $lab->name_th }}</p>
                </div>
            </div>

            <div class="pt-2">
                <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">
                    {{ __('locations.save') }}
                </button>
            </div>
        </form>
        @endif
    </div>
</x-layout>
