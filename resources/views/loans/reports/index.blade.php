<x-layout>
    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('loans.report.title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __($isAdmin ? 'loans.report.subtitle_admin' : 'loans.report.subtitle') }}</p>
    </div>

    @unless ($hasLab)
        <div class="mb-5 rounded-lg border border-warning/40 bg-warning-soft text-warning-ink px-4 py-3 text-sm">{{ __('loans.report.no_lab') }}</div>
    @endunless

    @if ($isAdmin)
        <form method="GET" action="{{ route('loans.reports.index') }}" class="flex flex-wrap items-end gap-3 mb-5">
            <div>
                <label for="lab_id" class="block text-xs text-ink-faint mb-0.5">{{ __('loans.col_lab') }}</label>
                <select id="lab_id" name="lab_id" class="rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                    <option value="">{{ __('loans.filter_all_labs') }}</option>
                    @foreach ($labs as $lab)
                        <option value="{{ $lab->id }}" @selected((string) request('lab_id') === (string) $lab->id)>{{ $lab->code }} {{ $lab->name_th }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="rounded-lg border border-border text-sm font-semibold px-4 py-2 hover:bg-surface-alt">{{ __('loans.report.btn_show') }}</button>
        </form>
    @endif

    {{-- Dashboard: counts only (no person, no value). --}}
    <div class="grid gap-3 grid-cols-2 lg:grid-cols-4 mb-6">
        @foreach (['pending', 'out', 'overdue', 'incidents'] as $card)
            <div class="bg-surface border border-border rounded-xl px-4 py-4">
                <div class="text-xs text-ink-faint">{{ __('loans.report.card_'.$card) }}</div>
                <div class="mt-1 font-display text-2xl font-bold {{ in_array($card, ['overdue', 'incidents'], true) && $summary[$card] > 0 ? 'text-warning-ink' : '' }}">{{ number_format($summary[$card]) }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-5 lg:grid-cols-2 mb-6">
        <section class="bg-surface border border-border rounded-xl px-4 py-4">
            <h2 class="text-sm font-semibold mb-3">{{ __('loans.report.top_title') }}</h2>
            @if ($summary['top'] === [])
                <p class="text-sm text-ink-muted">{{ __('loans.empty') }}</p>
            @else
                <div class="overflow-x-auto" tabindex="0">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-ink-faint">
                            <tr>
                                <th class="py-2 pr-3 font-semibold">{{ __('loans.report.col_item') }}</th>
                                <th class="py-2 pr-3 font-semibold text-right">{{ __('loans.report.col_docs') }}</th>
                                <th class="py-2 font-semibold text-right">{{ __('loans.report.col_pieces') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($summary['top'] as $row)
                                <tr>
                                    <td class="py-2 pr-3"><span class="font-mono text-xs text-ink-faint">{{ $row['item_code'] }}</span> {{ $row['name'] }}</td>
                                    <td class="py-2 pr-3 text-right">{{ number_format($row['loans']) }}</td>
                                    <td class="py-2 text-right">{{ $row['qty'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="bg-surface border border-border rounded-xl px-4 py-4">
            <h2 class="text-sm font-semibold mb-3">{{ __('loans.report.monthly_title') }}</h2>
            {{-- A table with a proportional bar per month: readable without colour and by a screen reader. --}}
            <table class="w-full text-sm">
                <tbody>
                    @foreach ($summary['monthly'] as $month)
                        <tr>
                            <th scope="row" class="py-1 pr-3 text-left font-mono text-xs font-normal text-ink-faint whitespace-nowrap">{{ $month['month'] }}</th>
                            <td class="py-1 w-full">
                                <progress class="w-full h-2 align-middle" max="{{ max(1, $summary['monthly_max']) }}" value="{{ $month['count'] }}">{{ $month['count'] }}</progress>
                            </td>
                            <td class="py-1 pl-3 text-right tabular-nums">{{ number_format($month['count']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    </div>

    <section class="bg-surface border border-border rounded-xl overflow-hidden">
        <h2 class="text-sm font-semibold px-4 pt-4">{{ __('loans.report.list_title') }}</h2>
        <p class="text-xs text-ink-muted px-4 pb-3 mt-1">{{ __('loans.report.export_note') }}</p>
        <ul class="divide-y divide-border border-t border-border">
            @foreach ($reports as $key)
                <li class="px-4 py-3">
                    <a href="{{ route('loans.reports.show', array_filter(['report' => $key, 'lab_id' => $isAdmin ? request('lab_id') : null])) }}" class="font-semibold text-accent hover:text-accent-strong">{{ __('loans.report.name_'.$key) }}</a>
                    <p class="text-xs text-ink-muted mt-0.5">{{ __('loans.report.desc_'.$key) }}</p>
                </li>
            @endforeach
        </ul>
    </section>
</x-layout>
