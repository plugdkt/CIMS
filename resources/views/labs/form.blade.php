<x-layout>
    <div class="max-w-xl">
        <div class="mb-5">
            <a href="{{ route('admin.labs.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('labs.back_to_list') }}</a>
            <h1 class="font-display text-lg font-bold mt-1">
                {{ $lab->exists ? __('labs.edit_title') : __('labs.create_title') }}
            </h1>
        </div>

        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3">
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $lab->exists ? route('admin.labs.update', $lab) : route('admin.labs.store') }}" class="bg-surface border border-border rounded-xl p-6 space-y-4">
            @csrf
            @if ($lab->exists) @method('PUT') @endif

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('labs.field_code') }}</label>
                <input type="text" name="code" value="{{ old('code', $lab->code) }}"
                       class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">{{ __('labs.field_name_th') }}</label>
                <input type="text" name="name_th" value="{{ old('name_th', $lab->name_th) }}"
                       class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">{{ __('labs.field_faculty') }}</label>
                <input type="text" name="faculty" value="{{ old('faculty', $lab->faculty) }}"
                       class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $lab->exists ? $lab->is_active : true)) class="rounded border-border">
                {{ __('labs.field_is_active') }}
            </label>

            <div class="pt-2">
                <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">
                    {{ __('labs.save') }}
                </button>
            </div>
        </form>
    </div>
</x-layout>
