<x-layout>
    <div class="max-w-2xl">
        <div class="mb-5">
            <a href="{{ route('requisitions.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('requisitions.back_to_list') }}</a>
            <h1 class="font-display text-lg font-bold mt-1">{{ __('requisitions.create_title') }}</h1>
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

        {{-- FR-RQ-01: requester identity is auto-filled from profile, shown read-only here — edited only via the complete-profile page. --}}
        <div class="bg-surface border border-border rounded-xl p-6 mb-6">
            <h2 class="font-display text-base font-bold mb-3">{{ __('requisitions.requester_info_title') }}</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('requisitions.field_requester_name') }}</dt>
                    <dd>{{ auth()->user()->full_name }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('requisitions.field_requester_status') }}</dt>
                    <dd>{{ __('requisitions.person_type_'.strtolower(auth()->user()->person_type)) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('requisitions.field_requester_phone') }}</dt>
                    <dd>{{ auth()->user()->phone_encrypted ?: '—' }}</dd>
                </div>
                @if (auth()->user()->person_type === 'STUDENT')
                    <div>
                        <dt class="text-xs text-ink-faint">{{ __('requisitions.field_student_code') }}</dt>
                        <dd>{{ auth()->user()->person_code_encrypted ?: '—' }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('requisitions.field_program') }}</dt>
                    <dd>{{ auth()->user()->program ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-faint">{{ __('requisitions.field_faculty') }}</dt>
                    <dd>{{ auth()->user()->faculty ?: '—' }}</dd>
                </div>
            </dl>
        </div>

        <form method="POST" action="{{ route('requisitions.store') }}" class="bg-surface border border-border rounded-xl p-6 space-y-4">
            @csrf

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('requisitions.field_lab') }}</label>
                <select name="lab_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                    <option value="">{{ __('items.select_placeholder') }}</option>
                    @foreach ($labs as $lab)
                        <option value="{{ $lab->id }}" @selected((int) old('lab_id') === $lab->id)>{{ $lab->name_th }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('requisitions.field_request_type') }}</label>
                <div class="flex gap-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="request_type[]" value="CHEMICAL" @checked(in_array('CHEMICAL', old('request_type', [])))>
                        {{ __('requisitions.request_type_chemical') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="request_type[]" value="CONSUMABLE" @checked(in_array('CONSUMABLE', old('request_type', [])))>
                        {{ __('requisitions.request_type_consumable') }}
                    </label>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('requisitions.field_purpose_type') }}</label>
                <select name="purpose_type" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                    <option value="">{{ __('items.select_placeholder') }}</option>
                    <option value="TEACHING" @selected(old('purpose_type') === 'TEACHING')>{{ __('requisitions.purpose_type_teaching') }}</option>
                    <option value="RESEARCH" @selected(old('purpose_type') === 'RESEARCH')>{{ __('requisitions.purpose_type_research') }}</option>
                    <option value="OTHER" @selected(old('purpose_type') === 'OTHER')>{{ __('requisitions.purpose_type_other') }}</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('requisitions.field_purpose_detail') }}</label>
                <input type="text" name="purpose_detail" value="{{ old('purpose_detail') }}"
                       placeholder="{{ __('requisitions.field_purpose_detail_hint') }}"
                       class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
            </div>

            <div class="pt-2">
                <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">
                    {{ __('requisitions.save') }}
                </button>
            </div>
        </form>
    </div>
</x-layout>
