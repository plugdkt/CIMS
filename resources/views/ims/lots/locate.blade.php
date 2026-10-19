<x-layout>
    @include('ims._tabs')
    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('ims.locate_title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('ims.locate_subtitle') }}</p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg bg-success-soft text-success-ink text-sm px-4 py-3">{{ session('status') }}</div>
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

    <form method="GET" action="{{ route('ims.lots.locate') }}" class="flex flex-wrap items-center gap-3 mb-4">
        <label for="search" class="sr-only">{{ __('ims.search_placeholder') }}</label>
        <input id="search" name="search" type="search" value="{{ $search }}" placeholder="{{ __('ims.search_placeholder') }}"
               class="w-72 rounded-lg border border-border px-3 py-2 text-sm">
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="show_all" value="1" @checked($showAll)>
            {{ __('ims.locate_show_all') }}
        </label>
        <button type="submit" class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-4 py-2">{{ __('ims.locate_filter') }}</button>
    </form>

    <form method="POST" action="{{ route('ims.lots.locate.store') }}"
          x-data="{ all: false, toggle(e) { this.$root.querySelectorAll('input[name=\'lots[]\']').forEach(c => c.checked = e.target.checked) } }">
        @csrf
        <div class="bg-surface border-2 border-accent rounded-xl p-5 mb-4 flex flex-wrap items-end gap-3">
            <div class="min-w-64">
                <label for="location_id" class="block text-sm font-semibold mb-1">1. {{ __('ims.locate_step_place') }}</label>
                <select id="location_id" name="location_id" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <option value="">{{ __('items.select_placeholder') }}</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected((int) old('location_id') === $location->id)>{{ $location->code }} — {{ $location->name }}</option>
                    @endforeach
                </select>
            </div>
            <p class="text-sm font-semibold pb-2">2. {{ __('ims.locate_step_lots') }}</p>
            <button type="submit" class="ml-auto rounded-lg bg-success hover:bg-success-ink text-white text-base font-bold px-6 py-2.5 shadow-md">
                3. {{ __('ims.locate_save') }}
            </button>
        </div>

        <div class="bg-surface border border-border rounded-xl overflow-hidden">
            <div class="overflow-x-auto" tabindex="0">
                <table class="w-full text-sm">
                    <thead class="bg-surface-alt text-left text-xs uppercase tracking-wide text-ink-faint">
                        <tr>
                            <th class="px-4 py-3 w-10">
                                <input type="checkbox" @change="toggle" aria-label="{{ __('ims.locate_select_all') }}">
                            </th>
                            <th class="px-4 py-3 font-semibold">{{ __('ims.col_item_code') }}</th>
                            <th class="px-4 py-3 font-semibold">{{ __('ims.col_item') }}</th>
                            <th class="px-4 py-3 font-semibold">Lot</th>
                            <th class="px-4 py-3 font-semibold">{{ __('ims.col_location') }}</th>
                            <th class="px-4 py-3 font-semibold text-right">{{ __('ims.col_remaining') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($lots as $lot)
                            <tr>
                                <td class="px-4 py-3">
                                    <input type="checkbox" name="lots[]" value="{{ $lot->id }}" id="lot-{{ $lot->id }}" @checked(in_array($lot->id, array_map('intval', (array) old('lots', [])), true))
                                           aria-label="{{ $lot->item?->name_th }}">
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $lot->item?->item_code }}</td>
                                <td class="px-4 py-3"><label for="lot-{{ $lot->id }}" class="cursor-pointer">{{ $lot->item?->name_th }}</label></td>
                                <td class="px-4 py-3">{{ $lot->lot_no ?: '—' }}</td>
                                <td class="px-4 py-3">{{ $lot->location?->name ?: '—' }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ rtrim(rtrim((string) $lot->qty_remaining_base, '0'), '.') ?: '0' }} {{ $lot->item?->baseUnit?->code }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-ink-muted">{{ __('ims.locate_none') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </form>
</x-layout>
