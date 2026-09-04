<x-layout>
    <div class="max-w-4xl">
        <div class="mb-5">
            <a href="{{ route('requisitions.show', $requisition) }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('requisitions.back_to_requisition') }}</a>
            <h1 class="font-display text-lg font-bold mt-1">{{ __('requisitions.issue_title') }} — {{ $requisition->doc_no }}</h1>
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

        @foreach ($lines as $row)
            @php($line = $row['line'])
            <div class="bg-surface border border-border rounded-xl p-6 mb-4">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="font-display text-base font-bold">{{ $line->item?->name_th }}</h2>
                    <span class="text-xs text-ink-muted">
                        {{ __('requisitions.field_qty_requested') }} {{ $line->qty_requested_base }}
                        / {{ __('requisitions.issued_so_far') }} {{ $line->qty_issued_base }}
                        ({{ __('requisitions.remaining') }} {{ $row['remaining_base'] }})
                    </span>
                </div>

                @if ($row['remaining_base'] <= 0)
                    <p class="text-sm text-success-ink">{{ __('requisitions.line_fully_issued') }}</p>
                @else
                    <div class="mb-3 overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="text-left text-ink-faint uppercase tracking-wide">
                                <tr>
                                    <th class="py-1 pr-3">{{ __('requisitions.col_barcode') }}</th>
                                    <th class="py-1 pr-3">{{ __('requisitions.col_container_status') }}</th>
                                    <th class="py-1 pr-3">{{ __('requisitions.col_expiry') }}</th>
                                    <th class="py-1 pr-3">{{ __('requisitions.col_remaining_qty') }}</th>
                                    <th class="py-1"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse ($row['containers'] as $i => $container)
                                    <tr class="{{ $selector->isExpired($container) ? 'text-danger-ink' : '' }}">
                                        <td class="py-1 pr-3 font-mono">{{ $container->barcode }}</td>
                                        <td class="py-1 pr-3">{{ $container->status }}</td>
                                        <td class="py-1 pr-3">
                                            {{ $container->expiry_date?->format('d/m/Y') ?: '—' }}
                                            @if ($selector->isExpired($container))
                                                <strong>{{ __('requisitions.expired_warning') }}</strong>
                                            @endif
                                        </td>
                                        <td class="py-1 pr-3">{{ $container->remaining_qty_base }}</td>
                                        <td class="py-1">
                                            @if ($i === 0 && ! $selector->isExpired($container))
                                                <span class="text-accent font-semibold">{{ __('requisitions.fefo_recommended') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="py-2 text-ink-muted">{{ __('requisitions.no_containers_available') }}</td></tr>
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
                        <input type="hidden" name="signature_image" x-ref="signatureImageInput">
                        <div>
                            <label class="block text-xs font-medium mb-1">{{ __('requisitions.field_barcode') }}</label>
                            <input type="text" name="barcode" value="{{ $row['containers']->first()?->barcode }}"
                                   class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm font-mono">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1">{{ __('requisitions.field_qty_issued') }}</label>
                            <input type="text" name="qty_issued" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1">{{ __('requisitions.field_unit') }}</label>
                            <select name="unit_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                                <option value="">{{ __('items.select_placeholder') }}</option>
                                @foreach ($units as $unit)
                                    <option value="{{ $unit->id }}" @selected($unit->id === $line->unit_id)>{{ $unit->code }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium mb-1">{{ __('requisitions.field_issue_remark') }}</label>
                            <input type="text" name="remark" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm"
                                   placeholder="{{ __('requisitions.field_issue_remark_hint') }}">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1">{{ __('requisitions.field_overage_approver') }}</label>
                            <input type="number" name="overage_approved_by" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm"
                                   placeholder="{{ __('requisitions.field_overage_approver_hint') }}">
                        </div>

                        {{-- FR-RQ-11: receiver identity — a drawn signature, or an OTP emailed to the requester. --}}
                        <div class="sm:col-span-3 border-t border-border pt-3 mt-1">
                            <div class="flex gap-4 mb-2">
                                <label class="flex items-center gap-2 text-xs">
                                    <input type="radio" x-model="mode" value="signature">
                                    {{ __('requisitions.receiver_mode_signature') }}
                                </label>
                                <label class="flex items-center gap-2 text-xs">
                                    <input type="radio" x-model="mode" value="otp">
                                    {{ __('requisitions.receiver_mode_otp') }}
                                </label>
                            </div>

                            <div x-show="mode === 'signature'">
                                <canvas x-ref="canvas"
                                        @mousedown="start" @mousemove="move" @mouseup="stop" @mouseleave="stop"
                                        @touchstart="start" @touchmove="move" @touchend="stop"
                                        class="w-full border border-border rounded-lg bg-white touch-none"></canvas>
                                <button type="button" @click="clearCanvas" class="text-xs text-ink-muted hover:text-ink mt-1">
                                    {{ __('requisitions.clear_signature') }}
                                </button>
                            </div>

                            <div x-show="mode === 'otp'" class="flex items-end gap-3">
                                <div>
                                    <label class="block text-xs font-medium mb-1">{{ __('requisitions.field_otp_code') }}</label>
                                    <input type="text" name="otp_code" x-ref="otpCodeInput" maxlength="6"
                                           class="w-32 rounded-lg border border-border bg-surface px-3 py-2 text-sm font-mono">
                                </div>
                                <button type="button" @click="sendOtp" class="text-xs font-semibold text-accent hover:text-accent-strong pb-2.5">
                                    {{ __('requisitions.send_otp') }}
                                </button>
                                <span class="text-xs text-success-ink pb-2.5" x-show="otpSent">{{ __('requisitions.otp_sent') }}</span>
                            </div>
                        </div>

                        <div class="sm:col-span-3">
                            <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">
                                {{ __('requisitions.record_issue') }}
                            </button>
                        </div>
                    </form>
                @endif

                @if ($canReturn && $row['returnable_base'] > 0)
                    <div class="border-t border-border mt-4 pt-4">
                        <h3 class="text-sm font-semibold mb-2">
                            {{ __('requisitions.return_title') }}
                            <span class="text-xs text-ink-muted font-normal">({{ __('requisitions.returnable') }} {{ $row['returnable_base'] }})</span>
                        </h3>
                        <form method="POST" action="{{ route('requisitions.items.return', [$requisition, $line]) }}" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                            @csrf
                            <div>
                                <label class="block text-xs font-medium mb-1">{{ __('requisitions.field_return_container') }}</label>
                                <select name="container_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                                    <option value="">{{ __('items.select_placeholder') }}</option>
                                    @foreach ($row['issued_containers'] as $container)
                                        <option value="{{ $container->id }}">{{ $container->barcode }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1">{{ __('requisitions.field_qty_returned') }}</label>
                                <input type="text" name="qty_returned" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1">{{ __('requisitions.field_unit') }}</label>
                                <select name="unit_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                                    <option value="">{{ __('items.select_placeholder') }}</option>
                                    @foreach ($units as $unit)
                                        <option value="{{ $unit->id }}" @selected($unit->id === $line->unit_id)>{{ $unit->code }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1">{{ __('requisitions.field_issue_remark') }}</label>
                                <input type="text" name="remark" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
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
