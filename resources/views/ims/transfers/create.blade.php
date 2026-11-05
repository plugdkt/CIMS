<x-layout>
    @include('ims._tabs')
    <div class="mb-5">
        <h1 class="font-display text-lg font-bold">{{ __('ims_transfer.title') }}</h1>
        <p class="text-sm text-ink-muted mt-1">{{ __('ims_transfer.subtitle') }}</p>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3" role="alert">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($truncated)
        <div class="mb-4 rounded-lg border border-warning/40 bg-warning-soft text-warning-ink text-sm px-4 py-3">{{ __('ims_transfer.truncated') }}</div>
    @endif

    <form method="POST" action="{{ route('ims.transfers.review') }}" class="space-y-5"
          x-data="{
              {{-- Js::from() is the safe way to embed data in an attribute (see requisitions/show.blade.php). --}}
              lots: {{ \Illuminate\Support\Js::from($lots) }},
              locations: {{ \Illuminate\Support\Js::from($locations) }},
              defaultLocation: {{ \Illuminate\Support\Js::from($defaultLocation) }},
              rows: {{ \Illuminate\Support\Js::from($rows) }},
              docNo: {{ \Illuminate\Support\Js::from($docNo) }},
              dupLots: [],
              clientError: '',
              incompleteMessage: {{ \Illuminate\Support\Js::from(__('ims_transfer.client_incomplete')) }},
              init() {
                  this.rows = this.rows.map(r => {
                      const lot = this.lotOf(r.lot);
                      return { lot: lot ? r.lot : '', qty: r.qty, location: r.location || this.defaultLocation, query: lot ? this.label(lot) : '', open: false };
                  });
                  if (this.rows.length === 0) { this.addRow(); }
                  this.checkDoc();
              },
              addRow() { this.rows.push({ lot: '', qty: '', location: this.defaultLocation, query: '', open: false }); },
              removeRow(i) { this.rows.splice(i, 1); if (this.rows.length === 0) { this.addRow(); } this.checkDoc(); },
              readonly(row) { const l = this.lotOf(row.lot); return l !== null && l.movable === false; },
              lotOf(ulid) { return this.lots.find(l => l.ulid === ulid) || null; },
              label(l) { return l.code + ' ' + l.name + (l.lot ? ' · Lot ' + l.lot : ''); },
              matches(row) {
                  const words = row.query.toLowerCase().split(/\s+/).filter(w => w !== '');
                  if (words.length === 0) { return []; }
                  return this.lots.filter(l => {
                      const hay = (l.code + ' ' + l.name + ' ' + l.lot).toLowerCase();
                      return words.every(w => hay.includes(w));
                  }).slice(0, 12);
              },
              pick(row, l) {
                  row.lot = l.ulid; row.query = this.label(l); row.open = false;
                  if (row.location === '') { row.location = this.defaultLocation; }
                  this.checkDoc();
              },
              typing(row) { row.lot = ''; row.open = true; },
              movableRows() { return this.rows.filter(r => r.lot !== '' && !this.readonly(r)); },
              async checkDoc() {
                  const v = this.docNo.trim();
                  const lots = this.movableRows().map(r => r.lot);
                  if (v === '' || lots.length === 0) { this.dupLots = []; return; }
                  const q = 'ims_doc_no=' + encodeURIComponent(v) + lots.map(u => '&lots[]=' + encodeURIComponent(u)).join('');
                  const res = await fetch({{ \Illuminate\Support\Js::from(route('ims.transfers.doc-check')) }} + '?' + q, { headers: { 'Accept': 'application/json' } });
                  if (res.ok) { this.dupLots = (await res.json()).lots || []; }
              },
              submit(e) {
                  this.clientError = '';
                  const mine = this.rows.filter(r => !this.readonly(r));
                  if (this.docNo.trim() === '' || mine.length === 0 || mine.some(r => r.lot === '' || r.qty.trim() === '' || r.location === '')) {
                      e.preventDefault(); this.clientError = this.incompleteMessage;
                  }
              }
          }"
          @submit="submit($event)">
        @csrf

        <div class="bg-surface border border-border rounded-xl p-5 grid gap-4 md:grid-cols-2">
            <div>
                <label for="ims_doc_no" class="block text-sm font-medium mb-1">{{ __('ims_transfer.field_doc_no') }}</label>
                <input id="ims_doc_no" name="ims_doc_no" type="text" maxlength="64" required x-model="docNo" @input.debounce.500ms="checkDoc()"
                       class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                <p class="text-xs text-ink-faint mt-1">{{ __('ims_transfer.field_doc_no_hint') }}</p>
            </div>
            <div>
                <label for="remark" class="block text-sm font-medium mb-1">{{ __('ims_transfer.field_remark') }}</label>
                <input id="remark" name="remark" type="text" maxlength="500" value="{{ $remark }}"
                       class="w-full rounded-lg border border-border px-3 py-2 text-sm">
            </div>
        </div>

        <div class="bg-surface border border-border rounded-xl p-5">
            <h2 class="text-sm font-semibold mb-1">{{ __('ims_transfer.rows_label') }}</h2>
            <p class="text-xs text-ink-faint mb-3">{{ __('ims_transfer.rows_hint') }}</p>

            <div class="space-y-3">
                <template x-for="(row, i) in rows" :key="i">
                    <div class="rounded-lg border border-border p-3 grid gap-3 md:grid-cols-12 items-start">
                        {{-- A row of a kind this user does not look after is shown read-only and never submitted (disabled). --}}
                        <input type="hidden" :name="'rows[' + i + '][lot]'" :value="row.lot" :disabled="readonly(row)">

                        <div class="md:col-span-5 relative">
                            <label class="block text-xs font-medium mb-1">{{ __('ims_transfer.col_item') }}</label>
                            <input type="text" x-model="row.query" @input="typing(row)" @focus="row.open = true" @keydown.enter.prevent @keydown.escape="row.open = false"
                                   placeholder="{{ __('ims_transfer.search_placeholder') }}" autocomplete="off"
                                   class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                            <ul x-show="row.open && matches(row).length > 0" x-cloak @click.outside="row.open = false"
                                class="absolute z-20 mt-1 w-full max-h-64 overflow-auto rounded-lg border border-border bg-surface shadow-lg text-sm">
                                <template x-for="l in matches(row)" :key="l.ulid">
                                    <li>
                                        <button type="button" @click="pick(row, l)" class="w-full text-left px-3 py-2 hover:bg-surface-alt">
                                            <span class="font-mono text-xs text-ink-faint" x-text="l.code"></span>
                                            <span x-text="l.name"></span>
                                            <span class="block text-xs text-ink-muted">
                                                Lot <span x-text="l.lot || '—'"></span>
                                                · {{ __('ims_transfer.remaining') }} <span class="font-mono" x-text="l.remaining + ' ' + l.unit"></span>
                                                <template x-if="l.expiry"><span> · {{ __('ims_transfer.expiry') }} <span x-text="l.expiry"></span></span></template>
                                            </span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                            <template x-if="lotOf(row.lot)">
                                <p class="text-xs text-ink-muted mt-1">
                                    {{ __('ims_transfer.remaining') }}
                                    <span class="font-mono font-semibold" x-text="lotOf(row.lot).remaining + ' ' + lotOf(row.lot).unit"></span>
                                    <template x-if="lotOf(row.lot).pack">
                                        <span> · {{ __('ims_transfer.pack_size') }} <span class="font-mono" x-text="lotOf(row.lot).pack + ' ' + lotOf(row.lot).unit"></span></span>
                                    </template>
                                    <template x-if="lotOf(row.lot).place"><span> · {{ __('ims_transfer.from_place') }} <span x-text="lotOf(row.lot).place"></span></span></template>
                                </p>
                            </template>
                            <p x-show="readonly(row)" x-cloak class="text-xs font-semibold text-ink-muted bg-surface-alt rounded-md px-2 py-1 mt-1">{{ __('ims_transfer.other_manager') }}</p>
                            <p x-show="!readonly(row) && dupLots.includes(row.lot)" x-cloak class="text-xs text-warning-ink bg-warning-soft rounded-md px-2 py-1 mt-1">{{ __('ims_transfer.lot_already_moved_warning') }}</p>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium mb-1">
                                {{ __('ims_transfer.col_qty') }}
                                <template x-if="lotOf(row.lot)"><span class="text-ink-faint" x-text="'(' + {{ \Illuminate\Support\Js::from(__('ims_transfer.max_packs')) }} + ' ' + lotOf(row.lot).packs_left + ')'"></span></template>
                            </label>
                            <input type="text" inputmode="numeric" pattern="[0-9]*" :name="'rows[' + i + '][qty]'" x-model="row.qty" :disabled="readonly(row)" @keydown.enter.prevent
                                   class="w-full rounded-lg border border-border px-3 py-2 text-sm font-mono text-right disabled:bg-surface-alt">
                        </div>

                        <div class="md:col-span-4">
                            <label class="block text-xs font-medium mb-1">{{ __('ims_transfer.col_location') }}</label>
                            <select :name="'rows[' + i + '][location]'" x-model="row.location" :disabled="readonly(row)" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                                <option value="">{{ __('items.select_placeholder') }}</option>
                                <template x-for="loc in locations" :key="loc.ulid">
                                    <option :value="loc.ulid" x-text="loc.label" :selected="loc.ulid === row.location"></option>
                                </template>
                            </select>
                        </div>

                        <div class="md:col-span-1 md:pt-6 text-right">
                            <button type="button" @click="removeRow(i)" class="text-xs font-semibold text-danger hover:underline">{{ __('ims_transfer.remove_row') }}</button>
                        </div>
                    </div>
                </template>
            </div>

            <button type="button" @click="addRow()" class="mt-3 rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-4 py-2">+ {{ __('ims_transfer.add_row') }}</button>
        </div>

        <p x-show="clientError !== ''" x-cloak x-text="clientError" class="rounded-lg bg-danger-soft text-danger-ink text-sm px-4 py-3" role="alert"></p>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-5 py-2.5">{{ __('ims_transfer.btn_review') }}</button>
            <a href="{{ route('ims.lots.index') }}" class="text-sm font-semibold text-ink-muted hover:text-ink">{{ __('ims.back') }}</a>
        </div>
    </form>
</x-layout>
