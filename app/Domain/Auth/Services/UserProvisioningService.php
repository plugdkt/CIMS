<?php

declare(strict_types=1);

namespace App\Domain\Auth\Services;

use App\Domain\Auth\DTO\SsoUserData;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * BR-11: first successful verify for a given sso_subject creates a local user with no
 * role (deny-by-default). Every login re-syncs the SSO-owned fields — CMIS never lets
 * the user edit username/email/full_name/pos_name/div_name, the SSO payload always wins.
 */
final class UserProvisioningService
{
    public function provision(SsoUserData $data): User
    {
        $user = User::where('sso_subject', $data->subject)->first();
        $isNewUser = $user === null;

        if ($isNewUser) {
            $user = new User(['sso_subject' => $data->subject]);
            $user->ulid = (string) Str::ulid();
            $user->is_active = true;
        }

        $user->username = $data->username;
        $user->email = $data->email;
        $user->full_name = $data->name;
        $user->pos_name = $data->posName;
        $user->div_name = $data->divName;
        $user->last_login_at = now();
        $user->last_sso_sync_at = now();
        $user->save();

        AuditLog::record(
            action: $isNewUser ? 'SSO_FIRST_LOGIN' : 'LOGIN_SUCCESS',
            userId: $user->id,
            username: $user->username,
        );

        return $user;
    }
}
