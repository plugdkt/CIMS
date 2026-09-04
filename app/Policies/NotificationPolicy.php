<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

/** Every user may view/manage only their own notifications — no permission code needed. */
final class NotificationPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Notification $notification): bool
    {
        return $notification->user_id === $user->id;
    }

    public function update(User $user, Notification $notification): bool
    {
        return $notification->user_id === $user->id;
    }
}
