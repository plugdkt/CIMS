{{--
    User-requested 2026-09-23: extracted so the auto (multi-container) and manual
    (single-container) issue forms can each embed the same receiver-confirmation widget
    without hand-copying its markup. The surrounding `<form>` still owns the Alpine
    `x-data`/`x-init`/`@submit` (mode, canvas drawing, hidden-field wiring) — a `submit`
    event only bubbles up to ancestors, never down into a child like this partial, so that
    logic can't live in here. Expects `$requisition`, `$line`, and `$formPrefix` (unique per
    form on the page, e.g. "auto-12"/"manual-12") so element ids never collide when both
    forms render in the same DOM (only one is visible at a time via the parent's x-show).
--}}
<div class="sm:col-span-3 border-t border-border pt-3 mt-1">
    <input type="hidden" name="signature_image" x-ref="signatureImageInput">
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
            <label class="block text-xs font-medium mb-1" for="otp_code-{{ $formPrefix }}">{{ __('requisitions.field_otp_code') }}</label>
            <input type="text" name="otp_code" id="otp_code-{{ $formPrefix }}" x-ref="otpCodeInput" maxlength="6"
                   class="w-32 rounded-lg border border-border bg-surface px-3 py-2 text-sm font-mono">
        </div>
        <button type="button" @click="sendOtp" class="text-xs font-semibold text-accent hover:text-accent-strong pb-2.5">
            {{ __('requisitions.send_otp') }}
        </button>
        <span class="text-xs text-success-ink pb-2.5" x-show="otpSent">{{ __('requisitions.otp_sent') }}</span>
    </div>
</div>
