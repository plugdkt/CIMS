<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Exceptions\SsoVerificationException;
use App\Domain\Auth\Services\SsoClient;
use App\Domain\Auth\Services\UserProvisioningService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class SsoCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        SsoClient $sso,
        UserProvisioningService $provisioning,
    ): RedirectResponse {
        $token = trim((string) $request->query('token', ''));
        $state = trim((string) $request->query('state', ''));

        try {
            $ssoUser = $sso->handleCallback($token, $state);
        } catch (SsoVerificationException $e) {
            // SEC-AU-08: generic message to the user, real detail only in audit_logs.
            AuditLog::record(action: 'LOGIN_FAILURE', result: 'FAILURE', message: $e->getMessage());

            abort(400, 'เข้าสู่ระบบล้มเหลว: โทเคนไม่ถูกต้อง หมดอายุ หรือถูกใช้ไปแล้ว');
        }

        $user = $provisioning->provision($ssoUser);

        // SEC-AU-06: regenerate the session ID right after a successful login.
        Auth::login($user);
        $request->session()->regenerate();

        // SEC-AU-11: always redirect out of /sso/callback immediately.
        if ($user->roles()->doesntExist()) {
            return redirect()->route('account.pending-role');
        }

        return redirect()->intended('/');
    }
}
