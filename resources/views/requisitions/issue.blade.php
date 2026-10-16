<x-layout>
    <div class="max-w-4xl">
        <div class="mb-5">
            <a href="{{ route('requisitions.show', $requisition) }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('requisitions.back_to_requisition') }}</a>
            <h1 class="font-display text-lg font-bold mt-1">{{ __('requisitions.issue_title') }} — {{ $requisition->doc_no }}</h1>
            @php
                $doneLines = $lines->filter(fn ($r) => bccomp($r['remaining_base'], '0', 6) <= 0)->count();
            @endphp
            <p class="text-sm text-ink-muted mt-1">
                {{ __('requisitions.col_requester') }}: <span class="font-semibold text-ink">{{ $requisition->requester?->full_name }}</span>
                · {{ $requisition->doc_date->format('d/m/Y') }}
                · {{ __('requisitions.issue_progress', ['done' => $doneLines, 'total' => $lines->count()]) }}
            </p>
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


        @php
            $pending = $lines->filter(fn ($r) => bccomp($r['remaining_base'], '0', 6) > 0);
            $fmtAll = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
        @endphp
        @if ($pending->isNotEmpty())
            <form method="POST" action="{{ route('requisitions.issue-all', $requisition) }}"
                  class="bg-surface border-2 border-accent rounded-xl p-6 mb-6"
                  x-data="{
                      mode: 'signature', otpSent: false, hasDrawn: false, drawing: false,
                      pos(el, e) {
                          const rect = el.getBoundingClientRect();
                          const t = e.touches ? e.touches[0] : e;
                          return [t.clientX - rect.left, t.clientY - rect.top];
                      },
                      start(e) {
                          this.drawing = true; this.hasDrawn = true;
                          const ctx = this.$refs.canvas.getContext('2d');
                          const [x, y] = this.pos(this.$refs.canvas, e);
                          ctx.beginPath(); ctx.moveTo(x, y);
                          e.preventDefault();
                      },
                      move(e) {
                          if (!this.drawing) return;
                          const ctx = this.$refs.canvas.getContext('2d');
                          const [x, y] = this.pos(this.$refs.canvas, e);
                          ctx.lineTo(x, y); ctx.stroke();
                          e.preventDefault();
                      },
                      stop() { this.drawing = false; },
                      clearCanvas() {
                          const canvas = this.$refs.canvas;
                          canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
                          this.hasDrawn = false;
                      },
                      async sendOtp() {
                          await fetch('{{ route('requisitions.receiver-otp.send', $requisition) }}', {
                              method: 'POST',
                              headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                          });
                          this.otpSent = true;
                      },
                      onSubmit() {
                          if (this.mode === 'signature') {
                              this.$refs.otpCodeInput.value = '';
                              this.$refs.signatureImageInput.value = this.hasDrawn ? this.$refs.canvas.toDataURL('image/png') : '';
                          } else {
                              this.$refs.signatureImageInput.value = '';
                          }
                      },
                  }"
                  x-init="$nextTick(() => {
                              $refs.canvas.width = $refs.canvas.getBoundingClientRect().width;
                              $refs.canvas.height = 120;
                              const ctx = $refs.canvas.getContext('2d');
                              ctx.strokeStyle = '#111827'; ctx.lineWidth = 2; ctx.lineJoin = 'round'; ctx.lineCap = 'round';
                          })"
                  @submit="onSubmit">
                @csrf
                <h2 class="font-display text-lg font-bold">{{ __('requisitions.issue_all_title') }}</h2>
                <p class="text-sm text-ink-muted mb-4">{{ __('requisitions.issue_all_desc') }}</p>

                <div class="overflow-x-auto mb-4" tabindex="0">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-ink-faint uppercase tracking-wide">
                            <tr>
                                <th class="py-2 pr-3">{{ __('requisitions.col_item') }}</th>
                                <th class="py-2 pr-3">{{ __('requisitions.tile_to_issue') }}</th>
                                <th class="py-2 pr-3">{{ __('requisitions.stock_available') }}</th>
                                <th class="py-2 pr-3">{{ __('requisitions.field_qty_issued_total') }}</th>
                                <th class="py-2">{{ __('requisitions.field_unit') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($pending as $row)
                                @php
                                    $l = $row['line'];
                                    $bu = $l->item?->baseUnit;
                                    $same = $units->filter(fn ($u) => $bu === null || $u->dimension === $bu->dimension);
                                    $isShort = bccomp($row['availability']['total'], $row['remaining_base'], 6) < 0;
                                @endphp
                                <tr>
                                    <td class="py-2 pr-3">
                                        <div class="font-semibold">{{ $l->item?->name_th }}</div>
                                        <div class="font-mono text-xs text-ink-muted">{{ $l->item?->item_code }}</div>
                                    </td>
                                    <td class="py-2 pr-3 font-mono font-bold whitespace-nowrap">{{ $fmtAll($row['remaining_base']) }} {{ $bu?->code }}</td>
                                    <td class="py-2 pr-3 font-mono whitespace-nowrap {{ $isShort ? 'text-danger-ink font-semibold' : 'text-ink-muted' }}">
                                        {{ $fmtAll($row['availability']['total']) }} {{ $bu?->code }}
                                        @if ($isShort) <span class="text-xs">({{ __('requisitions.stock_short') }})</span> @endif
                                    </td>
                                    <td class="py-2 pr-3">
                                        <label class="sr-only" for="all_qty-{{ $l->id }}">{{ __('requisitions.field_qty_issued_total') }} {{ $l->item?->name_th }}</label>
                                        <input type="text" inputmode="decimal" name="lines[{{ $l->id }}][qty]" id="all_qty-{{ $l->id }}"
                                               value="{{ old('lines.'.$l->id.'.qty', $fmtAll($row['remaining_base'])) }}"
                                               class="w-32 rounded-lg border border-border bg-surface px-3 py-2 font-mono font-bold">
                                    </td>
                                    <td class="py-2">
                                        <label class="sr-only" for="all_unit-{{ $l->id }}">{{ __('requisitions.field_unit') }} {{ $l->item?->name_th }}</label>
                                        <select name="lines[{{ $l->id }}][unit_id]" id="all_unit-{{ $l->id }}" class="rounded-lg border border-border bg-surface px-2 py-2">
                                            @foreach ($same as $u)
                                                <option value="{{ $u->id }}" @selected((int) old('lines.'.$l->id.'.unit_id', $bu?->id) === $u->id)>{{ $u->code }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <details class="rounded-lg border border-border bg-surface-alt px-3 py-2 mb-4">
                    <summary class="cursor-pointer text-xs font-semibold text-ink-muted">{{ __('requisitions.more_options') }}</summary>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium mb-1" for="issue_all_remark">{{ __('requisitions.field_issue_remark') }}</label>
                            <input type="text" name="remark" id="issue_all_remark" value="{{ old('remark') }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm"
                                   placeholder="{{ __('requisitions.field_issue_remark_hint') }}">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" for="issue_all_overage">{{ __('requisitions.field_overage_approver') }}</label>
                            <input type="number" name="overage_approved_by" id="issue_all_overage" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm"
                                   placeholder="{{ __('requisitions.field_overage_approver_hint') }}">
                        </div>
                    </div>
                </details>

                <p class="text-xs font-semibold text-ink-muted">{{ __('requisitions.issue_all_receiver') }}</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    @include('requisitions.partials.receiver-signature', ['requisition' => $requisition, 'formPrefix' => 'all'])
                </div>

                <button type="submit" class="mt-4 rounded-lg bg-success hover:bg-success-ink text-white text-base font-bold px-6 py-3 shadow-md focus:outline-none focus:ring-4 focus:ring-success-soft">
                    {{ __('requisitions.issue_all_button', ['count' => $pending->count()]) }}
                </button>
            </form>

            <h2 class="text-sm font-semibold text-ink-muted mb-3">{{ __('requisitions.issue_all_individual') }}</h2>
        @endif

        @foreach ($lines as $row)
            @php
                $line = $row['line'];
            @endphp
            @php
                $fmt = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
                $item = $line->item;
                $baseUnit = $item?->baseUnit;
                $unitCode = $baseUnit?->code;
                $sameDimension = $units->filter(fn ($u) => $baseUnit === null || $u->dimension === $baseUnit->dimension);
                $reduced = $line->qty_approved_base !== null && bccomp($line->qty_approved_base, $line->qty_requested_base, 6) < 0;
                $need = $row['remaining_base'];
                $stockTotal = $row['availability']['total'];
                $short = bccomp($stockTotal, $need, 6) < 0;
            @endphp
            <div class="bg-surface border border-border rounded-xl p-6 mb-4">
                <div class="flex items-center gap-2 flex-wrap mb-4">
                    <h2 class="font-display text-lg font-bold">{{ $item?->name_th }}</h2>
                    @if ($item?->item_code)
                        <span class="font-mono text-xs text-ink-muted px-1.5 py-0.5 rounded bg-surface-alt border border-border/70">{{ $item->item_code }}</span>
                    @endif
                    @if ($item?->grade)
                        <span class="inline-block text-xs px-2 py-0.5 rounded-md bg-accent-soft text-accent-strong font-semibold border border-accent/20">{{ $item->grade }}</span>
                    @endif
                </div>

                {{-- How much has to be handed out — big, so the dispenser never has to hunt for it. --}}
                <div class="grid grid-cols-2 {{ $reduced ? 'md:grid-cols-4' : 'md:grid-cols-3' }} gap-3 mb-4">
                    <div class="rounded-xl border border-border bg-surface-alt px-4 py-3">
                        <div class="text-xs text-ink-muted">{{ __('requisitions.tile_requested') }}</div>
                        <div class="font-mono text-xl font-bold">{{ $fmt($line->qty_requested_base) }} <span class="text-sm font-semibold">{{ $unitCode }}</span></div>
                        @if ($line->unit && $baseUnit && $line->unit_id !== $baseUnit->id)
                            <div class="text-[11px] text-ink-faint">({{ __('requisitions.tile_requested_as') }} {{ $fmt($line->qty_requested) }} {{ $line->unit->code }})</div>
                        @endif
                    </div>
                    @if ($reduced)
                        <div class="rounded-xl border border-warning/40 bg-warning-soft px-4 py-3">
                            <div class="text-xs text-warning-ink">{{ __('requisitions.tile_approved') }}</div>
                            <div class="font-mono text-xl font-bold text-warning-ink">{{ $fmt($line->qty_approved_base) }} <span class="text-sm font-semibold">{{ $unitCode }}</span></div>
                        </div>
                    @endif
                    <div class="rounded-xl border border-border bg-surface-alt px-4 py-3">
                        <div class="text-xs text-ink-muted">{{ __('requisitions.tile_issued') }}</div>
                        <div class="font-mono text-xl font-bold">{{ $fmt($line->qty_issued_base ?? 0) }} <span class="text-sm font-semibold">{{ $unitCode }}</span></div>
                    </div>
                    <div class="rounded-xl border-2 {{ bccomp($need, '0', 6) > 0 ? 'border-accent bg-accent-soft' : 'border-success bg-success-soft' }} px-4 py-3 col-span-2 md:col-span-1">
                        <div class="text-xs {{ bccomp($need, '0', 6) > 0 ? 'text-accent-soft-ink' : 'text-success-ink' }} font-semibold">{{ __('requisitions.tile_to_issue') }}</div>
                        <div class="font-mono text-2xl font-extrabold {{ bccomp($need, '0', 6) > 0 ? 'text-accent-strong' : 'text-success-ink' }}">{{ $fmt($need) }} <span class="text-base font-bold">{{ $unitCode }}</span></div>
                    </div>
                </div>

                {{-- Can the branch cover it? Working stock first; the rest is cut from IMS automatically. --}}
                @if (bccomp($need, '0', 6) > 0)
                    <p class="text-sm mb-3 {{ $short ? 'text-danger-ink font-semibold' : 'text-ink-muted' }}">
                        {{ __('requisitions.stock_available') }}
                        <span class="font-mono font-semibold">{{ $fmt($stockTotal) }} {{ $unitCode }}</span>
                        <span class="text-xs">({{ __('shop.from_working') }} {{ $fmt($row['availability']['working']) }} · {{ __('shop.from_ims') }} {{ $fmt($row['availability']['ims']) }})</span>
                        @if ($short)
                            — {{ __('requisitions.stock_short') }}
                        @endif
                    </p>
                @endif

                @if ($row['low_stock'])
                    <div class="mb-3">
                        <span class="inline-flex items-center gap-1 text-xs px-2 py-1 rounded-md bg-warning-soft text-warning-ink font-semibold">
                            ⚠️ {{ __('requisitions.low_stock_warning', ['balance' => $fmt($row['stock_balance'])]) }}
                        </span>
                    </div>
                @endif

                @if ($row['remaining_base'] <= 0)
                    <p class="text-sm text-success-ink">{{ __('requisitions.line_fully_issued') }}</p>
                @else
                    <div class="mb-3" x-data="{ issueMode: 'auto' }">
                        {{-- User-requested 2026-09-23: auto (multi-container) is the default —
                             manual stays available for an issuer who wants to hand-pick a
                             specific container (e.g. to force a normally-not-recommended one). --}}
                        <div class="flex gap-2 mb-3">
                            <button type="button" @click="issueMode = 'auto'"
                                    :class="issueMode === 'auto' ? 'bg-accent text-white' : 'bg-surface-alt text-ink-muted hover:text-ink'"
                                    class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors">
                                {{ __('requisitions.issue_mode_auto') }}
                            </button>
                            <button type="button" @click="issueMode = 'manual'"
                                    :class="issueMode === 'manual' ? 'bg-accent text-white' : 'bg-surface-alt text-ink-muted hover:text-ink'"
                                    class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors">
                                {{ __('requisitions.issue_mode_manual') }}
                            </button>
                        </div>

                        {{-- AUTO MODE: one total quantity, containers filled in FEFO order automatically. --}}
                        <div x-show="issueMode === 'auto'" x-cloak>
                            <p class="text-xs text-ink-muted mb-2">{{ __('requisitions.issue_mode_auto_desc') }}</p>
                            <details class="mb-3">
                            <summary class="cursor-pointer text-xs font-semibold text-accent">{{ __('requisitions.fefo_list_summary', ['count' => $row['containers']->count()]) }}</summary>
                            <div class="overflow-x-auto mt-2 mb-2" tabindex="0">
                                <table class="w-full text-xs">
                                    <thead class="text-left text-ink-faint uppercase tracking-wide">
                                        <tr>
                                            <th class="py-1 pr-3">{{ __('requisitions.col_barcode') }}</th>
                                            <th class="py-1 pr-3">{{ __('requisitions.col_container_status') }}</th>
                                            <th class="py-1 pr-3">{{ __('requisitions.col_expiry') }}</th>
                                            <th class="py-1 pr-3">{{ __('requisitions.col_remaining_qty') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border">
                                        @forelse ($row['containers'] as $container)
                                            <tr class="{{ $selector->isExpired($container) ? 'text-danger-ink' : '' }}">
                                                <td class="py-1 pr-3 font-mono">{{ $container->barcode }}</td>
                                                <td class="py-1 pr-3">{{ $container->status }}</td>
                                                <td class="py-1 pr-3">
                                                    {{ $container->expiry_date?->format('d/m/Y') ?: '—' }}
                                                    @if ($selector->isExpired($container))
                                                        <strong>{{ __('requisitions.expired_warning') }}</strong>
                                                    @endif
                                                </td>
                                                <td class="py-1 pr-3">{{ rtrim(rtrim((string) $container->remaining_qty_base, '0'), '.') }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="4" class="py-2 text-ink-muted">{{ __('requisitions.no_containers_available') }}</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-[11px] text-ink-faint mb-1">{{ __('requisitions.auto_issue_preview_title') }}</p>
                            </details>
                            <p class="text-xs text-ink-muted mb-3">{{ __('requisitions.auto_cut_hint') }}</p>

                            <form method="POST" action="{{ route('requisitions.items.issue-auto', [$requisition, $line]) }}" class="grid grid-cols-1 sm:grid-cols-3 gap-3"
                                  x-data="{
                                      mode: 'signature', otpSent: false, hasDrawn: false, drawing: false,
                                      pos(el, e) {
                                          const rect = el.getBoundingClientRect();
                                          const t = e.touches ? e.touches[0] : e;
                                          return [t.clientX - rect.left, t.clientY - rect.top];
                                      },
                                      start(e) {
                                          this.drawing = true; this.hasDrawn = true;
                                          const ctx = this.$refs.canvas.getContext('2d');
                                          const [x, y] = this.pos(this.$refs.canvas, e);
                                          ctx.beginPath(); ctx.moveTo(x, y);
                                          e.preventDefault();
                                      },
                                      move(e) {
                                          if (!this.drawing) return;
                                          const ctx = this.$refs.canvas.getContext('2d');
                                          const [x, y] = this.pos(this.$refs.canvas, e);
                                          ctx.lineTo(x, y); ctx.stroke();
                                          e.preventDefault();
                                      },
                                      stop() { this.drawing = false; },
                                      clearCanvas() {
                                          const canvas = this.$refs.canvas;
                                          canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
                                          this.hasDrawn = false;
                                      },
                                      async sendOtp() {
                                          await fetch('{{ route('requisitions.receiver-otp.send', $requisition) }}', {
                                              method: 'POST',
                                              headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                                          });
                                          this.otpSent = true;
                                      },
                                      onSubmit() {
                                          if (this.mode === 'signature') {
                                              this.$refs.otpCodeInput.value = '';
                                              this.$refs.signatureImageInput.value = this.hasDrawn ? this.$refs.canvas.toDataURL('image/png') : '';
                                          } else {
                                              this.$refs.signatureImageInput.value = '';
                                          }
                                      },
                                  }"
                                  x-init="$nextTick(() => {
                                              $refs.canvas.width = $refs.canvas.getBoundingClientRect().width;
                                              $refs.canvas.height = 120;
                                              const ctx = $refs.canvas.getContext('2d');
                                              ctx.strokeStyle = '#111827'; ctx.lineWidth = 2; ctx.lineJoin = 'round'; ctx.lineCap = 'round';
                                          })"
                                  @submit="onSubmit">
                                @csrf
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-medium mb-1" for="qty_issued_auto-{{ $line->id }}">{{ __('requisitions.field_qty_issued_total') }}</label>
                                    <input type="text" name="qty_issued" id="qty_issued_auto-{{ $line->id }}" value="{{ old('qty_issued', $fmt($need)) }}" inputmode="decimal"
                                           class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-lg font-mono font-bold">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" for="issue_unit_id_auto-{{ $line->id }}">{{ __('requisitions.field_unit') }}</label>
                                    <select name="unit_id" id="issue_unit_id_auto-{{ $line->id }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-lg">
                                        @foreach ($sameDimension as $unit)
                                            <option value="{{ $unit->id }}" @selected((int) old('unit_id', $baseUnit?->id) === $unit->id)>{{ $unit->code }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <details class="sm:col-span-3 rounded-lg border border-border bg-surface-alt px-3 py-2" @if ($errors->any()) open @endif>
                                    <summary class="cursor-pointer text-xs font-semibold text-ink-muted">{{ __('requisitions.more_options') }}</summary>
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-medium mb-1" for="issue_remark_auto-{{ $line->id }}">{{ __('requisitions.field_issue_remark') }}</label>
                                    <input type="text" name="remark" id="issue_remark_auto-{{ $line->id }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm"
                                           placeholder="{{ __('requisitions.field_issue_remark_hint') }}">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" for="overage_approved_by_auto-{{ $line->id }}">{{ __('requisitions.field_overage_approver') }}</label>
                                    <input type="number" name="overage_approved_by" id="overage_approved_by_auto-{{ $line->id }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm"
                                           placeholder="{{ __('requisitions.field_overage_approver_hint') }}">
                                </div>
                                    </div>
                                </details>

                                @include('requisitions.partials.receiver-signature', ['requisition' => $requisition, 'line' => $line, 'formPrefix' => 'auto-'.$line->id])

                                <div class="sm:col-span-3">
                                    <button type="submit" class="rounded-lg bg-success hover:bg-success-ink text-white text-base font-bold px-6 py-3 shadow-md focus:outline-none focus:ring-4 focus:ring-success-soft">
                                        {{ __('requisitions.record_issue_auto') }}
                                    </button>
                                </div>
                            </form>
                        </div>

                        {{-- MANUAL MODE: existing hand-pick-one-container flow, unchanged. --}}
                        <div x-show="issueMode === 'manual'" x-cloak
                             x-data="{ selectedBarcode: '{{ $row['containers']->first()?->barcode }}' }">
                        <div class="overflow-x-auto mb-3" tabindex="0">
                            <table class="w-full text-xs">
                                <thead class="text-left text-ink-faint uppercase tracking-wide">
                                    <tr>
                                        <th class="py-1 pr-3"></th>
                                        <th class="py-1 pr-3">{{ __('requisitions.col_barcode') }}</th>
                                        <th class="py-1 pr-3">{{ __('requisitions.col_container_status') }}</th>
                                        <th class="py-1 pr-3">{{ __('requisitions.col_expiry') }}</th>
                                        <th class="py-1 pr-3">{{ __('requisitions.col_remaining_qty') }}</th>
                                        <th class="py-1"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @forelse ($row['containers'] as $i => $container)
                                        <tr class="cursor-pointer hover:bg-surface-alt {{ $selector->isExpired($container) ? 'text-danger-ink' : '' }}"
                                            @click="selectedBarcode = '{{ $container->barcode }}'">
                                            <td class="py-1 pr-1">
                                                <input type="radio" :checked="selectedBarcode === '{{ $container->barcode }}'"
                                                       @click="selectedBarcode = '{{ $container->barcode }}'"
                                                       aria-label="{{ __('requisitions.select_container_to_issue') }}">
                                            </td>
                                            <td class="py-1 pr-3 font-mono">{{ $container->barcode }}</td>
                                            <td class="py-1 pr-3">{{ $container->status }}</td>
                                            <td class="py-1 pr-3">
                                                {{ $container->expiry_date?->format('d/m/Y') ?: '—' }}
                                                @if ($selector->isExpired($container))
                                                    <strong>{{ __('requisitions.expired_warning') }}</strong>
                                                @endif
                                            </td>
                                            <td class="py-1 pr-3">{{ rtrim(rtrim((string) $container->remaining_qty_base, '0'), '.') }}</td>
                                            <td class="py-1">
                                                @if ($i === 0 && ! $selector->isExpired($container))
                                                    <span class="text-accent font-semibold">{{ __('requisitions.fefo_recommended') }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="py-2 text-ink-muted">{{ __('requisitions.no_containers_available') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                    <form method="POST" action="{{ route('requisitions.items.issue', [$requisition, $line]) }}" class="grid grid-cols-1 sm:grid-cols-3 gap-3"
                          x-data="{
                              mode: 'signature', otpSent: false, hasDrawn: false, drawing: false,
                              pos(el, e) {
                                  const rect = el.getBoundingClientRect();
                                  const t = e.touches ? e.touches[0] : e;
                                  return [t.clientX - rect.left, t.clientY - rect.top];
                              },
                              start(e) {
                                  this.drawing = true; this.hasDrawn = true;
                                  const ctx = this.$refs.canvas.getContext('2d');
                                  const [x, y] = this.pos(this.$refs.canvas, e);
                                  ctx.beginPath(); ctx.moveTo(x, y);
                                  e.preventDefault();
                              },
                              move(e) {
                                  if (!this.drawing) return;
                                  const ctx = this.$refs.canvas.getContext('2d');
                                  const [x, y] = this.pos(this.$refs.canvas, e);
                                  ctx.lineTo(x, y); ctx.stroke();
                                  e.preventDefault();
                              },
                              stop() { this.drawing = false; },
                              clearCanvas() {
                                  const canvas = this.$refs.canvas;
                                  canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
                                  this.hasDrawn = false;
                              },
                              async sendOtp() {
                                  await fetch('{{ route('requisitions.receiver-otp.send', $requisition) }}', {
                                      method: 'POST',
                                      headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                                  });
                                  this.otpSent = true;
                              },
                              onSubmit() {
                                  if (this.mode === 'signature') {
                                      this.$refs.otpCodeInput.value = '';
                                      this.$refs.signatureImageInput.value = this.hasDrawn ? this.$refs.canvas.toDataURL('image/png') : '';
                                  } else {
                                      this.$refs.signatureImageInput.value = '';
                                  }
                              },
                          }"
                          x-init="$nextTick(() => {
                                      $refs.canvas.width = $refs.canvas.getBoundingClientRect().width;
                                      $refs.canvas.height = 120;
                                      const ctx = $refs.canvas.getContext('2d');
                                      ctx.strokeStyle = '#111827'; ctx.lineWidth = 2; ctx.lineJoin = 'round'; ctx.lineCap = 'round';
                                  })"
                          @submit="onSubmit">
                        @csrf
                        <div>
                            <label class="block text-xs font-medium mb-1" for="barcode-{{ $line->id }}">{{ __('requisitions.field_barcode') }}</label>
                            <input type="text" name="barcode" id="barcode-{{ $line->id }}" x-model="selectedBarcode"
                                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm font-mono">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" for="qty_issued-{{ $line->id }}">{{ __('requisitions.field_qty_issued') }}</label>
                            <input type="text" name="qty_issued" id="qty_issued-{{ $line->id }}" value="{{ old('qty_issued', $fmt($need)) }}" inputmode="decimal" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" for="issue_unit_id-{{ $line->id }}">{{ __('requisitions.field_unit') }}</label>
                            <select name="unit_id" id="issue_unit_id-{{ $line->id }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                                @foreach ($sameDimension as $unit)
                                    <option value="{{ $unit->id }}" @selected((int) old('unit_id', $baseUnit?->id) === $unit->id)>{{ $unit->code }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium mb-1" for="issue_remark-{{ $line->id }}">{{ __('requisitions.field_issue_remark') }}</label>
                            <input type="text" name="remark" id="issue_remark-{{ $line->id }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm"
                                   placeholder="{{ __('requisitions.field_issue_remark_hint') }}">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" for="overage_approved_by-{{ $line->id }}">{{ __('requisitions.field_overage_approver') }}</label>
                            <input type="number" name="overage_approved_by" id="overage_approved_by-{{ $line->id }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm"
                                   placeholder="{{ __('requisitions.field_overage_approver_hint') }}">
                        </div>

                        {{-- FR-RQ-11: receiver identity — a drawn signature, or an OTP emailed to the requester. --}}
                        @include('requisitions.partials.receiver-signature', ['requisition' => $requisition, 'line' => $line, 'formPrefix' => 'manual-'.$line->id])

                        <div class="sm:col-span-3">
                            <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">
                                {{ __('requisitions.record_issue') }}
                            </button>
                        </div>
                    </form>
                    </div>
                    </div>
                @endif

                @if ($canReturn && $row['returnable_base'] > 0)
                    <div class="border-t border-border mt-4 pt-4">
                        <h3 class="text-sm font-semibold mb-2">
                            {{ __('requisitions.return_title') }}
                            <span class="text-xs text-ink-muted font-normal">({{ __('requisitions.returnable') }} {{ rtrim(rtrim((string) $row['returnable_base'], '0'), '.') }})</span>
                        </h3>
                        <form method="POST" action="{{ route('requisitions.items.return', [$requisition, $line]) }}" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                            @csrf
                            <div>
                                <label class="block text-xs font-medium mb-1" for="return_container-{{ $line->id }}">{{ __('requisitions.field_return_container') }}</label>
                                <select name="container_id" id="return_container-{{ $line->id }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                                    <option value="">{{ __('items.select_placeholder') }}</option>
                                    @foreach ($row['issued_containers'] as $container)
                                        <option value="{{ $container->id }}">{{ $container->barcode }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1" for="qty_returned-{{ $line->id }}">{{ __('requisitions.field_qty_returned') }}</label>
                                <input type="text" name="qty_returned" id="qty_returned-{{ $line->id }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1" for="return_unit_id-{{ $line->id }}">{{ __('requisitions.field_unit') }}</label>
                                <select name="unit_id" id="return_unit_id-{{ $line->id }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                                    <option value="">{{ __('items.select_placeholder') }}</option>
                                    @foreach ($units as $unit)
                                        <option value="{{ $unit->id }}" @selected($unit->id === $line->unit_id)>{{ $unit->code }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1" for="return_remark-{{ $line->id }}">{{ __('requisitions.field_issue_remark') }}</label>
                                <input type="text" name="remark" id="return_remark-{{ $line->id }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                            </div>
                            <div class="sm:col-span-4">
                                <button type="submit" class="rounded-lg border border-border hover:bg-surface-alt text-sm font-semibold px-4 py-2.5">
                                    {{ __('requisitions.record_return') }}
                                </button>
                            </div>
                        </form>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</x-layout>
