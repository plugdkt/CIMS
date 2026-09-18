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
            // A bulk import (php artisan users:import-lab-assignments) may have
            // already pre-created this username's account — lab_id set, but
            // sso_subject still null, since a `users` row otherwise can't exist
            // ahead of a real SSO login at all (no admin "create user" flow).
            // Claim that row instead of creating a duplicate one for the same
            // person; every field it doesn't already carry (ulid, is_active) is
            // filled in exactly like a genuinely new row.
            $user = User::where('username', $data->username)->whereNull('sso_subject')->first()
                ?? new User();
            $user->sso_subject = $data->subject;
            $user->ulid ??= (string) Str::ulid();
            $user->is_active ??= true;
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
