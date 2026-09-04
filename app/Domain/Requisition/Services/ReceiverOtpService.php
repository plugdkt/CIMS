<?php

declare(strict_types=1);

namespace App\Domain\Requisition\Services;

use App\Mail\ReceiverOtpMail;
use App\Models\Requisition;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * FR-RQ-11 "หรือยืนยันด้วย OTP ทางอีเมล" — the fallback identity confirmation for a
 * receiver who isn't there to draw a signature on the spot. No local credentials exist
 * in this app (SEC-AU-01), so this OTP is deliberately not an authentication mechanism —
 * it's a one-time, single-use, short-lived code proving "the person named as receiver
 * saw this and confirmed it", stored in Redis (via the cache), never in a table.
 */
final class ReceiverOtpService
{
    private const TTL_MINUTES = 10;

    public function send(User $receiver, Requisition $requisition): void
    {
        $code = (string) random_int(100000, 999999);
        Cache::put($this->cacheKey($receiver, $requisition), $code, now()->addMinutes(self::TTL_MINUTES));

        Mail::to($receiver->email)->send(new ReceiverOtpMail($requisition, $code));
    }

    public function verify(User $receiver, Requisition $requisition, string $code): bool
    {
        $key = $this->cacheKey($receiver, $requisition);
        $cached = Cache::get($key);

        if ($cached === null || ! hash_equals((string) $cached, $code)) {
            return false;
        }

        Cache::forget($key);

        return true;
    }

    private function cacheKey(User $receiver, Requisition $requisition): string
    {
        return "receiver-otp:{$requisition->id}:{$receiver->id}";
    }
}
