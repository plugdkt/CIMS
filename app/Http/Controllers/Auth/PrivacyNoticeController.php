<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PrivacyConsentRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** SEC-PD-02: show the Privacy Notice and record consent on first use (or after a notice-version bump). */
final class PrivacyNoticeController extends Controller
{
    public function show(): View
    {
        /** @var User $user */
        $user = auth()->user();

        return view('privacy.notice', ['alreadyConsented' => $user->hasValidPrivacyConsent(), 'user' => $user]);
    }

    public function accept(PrivacyConsentRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->privacy_consent_at = now();
        $user->privacy_consent_version = (string) config('privacy.notice_version');
        $user->save();

        AuditLog::record(
            action: 'PRIVACY_CONSENT',
            userId: $user->id,
            username: $user->username,
            entityType: 'User',
            entityId: $user->id,
            message: 'ยอมรับ Privacy Notice เวอร์ชัน '.config('privacy.notice_version'),
        );

        // Mirrors SsoCallbackController's own post-login ordering (consent, then role) —
        // a still-roleless user must land on the pending-role page, not fall through to
        // EnsureRoleAssigned's 403 on whatever `intended()` resolves to.
        if ($user->roles()->doesntExist()) {
            return redirect()->route('account.pending-role')->with('status', __('privacy.consent_recorded'));
        }

        return redirect()->intended('/')->with('status', __('privacy.consent_recorded'));
    }
}
